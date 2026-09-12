<?php

namespace App\Services\Backup;

use App\Models\BackupAttempt;

/**
 * Is the office actually covered? — the one place that answers it.
 *
 * The screen shows the answer as an alert and the chat message repeats it when a run fails, so if
 * the two computed it separately the office could be told "everything is fine" in one and "nothing
 * has been sent for four days" in the other. Thresholds live here, once, and every sentence returned
 * is ready to render in either language.
 *
 * The scheduler heartbeat is deliberately part of coverage: a machine whose cron entry was never
 * installed makes NO attempts, and a panel that only looked at attempts would say "no copies yet"
 * forever without naming the reason. That is the failure this feature is most likely to have, and
 * the only one that cannot announce itself.
 */
final class BackupStatus
{
    /** A copy older than this many hours is late; older than twice it, the office is uncovered. */
    public static function freshnessHours(): int
    {
        return BackupSettings::staleAfterHours();
    }

    public static function lastDelivered(): ?BackupAttempt
    {
        return BackupSettings::lastDelivered();
    }

    public static function hoursSinceLastDelivered(): ?float
    {
        $last = self::lastDelivered();

        return $last === null ? null : round($last->started_at->diffInMinutes(now()) / 60, 1);
    }

    public static function schedulerIsLate(): bool
    {
        $hours = BackupSettings::hoursSinceScheduledRun();

        return $hours === null || $hours > self::freshnessHours();
    }

    /** Cover exists only when a destination exists, a copy arrived recently, and the scheduler runs. */
    public static function covered(): bool
    {
        return self::warnings() === [];
    }

    /**
     * Everything wrong with the office's cover, worst first.
     *
     * @return array<int, array{tone: string, text: string}>
     */
    public static function warnings(): array
    {
        $warnings = [];

        if (! BackupSettings::isConfigured()) {
            $warnings[] = ['tone' => 'danger', 'text' => __('bk.warn_no_destination')];
        }

        $hours = self::hoursSinceLastDelivered();
        $last = self::lastDelivered();

        if ($last === null) {
            $warnings[] = ['tone' => 'danger', 'text' => __('bk.warn_never_sent')];
        } elseif ($hours >= self::freshnessHours()) {
            $warnings[] = [
                'tone' => $hours >= self::freshnessHours() * 2 ? 'danger' : 'warning',
                'text' => plural('bk.warn_last_sent_hours', (int) round($hours), [
                    'night' => ltr_isolate($last->started_at->format('Y-m-d')),
                ]),
            ];
        }

        $sinceScheduler = BackupSettings::hoursSinceScheduledRun();

        if ($sinceScheduler === null) {
            $warnings[] = ['tone' => 'danger', 'text' => __('bk.warn_scheduler_never')];
        } elseif ($sinceScheduler > self::freshnessHours()) {
            $warnings[] = [
                'tone' => 'danger',
                'text' => plural('bk.warn_scheduler_hours', (int) round($sinceScheduler)),
            ];
        }

        return $warnings;
    }
}
