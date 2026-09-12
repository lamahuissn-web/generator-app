# Contract: the `backup:send` command

The one entry point that makes a copy and delivers it. The scheduler calls it; an admin's "send now"
in the panel calls the same service directly (not the command), so both paths share one
implementation and one record.

## Invocation

```bash
php artisan backup:send                # the nightly run: dump, verify, deliver, record
php artisan backup:send --test          # one message to the configured chat, no copy made
php artisan backup:send --no-deliver    # make the copy and verify it, upload nothing
php artisan backup:send --keep=30       # prune to this many copies (default from settings)
```

| Option | Meaning |
|---|---|
| `--test` | Sends a short "the destination works" message and exits; used by the screen's test button and by an operator after changing the bot |
| `--no-deliver` | The whole run minus the upload: useful when the office's connection is down and they want the file on disk; the attempt is recorded as `failed` with "not delivered" as the reason, never as `sent` |
| `--keep` | Overrides the retention count for this run |

## Inputs read

- `telegram.bot_token` (encrypted), `telegram.chat_id`, `backup.delivery_limit_bytes`,
  `backup.retain` — all from `settings` through `Setting::get`.
- The panel's own database connection, from `config/database.php` — no credentials are passed on a
  command line and none are logged.

## Output and exit codes

| Exit | When | What it printed |
|---|---|---|
| `0` | copy verified and delivered (`sent`) | one line per stage: `dump: 24 tables, 2.4 MB`, `delivered: chat 123456789, 1 message` |
| `1` | the copy could not be made or verified | `failed: <reason>` — the same sentence stored in the attempt |
| `2` | the copy was made but not delivered | `failed to deliver: <Telegram's description>` |
| `3` | no destination configured | `no destination: set the bot and chat in the panel` — the run does not create a copy it cannot send, and says so |

Every exit code above also writes exactly one `backup_attempts` row, except the `--test` path, which
writes none (it is not a copy).

## What it must never do

- **Never** record `sent` for a copy that Telegram did not accept with `ok: true`.
- **Never** delete, overwrite or hide an earlier attempt row — history is append-only (FR-008).
- **Never** leave a partial file where a later run could pick it up as a copy: the dump is written to
  a temporary name and renamed only after the completion marker is verified (R3).
- **Never** run twice at once: the scheduler entry uses `withoutOverlapping()`, and the command
  refuses to start when another `running` attempt is younger than its own timeout.

## The scheduler line

Added to `routes/console.php` (Laravel 12's scheduling file) and installed on the machine as one cron
entry, which is part of deployment rather than of this code:

```php
Schedule::command('backup:send')
    ->dailyAt(config('backup.hour', '03:00'))
    ->withoutOverlapping();
```

```cron
* * * * * cd /path/to/panel && php artisan schedule:run >> /dev/null 2>&1
```

The first thing the command does on any run is write `backup.scheduler_seen_at`, and the panel warns
when that key is older than 26 hours — the explicit answer to the fact that this repository's user
has **no crontab installed today**, which is the most likely way this feature fails in the field.
