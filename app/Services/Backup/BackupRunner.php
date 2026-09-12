<?php

namespace App\Services\Backup;

use App\Models\BackupAttempt;

/**
 * One backup run, from "make a copy" to "the office has it" — in one place, so the nightly command
 * and the panel's send-now button cannot behave differently.
 *
 * The attempt row is written BEFORE any work starts and updated at the end, which is what makes a
 * killed process visible instead of invisible: a `running` row that never finished is evidence, and
 * an attempt is only ever called `sent` when Telegram accepted the copy (`ok: true`). Nothing here
 * modifies an earlier attempt — the record is append-only, so the history the office looks at cannot
 * be rewritten by a later run.
 */
final class BackupRunner
{
    /** A run still `running` after this long is treated as dead, not as an overlap. */
    public const STALE_RUN_MINUTES = 30;

    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly TelegramBackupSender $sender,
    ) {}

    public static function make(): self
    {
        return new self(DatabaseDumper::make(), TelegramBackupSender::make());
    }

    /** Is another run in flight right now? Asked before starting, and by the command. */
    public static function running(): bool
    {
        return BackupAttempt::query()
            ->where('status', BackupAttempt::STATUS_RUNNING)
            ->where('started_at', '>', now()->subMinutes(self::STALE_RUN_MINUTES))
            ->exists();
    }

    /**
     * Make a copy and try to deliver it.
     *
     * @param  string  $trigger  BackupAttempt::TRIGGER_SCHEDULED|TRIGGER_MANUAL
     * @param  int|null  $userId  the admin, for a manual run
     * @param  bool  $deliver  false = make and verify the copy, upload nothing (`--no-deliver`)
     * @param  int|null  $keep  retention for this run, overriding the configured count
     */
    public function run(
        string $trigger,
        ?int $userId = null,
        bool $deliver = true,
        ?int $keep = null,
    ): BackupAttempt {
        $attempt = BackupAttempt::create([
            'started_at' => now(),
            'trigger' => $trigger,
            'status' => BackupAttempt::STATUS_RUNNING,
            'created_by' => $userId,
        ]);

        // Read before the new row exists, so it means "the copy the office had until tonight".
        $previous = BackupAttempt::lastSent();

        try {
            $dump = $this->dumper->run();
        } catch (\Throwable $e) {
            return $this->fail($attempt, $e->getMessage());
        }

        $attempt->fill([
            'file_name' => $dump['name'],
            'size_bytes' => $dump['size'],
        ])->save();

        if (! $deliver) {
            return $this->fail($attempt, __('bk.reason_not_delivered'));
        }

        $result = $this->sender->deliver($dump['path'], __('bk.caption', [
            'night' => ltr_isolate(now()->format('Y-m-d')),
            'size' => ltr_isolate(BackupAttempt::humanSize($dump['size'])),
        ]));

        if (! $result['ok']) {
            return $this->fail($attempt, (string) $result['reason'], $result['parts']);
        }

        $attempt->update([
            'status' => BackupAttempt::STATUS_SENT,
            'finished_at' => now(),
            'parts' => max(1, $result['parts']),
            'error' => null,
        ]);

        $this->prune($keep);
        $this->reportOutage($previous);

        return $attempt->refresh();
    }

    /** Send a copy now, from the panel. */
    public function sendNow(int $userId): BackupAttempt
    {
        return $this->run(BackupAttempt::TRIGGER_MANUAL, $userId);
    }

    private function prune(?int $keep): void
    {
        $this->dumper->prune($keep ?? BackupSettings::retain());
    }

    private function fail(BackupAttempt $attempt, string $reason, int $parts = 0): BackupAttempt
    {
        $attempt->update([
            'status' => BackupAttempt::STATUS_FAILED,
            'finished_at' => now(),
            'parts' => max(1, $parts),
            'error' => mb_substr(trim($reason), 0, 500),
        ]);

        return $attempt->refresh();
    }

    /**
     * The line that closes a gap.
     *
     * When the channel was down, the failures were recorded but the office saw nothing in the chat —
     * which is exactly the silence this feature exists to prevent. The first successful delivery
     * after an outage therefore says how many attempts were lost and since when, once, so a chat
     * that went quiet for three nights explains itself the moment it comes back.
     */
    private function reportOutage(?BackupAttempt $previous): void
    {
        $failures = BackupAttempt::query()
            ->failed()
            ->when($previous !== null, fn ($query) => $query->where('started_at', '>', $previous->started_at))
            ->latestFirst()
            ->get();

        if ($failures->isEmpty()) {
            return;
        }

        $oldest = $failures->last();

        $this->sender->message(plural('bk.catch_up', $failures->count(), [
            'since' => ltr_isolate((string) $oldest?->started_at?->format('Y-m-d H:i')),
        ]));
    }
}
