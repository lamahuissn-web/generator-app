# Feature 003 — التحصيل والتسليم (a single screen for the office's cash)

**Status:** approved by the office 2026-09-13, mockup frozen at `~/Downloads/collections-handover-mockup.html`.
**Replaces:** ثلاثة شاشات — `admin.handovers.*` (تسليم النقدية) · `admin.collector-statement` (كشف حركات المحصّل) · `admin.collection-report` (تقرير التحصيل).

## Why

The three screens are three VIEWS OF ONE THING — the cash a collector took from subscribers, the
cash he handed back, and what is still with him. Because they were separate, the same handover could
be created from two places, «بيده الآن» was computed and shown twice with different captions, and
answering "من مين بدنا نستلم؟" meant visiting two screens. The office asked for one screen, simple,
practical and VERY detailed — and for more auditing: **when** a collector took money from a
subscriber ("تدقيق أكتر بحيث اعرف متي الجابي اخذ مال من الزبون").

## The question this screen answers

> المصاري اللّي بيد المحصّلين — شو انحصّل، شو سلّموا، وشو بعدو بيدهم؟ ومين بدنا نستلم منو؟

## Layers (frozen in the mockup)

1. **Period bar** — chips (هذا الشهر · الشهر الماضي · آخر ٧ أيام · كل الوقت) over from/to dates,
   then four headline figures: انحصّل · سلّموا · **بيد المحصّلين** · عدد الوصلات.
2. **Collectors table** — one row per collector: وصلات · انحصّل · سلّم · **بيده الآن** · آخر تسليم
   (ومنذ كم يوم) · الحال · إجراءات (تسليم نقدية للمكتب · الكشف · طباعة). The row decides the next
   action, so «بيده الآن» is the emphasised figure.
3. **The selected collector's dossier, in the same page** — five figures (انحصّل · سلّم · بيده الآن ·
   عدد الوصلات · نسبة التسليم) and four tabs:
   - **الحركة الزمنية** — every movement with a running balance; a **قصّ على الفترة** toggle
     (default: كل الوقت) that shows the **opening balance** as its first row.
   - **تدقيق التحصيل** — one row per receipt, grouped by the day the collector went out: الوقت ·
     الوصل · الزبون (عدّاد) · المنطقة · المبلغ · الطريقة (with the lira detail) · **عن أي فاتورة** ·
     ملاحظة الجابي · **سجّلها** (الجابي / المكتب / غير مسجّل). Search by receipt or subscriber, and
     a method filter.
   - **التسليمات** — with تعديل / حذف / جديد, guarded by the same rule as before.
   - **سجل التدقيق** — every recorded event to the second with **الرصيد بعدو**, and the actor. Built
     from `audit_logs`, so a deleted or edited record keeps its line.
4. **Collapsed below** — التحصيل حسب المنطقة وحسب اليوم + تصدير وطباعة تقرير التحصيل.

## Requirements

- **FR-1** "بيده الآن" is ONE number per collector, all-time (collected − handed), never range-scoped.
- **FR-2** Every figure states its scope (`بالفترة` / `كل الوقت`) — on the cards AND in the table headers,
  because a column set mixing a period figure with an all-time one gets added up and read as an error.
- **FR-3** The running balance is all-time; cutting it to the period shows the opening balance first.
- **FR-4** A handover can never exceed what the collector holds (`CashHandoverService` unchanged).
- **FR-5** Handovers are created, edited and deleted from this one screen only; each is audited.
- **FR-6** The audit row of a collection answers: who paid, how much, when (to the minute), by which
  method, against which invoice, with the collector's own note, and who recorded it.
- **FR-7** The audit log answers: what the program recorded, to the second, with the balance after it —
  including events whose record no longer exists (حذف/تعديل).
- **FR-8** The three old routes redirect to this screen with the right collector/period, so bookmarks,
  the dashboard and the collector portal keep working.
- **FR-9** The two printed documents (كشف المحصّل · تقرير التحصيل) are unchanged and launched from here.
- **FR-10** Money is `money()` / `amount-cell` (Latin digits, `$`) in both languages.

## What this does NOT do

- No new accounting rule, no new table: the screen composes `CollectionService` +
  `CashHandoverService` (a merge is a presentation job).
- No change to the collector portal.

## Success

- A green suite covering: period scoping, بيده = انحصّل − سلّم, the handover guard, the three
  redirects, the audit grouping and its day subtotal, the balance after each event, the period-cut
  toggle and its opening balance.
- Rendered on the office's own data, reviewed by the office before any commit.
