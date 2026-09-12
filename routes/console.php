<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | The nightly copy of the ledger.
 |
 | The hour comes from config/backup.php (BACKUP_HOUR) rather than from the settings table, because
 | this file is evaluated on every console boot and must not need the database to know when to fire.
 | The one cron entry the machine needs is `php artisan schedule:run` every minute; if nobody installs
 | it, the panel says so rather than going quiet — the command writes a heartbeat, and the backup
 | screen reports it going stale (see App\Services\Backup\BackupStatus).
 |
 | withoutOverlapping() is the second guard: a copy that somehow runs long must not be started again
 | on top of itself by the next minute.
 */
Schedule::command('backup:send')
    ->dailyAt(config('backup.hour'))
    ->withoutOverlapping();
