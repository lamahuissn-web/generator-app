# Tasks: A Daily Copy of the Database, Sent to Telegram

**Feature**: `001-telegram-backup` | **Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

**Tests are NOT optional in this repository.** The project constitution makes tests the definition
of done (Principle III), so every story below carries its test tasks and a phase is not finished
until `php artisan test` is green.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel — a different file, no dependency on an unfinished task
- **[Story]**: which user story the task serves (US1, US2, US3)
- Every task names the exact file it touches

## Path Conventions

Single Laravel application at the repository root: `app/`, `resources/views/`, `database/migrations/`,
`lang/`, `tests/Feature/`. Feature artifacts live in `specs/001-telegram-backup/`.

---

## Phase 1: Setup (Shared Infrastructure)

- [X] T001 Create the `backup_attempts` migration in `database/migrations/2026_09_12_000300_create_backup_attempts_table.php` — columns exactly as `data-model.md` states them: `started_at` datetime, `finished_at` datetime nullable, `trigger` enum(scheduled,manual), `status` enum(running,sent,failed), `file_name` string(120) nullable, `size_bytes` unsignedBigInteger nullable, `parts` unsignedTinyInteger default 1, `error` string(500) nullable, `created_by` foreignId→users nullable nullOnDelete, indexes on `(started_at)` and `(status, finished_at)`; `down()` drops the table
- [X] T002 [P] Create the settings migration in `database/migrations/2026_09_12_000400_widen_settings_value.php` — widen `settings.value` from `string(500)` to `string(1000)`; `down()` restores 500 (an encrypted token payload is longer than the token, and silent truncation of a credential is the failure being avoided)
- [X] T003 [P] Create `app/Models/BackupAttempt.php` — `$fillable` for the columns above, casts (`started_at`/`finished_at` datetime), relations (`creator()` → User), and the query scopes the screen and the runner both read: `latestFirst()`, `sent()`, `failed()`, `lastSent()` returning the newest `sent` row; the model owns no wording
- [X] T004 [P] Add the `bk.` string block to BOTH `lang/ar.json` and `lang/en.json` (labels for trigger, status, parts, the warning lines, the button labels) with Arabic plural forms through the existing `plural()` helper where a count is involved

## Phase 2: Foundational (Blocking Prerequisites)

- [X] T005 Implement `app/Services/Backup/DatabaseDumper.php` — driver-aware, no shell: MySQL reads `SHOW TABLES` + `SHOW CREATE TABLE`; SQLite reads `sqlite_master`; any other driver throws a refusal naming the driver. Writes `DROP TABLE IF EXISTS`, the table's own `CREATE TABLE`, then `INSERT`s 500 rows at a time streamed to disk; values written at their stored precision with no formatting; the run writes to a temporary name and renames to `generator-<YYYY-MM-DD>-<HHMM>.sql` only after appending and verifying the completion marker (table count + checksum line, per `research.md` R3). Returns the path, size and table count
- [X] T006 [P] Write the dump round-trip test in `tests/Feature/TelegramBackupTest.php` — populate a database, dump it, import the file into a second empty connection, then compare table lists, per-table row counts and sampled rows including a `decimal(12,2)` (must come back `36.50`, never `36.5`) and a string carrying an apostrophe and Arabic text; also pin that a dump truncated mid-write fails the completion check and is never reported as usable
- [X] T007 Implement `app/Services/Backup/TelegramBackupSender.php` — the only caller of the Bot API in the application, per `contracts/telegram-bot-api.md`: `sendDocument` (multipart through `Http::attach()`, 120 s timeout, caption), `sendMessage` (failure report, catch-up line, test message), and the split rule — a file over `backup.delivery_limit_bytes` is cut at statement boundaries into `<name>-part1ofN.sql` and sent in order; **a delivery counts as delivered only when the response is HTTP 200 with `ok: true`**; on `ok: false` the `description` is the reason, on a transport failure the composed exception message is
- [X] T008 [P] Write the sender test in `tests/Feature/TelegramBackupTest.php` — `Http::fake()` asserting the exact URL (`/bot{token}/sendDocument`), the multipart fields (`chat_id`, `document`, `caption`) and the caption's content; `ok: false` with a description; a transport exception; and an oversized file split into ordered parts whose captions say which part of how many — never a fragment cut mid-statement
- [X] T009 Implement `app/Services/Backup/BackupRunner.php` — the one place that defines a backup run: insert the attempt as `running`, dump, verify, deliver, then mark `sent` (with `file_name`, `size_bytes`, `parts`) or `failed` (with `error`); prune `storage/app/backups/` to the configured retention (default 30) keeping the newest file whatever happens; if attempts failed since the last delivered copy, post the catch-up line after a successful send. A new run NEVER mutates or hides an older attempt row
- [X] T010 [P] Write the runner test in `tests/Feature/TelegramBackupTest.php` — `sent` only on a real delivery; a failure records the reason and leaves earlier rows untouched; retention prunes to the configured count and never deletes the newest file; the catch-up line is posted once after a channel outage

## Phase 3: User Story 1 — Every night, a copy arrives in the chat (Priority: P1) 🎯 MVP

**Goal**: the nightly run makes a complete copy and delivers it to the office's chat, unattended.

**Independent test**: configure a bot and chat, run one night's job against a populated database, and
confirm the file arrives with its caption; run it twice in one night and confirm both copies arrive
and neither replaces the other.

- [X] T011 [US1] Implement `app/Console/Commands/SendDatabaseBackup.php` — `backup:send` with `--test`, `--no-deliver`, `--keep=`; exit codes 0 sent / 1 dump failed / 2 not delivered / 3 no destination configured, each printing the same sentence it stores (per `contracts/artisan-backup-command.md`); writes `backup.scheduler_seen_at` as its FIRST action on a real run; refuses to start when another `running` attempt is younger than the run timeout
- [X] T012 [P] [US1] Add the schedule line to `routes/console.php` — `Schedule::command('backup:send')->dailyAt(...)->withoutOverlapping()`, the hour read from settings with a 03:00 default
- [X] T013 [US1] Add the delivery wording to BOTH catalogues — the caption naming the night and size, the failure message, the catch-up line, with sizes and dates as Latin digits isolated for Arabic (`ltr_isolate`), and the Arabic plural forms for "N parts"/"N failed attempts"
- [X] T014 [US1] Test the command's success path in `tests/Feature/TelegramBackupTest.php` — `Http::fake()`; exactly one attempt row recorded `sent` with size and file name; the caption names the night and the delivered size; the scheduled trigger is recorded; asserted in both AR and EN
- [X] T015 [P] [US1] Test the command's edge paths in `tests/Feature/TelegramBackupTest.php` — `--no-deliver` leaves a verified file and does NOT record `sent`; `--test` records no attempt; no destination configured exits 3 with the message and makes no copy; two runs in one night produce two rows and two files
- [X] T016 [P] [US1] Test the failure paths in `tests/Feature/TelegramBackupTest.php` — Telegram's `ok: false` description is stored and rendered verbatim; a transport failure is stored with its reason; neither is ever `sent`; the earlier successful attempt is still the last delivered one; after the channel recovers, exactly one catch-up line names how many attempts failed

## Phase 4: User Story 2 — The office can see it happened, and send one by hand (Priority: P2)

**Goal**: one screen states the truth about the last attempts, warns when the office is uncovered,
and can send today's copy on demand.

**Independent test**: run one successful send and one failing send, open the screen, and confirm both
outcomes are shown with the failure's reason; press send-now and confirm a third entry arrives.

- [X] T017 [US2] Create `app/Admin/Tables/BackupAttemptsTable.php` — columns `started_at` (sortable), `trigger`, `status` (badge, coloured by outcome), `size_bytes` (money-free human size), `parts`, `error`; default sort newest first; rows are not links; empty state wording from the catalogue
- [X] T018 [US2] Implement `app/Livewire/Admin/Pages/Backup.php` — `subheading()`, the list, the send-now action (through `BackupRunner`, recording `trigger = manual` and `created_by`), and ONE public verdict method that answers "is the office covered?" from the newest delivered attempt and the `backup.scheduler_seen_at` heartbeat (older than 26 hours = the scheduler was never installed or stopped), returning the wording the screen and the chat both use
- [X] T019 [US2] Create `resources/views/livewire/admin/pages/backup.blade.php` — figures through `x-admin.stat` (last delivered, size, last scheduled run), the coverage warning as an alert, the attempts list through `x-admin.table`, and the send-now button with `wire:loading` naming its own action; utilities verified against the built stylesheet
- [X] T020 [P] [US2] Add the Backup entry to `app/Admin/AdminNav.php` under `nav.system` (label `mdl.backups`, icon verified against the Keenicons stylesheet, `route: admin.backup`, `match: ['admin.backup']`, `component: App\Livewire\Admin\Pages\Backup::class`) so the route and the sidebar item come from one entry
- [X] T021 [US2] Add the screen's wording to BOTH catalogues — title, subheading, the coverage warnings, the empty state, the button labels, the column headers; every counted label through `plural()`
- [X] T022 [US2] Test the screen in `tests/Feature/TelegramBackupTest.php` — no copies yet says so plainly; a failed attempt shows its reason and the older delivered copy is NOT presented as last night's; the warning fires when the heartbeat is stale (the missing-cron case) and when the newest success is old, and does not fire when both are fresh; send-now records a `manual` attempt attributed to the signed-in admin and delivers through the faked API
- [X] T023 [P] [US2] Test the door in `tests/Feature/TelegramBackupTest.php` — a collector gets 403 on the route and no Backup item in the sidebar; a guest is redirected to the login screen; an admin sees both

## Phase 5: User Story 3 — Setting the destination up once (Priority: P3)

**Goal**: the office tells the panel where to send, verifies it, and the credential stays out of sight.

**Independent test**: enter a bot and chat, press test, and see the panel confirm a reachable chat;
enter a wrong credential and see the failure stated plainly.

- [X] T024 [US3] Add the destination to `app/Livewire/Admin/Pages/Backup.php` — `bot_token` (write-only: saved encrypted with `Crypt::encryptString`, never rendered back, a saved token shows as saved and can be replaced), `chat_id`, `delivery_limit_bytes` (default 45 MB, under the API's 50 MB), `backup.retain` (default 30); validated in place, saved through `Setting::set`
- [X] T025 [US3] Wire the test action — `--test`'s path from the screen: one `sendMessage` to the configured chat, the outcome rendered next to the button with Telegram's own wording when it refuses
- [X] T026 [US3] Add the destination wording to BOTH catalogues — field labels, the helper that explains where the token comes from and that it is not shown again, the test outcome lines
- [X] T027 [US3] Test the destination in `tests/Feature/TelegramBackupTest.php` — saving stores the token encrypted at rest (the raw column does not contain the token and `Crypt::decryptString` returns it) and the token never appears in the rendered page; a test send reports success and reports a refusal with Telegram's description; an invalid chat id is refused by validation before any call

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T028 [P] Add the bilingual parity test in `tests/Feature/TelegramBackupTest.php` — every `bk.` key present in both catalogues, no raw key rendered on the screen or posted in a caption, Arabic plural forms asserted for the counted labels
- [ ] T029 [P] Run `quickstart.md` end to end against the development database and record the outcome — configure, `php artisan backup:send`, restore the file into a scratch database and compare the figures the office recognises, break the credential and see the failure, check the scheduler warning
- [X] T030 Run `./vendor/bin/pint` over the files this feature touched, then `php artisan test` — the whole suite green, and no unrelated file reformatted (pint is not enforced repo-wide: do not let it rewrite files this feature did not change)
- [X] T031 Run the converge step (`speckit-converge`) against this feature and append whatever remains to `tasks.md`, then implement and converge again until it reports Converged

---

## Dependencies & Execution Order

```text
Phase 1 (T001-T004)  ──► Phase 2 (T005-T010)  ──► Phase 3 US1 (T011-T016) 🎯 MVP
                                                        │
                                                        ├──► Phase 4 US2 (T017-T023)
                                                        └──► Phase 5 US3 (T024-T027)

Phase 6 (T028-T031) after the stories it polishes; T031 last.
```

- **US1 does not depend on the screen**: the nightly command and its recording are the MVP — an
  office can be protected before a single pixel of the screen exists.
- **US2 depends on US1** for records to display, but is independently testable with hand-made
  attempt rows.
- **US3 depends on US1** for a sender to test; the screen it lives on arrives with US2, so T024's
  fields can be built against the US2 page component.
- Within Phase 2, T005 → T007 → T009 (dumper, then sender, then the runner that uses both); the
  three test tasks are [P] with each other once their subject exists.

## Parallel Execution Examples

- **Phase 1**: T002, T003 and T004 touch three different files and can be written together after
  T001's column list is fixed.
- **Phase 2**: T006, T008 and T010 are three test files' worth of independent work once T005, T007
  and T009 exist; conversely T005 and T007 can be built at the same time (separate files, no shared
  state) and T009 joins them.
- **Phase 4/5**: T020 (nav) and T021 (catalogue) do not overlap with T017-T019's files; T023 and
  T027 are separate test concerns.

## Implementation Strategy

**MVP = Phase 3 (US1).** It delivers the whole point of the feature — a copy of the database in the
office's chat every night, recorded and verified — and it is provable without any screen. Ship it,
run it for a night, and confirm the chat receives a file that restores into a scratch database.

Then US2 (visibility and send-now), then US3 (the destination form, which an operator can also set by
the command's own refusal messages in the meantime).

Nothing in this feature waits on another feature: no restore screen, no import, no export bundle —
those are named in the spec's Out of Scope and get their own specs when the office asks for them.

---

## Phase 7: Convergence

Findings from assessing the code against the spec, plan and tasks on 2026-09-12. The feature's
specified scope is built and green (446 tests, 30 of them this feature's); what remains is the
verification that depends on things a test suite cannot provide.

- [ ] T032 Run `quickstart.md` end to end against the MySQL development database per SC-001/FR-002 (partial) — configure a real bot in @BotFather, `php artisan backup:send`, open the file in the chat, restore it into a scratch MySQL database and compare the figures the office recognises (subscriber count, an invoice total, the payment sum), then break the credential and watch the failure appear on the screen and in the chat. The suite exercises the SQLite branch of `DatabaseDumper` only: the MySQL branch (`SHOW TABLES` / `SHOW CREATE TABLE`) is the one that runs in production and has no automated coverage — this run is its proof.
- [X] T033 Justify `app/Services/Backup/BackupStatus.php` in `plan.md` or fold it into `BackupRunner` per plan: "the one place that defines a backup run" (unrequested) — the class holds the coverage verdict and its wording, which the screen's alert and the chat message both read; the plan named `BackupRunner` as the owner of the verdict. Either record the extra class in the plan (converge itself may not edit `plan.md`) or move the verdict into the runner so the plan and the code describe the same shape.
