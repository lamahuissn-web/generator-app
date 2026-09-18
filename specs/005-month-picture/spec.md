# Feature 005 — صورة الشهر: the machine, the money, and what the generosity costs

**Status:** draft for the owner's approval (2026-09-18). No code before «نفّذ».
**Depends on:** 002 (the balance we hold), 004 (exemptions and discounts — the exempt house's consumption is
already on record and must count as consumption here, never as loss).
**Idea source:** the three reference screens the owner sent (sector energy balance, diesel refuelling,
engine profitability), read against this app's own model.

## Why

The office can already see the money. It cannot see the machine: how many kWh the generator produced, how many
were consumed, what leaked away, what a kWh costs to produce, and what the office's generosity actually cost
it that month. Every one of those numbers is derivable from data the office already touches — except one,
production, and that is recorded with the thing the office does anyway: buying diesel.

**The governing rule stays the same as Feature 004** (fixed / configurable / optional, and *a correct invoice
may never depend on optional data*): the energy layer is **disclosure**, it never touches an invoice, a
balance, or the profit definition. An office that records no fuel still bills exactly as it does today; it
simply does not see the energy cards, and never sees a fabricated number in their place.

## Requirements

**The energy layer (optional)**

- **FR-1** Production is entered **with each refuelling** — the default and cheapest source: date, litres,
  price per litre, engine hours on the clock, and the kWh produced since the last refuelling. From this the
  app derives L/kWh, the real diesel cost per kWh, and the month's production. No extra meter, no daily work.
- **FR-2** Two alternative sources, useful for an office that fuels in bulk and cannot fill the sheet every
  time: a **monthly kWh produced** entered by hand, or an **estimate from litres** at a buyer-set kWh/litre
  rate. An estimated figure is labelled as an estimate everywhere it appears and is never averaged silently
  with a measured one.
- **FR-3** Consumption = the metered readings of the month (including houses on the exempt list — their meters
  are still read) **plus** the flat houses' draw, estimated at a configurable **draw factor** (default **40%**,
  the reference band being **35–45%**, shown beside the field so a buyer can sanity-check his own number).
- **FR-4** Losses = produced − consumed, shown with its percentage and classified against a configurable
  normal band. When production is missing, losses are **hidden** with the reason («سجّل الإنتاج مع تعبئة
  المازوت»), never guessed.
- **FR-5** Engine hours and maintenance alerts at configurable thresholds (reference: **250 / 500 / 2000**
  hours) counted from the refuelling log, plus a fuel anomaly check (litres against engine hours and kWh
  produced) — a generator that burns 30% more litres for the same hours is a leak, a theft, or a fault.

**The money layer (mandatory — it already exists)**

- **FR-6** One screen shows the month's money as it is today: مفوتَر (including the waived lines), مقبوض,
  مكتسب, متأخرات, رصيد المشتركين — علينا, مصاريف, and **صافي = المكتسب − المصاريف**. The disclosure figures from
  Feature 004 (الخصومات، الإعانات الدائمة، قيمة الإعفاءات) sit beside the net, never inside it.

**The cost layer (derived, optional)**

- **FR-7** Cost per kWh = (diesel + operating expenses) ÷ kWh produced. Profit per kWh = (earned − that cost)
  ÷ kWh sold. Both are cards, not rules.
- **FR-8** **Cost of generosity** = the exempt houses' kWh × cost per kWh — what the office gave away that
  month in money it actually spent. It appears **beside the profit, never inside it**: generosity is a
  decision, not a cost of doing business.

**The screen**

- **FR-9** «صورة الشهر» — one page, three columns: **الطاقة** (منتج/مستهلك/فاقد by type) · **المال** (the money
  layer) · **الكلفة** (كلفة الكيلوواط، ربح الكيلوواط، كلفة الإعفاءات). Every card declares where its number came
  from, and a card whose input is missing hides itself with one line saying what to record.
- **FR-10** The same energy balance per **route** (the competitor's «قطاع» is our route), with a dated
  reconciliation log so a change in the figures can be explained later.
- **FR-11** One generator per installation by default; the refuelling and production rows carry an **optional
  generator reference from the start**, so a second machine later is data, not a migration.

## Classification (the house rule)

| Kind | Items |
|---|---|
| **Fixed** | losses = produced − consumed; cost/kWh = (diesel + operating) ÷ produced; net = earned − expenses; the energy layer never changes an invoice or a balance; an estimate is always labelled |
| **Configurable** | the draw factor (default 40%, band 35–45% shown); the maintenance thresholds (250/500/2000); the normal loss band; the kWh/litre rate when estimating; the fuel price per litre (per refuelling, with a default) |
| **Optional** | everything in the energy layer: refuellings, kWh produced, engine hours. Absent → the cards hide; billing is untouched |

## Data

- `refuellings`: `date`, `litres`, `price_per_litre`, `engine_hours`, `kwh_produced`, `supplier` (nullable),
  `generator_ref` (nullable), `notes`, `created_by`, timestamps. `kwh_produced` may be filled per refuelling
  (FR-1), left empty when the office enters production monthly (FR-2), or estimated from litres — the row
  records which of the three it was.
- Settings added: `flat_draw_factor` (40), `loss_band_normal` («5–15%» or a buyer's own range),
  `maintenance_alert_hours` («250,500,2000»), `kwh_per_litre` (blank = no estimation), `diesel_price_default`.
- Existing tables are read, not changed: `readings`, `bills`, `expenses` (the diesel expense stays the money
  record; a refuelling may optionally link to the expense it produced, without duplicating it).

## Out of scope

- Multi-generator comparison screens (the reference app's other half) — the optional reference is the wire left
  in place, not a feature.
- Any tariff decision: the reference app's 0.54 $/kWh MoEW figure is a yardstick to display beside the office's
  own effective price, never a value the app imposes.
- Customer-facing energy accounting: subscribers are billed in money, as today.

## Defaults chosen (change any of them by saying so)

1. Production is recorded **with each refuelling**, with monthly entry as the fallback and litres-estimation as
   the third option — the office picks per month, and the row remembers which it was.
2. The flat draw factor defaults to **40%** with the **35–45%** band displayed.
3. Maintenance alerts at **250 / 500 / 2000** engine hours.
4. Losses are computed for the **generator as a whole** and, where routes are meaningful, per route.
