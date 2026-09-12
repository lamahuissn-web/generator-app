<?php

namespace App\Services\Backup;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only thing in the application that talks to Telegram.
 *
 * Two facts about the Bot API shape everything here:
 *
 *  - IT ANSWERS HTTP 200 WHILE REFUSING. `{"ok": false, "description": "chat not found"}` arrives
 *    with a 200, so a client that reads the status code alone records a delivered backup that was
 *    never delivered. Only `ok: true` counts, and the API's own `description` is the message the
 *    operator reads — it is already a sentence, and rewording it would make it less exact.
 *  - IT ACCEPTS A LIMITED SIZE PER MESSAGE, so a copy that outgrows one document is split at
 *    statement boundaries into ordered parts. A fragment cut mid-statement would be worse than a
 *    failure: it looks like a backup.
 *
 * The token is never logged, never stored in an attempt row, and never allowed to survive inside an
 * exception message (Guzzle puts the whole URI — token and all — into its own errors, so every
 * message leaving this class passes through sanitise()).
 */
final class TelegramBackupSender
{
    private const ENDPOINT = 'https://api.telegram.org';

    public function __construct(
        private readonly ?string $token = null,
        private readonly ?string $chatId = null,
    ) {}

    public static function make(): self
    {
        return new self(BackupSettings::botToken(), BackupSettings::chatId());
    }

    public function configured(): bool
    {
        return $this->token !== null && $this->chatId !== null;
    }

    /**
     * Send the copy — in one message when it fits, in ordered parts when it does not.
     *
     * @return array{ok: bool, reason: ?string, parts: int}
     */
    public function deliver(string $path, string $caption): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'reason' => __('bk.reason_no_destination'), 'parts' => 0];
        }

        if (! is_file($path)) {
            return ['ok' => false, 'reason' => __('bk.reason_file_missing'), 'parts' => 0];
        }

        $parts = $this->partsFor($path);

        if ($parts === []) {
            return ['ok' => false, 'reason' => __('bk.reason_unsplittable'), 'parts' => 0];
        }

        $total = count($parts);

        try {
            foreach ($parts as $index => $part) {
                $text = $total === 1
                    ? $caption
                    : $caption."\n".__('bk.part_line', ['part' => $index + 1, 'total' => $total]);

                $result = $this->sendDocument($part, $text);

                if (! $result['ok']) {
                    // Parts already delivered stay in the chat; this attempt is a failure, and the
                    // next run sends a fresh, complete copy rather than resuming a stale one.
                    return ['ok' => false, 'reason' => $result['reason'], 'parts' => $index];
                }
            }
        } finally {
            $this->removeParts($parts, $path);
        }

        return ['ok' => true, 'reason' => null, 'parts' => $total];
    }

    /** One plain message: a failure report, a catch-up line, a test. */
    public function message(string $text): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'reason' => __('bk.reason_no_destination')];
        }

        try {
            $response = Http::timeout(30)->asJson()->post($this->url('sendMessage'), [
                'chat_id' => $this->chatId,
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $this->sanitise($e->getMessage())];
        }

        return $response->json('ok') === true
            ? ['ok' => true, 'reason' => null]
            : ['ok' => false, 'reason' => $this->failure($response)];
    }

    /** Does the configured chat answer at all? Asked by the screen's test button. */
    public function test(): array
    {
        return $this->message(__('bk.test_line'));
    }

    // ---- the API calls --------------------------------------------------------

    /** @return array{ok: bool, reason: ?string} */
    private function sendDocument(string $path, string $caption): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ['ok' => false, 'reason' => __('bk.reason_file_missing')];
        }

        try {
            $response = Http::timeout(120)
                ->attach('document', $handle, basename($path))
                ->post($this->url('sendDocument'), [
                    'chat_id' => $this->chatId,
                    'caption' => $caption,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $this->sanitise($e->getMessage())];
        } finally {
            fclose($handle);
        }

        return $response->json('ok') === true
            ? ['ok' => true, 'reason' => null]
            : ['ok' => false, 'reason' => $this->failure($response)];
    }

    private function url(string $method): string
    {
        return self::ENDPOINT.'/bot'.$this->token.'/'.$method;
    }

    /** The API's own words when it has them, the status code when it does not. */
    private function failure(Response $response): string
    {
        $described = $response->json('description');

        return is_string($described) && $described !== ''
            ? $this->sanitise($described)
            : $this->sanitise('HTTP '.$response->status());
    }

    /** Never let the credential travel inside a message that gets stored and shown. */
    private function sanitise(string $message): string
    {
        if ($this->token === null || $this->token === '') {
            return $message;
        }

        return str_replace($this->token, '•••', $message);
    }

    // ---- splitting ------------------------------------------------------------

    /**
     * The file as one part, or as ordered parts small enough to send.
     *
     * Cutting happens on LINE boundaries, so every part is still valid SQL and reassembling is
     * concatenation — nothing about the format has to be explained to whoever restores it. A single
     * line larger than the limit cannot be split honestly, and refusing is the right answer.
     *
     * @return array<int, string> part paths, in order; empty when the file cannot be split
     */
    private function partsFor(string $path): array
    {
        $limit = BackupSettings::deliveryLimit();

        if (filesize($path) <= $limit) {
            return [$path];
        }

        $base = preg_replace('/\.sql$/', '', $path);

        // Two passes: count the parts first, so each caption can say "part 1 of 3" without a second
        // rewrite of files already sent.
        $source = fopen($path, 'rb');

        if ($source === false) {
            return [];
        }

        $sizes = [];
        $current = 0;

        while (($line = fgets($source)) !== false) {
            if (strlen($line) > $limit) {
                fclose($source);

                return [];
            }

            if ($current + strlen($line) > $limit) {
                $sizes[] = $current;
                $current = 0;
            }

            $current += strlen($line);
        }

        if ($current > 0) {
            $sizes[] = $current;
        }

        rewind($source);
        $total = count($sizes);
        $parts = [];
        $index = 0;
        $written = 0;
        $target = array_shift($sizes);
        $handle = null;

        while (($line = fgets($source)) !== false) {
            if ($handle === null || $written + strlen($line) > $target) {
                if ($handle !== null) {
                    fclose($handle);
                }

                $index++;
                $parts[] = sprintf('%s-part%dof%d.sql', $base, $index, $total);
                $handle = fopen(end($parts), 'wb');
                $target = array_shift($sizes) ?? $limit;
                $written = 0;

                if ($handle === false) {
                    fclose($source);

                    return [];
                }
            }

            fwrite($handle, $line);
            $written += strlen($line);
        }

        fclose($source);

        if ($handle !== null) {
            fclose($handle);
        }

        return $parts;
    }

    /** @param array<int, string> $parts */
    private function removeParts(array $parts, string $original): void
    {
        foreach ($parts as $part) {
            if ($part !== $original) {
                @unlink($part);
            }
        }
    }
}
