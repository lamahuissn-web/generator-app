# Phase 0 — Research: A Daily Copy of the Database, Sent to Telegram

Every decision below is written as Decision / Rationale / Alternatives considered, and each one is
grounded in what was verified in this repository today rather than in general practice.

## R1. How the copy of the database is produced

**Decision**: build the SQL copy from inside the application, in PHP, using Eloquent and PDO — a
`DatabaseDumper` service that writes, for every table: `DROP TABLE IF EXISTS`, the table's own
`CREATE TABLE` statement, then its rows as `INSERT` statements in pages of 500, streamed to disk.

**Rationale**: the dump has to work on whatever machine the office runs, and the panel's deployment
assumption is `composer install` and nothing else. Generating the file in-process also makes it
testable: the suite runs on in-memory SQLite, where an external binary would be untestable, and the
constitution makes tests the definition of done. Reading the schema through the connection means the
copy carries the structure the panel is actually running, so a restore does not depend on someone
replaying migrations correctly.

**Alternatives considered**:
- **`mysqldump` through `exec()`.** `/usr/bin/mysqldump` exists on this machine, so it would work
  here. Rejected because it depends on a binary the office's host may not have, it puts the database
  password on a command line, cheap hosting often disables `exec`, and none of it can be exercised by
  the test suite.
- **`spatie/laravel-backup`.** Rejected: it is a new runtime dependency whose default behaviour
  (zips, disks, notifications, cleanup policies) is far larger than what the spec asks for, and it
  would still need a Telegram transport written by hand.
- **Dump data only, rebuild the schema with migrations.** Rejected: it makes the copy dependent on
  replaying migrations in the right order on the destination machine, which is exactly the kind of
  step that turns a restore into an archaeology exercise.

## R2. Driver support and the test database

**Decision**: one dumper, driver-aware for what it reads of the schema: MySQL through `SHOW CREATE
TABLE` / `SHOW TABLES`, SQLite through `sqlite_master`. Both write the same file format (plain SQL
with `DROP`/`CREATE`/`INSERT`). Any other driver refuses with a clear message instead of writing a
file that cannot restore.

**Rationale**: production and development are MySQL (verified: `DB_CONNECTION=mysql`, database
`generator`), and SQLite exists only so the feature can be tested in the suite. Two small branches
buy a testable dump, and the refusal keeps the promise "verified complete" honest on a driver nobody
has exercised.

**Alternatives considered**: using Laravel 12's portable introspection (`Schema::getTables()`,
`Schema::getColumns()`) to rebuild `CREATE TABLE` for every driver — rejected as more code and more
places to be wrong for a panel that runs on MySQL in production; `SHOW CREATE TABLE` returns the
table exactly as the server sees it.

## R3. Proving the copy is complete before it is sent

**Decision**: the dumper ends the file with a completion comment carrying the table count and a
checksum line, and the file is re-read after writing to confirm the marker is present and the file is
non-empty. An attempt is only ever recorded as sent when that check passed; a copy that fails it is
recorded as failed with the reason, and is never uploaded or offered as a good copy.

**Rationale**: FR-013 and SC-006. A half-written file sent to Telegram is worse than no file: the
office would believe it has a backup of a night it does not have. Checking the tail of a file is
cheap and catches the failure that actually happens (disk full, process killed).

**Alternatives considered**: hashing the file and storing the hash — kept for the log line, but the
marker is what makes a truncated file detectable without a second full read; comparing row counts
against the live database — rejected as unreliable while the panel is in use at three in the morning
(a reading can arrive mid-dump), and it would report a false failure.

## R4. How the copy leaves: the Telegram Bot API

**Decision**: two calls, both through Laravel's HTTP client against `https://api.telegram.org`, with
the bot token in the path:
- **Success path**: `sendDocument` with the file attached (multipart) and a caption naming the night
  and the size — one message that carries both the file and its label.
- **Failure path**: `sendMessage` with the reason, best effort. If Telegram is unreachable, the
  attempt row keeps the reason, and the next successful contact posts a catch-up line naming how many
  attempts failed since the last delivered one.

Every response is inspected: the Bot API answers HTTP 200 with `{"ok": false, "description": "..."}`
for most failures, so treating a 200 as success would report a backup that never arrived. The
`description` is what the operator reads — it is already a human sentence, and translating it would
only make it less exact.

**Rationale**: FR-003/FR-004/FR-008 and the constitution's "nothing fails silently". One call on the
happy path keeps the nightly run short on a weak connection.

**Alternatives considered**: `sendDocument` and then a separate `sendMessage` — two calls, two
chances to half-succeed; the caption carries the same information in one. Sending through an
SMTP/email fallback — out of scope, the office chose Telegram.

## R5. A copy too big for one message

**Decision**: when the file exceeds the delivery limit (default 45 MB, under Telegram's 50 MB
document limit, configurable), it is split at statement boundaries into ordered parts named
`…-part1of3.sql`, each sent with a caption saying which part it is and how many belong together. No
part is ever sent if the file could not be split at a boundary.

**Rationale**: FR-011, and version one keeps the database only, so this is a safety net rather than
the normal path. Splitting on statement boundaries means each part is still valid SQL for anyone who
inspects it, and reassembling is concatenation — no archive format to explain to whoever restores.

**Alternatives considered**: zipping before sending (adds a format dependency and hides the SQL from
a glance), dropping the largest tables (silently incomplete — the one thing the spec forbids).

## R6. Where the destination lives, and the secret

**Decision**: two settings keys — `telegram.bot_token` and `telegram.chat_id` — read through the
existing `Setting` model, with the token stored **encrypted** (`Crypt::encryptString`) and never
rendered back into the screen: the field shows that a token is saved and accepts a replacement. The
`settings.value` column is widened from 500 to 1000 characters in this feature's own migration,
because an encrypted payload is much longer than the token that produced it and silently truncating a
credential is exactly the failure this feature must not have.

**Rationale**: the panel already keeps every configuration in `settings` (pricing card, rounding,
lira rate), so the destination belongs there too; encryption at rest costs one line and keeps a
credential that can read every message the bot receives out of a database dump made by a previous
admin. The widening is a reversible migration (constitution: schema).

**Alternatives considered**: a dedicated `telegram_destinations` table — a table for two values the
panel already has a home for; `.env` only — the office cannot edit a file on a server, which is the
whole reason this screen exists.

## R7. Scheduling, and proving the scheduler is alive

**Decision**: one line in `routes/console.php` schedules `backup:send` daily at a configured hour
(default 03:00) with `withoutOverlapping()`; the command writes a heartbeat (a settings key with the
run time) *before* it starts working, and the screen warns when that heartbeat is older than 26 hours
— which is the shape a missing or broken cron takes.

**Rationale**: `crontab -l` for this user is **empty** today, so the single most likely way this
feature fails in the field is that nobody ever installed the scheduler line. Every other failure this
feature can have announces itself in the chat; a missing cron announces itself by silence, and the
constitution forbids silent failure. The heartbeat is one settings row and turns silence into a
sentence on the screen.

**Alternatives considered**: relying on the last attempt's absence (a panel that was simply never
used looks identical to a broken scheduler) — rejected; queue workers instead of cron (the office has
no worker running, and `QUEUE_CONNECTION=database` with no worker would queue copies forever).

## R8. The screen, and where it sits

**Decision**: a Livewire page component (`App\Livewire\Admin\Pages\Backup`) reached from the sidebar
under **النظام / System**, beside Users, Settings and the audit log — following the panel's existing
pattern for pages (an `AdminNav` entry carrying the label, icon, route and component). The attempts
list is rendered through the panel's own `x-admin.table` driven by a `TableDefinition`
(`BackupAttemptsTable`), so sorting, empty states and mobile behaviour are the panel's, not a
one-off. Destination fields are plain Livewire state on that page (they are two settings values, not
a model), validated in place, with a test button.

**Rationale**: FR-006/FR-009/FR-012 and Principle II — a second way to render a table would be a
second description of a table. Admin-only comes free from the `role:admin` middleware group the other
system screens sit in.

**Alternatives considered**: a section on the Settings page — the settings screen is already long and
this one has a list, a test and two actions; its own screen under System is where an operator looks
for it first.

## R9. What the tests must prove

**Decision**: one feature test file covering, at minimum: a dump round-trip (dump a populated
database, import it into an empty one, compare tables, counts and sampled rows including a decimal
and a text with quotes and Arabic); a successful nightly send (`Http::fake`) that records one attempt
and posts a caption naming the night and size; a Telegram `ok:false` response that records a failure
with Telegram's description and does **not** mark the attempt as delivered; an unreachable Telegram
that records the failure and reports it on the next successful send; a split of an oversized file
into ordered parts; the manual send; the admin-only access; the staleness warning; and both
catalogues carrying every new string.

**Rationale**: Principle III — the failures above are the ones this feature can actually have, and
each is cheaper to pin here than to discover from an office that lost a month of books.

**Alternatives considered**: testing the Telegram client against the live API — never in the suite
(no credentials, no network in tests); a contract test of the Bot API payloads instead — kept as the
written contract in `contracts/telegram-bot-api.md` plus `Http::fake()` assertions on the exact URL
and multipart fields.
