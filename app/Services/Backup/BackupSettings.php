<?php

namespace App\Services\Backup;

use App\Models\BackupAttempt;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Every value the backup feature can be configured with, in one place.
 *
 * The screen, the command and the chat message all read the destination through this class, so the
 * panel cannot post to one chat while the screen says another, and the credential is decrypted in
 * exactly one method. The keys are private to this class for the same reason: a settings row written
 * by hand under a slightly different name would otherwise be a destination nothing ever reads.
 */
final class BackupSettings
{
    private const TOKEN_KEY = 'telegram.bot_token';

    private const CHAT_KEY = 'telegram.chat_id';

    private const RETAIN_KEY = 'backup.retain';

    private const LIMIT_KEY = 'backup.delivery_limit_bytes';

    private const SEEN_KEY = 'backup.scheduler_seen_at';

    /** The bot credential, decrypted. Null when nothing has been saved. */
    public static function botToken(): ?string
    {
        $stored = Setting::get(self::TOKEN_KEY);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            // A token that cannot be decrypted is a token that was stored before the column was
            // widened, or by a key this installation no longer has. Either way it is unusable, and
            // saying "nothing is configured" is the honest answer — the screen asks for it again.
            return null;
        }
    }

    public static function hasBotToken(): bool
    {
        return (bool) Setting::get(self::TOKEN_KEY);
    }

    /** Saved encrypted; the plaintext never reaches a settings row. */
    public static function saveBotToken(string $token): void
    {
        Setting::set(self::TOKEN_KEY, Crypt::encryptString(trim($token)), 'Telegram bot token (encrypted)');
    }

    public static function chatId(): ?string
    {
        $stored = Setting::get(self::CHAT_KEY);

        return is_string($stored) && trim($stored) !== '' ? trim($stored) : null;
    }

    public static function saveChatId(string $chatId): void
    {
        Setting::set(self::CHAT_KEY, trim($chatId), 'Telegram chat the nightly copy is posted to');
    }

    /** Enough of a destination to send anything. */
    public static function isConfigured(): bool
    {
        return self::botToken() !== null && self::chatId() !== null;
    }

    public static function retain(): int
    {
        $stored = (int) Setting::get(self::RETAIN_KEY, 0);

        return $stored > 0 ? $stored : (int) config('backup.defaults.retain');
    }

    public static function saveRetain(int $retain): void
    {
        Setting::set(self::RETAIN_KEY, (string) $retain, 'How many database copies stay on the machine');
    }

    public static function deliveryLimit(): int
    {
        $stored = (int) Setting::get(self::LIMIT_KEY, 0);

        return $stored > 0 ? $stored : (int) config('backup.defaults.delivery_limit_bytes');
    }

    public static function saveDeliveryLimit(int $bytes): void
    {
        Setting::set(self::LIMIT_KEY, (string) $bytes, 'Largest copy sent as one Telegram message');
    }

    /**
     * The scheduler's heartbeat.
     *
     * Written by the command before it does any work, because the most likely way this feature dies
     * is a cron entry nobody installed: every other failure announces itself in the chat, and that
     * one announces itself by silence.
     */
    public static function markSchedulerSeen(?Carbon $at = null): void
    {
        Setting::set(self::SEEN_KEY, ($at ?? now())->toDateTimeString(), 'Last time the scheduler ran the backup command');
    }

    public static function schedulerSeenAt(): ?Carbon
    {
        $stored = Setting::get(self::SEEN_KEY);

        return is_string($stored) && $stored !== '' ? Carbon::parse($stored) : null;
    }

    /** Hours since the scheduler last invoked the command, or null when it never has. */
    public static function hoursSinceScheduledRun(): ?float
    {
        $seen = self::schedulerSeenAt();

        return $seen === null ? null : round($seen->diffInMinutes(now()) / 60, 1);
    }

    /** Where copies are written before they leave the machine. */
    public static function path(): string
    {
        return (string) config('backup.path');
    }

    /** Hours of silence after which the scheduler is presumed dead. */
    public static function staleAfterHours(): int
    {
        return (int) config('backup.stale_after_hours');
    }

    /** The copy the office actually has, if any. */
    public static function lastDelivered(): ?BackupAttempt
    {
        return BackupAttempt::lastSent();
    }
}
