# Phase 1 — Data Model: A Daily Copy of the Database, Sent to Telegram

## Entity: Backup attempt

One row per run of "make a copy and try to deliver it" — whether it succeeded, whether it failed, and
why. This is the record the screen reads and the reason a failure can never be invisible.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned, PK | |
| `started_at` | datetime | when the run began; what the screen sorts by |
| `finished_at` | datetime, nullable | null while the run is in flight |
| `trigger` | enum(`scheduled`, `manual`) | what started it; FR-005 |
| `status` | enum(`running`, `sent`, `failed`) | `sent` means *delivered to Telegram*, never merely written to disk |
| `file_name` | string(120), nullable | e.g. `generator-2026-09-12-0300.sql`; null when the dump never completed |
| `size_bytes` | unsigned bigint, nullable | the size of the copy, exactly as written |
| `parts` | unsigned tinyint, default 1 | how many messages the copy was delivered in (FR-011) |
| `error` | string(500), nullable | the reason, in the operator's words — Telegram's own `description` when Telegram refused |
| `created_by` | foreignId → users, nullable, nullOnDelete | the admin, for a manual send; null for the nightly job |

**Indexes**: `(started_at)` for the list; `(status, finished_at)` for the staleness question.

**Validation rules implied by the requirements**

- `status = sent` requires `file_name` and `size_bytes` — a delivered copy has both (FR-013).
- `status = failed` requires `error` (FR-005, FR-008).
- `trigger = manual` requires `created_by`.

**State transitions**

```text
                 ┌──────────────► sent        (verified copy, delivered, Telegram accepted)
running ─────────┤
                 └──────────────► failed      (dump incomplete, or delivery refused/unreachable)
                 (a new attempt never mutates an older one — FR-008)
```

An attempt is inserted as `running` before the dump starts, so a process killed mid-run leaves
evidence rather than a gap; a `running` row older than the run's own timeout is rendered as
"interrupted" by the screen rather than as a success.

## Entity: Destination (settings, not a table)

Two keys in the existing `settings` table, written and read through `Setting::get/set`:

| Key | Contents | Handling |
|---|---|---|
| `telegram.bot_token` | the bot credential | encrypted with `Crypt::encryptString`; never rendered back; replacing it is allowed |
| `telegram.chat_id` | the chat the copy is posted to | plain; shown back (it is not a secret) |

Plus two operational keys no form writes directly:

| Key | Contents | Written by |
|---|---|---|
| `backup.scheduler_seen_at` | the last time the scheduler invoked the command | the command, before doing any work — this is what turns a missing cron into a sentence on the screen |
| `backup.delivery_limit_bytes` | the per-message delivery limit (default 45 MB, under Telegram's 50 MB) | the destination form |

**Schema change required**: `settings.value` is `string(500)` today; an encrypted token payload is
several times the length of the token, so this feature widens the column to `string(1000)` in a
reversible migration. Truncating a credential silently is the failure mode being avoided.

## The copy itself (not an entity — a file)

- **Name**: `generator-<YYYY-MM-DD>-<HHMM>.sql` — the database name, the night, the minute the run
  started; unique per run, sortable by name.
- **Location**: `storage/app/backups/`, the most recent 30 kept, older ones pruned by the same run
  that made the new one (FR-010). Pruning never removes the newest file, whatever else happens.
- **Contents**: for each table — `DROP TABLE IF EXISTS`, the driver's own `CREATE TABLE`, then
  `INSERT` statements written 500 rows at a time, values at their stored precision. The file ends
  with a completion marker naming the table count and a checksum line (R3), which is what makes a
  truncated copy detectable before it is ever sent.
- **Parts**: when the file exceeds the delivery limit it is split at statement boundaries into
  `…-part1of3.sql`, `…-part2of3.sql`, … — each still valid SQL, reassembled by concatenation.

## What the screen computes from this

Nothing that is not stored: the list is the attempts, newest first; "last delivered copy" is the
newest row with `status = sent`; staleness compares that timestamp (and `backup.scheduler_seen_at`)
against thresholds, in one method shared by the screen and the chat message, so the panel and the
message can never disagree about whether the office is covered.
