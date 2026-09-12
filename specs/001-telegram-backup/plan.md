# Implementation Plan: A Daily Copy of the Database, Sent to Telegram

**Branch**: `001-telegram-backup` | **Date**: 2026-09-12 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-telegram-backup/spec.md`

## Summary

Every night the panel builds a complete SQL copy of its own database from inside the application,
verifies that copy is whole, sends it to the office's Telegram chat through the office's bot, and
records the attempt. A short message goes to the same chat saying which night the copy is from and
how big it is; a failure is recorded with its reason and reported to the chat the moment the channel
works again. One admin screen states the truth — the last attempts, whether each arrived and why not
— offers a test of the destination and a send-now, and warns when either the last success or the
scheduler itself has gone stale.

## Technical Context

**Language/Version**: PHP 8.2+ (the host runs 8.3), Laravel 12, Livewire 4

**Primary Dependencies**: none added. The dump uses Eloquent + PDO; delivery uses Laravel's HTTP
client (`Http::attach()` for multipart). No `exec`, no shell, no backup package.

**Storage**: MySQL — the panel's own database (`generator` in development). Copy files are written
to `storage/app/backups/`, the most recent 30 kept, and the durable copy lives in the Telegram chat.
Two settings keys hold the destination; the credential is encrypted at rest.

**Testing**: PHPUnit 11 through `php artisan test`, against in-memory SQLite (`tests/TestCase.php`
refuses any other connection). Telegram is never contacted in tests: `Http::fake()`.

**Target Platform**: the office's own machine (Linux server or a Windows box with PHP), plus
Telegram. Requires one scheduler entry, configured at installation and verifiable from the panel.

**Project Type**: single Laravel web application (Livewire panel, no separate frontend build).

**Performance Goals**: the nightly run must not disturb the office. The dump is paged (500 rows per
read) and streamed to disk, so memory stays flat as the ledger grows; for the office's size (hundreds
of subscribers, tens of thousands of rows) the whole run is seconds of CPU and one upload. HTTP
timeouts are generous (120 s) because the office's connection is weak, and a failed upload must not
leave a half-reported state.

**Constraints**: no new runtime dependency (constitution: Additional Constraints); no shelling out;
every new string in both catalogues; the screen is admin-only; a failure must be visible in the panel
and in the chat, never silent; the dump writes every stored value at its stored precision (no
reformatting of money or readings).

**Scale/Scope**: one artisan command, one screen, four small services, one new table, two settings
keys, one scheduler line. One office, one machine, one bot.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | How this plan satisfies it |
|---|---|
| **I. Bilingual parity, numbers never change** | Every new string (screen, message, refusal) lands in `lang/ar.json` **and** `lang/en.json` in the same change; the chat message is composed through `__()`/`plural()` so it agrees with the panel's language; sizes and dates are rendered as Latin digits and isolated (`ltr_isolate`) inside Arabic prose. |
| **II. One description of every rule** | One service owns "what a copy is" (`DatabaseDumper`), one owns "how it leaves" (`Telegram`), one ties them together and records the outcome (`BackupRunner`). The screen reads attempt rows; it never re-derives a size, a status or a staleness verdict — the staleness rule lives in a single method used by both the screen and the message. |
| **III. Tests are the definition of done** | The dump is proven by round-trip: dump a populated database, import it into a second empty one, compare tables, row counts and sampled rows. Delivery is proven with `Http::fake()` for success, Telegram-reported failure, and transport failure. Each test is written to fail before the change (verified by reverting). |
| **IV. Nothing fails silently** | FR-005/FR-006/FR-008: every attempt is a row with its reason; a failed attempt is never rendered as a success and never replaces a good copy; Telegram's own error description is what the operator reads, not a generic message. The case this feature is most likely to die of — a scheduler that was never installed — is surfaced by a heartbeat the panel checks and reports. |
| **V. Exact money, and the paper adds up** | No arithmetic is added, and none is allowed in the dump: values are written exactly as stored (`decimal` verbatim, no `money()` formatting, no rounding), so a restored ledger is the same ledger. |
| **Additional Constraints (Metronic/migrations/previews)** | The screen uses the panel's own components (`x-admin.card`, `x-admin.table`, `x-admin.stat`) and utilities verified against the built stylesheet; the two migrations are reversible; no preview artifact is committed. |
| **Development Workflow** | Delivered as one phase with the full suite green, a short verified report, and the design artifacts kept under `specs/001-telegram-backup/`. |

**Gate result: PASS.** No principle is bent and no complexity needs justifying, so the Complexity
Tracking table stays empty. Re-checked after Phase 1 design below — unchanged.

## Project Structure

### Documentation (this feature)

```text
specs/001-telegram-backup/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   ├── telegram-bot-api.md
│   └── artisan-backup-command.md
├── checklists/
│   └── requirements.md  # Spec quality gate
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Console/Commands/
│   └── SendDatabaseBackup.php          # artisan backup:send — the nightly entry point
├── Livewire/Admin/Pages/
│   └── Backup.php                      # the screen (destination, test, send now, attempts)
├── Models/
│   └── BackupAttempt.php               # one row per attempt
├── Admin/Tables/
│   └── BackupAttemptsTable.php         # the attempts list, through the panel's table contract
└── Services/Backup/
    ├── DatabaseDumper.php              # builds the SQL copy and proves it complete
    ├── BackupRunner.php                # dump → verify → deliver → record, in one place
    ├── BackupSettings.php              # every configurable value, and the credential's one decrypt
    ├── BackupStatus.php                # "is the office covered?" — the verdict and its wording
    └── TelegramBackupSender.php        # sendDocument / sendMessage, parts, error mapping
        (Telegram HTTP concerns only; nothing else in the app talks to Telegram)

resources/views/livewire/admin/pages/
└── backup.blade.php                    # the screen's markup

database/migrations/
├── 2026_09_12_000300_create_backup_attempts_table.php
└── 2026_09_12_000400_widen_settings_value.php

routes/console.php                      # the scheduler line (daily, without overlapping)
app/Admin/AdminNav.php                  # a Backup entry under nav.system
lang/ar.json, lang/en.json              # both catalogues, same change

tests/Feature/
└── TelegramBackupTest.php              # dump round-trip, delivery, failures, staleness, access
```

**Structure Decision**: the existing single-app layout is kept and the feature is added where the
panel already puts things of this kind: an artisan command beside `ResetData`, a page component
beside the other reporting pages, a table definition beside the other tables, and — the one new
directory — `app/Services/Backup/`, so the parts of a backup (make it, move it, record it) are one
folder rather than files scattered in `app/Services`.

**Convergence note (2026-09-12)**: two classes were added inside that folder beyond the three named
above, and they earn their place rather than splitting the design further. `BackupSettings` owns every
configurable value and the credential's single decrypt, because the screen, the command and the chat
message must read ONE destination — a settings row written by hand under a slightly different name
would otherwise be a destination nothing ever reads. `BackupStatus` owns the coverage verdict and its
wording, because the alert on the screen and the line in the chat are the same question; leaving that
rule inside `BackupRunner` (which is about running) would have put a reader-facing judgement in the
middle of an operational one, and the tests that pin "a missing cron is named, not implied" belong to
the verdict, not to the run. Telegram lives in that folder as
well, because nothing else in the panel should grow a second way to talk to it.

## Complexity Tracking

*No Constitution Check violations — nothing to justify here.*
