# Feature 007 — قارئ سجل التدقيق (a reader for the audit trail)

**Status:** the owner approved this shape and said «ابدأ» (2026-10-03). Page stays **admin-only**, as it is today.
**Depends on:** nothing new. It reads the trail every other feature already writes.
**Screens:** `سجل التدقيق` (`/admin/audit-logs`, `app/Admin/Tables/AuditLogsTable.php`).

## What this is, in one line

The trail already records everything — **this feature changes nothing about WHAT is written; it changes how it
is READ.** The office opens this screen when the numbers disagree, and today it answers in machine language.

## Why not the audit feature that was rejected

`specs/BACKLOG.md` records that the office turned down the earlier audit work (2026-09-12): *«الفكرة عقّدت
البرنامج ككل، فلا أريدها»* — a change trail on every priced value, eight arithmetic checks, a screen listing
what does not add up, and a nightly red alert. **None of that comes back.** This is the smaller and plainer
thing the note itself asked for: no checks, no alerts, no scheduled job, no new column, no new event — a
reader for rows that already exist, so «مين، متى، وشو كانت القيمة القديمة» is true of the screen.

## What the page says today (measured on the office's own data, 2026-10-03)

Its own description promises: «كل تعديل مسجّل: مين، متى، وشو كانت القيمة القديمة». It shows five columns —
متى · بواسطة · إجراء · الموضوع · التفاصيل — and three things go wrong on real rows:

1. **The important part is cut off.** عمود «التفاصيل» renders the payload's **first four keys**, then `+N`. A
   real `reading.created` row carries **twelve** keys and shows
   `period: 2026-10 · current_reading: 210 · consumption: 35 · bill_id: 21 +8` — the household's name, the
   previous reading, **the invoice amount ($22.50)** and whether it was estimated are inside the `+8`.
2. **A nested block is dumped.** A real `refuelling.removed` row shows
   `removed: {"date":"2026-09-08","litres":200,… } · cycle_closed: false · reason: null`: the figure the owner
   looks for — 200 litres — is buried in JSON he cannot read.
3. **No detail, no filters.** There is no way to open a row, no before → after, and no filter by person,
   period or kind of act.

The data itself is complete: dates, litres, prices, amounts, reasons and whether the month was closed are all
**in the payload**. Only the reader is missing.

## Requirements

- **FR-1 — Every row is a sentence in Arabic.** Each of the 29 recorded actions gets a title (a badge) and one
  sentence built from its own payload. Examples, from the office's own rows:
  - `reading.created` → «قراءة جديدة: **الحاج** — ٢١٠ عدّاد / ٣٥ ك.و.س لشهر **تشرين الأول** ⇒ فاتورة **#21 بـ$22.50** · القراءة السابقة ١٧٥ · تقديرية: لا»
  - `refuelling.removed` → «حذف تعبئة: **٢٠٠ لتر × $1.300** (٧٣٩ ك.و.س مقيسة) بتاريخ ٨/٩ — المولّد الرئيسي»
  - `payment.created` → «**قبض $20.00** من الحاج — وزّع على فاتورتين · المتبقي عليه $35.00 · نقدًا»
- **FR-2 — A change reads as «قبل ← بعد».** Any payload carrying `before` + (`after` or `removed`) renders the
  fields that ACTUALLY moved, in the office's words (`اللترات: 100 ← 140`), and never as two JSON blobs.
- **FR-3 — «لماذا» sits on the line, not behind a click.** When the payload carries a reason, it is shown in
  the row (his own decision: the reason matters more than the act in a small office), and `cycle_closed` reads
  as «شهر مقفّل».
- **FR-4 — Every value is reachable.** A «تفاصيل» action opens the row in a read-only modal: the sentence, the
  changes, the reason, and **every** key of the payload as `label: value` (no JSON, no truncation), labelled in
  Arabic where a label exists. Nothing on this screen is editable and nothing is written by reading it.
- **FR-5 — The subject is a name, not a class.** `App\Models\Reading #13` becomes the household's name; a
  device row names the machine; a bill row names the invoice. Where the subject still exists, a second row
  action opens **the thing itself** (ملف المشترك · الفاتورة · صفحة الجهاز) instead of forcing a search.
- **FR-6 — Filters the office actually uses.** الشهر (month picker) · **النوع** (the nine families: مال،
  قراءات وفواتير، مشتركون، أجهزة، مصاريف، تسليمات، استيراد، مستخدمون) · **من فعلها** (the actors that have
  rows) · **الإجراء** (the existing filter, whose dropdown stops showing raw slugs and shows the Arabic
  titles) · the free search stays.
- **FR-7 — An action nobody templated still reads.** A new or forgotten action must not crash the screen or
  print JSON: it falls back to its own key as the title and its payload as `key: value` pairs. Never a blank
  row, never an exception.
- **FR-8 — Admin only, unchanged.** The route and the registry entry stay `admin_only`; nothing here widens who
  can see money reasons and prices.
- **FR-9 — Reading moves nothing.** No audit row is written by opening the screen or a detail (the reader must
  not audit itself), no bill, payment, balance or expense is touched, and the payloads are never rewritten.

## Coverage (29 actions, 9 families)

| Family | Actions | Reads from |
|---|---|---|
| قراءات | `reading.created`, `reading.deleted`, `reading.baseline`, `reading.imported` | period, reading, consumption, bill, estimate flags |
| فواتير | `bill.generated`, `bill.deleted`, `bill.special_created`, `bill.special_deleted`, `bill.discounted` | totals, items, discount + reason |
| مال | `payment.created`, `payment.reversed` | cash / applied / excess / remaining, method |
| مشتركون | `subscriber.exempted`, `subscriber.exemption_removed`, `subscriber.converted_to_flat`, `subscriber.converted_to_metered`, `subscriber.meter_reported_broken`, `subscriber.deleted`, `subscriber.imported` | name, reason, fee, meter |
| أجهزة | `refuelling.corrected`, `refuelling.removed`, `service.corrected`, `service.removed` | before → after, litres, cost, expense moved |
| مصاريف | `expense.created`, `expense.updated`, `expense.deleted` | category, amount, date |
| تسليمات | `handover.created`, `handover.updated`, `handover.deleted` | collector, amount, what stays in hand |
| استيراد | (via the two import actions above) | created / refused / failed |
| مستخدمون | `user.deleted` | name, username, role |

## Classification (the house rule)

| Kind | Items |
|---|---|
| **Fixed** | the trail is what it is — this feature never writes, never changes a payload, never adds a check or an alert; the page stays admin-only; a row always renders *something* readable |
| **Configurable** | nothing |
| **Optional** | nothing — every value shown comes from a payload that already exists |

## Data

**No migration, no new table, no new column, no new audit event.** The only new artifacts are a renderer
(`app/Admin/AuditNarrative.php`), a read-only modal definition, the table's columns/filters, and language keys
(both locales, checked by `scripts/check-lang-keys.py`).

## Out of scope

- Any integrity/reconciliation check, red alert or scheduled job (rejected 2026-09-12 — not returning).
- Day-grouping headers and per-subject drill-down links from other screens (**deferred**; the same renderer
  makes both a small next step).
- Exporting the trail to a file (the generic table's CSV export stays whatever it already does).
- Editing or deleting an audit row: a trail that can be edited is not a trail.

## Acceptance (tests that must fail first on the pre-fix code)

1. A real `reading.created` row renders the household's **name** and the **invoice amount**, and does NOT
   contain `+` truncation marks or a raw `{` payload dump.
2. A `refuelling.removed` row renders «٢٠٠ لتر» and the date, and no `{"date"` JSON fragment.
3. A correction row renders its change as `قبل ← بعد` for the fields that moved, and its reason.
4. The reason and «شهر مقفّل» appear on the line for a closed-month correction.
5. The detail action opens a read-only modal that contains **every** payload key of that row, and the modal
   writes nothing (no new audit row, no money moved).
6. An untemplated action (a fabricated `something.odd` row) renders its key and its pairs — no exception, no JSON.
7. Each filter narrows the list: by month, by family, by actor; and the action dropdown shows Arabic titles.
8. Admin-only: the audit route refuses a non-admin, and nothing on the page is editable.
9. Opening the list and the detail writes no audit row (the reader does not audit itself) and leaves
   bills/payments/subscribers.credit unchanged.

## Defaults chosen (change any by saying so)

1. **الأحدث أولًا**, with the day written in Arabic beside the time (the office reads dates a day at a time).
2. The action filter's labels become Arabic titles; its raw slug is not hidden — it stays in the detail modal,
   because that is the string a developer or a support call needs.
3. A payload key with no Arabic label shows its own key name rather than a guessed translation.
4. The detail modal is read-only through a new `static` field type in the shared field component (a small
   additive branch, so no existing form changes behaviour).
5. The row says «المبلغ» **only** when money actually moved in that row — an amount invented for a row that
   has none is worse than no column.
