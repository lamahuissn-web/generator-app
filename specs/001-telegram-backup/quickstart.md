# Quickstart: prove the daily Telegram copy works

A validation guide, not an implementation guide — every step below is something a person can run and
check. Implementation details live in `plan.md`, `research.md` and (once generated) `tasks.md`.

## Prerequisites

- The panel installed and running (`php artisan serve` or the office's own host).
- A Telegram bot created with @BotFather, and the chat it should post to (the bot must be a member
  of that chat, or be messaged by the owner first).
- The scheduler line installed on the machine — this is the step that is easiest to skip and the
  feature's own screen will tell you if it was:

```cron
* * * * * cd /path/to/panel && php artisan schedule:run >> /dev/null 2>&1
```

## 1. Configure the destination

1. Sign in as an admin → sidebar → **النظام / System → النسخ الاحتياطي / Backups**.
2. Enter the bot credential and the chat id, save.
3. Press **اختبار / Test**. Expected: a message arrives in the chat within a few seconds, and the
   screen says the destination works. With a wrong token, expected: the screen states what Telegram
   said (e.g. `Unauthorized`), and the credential field still says a token is saved without showing
   it.

## 2. Make and deliver a copy by hand

```bash
php artisan backup:send
```

Expected: exit code `0`, the console prints the table count and size, a file appears in
`storage/app/backups/`, the chat receives the file with a caption naming the night and the size, and
the panel's screen lists one attempt as **sent** with the same size.

```bash
php artisan backup:send --test        # one message, no copy
php artisan backup:send --no-deliver  # a verified copy on disk, nothing uploaded
```

Expected for `--no-deliver`: exit code `2` or the documented "made but not delivered" outcome, a file
on disk, and an attempt row that is **not** `sent`.

## 3. Prove the copy is complete

Restore it somewhere that is not the office's machine, using only the file from the chat:

```bash
mysql -u root -p -e "DROP DATABASE IF EXISTS generator_check; CREATE DATABASE generator_check;"
mysql -u root -p generator_check < generator-2026-09-12-0300.sql
```

Expected: the import finishes without an error, and a handful of figures match the panel exactly —
subscriber count, the newest invoice's total, the sum of payments, the number of readings for the
current cycle:

```sql
SELECT COUNT(*) FROM subscribers;
SELECT SUM(total) FROM bills WHERE period = '2026-09';
SELECT SUM(amount) FROM payments;
```

The check is not "the file opens": it is that a number the office recognises comes back identical,
including a decimal (`$36.50` stays `36.50`, never `36.5` and never rounded).

## 4. Prove a failure is visible

1. Replace the bot credential with a wrong one (or revoke the bot in @BotFather).
2. Run `php artisan backup:send`.
3. Expected: exit code `2`, the failure recorded with Telegram's own words, the panel's screen
   showing the failed attempt with that reason, the **older successful copy still listed as the last
   delivered one**, and next run after restoring the credential posting a catch-up line naming how
   many attempts failed.

## 5. Prove the scheduler is watched

1. Remove the cron line (or ask the panel to be idle for over 26 hours).
2. Open the screen.
3. Expected: a warning in plain Arabic that no scheduled run has been seen for N hours, with what to
   do — the feature's answer to a machine whose scheduler was never installed.

## 6. Prove the door

1. Sign in as a collector and open the sidebar: no Backup entry, and the URL is refused.
2. Sign out and request the screen's URL directly: redirected to the login page.
3. Open the screen in Arabic and in English: every string present, no raw keys, sizes and dates in
   Latin digits.

## 7. Prove nothing else broke

```bash
php artisan test
```

Expected: the whole suite green, including the new dump round-trip, delivery, failure, splitting,
access and wording tests. The suite never contacts Telegram: `Http::fake()` stands in for the Bot API.
