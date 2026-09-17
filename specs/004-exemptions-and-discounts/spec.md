# Feature 004 — الإعفاءات والخصومات (who is not charged, why, and for how much)

**Status:** principle approved by the product owner 2026-09-17; built on the manager's recommendations.
**Depends on:** Feature 002-era credit balance (`subscribers.credit`, `bills.credit_applied`,
`payments.applied`) — a discount is NOT credit and must never be written to that column.

## Why

The office asked for three things it cannot express today:

> احيانا يكون عندي زبون واريد ان اعطيه خصم أو شهر مجاني أو أحيانا ممكن يكون في زبون لا يدفع متل مسجد أو قريب

Today the only ways out are hacks: setting the subscriber `inactive` (which means "stopped", and quietly
removes him from the working lists), deleting an invoice (loses the month's record), or editing the reading
(tampering with the meter's history and the tariff audit). A mosque and a relative are not stopped
subscribers, a free month is not a missing invoice, and a discount is not a payment.

This is a product, not one office's private habit: **every generator owner has a mosque, a relative or a
bad month to handle**, and every one of them wants to know what that generosity cost him.

## The question this screen answers

> مين ما منطالبو؟ ليش؟ وشو كلّفنا هالشي هالشهر؟

## The governing rule (adopted 2026-09-17)

Every number or rule in this feature, and in everything built after it, is one of three kinds:

| Kind | Meaning | Here |
|---|---|---|
| **Fixed** | an accounting rule nobody may change | a discount reduces the charge; credit is real money; the paper adds up; total never below zero |
| **Configurable** | a policy that differs between offices | the category list; (later) whether the exempt receive paper |
| **Optional** | data not every buyer has | — (this feature has none: every office needs it) |

**Golden rule:** a correct invoice may never depend on optional data. This feature therefore has no
optional inputs at all.

## Layers

### 1. The exempt subscriber (المعفى) — a permanent standing, with a reason

- A subscriber can be marked exempt: **reason (required, free text)**, **category** (from a configurable
  list: مسجد · قريب · حالة إنسانية · أخرى), **who** marked it and **when**.
- An exempt subscriber is **not invoiced at all** — no bill, no charge, no collection queue — while his
  **readings continue to be recorded** like anyone else's. The meter still tells the truth about the
  network.
- He remains an **active** subscriber: he appears in the readings board and in his own file, and he is
  listed in a separate **«المعفون»** list with his reason and his category.
- **The value of the exemption is computed** — what his invoice would have been this cycle — and reported
  as its own figure («إعفاءات هذا الشهر»), never folded into the office's earnings.

### 2. The one-cycle discount (خصم / شهر مجاني) — a line on the invoice

- An admin applies a discount to **one bill**: an amount, a **required reason**, a category, and the
  record of who granted it and when.
- **100% of the charge = a free month**, by the same mechanism. The invoice still exists and still prints:
  the household receives paper that says what the month cost and that it was waived.
- The discount is a **named line on the invoice**, so the paper adds up on its own:
  `usage + fees − discount − credit = total`.

### 3. The permanent partial discount (اشتراك مخفّض) — a percent on the subscriber

- A per-subscriber percent off the charge, applied **before** the credit and the rounding, so a
  subsidised household is a normal monthly invoice at a lower price.

## The accounting chain (fixed order — this is the rule)

```
exact charge   = usage + maintenance + flat           (as the meter and the tariff produced it)
charge         = exact − (exact × discount_percent)   (permanent, per-subscriber)
− one-cycle discount on the bill                      (admin, with a reason)
− credit he carries   (subscribers.credit, his money) (Feature 002)
→ the office's rounding rule, if enabled
= total (never below zero)
```

## Requirements

- **FR-1** Marking a subscriber exempt requires a reason; the category comes from the configurable list.
- **FR-2** An exempt subscriber is never invoiced; his readings are still recorded and displayed.
- **FR-3** The exempt value (what would have been charged) is computed for the cycle and shown as its own
  figure in the month report; it is never part of «المكتسب».
- **FR-4** Exempt subscribers are excluded from المتأخرات · تقرير الفصل · قائمة تحصيل اليوم, and listed in
  their own «المعفون» list.
- **FR-5** A one-cycle discount is granted by an admin, on an unpaid bill, with a required reason and a
  category; who and when are recorded.
- **FR-6** The invoice prints its discount as a line, and `Bill::charges()` still equals `total`.
- **FR-7** A discount is never written to `subscribers.credit`: it reduces what the office charged; it is
  not money it holds.
- **FR-8** A bill that carries payments (`paid_amount > 0`) can never receive a discount — refused by name,
  like the other deletion guards.
- **FR-9** A 100% discount still produces the invoice (the paper of a free month) with a zero total.
- **FR-10** A permanent percent discount is applied before the credit and the rounding, and is visible in
  the subscriber's file.
- **FR-11** Every grant, change and removal is audited with the old and the new value.
- **FR-12** The exempt value is a *money* figure only: it changes no kWh, no reading, no loss figure.

## Configurable

- **The category list** — one setting holding the categories offered for exemptions and discounts.
- *(Deferred, recorded here)* **Whether an exempt subscriber receives a zero invoice each cycle** — v1
  prints nothing for the permanently exempt, and tells the customer on his **statement** («معفى — لا
  مطالبة»). A per-office switch is a follow-up, not a v1 need.

## Not in this feature

- The energy layer (produced / consumed / losses / cost per kWh) — Feature 005, its own spec.
- Collector-granted discounts: v1 keeps granting admin-only (the recommendation adopted).


## Amendment 1 — two columns of waived money (after the independent audit, 2026-09-17)

The first cut stored BOTH kinds of waived money in `bills.discount_amount`: the arithmetic the invoice was
produced with (an exemption, or a `subscribers.discount_percent`) and the waiver the office grants by hand on
one invoice. One column, two acts — so the office's waiver REPLACED the subsidy, and a subsidised invoice
could come out HIGHER after a discount was granted (60 − 15 = 45, discount 5 → 55). The audit caught it with
a repro. The fix is the split migration `2026_09_17_160000_split_the_two_kinds_of_waived_money`:

| column | written when | who is named | on the paper |
|---|---|---|---|
| `bills.subsidy_amount` | the invoice is produced | nobody — it is arithmetic | «إعانة/إعفاء» + the note |
| `bills.discount_amount` | the office decides, afterwards | `discount_by` + `discount_at` | «خصم» + the reason |

`charges() = usage + fees + items − credit_applied − subsidy_amount − discount_amount = total`, and a
one-cycle waiver is refused above what is still charged on that invoice — so it can only ever lower a total.

The month's disclosure therefore has **three** figures, and none of them counts a house twice:

- «الخصومات» = Σ `discount_amount` — what the office waived by hand this month.
- «الإعانات الدائمة» = Σ `subsidy_amount` — the standing subsidies and exemptions written into this month's invoices.
- «قيمة الإعفاءات» = the value of the exempt houses the month did **not** bill (read from the run's own
  preview); a house that WAS billed carries its waiver in the line above.

The office stops chasing what it waived: exempt houses are now absent from the dashboard's outstanding figure
and its top-debtor list too, not only from the overdue report and today's queue.

Deliberate as designed: the monthly run bills an exempt house **nothing at all** (no invoice), while a reading
entered by hand produces a **zero invoice** carrying the waiver — the paper only exists when someone visits
the meter, and in both cases nothing is claimed from the house.
