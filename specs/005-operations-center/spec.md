# Feature 005 — مركز العمليات والأجهزة الهندسية

**Status:** draft for the owner's approval (2026-09-18). No code before «نفّذ».
**Depends on:** 002 (the balance we hold), 004 (exemptions and discounts — an exempt house's consumption is
already on record and must count as **consumption** here, never as loss).
**Idea source:** the three reference screens the owner sent (sector energy balance, diesel refuelling, engine
profitability), read against this app's own model.
**Name:** the owner's own — «مركز العمليات والأجهزة الهندسية». The month picture is one tab inside it, not the
whole idea: the centre is about **the machines**.

## Why an operations centre, not a report

The office can see the money. It cannot see the machine: which device it owns, how many hours it has run, when
it was last serviced, how many kWh it produced, what leaked away, what a kWh costs, and what the office's own
generosity cost it. Those are the questions a generator owner asks about his equipment — and every one of them
is derivable from data the office already touches, except production, which is recorded with the thing it does
anyway: buying diesel.

**The governing rule is Feature 004's, unchanged:** every figure is **fixed / configurable / optional**, and
**a correct invoice may never depend on optional data**. The whole centre is **disclosure**: it changes no
invoice, no balance, no profit figure. An office that records no fuel and no equipment still bills exactly as
it does today — it simply does not see those cards, and never sees a fabricated number in their place.

## The five areas (tabs)

### 1. لوحة المركز — the centre's board

- **FR-1** One screen per period with the three layers side by side: **الطاقة** (منتج · مستهلك · فاقد ونسبته)
  · **المال** (مفوتر · مقبوض · مكتسب · متأخرات · رصيد المشتركين — علينا · مصاريف · **صافي = المكتسب − المصاريف**)
  · **الكلفة** (كلفة الكيلوواط · ربح الكيلوواط · كلفة الإعفاءات).
- **FR-2** Every card states where its number came from, and a card whose input is missing **hides itself**
  with one line saying what to record («سجّل الإنتاج مع تعبئة المازوت»). Nothing is guessed.
- **FR-3** The Feature 004 disclosure figures (الخصومات · الإعانات الدائمة · قيمة الإعفاءات) stay beside the
  net, never inside it.

### 2. الأجهزة الهندسية — the equipment registry

- **FR-4** A device record per machine: name, kind (مولّد / مضخة / UPS / أخرى), capacity (kVA or kW), serial,
  install date, opening engine hours, status (يعمل / صيانة / متوقف), notes.
- **FR-5** Each device gets its own card: hours run, litres burned, L/kWh, kWh produced, cost per kWh, and
  **the date of its next service** — computed from the service log, not typed by hand.
- **FR-6** A device that is stopped or under maintenance is a state, not a deletion: its history stays, and the
  centre says which device is carrying the load this month.

### 3. الوقود — refuelling

- **FR-7** Production is recorded **with each refuelling** — the default and cheapest source: date, litres,
  price per litre, engine hours on the clock, and **the kWh produced since the last refuelling**. From this the
  app derives L/kWh, the real diesel cost per kWh, and the period's production. No extra meter, no daily work.
- **FR-8** Two alternatives for an office that buys in bulk and cannot fill the sheet every time: a **monthly
  kWh produced** entered by hand, or an **estimate from litres** at a buyer-set kWh/litre rate. Every estimated
  figure is labelled as an estimate wherever it appears and is never averaged silently with a measured one.
- **FR-9** **Fuel anomaly check**: litres against engine hours and kWh produced, per device. A machine that
  burns 30% more litres for the same hours is a leak, a theft, or a fault — the centre names the deviation
  instead of leaving it in the total.

### 4. الصيانة — service and alerts

- **FR-10** A service log per device: date, kind (زيت / فلتر / إصلاح / فحص), engine hours at service, cost, and
  an optional link to the expense row it produced — the money stays in expenses, it is not duplicated here.
- **FR-11** Alerts at configurable engine-hour thresholds (reference **250 / 500 / 2000**), shown per device,
  so a service is decided from the log instead of from memory.

### 5. الطاقة والتوازن — energy balance and losses

- **FR-12** Consumption = the month's metered readings (including exempt houses) **plus** the flat houses'
  draw, estimated at a configurable **draw factor** (default **40%**, the reference band **35–45%** displayed
  beside the field so a buyer can check his own number).
- **FR-13** Losses = produced − consumed, with its percentage and its classification against a configurable
  normal band. Missing production hides the figure rather than guessing it.
- **FR-14** The same balance **per route** (the competitor's «قطاع» is our route), with a **dated
  reconciliation log** so a change can be explained months later.

## Classification (the house rule)

| Kind | Items |
|---|---|
| **Fixed** | losses = produced − consumed; cost/kWh = (diesel + operating) ÷ produced; net = earned − expenses; the centre never changes an invoice or a balance; an estimate is always labelled; an exempt house's consumption is consumption |
| **Configurable** | the draw factor (default 40%, band 35–45% shown); the maintenance thresholds (250/500/2000); the normal loss band; the kWh/litre rate when estimating; the default diesel price per litre |
| **Optional** | the equipment registry, refuellings, kWh produced, engine hours, service log. Absent → the cards hide; billing is untouched |

## Data

- `equipment`: `name`, `kind`, `capacity`, `serial`, `installed_on`, `engine_hours_opening`, `status`,
  `notes`, `created_by`, timestamps.
- `equipment_services`: `equipment_id`, `date`, `kind`, `engine_hours`, `cost`, `expense_id` (nullable),
  `notes`, `created_by`.
- `refuellings`: `equipment_id` (nullable), `date`, `litres`, `price_per_litre`, `engine_hours`,
  `kwh_produced`, `kwh_source` (`measured` | `monthly` | `estimated`), `supplier` (nullable), `notes`,
  `created_by`.
- Settings added: `flat_draw_factor` (40), `loss_band_normal`, `maintenance_alert_hours` («250,500,2000»),
  `kwh_per_litre` (blank = no estimation), `diesel_price_default`.
- Existing tables are **read, not changed**: `readings`, `bills`, `payments`, `expenses`. One device per
  installation is the default; the optional `equipment_id` means a second machine is data, not a migration.

## Out of scope

- Any tariff decision: the reference app's 0.54 $/kWh MoEW figure is a yardstick displayed beside the office's
  own effective price, never a value the app imposes.
- Customer-facing energy accounting: subscribers are billed in money, as today.
- Remote monitoring / SCADA: values are typed by the office, never read from a device.

## Defaults chosen (change any of them by saying so)

1. The centre is a section of its own in the sidebar, with the five areas above as tabs; the monthly picture
   lives inside it (لوحة المركز) rather than as a separate report.
2. Production is recorded **with each refuelling**, with monthly entry as the fallback and litres-estimation as
   the third option — the office picks per month, and each row remembers which it was.
3. The flat draw factor defaults to **40%** with the **35–45%** band displayed.
4. Maintenance alerts at **250 / 500 / 2000** engine hours.
5. **Confirmed by the owner:** the sidebar entry reads **«مركز العمليات»**, and the page heading carries the
   full name **«مركز العمليات والأجهزة الهندسية»**.
