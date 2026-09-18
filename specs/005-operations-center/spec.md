# Feature 005 — مركز العمليات والأجهزة الهندسية

**Status:** draft for the owner's approval (2026-09-18). No code before «نفّذ».
**Depends on:** 002 (the balance we hold), 004 (exemptions and discounts — an exempt house's consumption is
already on record and must count as **consumption** here, never as loss).
**Owner's own name:** the sidebar entry is **«مركز العمليات»**; the page carries **«مركز العمليات والأجهزة الهندسية»**.

## The rule this feature is built on (the owner's words)

> «أريد أن يكون النظام سهلاً كي يستطيع صاحب المولّد التعامل معه.»

The buyer is a **generator owner**, not an engineer. Every design decision below answers one question: *how
little must he learn and type to get a true picture of his machine?*

### قواعد البساطة (binding, like the fixed/configurable/optional rule)

1. **The owner's routine is two things**, and nothing else is ever required of him:
   - **once**, when he starts: three questions with defaults already filled (سعر اللتر · كم كيلوواط يعطي اللتر ·
     نوع المولّد) — and «لا أعرف» is a valid answer that keeps the defaults;
   - **each time he buys diesel**: اللترات والسعر — **حقلان، خمس عشرة ثانية**, typed from the pumps receipt.
2. **Everything the app can work out, the app works out.** He is never asked for kWh produced, engine
   efficiency, L/kWh, losses or cost per kWh: those are answers, not questions. Measuring production exactly
   (kWh on the clock, engine hours) stays available as an **advanced** path for the owner who wants precision.
3. **One device needs no list.** A one-generator office sees «المولّد» — never a registry with a single row —
   and the equipment screens appear only when he adds a second machine.
4. **Advanced figures never sit on the first screen.** The board shows six big numbers in plain words; the
   engineering detail (loss percentage, cost per kWh, per-device efficiency, fuel anomalies) lives behind one
   «أرقام أكثر» toggle.
5. **No engineering term without a plain-Arabic explanation beside it**, and no word the office does not use
   (مثال: «كم كيلوواط يعطي لتر المازوت» — لا «الكفاءة النوعية»).
6. **Optional means optional, always.** Whatever he does not record, the app hides that card with one line
   saying what to record — it never blocks a bill, an invoice or a collection, and it never prints a
   fabricated number (Feature 004's golden rule, unchanged).
7. **A number the app is unsure of says so**: an estimated figure is labelled «تقديري», and a measurement
   taken with unread meters says how many are missing.

## The two screens (v1)

### 1. مركز العمليات — the board

Six cards, in the office's own words, for the period:

| Card | In plain words | Source |
|---|---|---|
| **دخل الشهر** | what came in | `payments` (cash) |
| **مكتسب** | what the office actually earned | `payments.applied` |
| **علينا للمشتركين** | money the office holds for customers | `SUM(subscribers.credit)` |
| **متأخرات** | what is still owed by customers | bills − payments |
| **مصاريف** | what the office spent (including diesel) | `expenses` |
| **صافي** | مكتسب − مصاريف | derived (fixed) |

Then, behind **«أرقام أكثر»**: مصاريف المازوت · كم كيلوواط أنتج المولّد · كم استهلك المشتركون · **الفاقد
ونسبته** (with the count of unread meters and the share of estimated consumption) · **كلفة الكيلوواط** ·
**ربح الكيلوواط** · **كلفة الإعفاءات** (what the office's generosity cost it — beside the net, never inside).

The board also carries the Feature 004 disclosure lines (الخصومات · الإعانات الدائمة · قيمة الإعفاءات) and the
per-route energy balance as a card — **not** a separate report the owner has to go looking for.

### 2. أجهزتي — one page per machine

- A single page: its name, kind, capacity, install date, hours run, **its next service date**, and one
  **timeline** holding both what it drank (refuellings) and what was done to it (services).
- Two buttons on that page, each one form of two or three fields: **«تعبئة مازوت»** and **«صيانة»**.
- Its own three numbers, computed: كم كيلوواط أنتج · كم كلف كيلوواط · كم لتر لكل كيلوواط — plus a plain
  warning when the machine's own numbers drift from its history (the fuel-anomaly check).
- An office with one generator never sees an index: the page opens directly on «المولّد».

## Requirements

**Fuel (the only new routine — two fields)**

- **FR-1** Every refuelling: date, litres, price per litre (defaulted to the last price), and optionally
  engine hours and kWh produced. **Litres and price alone are enough** for the app to compute production,
  diesel cost, cost per kWh and losses — using the buyer-set kWh/litre rate (default filled at onboarding).
- **FR-2** When the owner does record kWh produced or engine hours, those take precedence over the rate and
  the row is marked «مقيس» instead of «تقديري»; the two are never averaged silently.
- **FR-3** The fuel card shows this month's litres, this month's diesel money, and the price per litre trend
  (a rise is a real business event the owner must see).

**Equipment**

- **FR-4** A device record: name, kind (مولّد / مضخة / UPS / أخرى), capacity, serial, install date, opening
  engine hours, status (يعمل / صيانة / متوقف), notes. **One device by default and no index until a second.**
- **FR-5** Service log per device: date, kind (زيت / فلتر / إصلاح / فحص), engine hours, cost, optional link to
  the expense it produced (the money stays in expenses, never duplicated here).
- **FR-6** **Next service** computed from the log and the hour thresholds (reference **250 / 500 / 2000**), said
  in plain words («بعد 150 ساعة» — أو «متأخرة 40 ساعة») and turned into a date at the machine's average pace.

**The energy picture (all derived — nothing asked)**

- **FR-7** Consumption = the period's metered readings (including exempt houses) **plus** the flat houses'
  draw estimated from their connected load (amperage) at a configurable **draw factor** (default **40%**, the
  reference band **35–45%** shown beside the field in one sentence the owner understands).
- **FR-8** Production = measured kWh (when recorded) or litres × the kWh/litre rate, labelled accordingly.
- **FR-9** Losses = produced − consumed, with its percentage, its classification against a configurable band,
  **the number of meters not read in the period**, and the share of consumption that is estimated. An unknown
  production hides the figure instead of guessing it.
- **FR-10** The same balance per **route**, with a dated reconciliation log so a change can be explained later.

**Nothing changes the money**

- **FR-11** The centre is disclosure only: no invoice, balance, collection or profit figure moves because of
  anything on these screens. `net` stays **مكتسب − مصاريف**; «كلفة الإعفاءات» sits beside it, never inside.

## Classification (the house rule)

| Kind | Items |
|---|---|
| **Fixed** | losses = produced − consumed; cost/kWh = (diesel + operating) ÷ produced; net = earned − expenses; the centre never moves an invoice or a balance; an estimate is always labelled; an exempt house's consumption is consumption |
| **Configurable** | kWh per litre (filled once at onboarding); the draw factor (40%, band 35–45% shown); the maintenance thresholds (250/500/2000); the normal loss band; the default diesel price |
| **Optional** | engine hours, kWh produced, extra devices, the service log, exact measurements of any kind. Absent → the card hides with one line; billing is untouched |

## Data

- `equipment`: `name`, `kind`, `capacity`, `serial`, `installed_on`, `engine_hours_opening`, `status`, `notes`,
  `created_by`, timestamps.
- `equipment_services`: `equipment_id`, `date`, `kind`, `engine_hours`, `cost`, `expense_id` (nullable),
  `notes`, `created_by`.
- `refuellings`: `equipment_id` (nullable), `date`, `litres`, `price_per_litre`, `engine_hours`,
  `kwh_produced`, `kwh_source` (`measured` | `estimated`), `supplier` (nullable), `notes`, `created_by`.
- Settings added: `kwh_per_litre` (onboarding answer), `flat_draw_factor` (40), `loss_band_normal`,
  `maintenance_alert_hours` («250,500,2000»), `diesel_price_default`.
- Existing tables are **read, not changed**: `readings`, `bills`, `payments`, `expenses`.

## Out of scope

- Any tariff decision (the reference app's 0.54 $/kWh MoEW is a yardstick displayed beside the office's own
  price, never imposed).
- Customer-facing energy accounting: subscribers are billed in money, as today.
- Remote monitoring / SCADA: values are typed by the office, never read from a device.

## Defaults chosen (change any by saying so)

1. Onboarding: three questions maximum, all with defaults, all answerable with «لا أعرف».
2. **Production comes from litres × the kWh/litre rate** unless the owner records exact kWh — and every
   figure that used the rate says «تقديري» on it.
3. A one-generator office never sees an equipment index.
4. The board is six plain cards; everything engineering sits behind «أرقام أكثر».
5. The draw factor defaults to 40% with the 35–45% band shown; maintenance alerts at 250 / 500 / 2000 hours.

## Amendment 1 — the audit's two blockers, and what is deferred on purpose

**Fixed after the first independent audit (BLOCK):**

1. **A loss is never printed from a month nobody has read.** The engine knew the flat houses' draw was
   unknowable (no metered reading to average) and still published consumption as if it were complete — so on the
   first day of a month, or on a network of flat houses only, the board showed «100% lost», the most alarming
   number in the app, fabricated. `consumption()` now carries `known`, and `losses()` hides the figure with the
   RIGHT reason (`no_readings` — «لم تُدخل قراءات هذا الشهر بعد») instead of a number. The loss also carries
   `estimated_share`: how much of the consumption it was measured against is an estimate.
2. **The five settings the centre reads have somewhere to be typed.** `kwh_per_litre`, `flat_draw_factor`,
   `diesel_price_default`, `loss_band_normal` and `maintenance_alert_hours` are now fields on the settings
   screen (the onboarding answer lives there rather than behind a wizard), the first refuelling is prefilled
   with the office's default price, and a zero rate is refused (it would make every litre produce nothing).

Also fixed in the same pass: the loss verdict is a KEY the screen translates rather than an English word, and a
negative loss is no longer classified against a band; the expenses hint no longer claims to include diesel when
the fuel card reads the refuelling sheet; «متأخرات» says it is all-periods while its neighbours follow the month
picker; a device with any log cannot be deleted (the machine that stopped is a STATE, and deleting it would
orphan refuellings that the centre still counts); and a service's expense is optional (FR-5) instead of forced.

**Deferred on purpose (recorded, not pretended):**

- **FR-3's price-per-litre trend** — the card shows this month's average; the trend needs a chart, and a chart
  is the next pass.
- **FR-10's dated reconciliation log** — the per-route balance is live; the log of changes over time is not
  built yet.
- **A tooling limit worth knowing:** the modal's DOM values could not be driven by the reviewer's browser
  automation in this Livewire build; the click path is covered by component tests (mount → open → set → submit)
  and by a manual browser walk-through that wrote a real refuelling.
