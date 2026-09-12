<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nightly database copy
    |--------------------------------------------------------------------------
    |
    | Values a DEPLOYMENT decides, kept out of the settings table so the scheduler line
    | (routes/console.php, evaluated on every console boot) never has to read the database to know
    | when to fire. Everything the office can change from the panel — the bot, the chat, the
    | retention count, the delivery limit — lives in `settings` instead, through BackupSettings.
    |
    */

    // When the scheduled run fires. The one cron entry runs every minute; this is the hour it
    // actually does the work, chosen to be a time the office is asleep and the machine is idle.
    'hour' => env('BACKUP_HOUR', '03:00'),

    // Where copies are written before they leave. The durable copy is the one in the chat; these
    // files exist so a restore is possible without downloading, and so `--no-deliver` means something.
    'path' => storage_path('app/backups'),

    'defaults' => [
        // How many copies stay on the machine before the oldest is pruned.
        'retain' => 30,

        // One message's worth. Telegram accepts documents up to 50 MB; stopping short of the limit
        // leaves room for the multipart envelope, and a copy past this is split into ordered parts
        // rather than silently trimmed.
        'delivery_limit_bytes' => 45 * 1024 * 1024,
    ],

    // Hours without a scheduled run before the panel says the scheduler looks dead. Chosen at just
    // over a day: one missed night is a late cron, two in a row means nobody installed it.
    'stale_after_hours' => 26,

];
