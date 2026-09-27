# Feature 006 — تصحيح تعبئة أو صيانة (correcting a refuelling or a service)

**Status:** the owner decided the four design questions (2026-09-25); this spec is the thing he approves.
**No code before «نفّذ».**
**Depends on:** 005 (مركز العمليات والأجهزة الهندسية — the device file and its timeline).
**Screens touched:** `أجهزتي` (`app/Livewire/Admin/Pages/DeviceFile.php`,
`resources/views/livewire/admin/pages/device-file.blade.php`). Nothing else moves.

## The owner's decision (his own four answers)

> «الخيار أ · المدير فقط · تصحيح شهر مضى بسبب مكتوب · عند تعديل صيانة نعدّل المصروف تلقائيا»

1. **Correction = حذف + إعادة إدخال داخل معاملة واحدة** — he sees one «تصحيح» that saves once; the database
   ends with one row, not an edit history.
2. **المدير فقط** — the buttons and the write sites both.
3. **تصحيح شهر مضى يحتاج سببًا مكتوبًا** — and the reason lands in the audit trail.
4. **صيانة: المصروف المرتبط يُعدَّل تلقائيًا** في نفس المعاملة.

## Why not edit the row in place (the reason this feature exists)

A refuelling row is not a log line. It is the **only input** of four figures the owner reads on the board
(كم كيلوواط أنتج · الفاقد ونسبته · كلفة الكيلوواط · ربح الكيلوواط) and of two derived numbers on the device
page (موعد الصيانة القادم من ساعات المحرّك · شذوذ لتر/ساعة). Editing one column silently rewrites a month
that has already been reported.

A service is worse, because it carries money: `equipment_services.expense_id` → `expenses`, and 005's own rule
is that «the expense stays the single money record». A correction that changes the cost without moving the
expense leaves `net` on the old figure — the exact class this repository has already fixed once for invoices
(«a document that is deleted must give back what it took»).

And the house has already decided this shape once, in writing: a reading has **no edit screen** — «Editing a
reading in a closed cycle: protected — no path overwrites an existing figure; correction is delete-then-re-enter»
(`specs/RISKS.md` §3), because an edit screen invites an edit that leaves the old bill behind. A refuelling's
"bill" is the month's production and the linked expense. So: one action the owner experiences as *edit*, two
writes the database sees, inside one transaction.

## What the screen gets

The device file's timeline (`DeviceFile::timeline()`) stops being read-only. Each entry gains its own
identity (`id`, `locked`), and each row carries **exactly one of two button pairs**, so the operator never has
to guess which one they are looking at:

| The row's month | Buttons on the row | Reason |
|---|---|---|
| **open** (not closed in `CycleLock`) | **«تصحيح»** (prefilled) · **«حذف»** (confirm only) | this morning's mistake is housekeeping |
| **closed** | **«تصحيح»** only, and the modal asks for a reason | the month was already reported and collected against |

«تصحيح» opens the SAME form as the add action, prefilled with the row's own values (date, litres, price,
engine hours, kWh, notes — or date, kind, hours, cost, «تسجيل كمصروف», notes), plus a **السبب** field that is
shown always and required only when the row's month is closed.

The pencil on the device card (the device's own identity: name, kind, hours, status) is **not** part of this
feature and keeps working as it does today.

## Requirements

- **FR-1 One save, one transaction.** A correction removes the old row and writes the new one inside
  `DB::transaction`; the new row is attributed to the correcting admin (`created_by`). No row is ever left
  edited half-way: the transaction is all or nothing.
- **FR-2 The service's expense moves with it, in the same transaction.**
  - the row had an expense and still costs something → `amount`, `description`, `expense_date`, `category` updated;
  - the row had an expense and no longer costs anything (cost 0, or «تسجيل كمصروف» unticked) → the expense row
    is **deleted**, because keeping it would leave the month's costs inflated by a service that no longer cost;
  - the row had no expense and the correction says it does → the expense is created;
  - the checkbox defaults to whether the row **has** an expense today, so re-saving without thinking changes nothing.
- **FR-3 A closed month needs a written reason, twice.** Once in the form
  (`required_when` → the reason is required only when the row's month is closed, i.e. the modal the operator
  actually sees) and once at the write site (`CycleLock::refusal($period, $reason, auth()->user())`, whose
  message `refuse.closed_cycle_reason` is already the app's wording) — the screens ask, the service refuses.
- **FR-4 المدير فقط, at both ends.** The buttons do not render for a non-admin, and both write paths
  (the correction action and the delete) refuse a non-admin even if reached directly.
- **FR-5 A refusal is named, never a silent rewrite.** A missing reason on a closed month refuses by name
  (`refuse.correction_reason_required`) with the modal staying open on the operator's input — the shape
  `deleteReadingAndBill` and `CorrectLockedReading` already use.
- **FR-6 The audit row carries before → after.** Every correction writes one audit row with the fields that
  moved (old value and new value on each), the reason, and `cycle_closed` — the snapshot shape already used by
  `BillingService::deleteReadingAndBill`.
- **FR-7 The device file and the board re-read the database.** Nothing derived is stored (verified:
  `refuellings` and `equipment` carry raw inputs only — no engine-hours total, no produced kWh, no loss column),
  so a correction has no cache to invalidate; the existing `ActionForm` redirect re-renders both pages.
- **FR-8 A refuelling's own money is out of scope.** `refuellings.expense_id` exists in the schema but
  `RecordRefuelling` never writes it; a correction leaves it exactly as found and invents no expense.
- **FR-9 The correction moves no document.** No invoice, payment, reading or balance is touched — the blast
  radius is the corrected row, the service's linked expense, and the cards re-derived from them.

## Classification (the house rule)

| Kind | Items |
|---|---|
| **Fixed** | a correction is delete-then-re-enter in one transaction; a closed month needs a written reason; the service's expense moves with the service or is removed; a correction is attributable to an admin in the audit trail |
| **Configurable** | nothing new — the reason's maximum length (255) follows the app's other reason fields |
| **Optional** | nothing new — a row with no engine hours, no kWh or no expense still corrects cleanly |

## Data

- **No migration, no new table, no new column.** Reuses `refuellings`, `equipment_services`, `expenses`,
  `audit_logs`.
- `CycleLock::isClosed($row->date->format('Y-m'))` is the single definition of "closed" — the same one the
  reading corrections and the collection screens use; no second notion of "a month that is over".
- New language keys (both locales, checked by `scripts/check-lang-keys.py`): the two row buttons, the two
  modal headings/hints, the prefilled-form labels that do not exist yet, and
  `refuse.correction_reason_required`.

## Out of scope

- Correcting a subscriber's reading through this path — readings keep their own pair
  (`ReadingsTable` delete / `CorrectLockedReading`).
- Bulk correction, an import/undo function, and a "correction history" screen: the audit log is the history.
- Deleting a device, changing a device's identity, and moving a refuelling to another device.
- Any money document: no invoice, receipt or balance is created, changed or removed by this feature.

## Acceptance (tests that must fail first on the pre-fix code)

1. A refuelling corrected in an open month: one save, the row's litres/kWh change, the month's production and
   loss follow, the audit row carries before → after, and no reason was asked.
2. A service corrected from 40.00 to 65.00: the linked expense reads 65.00 **and** the month's net follows —
   asserted in the same test, because the expense moving without the net (or the reverse) is the defect.
3. A service corrected to cost 0 (and the checkbox off): the expense row is gone and the net follows.
4. A service corrected from 0 to a cost, with the checkbox on: an expense exists afterwards and the net follows.
5. A closed month's correction with no reason: refused by name, the row unchanged. With a reason: it succeeds and
   the audit carries the reason and `cycle_closed`.
6. A non-admin: the timeline renders no correction button for him, and the write path refuses him directly.
7. A correction that turns an estimated refuelling into a measured one: the row is labelled «مقيس» afterwards and
   production switches from litres × the rate to the recorded kWh.
8. The pair, so the fix does not over-refuse: an open row still shows «حذف», a closed row does not.
9. The board is unaffected: an invoice raised before the correction still satisfies `Bill::charges() == total`,
   and rendering the device page and the board moves no money (bills/payments/subscribers.credit unchanged).

## Defaults chosen (change any by saying so)

1. The reason field is **always visible** in the correction modal and required only when the row's month is closed.
2. «حذف» appears on an open month's row only; a closed month offers «تصحيح» with a reason instead — deleting
   alone would rewrite a reported month with nothing written down.
3. The service's «تسجيل كمصروف» checkbox defaults to the row's current state rather than to «نعم».
4. Correcting a refuelling never creates or touches an expense (FR-8).
5. The audit row is written even when the correction changes nothing an operator can see (a no-op correction
   still records who saved what).
