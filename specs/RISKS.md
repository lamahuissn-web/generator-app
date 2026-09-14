# Risk register — the generator panel

A scenario is only real once it is written down. This file lists what can go wrong with the money,
the readings and the records, what the panel already does about it, and what is still open — so a
later phase can say "this stopped item N" instead of rediscovering it.

**Statuses**: `protected` (the panel refuses or records it) · `open` (a real hole) · `policy` (a
decision for the operator, not a defect) · `?` (not yet verified — check before acting).

**How to read a row**: scenario → what happens today → what would remove or detect it.

---

## 1 · Money (the ledger)

| Scenario | Status | Today / note |
|---|---|---|
| Tender above the balance | protected | The excess is change handed back and is NOT income (principle V). |
| Collecting when the balance is zero | protected | No payment row is created at all — `if ($applied <= 0) return` in `BillingService::collect`. Measured: a payment with no allocation cannot exist through the service. |
| One receipt settling several months | protected | FIFO, and the report says which bills it cleared. |
| **Reversing a receipt** | open | Restores the debt on EVERY bill it was allocated to (a surprise for the office), asks for no reason, and leaves the customer holding a receipt that no longer exists in the system. |
| A lira payment at an old rate | protected | The rate is stored with the receipt; today's rate cannot restate last month. |
| Electronic payments and what a collector owes | DECIDED | The office settles ONE figure and does not care how the money arrived: «ما بهمني شو قبض الجابي سواء كاش او الكتروني، بس يحاسب المكتب». What a collector owes is everything he collected minus what he handed over — every method alike — and a settlement may cover a transfer as well as cash. The method stays on the receipt. An earlier version of this change split cash from electronic and showed both; the office turned it down, and `CollectorAccountabilityTest` now pins the rule it asked for. |
| **A collection entered after the month turned** | protected | Fixed 2026-09-14: `payments.collected_on` carries the day the money moved, asked for on the office's collection form (refused if it is in the future). Every period figure, the receipt's own date and the receipt NUMBER follow it, and it is left empty when a collection is recorded as it happens — so the ordinary case behaves exactly as before. A row with no business date still counts on the day it was entered (the scope COALESCEs). |

## 2 · Billing

| Scenario | Status | Today / note |
|---|---|---|
| Re-running a cycle | protected | `RUN_EXISTS`. |
| Editing an invoice amount by hand | protected | No path: an invoice is built from a reading and its lines. |
| Deleting a flat invoice that was paid | protected | Refused (`refuse.bill_paid_delete`). |
| Tariff / maintenance change mid-cycle | protected | Each invoice carries the prices it was built with. |
| A special invoice with a negative line | protected | `min:0.01` on quantity and unit price — a fake credit is impossible. |
| Metered↔flat conversion mid-cycle | ? | The transition exists; what happens to an invoice already issued for that cycle is unverified. |

## 3 · Readings and meters

| Scenario | Status | Today / note |
|---|---|---|
| Deleting a reading in a closed cycle | protected | Admin + written reason (`CycleLock::refusal`), logged with the figures and the reason. |
| **Editing** a reading in a closed cycle | protected | No path overwrites an existing figure — correction is delete-then-re-enter. |
| A dead meter | protected | The collector reports it; the office decides (estimate / swap / flat). |
| **An estimated reading on the paper** | open | The system knows (`is_estimated`), the printed invoice does not say it — so a later correction leaves the customer holding a document that looks normal. |
| A meter swap (counter back to zero) | ? | `meter_swap` handling exists; negative consumption is unverified. |
| Two readings for one cycle | protected | The field app refuses; the office screen says a reading is already recorded. |
| One meter read under two subscribers | protected | `meter_no` is unique. |
| Filling a missing past cycle | protected | Deliberate: there is no figure to undo. |
| A broken reading chain (`previous_reading`) | open | Nothing checks it automatically — the books check below would. |

## 4 · Collector and settlement

| Scenario | Status | Today / note |
|---|---|---|
| Settling more than he holds | protected | Refused (`availableFor`). |
| Settling less | protected | Allowed; the difference stays visible as cash in his hand. |
| Deleting a settlement | protected | The money returns to his hand, logged — but with no reason asked (as with a reversal). |
| A settlement dated in the future | ? | `handover_date` is free text to the database; no range is enforced. |
| Two collectors collecting from one subscriber | protected | FIFO prevents double-counting; the second receipt becomes change. |
| A collector recording a collection he never made | policy | Nothing prevents it; the record is in his name in the audit trail. |

## 5 · Deletion and audit

| Scenario | Status | Today / note |
|---|---|---|
| **Deleting a subscriber** | protected | Fixed 2026-09-14: a subscriber with ANY ledger trace (invoice, receipt or reading) cannot be deleted, and the refusal names the alternative («حوّلو إلى موقوف»). A row with no trace is still deletable — that is honest correction for a duplicate — and even that is written to the trail (`subscriber.deleted` with a snapshot). |
| **Deleting a user (a departing collector)** | protected | Fixed 2026-09-14: accounts have a status. `inactive` cannot sign in, is stopped at the next request even mid-session, and is refused deletion while any row names them (the refusal says «عطّل حسابو»). A footprint-free account is still deletable, and recorded. |
| Deleting a route | protected | Subscribers fall back to "no route" and the today screen flags them. |
| Reversal / settlement deletion with no reason | open | Inconsistent with the closed-cycle correction, which demands one (principle II). |
| A direct database edit | open | Undetectable today — the books check below is the detector. |
| `php artisan app:reset-data` | protected | Console-only, asks for confirmation, wipes the audit trail with everything else: the off-box backup is the only answer. |

## 6 · Access and users

| Scenario | Status | Today / note |
|---|---|---|
| One role for the whole office | policy | Every office user is `admin`; there is no accountant/data-entry split yet. |
| A collector opening office screens | protected | `role:admin`. |
| A lost collector phone | policy | Password change; session revocation is unverified. |
| Password policy | ? | Unverified. |

## 7 · Data and backups

| Scenario | Status | Today / note |
|---|---|---|
| **The nightly copy reaching the office chat** | open | The scheduler is registered (`0 3 * * * backup:send`) but no `schedule:run` cron entry is installed on the machine, and no bot/chat is configured — so nothing is delivered. |
| A local copy on demand | protected | `backup:send --no-deliver` now works without a destination (fixed 2026-09-14); the panel still refuses to call that night covered. |
| The copy being whole | protected | Written under a temporary name, renamed only when complete, and it ends with a completion marker carrying the table count and a checksum (`verify()`). |
| A copy that restores | protected | Tested in the suite (`test_the_copy_restores_the_ledger_exactly`); a restore on the REAL MySQL needs a privileged account and has not been run yet. |
| Year rollover | protected | `'Y-m'` compares correctly as a string. |
| Growth over years | policy | Not addressed; revisit with volume. |

## 8 · Paper

| Scenario | Status | Today / note |
|---|---|---|
| Reversing a receipt | open | No cancellation document: the number disappears and the customer keeps a receipt that the system no longer knows. |
| Refunding a customer | open | No shape for it: money leaving the drawer is recorded as an expense with a note. |
| Re-printing | open | Nothing marks a copy. |
| Old paper after a past cycle is corrected | open | `prev_debt` and the statement disagree with what the customer was given. |

---

## Priority order

1. **The off-box copy** (section 7) — without it there is no way back from anything below.
2. **Deleting a subscriber** (section 5) — the one place where a mistake destroys history silently.
3. **Deleting a user** (section 5) — money loses its attribution, and there is no other way to retire an account.
4. **The in-hand figure** (section 1) — electronic payments counted as cash in a pocket.
5. **The payment business date** (section 1) — monthly figures slide with entry lag.
6. **The paper** (sections 3 and 8) — estimate, cancellation, copy.
7. **The books check** — a read-only pass that names any broken invariant (receipts ≠ allocations ≠ paid-on-bills, invoice numbers missing, a receipt with no audit row, a reading chain broken). It is the detector for every "direct database edit" row above.

## Done so far

- 2026-09-14 · `backup:send --no-deliver` no longer requires a destination; a real local copy was
  produced and verified on the office's own database, and the attempt is recorded as not delivered
  so the panel does not call the night covered (5 new tests on the command's states).
- 2026-09-14 · **A subscriber who exists in the ledger can no longer be deleted.** The cascade used
  to take readings, invoices, receipts and allocations with him and to leave no audit row; the
  refusal now names «موقوف» as the way out, a trace-free row is still deletable, and that deletion
  is recorded. The generic table gained `deleteRefusal()` so any definition can explain itself
  instead of answering with a bare "not allowed" — the old test that asserted the click-through
  warning was re-pointed at the new rule rather than deleted.
  Suite: **498 passed (2751 assertions)**.
- 2026-09-14 · **Accounts are retired, not deleted.** Users gained a `status`; a disabled account
  cannot sign in (checked at both doors AND on every request, so a phone already signed in stops
  working at once) and is refused deletion while any row names it — every one of those columns is
  `nullOnDelete`, which is how a receipt loses the name of whoever collected it. The users screen
  shows the status and the form can set it. Suite: **507 passed (2785 assertions)**.
 - 2026-09-14 · **What a collector owes is one figure, whatever the method.** A split (cash vs
   electronic, two numbers, a smaller settlement cap) was built and verified, then REJECTED by the
   office — it introduced a distinction the office does not settle by, and it made an ordinary figure
   look alarming on its own data (measured: −$117.25 «cash in hand» once transfers stopped counting,
   against a truth the office reads as $184.30 owed by one collector and nothing by the other). It
   was reverted; the tests that encoded it were replaced by ones that pin the office's rule.
 - 2026-09-14 · **A receipt carries its business date.** `payments.collected_on`, asked for on the
 office's collection form (future dates refused), read by every period figure and by the receipt's
 number and printed date; left empty when recording as it happens, and a row without one still
 counts on the day it was entered.
 Suite: **517 passed (2820 assertions)**.

## Pending with the operator

- The bot token and the chat id (the panel's backup screen, then `backup:send --test`).
- The cron entry: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.
- A restore drill on the real MySQL, run with a privileged account:
  `CREATE DATABASE generator_restore_check` → `mysql generator_restore_check < <dump>` → compare
  `SUM(payments.amount)`, `SUM(payment_allocations.amount)` and `SUM(bills.paid_amount)` → `DROP`.
- Decisions: (a) DONE — refused with a reason. (b) DONE — accounts have a status, deletion is
  refused while any row names them. (c) still open: is a reason required for reversing a receipt?
  (d) DECIDED — a collector is accountable for the TOTAL he collected; the panel shows ONE figure and
  never splits it by method (the split was built, then rejected — see Done so far).

## To confirm (not yet verified in code)

Metered↔flat conversion mid-cycle · a negative meter swap · a future `handover_date` ·
session revocation · password policy · whether an on-screen invoice marks an estimate.
