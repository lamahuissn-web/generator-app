<?php

namespace App\Console\Commands;

use App\Models\BackupAttempt;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\BackupSettings;
use App\Services\Backup\TelegramBackupSender;
use Illuminate\Console\Command;

/**
 * The nightly copy, and the way to make one by hand.
 *
 * Exit codes are part of the contract (see specs/001-telegram-backup/contracts/artisan-backup-command.md)
 * because a cron entry, a monitoring script and a human all read them differently: 0 delivered,
 * 1 the copy could not be made, 2 the copy was made but never left, 3 there is nowhere to send it.
 *
 * The FIRST thing a real run does is record that the scheduler was seen, before any work that could
 * fail. That heartbeat is the only way the panel can tell "nothing to back up yet" from "the cron
 * entry was never installed" — the failure that otherwise announces itself by silence.
 */
class SendDatabaseBackup extends Command
{
    protected $signature = 'backup:send
                            {--test : Send one message to the configured chat and stop}
                            {--no-deliver : Make and verify the copy, upload nothing}
                            {--keep= : How many copies stay on the machine (default from settings)}';

    protected $description = 'Copy the database and send it to the office Telegram chat';

    public function handle(): int
    {
        $sender = TelegramBackupSender::make();

        if ($this->option('test')) {
            $result = $sender->test();

            $this->line($result['ok']
                ? 'destination ok: '.BackupSettings::chatId()
                : 'destination failed: '.$result['reason']);

            return $result['ok'] ? self::SUCCESS : 2;
        }

        BackupSettings::markSchedulerSeen();

        // A SCHEDULED run still refuses without a destination: a nightly copy that cannot leave the
        // machine is a half-feature, and it would read as protection the office does not have. But
        // --no-deliver is the OPPOSITE request — an operator asking for a local copy right now, to
        // snapshot before a migration or because the channel is down — and refusing that for want of
        // a destination is a category error. It is also the only way to take a copy at all while the
        // bot is unconfigured, which is exactly when a snapshot is worth having.
        if (! $this->option('no-deliver') && ! $sender->configured()) {
            $this->line('no destination: set the bot and chat in the panel');

            return 3;
        }

        if (BackupRunner::running()) {
            $this->line('skipped: another backup run is in progress');

            return 1;
        }

        $attempt = BackupRunner::make()->run(
            BackupAttempt::TRIGGER_SCHEDULED,
            null,
            ! $this->option('no-deliver'),
            $this->option('keep') === null ? null : (int) $this->option('keep'),
        );

        if ($attempt->delivered()) {
            $this->line(sprintf(
                'dump: %s, %s',
                $attempt->file_name,
                $attempt->sizeForHumans(),
            ));
            $this->line(sprintf(
                'delivered: chat %s, %d message%s',
                BackupSettings::chatId(),
                $attempt->parts,
                $attempt->parts === 1 ? '' : 's',
            ));

            return self::SUCCESS;
        }

        // Made but not delivered, or never made at all — the difference matters to whoever reads the
        // log: one is a channel problem, the other is the panel's own.
        if ($attempt->file_name !== null) {
            $this->line('failed to deliver: '.$attempt->error);

            return 2;
        }

        $this->line('failed: '.$attempt->error);

        return 1;
    }
}
