<?php

namespace App\Reports;

use App\Models\Equipment;
use App\Models\Expense;
use App\Models\Reading;
use App\Models\Refuelling;
use App\Models\Setting;
use App\Models\Subscriber;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * مركز العمليات — the numbers the centre is allowed to say, and the ones it must refuse to say.
 *
 * The rule this class exists to keep (see specs/005-operations-center/spec.md): everything here is DERIVED
 * and DISCLOSURE. It never touches an invoice, a balance or the profit figure — `net` stays
 * «مكتسب − مصاريف» and the cost of generosity sits beside it. And when an input is missing, the answer is
 * null with a reason, never a plausible-looking number.
 *
 * Production is the owner's one new routine: litres and price at each refuelling. When he also writes down the
 * kWh the machine produced, that figure is used and the row is `measured`; otherwise litres × the office's
 * kWh/litre rate, and the row stays `estimated`. The two are never averaged silently — `basis` says which.
 */
final class OperationsReport
{
    /** What the onboarding answer falls back to when the office has not set its own rate yet. */
    public const DEFAULT_KWH_PER_LITRE = 3.0;

    private const FLAT_FACTOR_DEFAULT = 40;

    public function __construct(private readonly string $period) {}

    public static function forMonth(string $period): self
    {
        return new self($period);
    }

    public static function currentMonth(): self
    {
        return new self(now()->format('Y-m'));
    }

    private function start(): string
    {
        return $this->period.'-01';
    }

    private function end(): string
    {
        return Carbon::parse($this->start())->endOfMonth()->toDateString();
    }

    private function rate(): float
    {
        return (float) Setting::get('kwh_per_litre', self::DEFAULT_KWH_PER_LITRE);
    }

    /** Is the office still on the default rate? The screen must say so instead of implying precision. */
    public function rateIsDefault(): bool
    {
        return (float) Setting::get('kwh_per_litre', self::DEFAULT_KWH_PER_LITRE) === self::DEFAULT_KWH_PER_LITRE;
    }

    // ---------------------------------------------------------------- energy: produced

    /**
     * @return array{kwh: float|null, measured: float, from_litres: float, litres: float,
     *               basis: string|null, basis_ar: string|null, rate: float, rate_is_default: bool}
     */
    public function production(): array
    {
        $rows = Refuelling::query()->whereBetween('date', [$this->start(), $this->end()])->get();

        $empty = [
            'kwh' => null, 'measured' => 0.0, 'from_litres' => 0.0, 'litres' => 0.0,
            'basis' => null, 'basis_ar' => null, 'rate' => $this->rate(), 'rate_is_default' => $this->rateIsDefault(),
        ];

        if ($rows->isEmpty()) {
            return $empty;
        }

        $measured = round((float) $rows->filter(fn (Refuelling $r): bool => $r->isMeasured())->sum('kwh_produced'), 2);
        $openLitres = round((float) $rows->reject(fn (Refuelling $r): bool => $r->isMeasured())->sum('litres'), 2);
        $fromLitres = round($openLitres * $this->rate(), 2);

        $litres = round((float) $rows->sum('litres'), 2);
        $kwh = round($measured + $fromLitres, 2);

        $basis = $fromLitres <= 0 ? 'measured' : ($measured <= 0 ? 'estimated' : 'mixed');

        return [
            'kwh' => $kwh,
            'measured' => $measured,
            'from_litres' => $fromLitres,
            'litres' => $litres,
            'basis' => $basis,
            'basis_ar' => match ($basis) {
                'measured' => __('ops.basis_measured'),
                'mixed' => __('ops.basis_mixed'),
                default => __('ops.basis_estimated'),
            },
            'rate' => $this->rate(),
            'rate_is_default' => $this->rateIsDefault(),
        ];
    }

    // ---------------------------------------------------------------- energy: consumed

    /**
     * @return array{kwh: float, metered: float, flat_estimated: float|null, flat_houses: int,
     *               average_metered: float|null, unread_meters: int, estimated_readings: int,
     *               complete: bool, factor: float}
     */
    public function consumption(): array
    {
        $readings = Reading::query()->where('period', $this->period);

        $metered = round((float) (clone $readings)->sum('consumption'), 1);
        $readCount = (clone $readings)->count();
        $estimatedReadings = (clone $readings)->where('is_estimated', true)->count();

        $activeMetered = Subscriber::query()->active()->where('type', Subscriber::TYPE_METERED);
        $meteredHouses = (clone $activeMetered)->count();
        $readSubscribers = (clone $readings)->pluck('subscriber_id')->all();
        $unread = (clone $activeMetered)->whereNotIn('id', $readSubscribers)->count();

        // The flat houses' draw, estimated from the office's own average metered subscriber at the factor the
        // office sets (40% by default, the reference band 35–45%). Deliberately not an electrical
        // calculation: the owner can reason about "a flat house draws about this much of a metered one", and
        // an assumption he understands is worth more than a formula he does not.
        $factor = (float) Setting::get('flat_draw_factor', self::FLAT_FACTOR_DEFAULT);
        $averageMetered = $readCount > 0 ? round($metered / $readCount, 1) : null;
        // A house that was read this period and then converted to flat is already in the metered figure: it
        // must not be counted a second time as a flat house (which inflated consumption and hid the loss).
        $flatHouses = Subscriber::query()->active()
            ->where('type', Subscriber::TYPE_FLAT)
            ->whereNotIn('id', $readSubscribers)
            ->count();
        $flatEstimated = $averageMetered === null || $flatHouses === 0
            ? ($flatHouses === 0 ? 0.0 : null)
            : round($averageMetered * $factor / 100 * $flatHouses, 1);

        return [
            // The total, but the honest flags travel with it: `known` false means part of the consumption is
            // unknowable this month — no reading has been entered and there is no metered house to average the
            // flat draw from — and a caller that needs a real figure must refuse to print one.
            'kwh' => round($metered + ($flatEstimated ?? 0.0), 1),
            'metered' => $metered,
            'read_count' => $readCount,
            'flat_estimated' => $flatEstimated,
            'flat_houses' => $flatHouses,
            'average_metered' => $averageMetered,
            'unread_meters' => $unread,
            'estimated_readings' => $estimatedReadings,
            // ONE rule, and it is about the READINGS, not about how many houses happen to be active: a month
            // with no reading at all has no measured consumption to subtract, so a loss computed from it is
            // arithmetic dressed as a measurement. This also covers the fresh install (a machine and diesel
            // before any subscriber exists) and a network whose houses are all stopped — the audit found both
            // doors still open, both reachable from the screens.
            'known' => $readCount > 0,
            'complete' => $unread === 0 && $meteredHouses > 0 && $flatEstimated !== null,
            'estimated_share' => $flatEstimated === null || $flatEstimated <= 0 || round($metered + $flatEstimated, 1) <= 0
                ? null
                : round($flatEstimated / ($metered + $flatEstimated) * 100, 1),
            'factor' => $factor,
        ];
    }

    /**
     * @return array{kwh: float|null, percent: float|null, verdict: string|null, note: string|null,
     *               unread_meters: int, estimated_share: float|null, reason: string|null}
     */
    public function losses(): array
    {
        $produced = $this->production();
        $consumed = $this->consumption();

        if ($produced['kwh'] === null || $produced['kwh'] <= 0) {
            return [
                'kwh' => null, 'percent' => null, 'verdict' => null,
                'note' => null, 'unread_meters' => $consumed['unread_meters'],
                'estimated_share' => null, 'reason' => 'no_production',
            ];
        }

        // A loss needs a consumption figure that means something. Before the month's readings are in, the
        // consumption is not small — it is UNKNOWN, and a loss printed from it (300 kWh produced − 0 consumed =
        // 100% lost) is the most alarming number in the app and entirely fabricated. So the figure hides, with
        // the reason being the thing that is actually missing.
        if (! $consumed['known']) {
            return [
                'kwh' => null, 'percent' => null, 'verdict' => null,
                'note' => null, 'unread_meters' => $consumed['unread_meters'],
                'estimated_share' => null, 'reason' => 'no_readings',
            ];
        }

        $loss = round($produced['kwh'] - $consumed['kwh'], 1);
        $percent = round($loss / $produced['kwh'] * 100, 1);

        return [
            'kwh' => $loss,
            'percent' => $percent,
            'verdict' => $this->verdict($percent),
            'note' => $loss < 0 ? __('ops.loss_negative') : null,
            'unread_meters' => $consumed['unread_meters'],
            'estimated_share' => $consumed['estimated_share'],
            'reason' => null,
        ];
    }

    /**
     * A verdict only when the office has set the band it considers normal — otherwise the number stands alone.
     * A negative loss is never classified: consumption above production is a measurement question, not a
     * band the office can be inside or outside of.
     */
    private function verdict(float $percent): ?string
    {
        if ($percent < 0) {
            return null;
        }

        $band = trim((string) Setting::get('loss_band_normal', ''));

        if ($band === '' || ! preg_match('/^\s*(\d+(?:\.\d+)?)\s*[-–]\s*(\d+(?:\.\d+)?)\s*$/', $band, $m)) {
            return null;
        }

        [$low, $high] = [(float) $m[1], (float) $m[2]];

        return $percent < $low ? 'low' : ($percent > $high ? 'high' : 'normal');
    }

    // ---------------------------------------------------------------- fuel and cost

    /**
     * @return array{litres: float, money: float, average_price: float|null, basis: string|null}
     */
    public function fuel(): array
    {
        $rows = Refuelling::query()->whereBetween('date', [$this->start(), $this->end()])->get();

        if ($rows->isNotEmpty()) {
            $litres = round((float) $rows->sum('litres'), 2);
            $money = round((float) $rows->sum(fn (Refuelling $r): float => $r->money()), 2);

            return [
                'litres' => $litres,
                'money' => $money,
                'average_price' => $litres > 0 ? round($money / $litres, 3) : null,
                'basis' => 'log',
            ];
        }

        // No refuelling sheet: the office's own expenses are the only money record, so the diesel figure comes
        // from there. Both are never summed — that would count the same purchase twice.
        $expense = round((float) Expense::query()
            ->where('category', Expense::CAT_FUEL)
            ->whereBetween('expense_date', [$this->start(), $this->end()])
            ->sum('amount'), 2);

        return [
            'litres' => round((float) Expense::query()
                ->where('category', Expense::CAT_FUEL)
                ->whereBetween('expense_date', [$this->start(), $this->end()])
                ->sum('quantity'), 2),
            'money' => $expense,
            'average_price' => null,
            'basis' => $expense > 0 ? 'expenses' : null,
        ];
    }

    /** What the machine cost to run this period: diesel + oil + repairs. */
    public function operatingCosts(): array
    {
        $fuel = $this->fuel();

        $other = round((float) Expense::query()
            ->whereIn('category', [Expense::CAT_OIL, Expense::CAT_REPAIR])
            ->whereBetween('expense_date', [$this->start(), $this->end()])
            ->sum('amount'), 2);

        return [
            'diesel' => $fuel['money'],
            'other' => $other,
            'total' => round($fuel['money'] + $other, 2),
            'basis' => $fuel['basis'],
        ];
    }

    public function costPerKwh(): ?float
    {
        $produced = $this->production()['kwh'];

        if ($produced === null || $produced <= 0) {
            return null;
        }

        return round($this->operatingCosts()['total'] / $produced, 3);
    }

    public function profitPerKwh(): ?float
    {
        $delivered = $this->consumption()['kwh'];

        if ($delivered <= 0) {
            return null;
        }

        $earned = (float) (ProfitLossReport::forMonth($this->period)->data()['earned'] ?? 0);

        return round(($earned - $this->operatingCosts()['total']) / $delivered, 3);
    }

    /**
     * What the office's generosity cost it this month, in money it actually spent: the kWh an exempt house
     * consumed × the cost of producing a kWh. Shown BESIDE the net profit, never inside it — a waiver is a
     * decision, not a cost of doing business.
     *
     * @return array{kwh: float|null, cost: float|null}
     */
    public function generosity(): array
    {
        $exemptIds = Subscriber::query()->whereNotNull('exempt_at')->pluck('id')->all();

        if ($exemptIds === []) {
            return ['kwh' => 0.0, 'cost' => 0.0];
        }

        $metered = round((float) Reading::query()
            ->where('period', $this->period)
            ->whereIn('subscriber_id', $exemptIds)
            ->sum('consumption'), 1);

        $consumed = $this->consumption();
        $flatExempt = Subscriber::query()
            ->whereNotNull('exempt_at')
            ->where('type', Subscriber::TYPE_FLAT)
            ->count();

        $flatKwh = ($consumed['average_metered'] === null || $flatExempt === 0)
            ? 0.0
            : round($consumed['average_metered'] * $consumed['factor'] / 100 * $flatExempt, 1);

        $kwh = round($metered + $flatKwh, 1);
        $cost = $this->costPerKwh();

        // A flat-only exempt house (or one whose draw cannot be anchored) has no kWh we can honestly state:
        // `kwh` stays null and the card says "unknown" rather than "none".
        $known = $metered > 0 || $flatExempt === 0 || $consumed['average_metered'] !== null;

        return [
            'kwh' => $known ? $kwh : null,
            'cost' => ! $known || $cost === null ? null : round($kwh * $cost, 2),
        ];
    }

    // ---------------------------------------------------------------- one machine

    /**
     * When this device is due, in the owner's words: hours left, and a date only when its own logs carry
     * enough pace to project one.
     *
     * @return array{current: float|null, threshold: float|null, hours_left: float|null,
     *               date: string|null, wording: string|null, beyond_bands: bool}
     */
    public function nextService(Equipment $equipment): array
    {
        $bands = $this->serviceBands();
        $current = $equipment->currentEngineHours();

        $unknown = ['current' => $current, 'threshold' => null, 'hours_left' => null, 'date' => null, 'wording' => null, 'beyond_bands' => false];

        if ($current === null || $bands === []) {
            return $unknown;
        }

        $lastService = $equipment->services()->whereNotNull('engine_hours')->max('engine_hours');
        $base = $lastService !== null ? (float) $lastService : (float) $equipment->engine_hours_opening;

        $threshold = null;
        foreach ($bands as $band) {
            if ($band > $base) {
                $threshold = $band;
                break;
            }
        }

        if ($threshold === null) {
            return array_merge($unknown, ['beyond_bands' => true]);
        }

        $left = round($threshold - $current, 1);
        $pace = $this->hoursPerDay($equipment);

        return [
            'current' => $current,
            'threshold' => $threshold,
            'hours_left' => $left,
            'date' => ($pace !== null && $pace > 0 && $left > 0)
                ? Carbon::now()->addDays((int) ceil($left / $pace))->toDateString()
                : null,
            'wording' => $left >= 0
                ? __('ops.service_in', ['hours' => number_format($left, 0)])
                : __('ops.service_late', ['hours' => number_format(abs($left), 0)]),
            'beyond_bands' => false,
        ];
    }

    /** @return list<float> */
    private function serviceBands(): array
    {
        $raw = (string) Setting::get('maintenance_alert_hours', '250,500,2000');

        $bands = collect(explode(',', $raw))
            ->map(fn (string $v): float => (float) trim($v))
            ->filter(fn (float $v): bool => $v > 0)
            ->sort()
            ->values()
            ->all();

        return $bands;
    }

    /** Hours the machine runs per day, from its own log — null when the log cannot say. */
    private function hoursPerDay(Equipment $equipment): ?float
    {
        $rows = $equipment->refuellings()->whereNotNull('engine_hours')->get(['date', 'engine_hours']);

        if ($rows->count() < 2) {
            return null;
        }

        $first = $rows->first();
        $last = $rows->last();
        $days = Carbon::parse($first->date)->diffInDays(Carbon::parse($last->date));

        if ($days <= 0) {
            return null;
        }

        $hours = (float) $last->engine_hours - (float) $first->engine_hours;

        return $hours > 0 ? round($hours / $days, 2) : null;
    }

    /**
     * Litres per engine hour, this period against the machine's own history. A generator that suddenly drinks
     * a quarter more per hour is a leak, a theft or a fault — and the centre says so instead of hiding it in
     * the month's total.
     *
     * @return array{litres_per_hour: float, history: float, deviation: float, direction: string}|null
     */
    public function fuelAnomaly(Equipment $equipment): ?array
    {
        $rows = $equipment->refuellings()->whereNotNull('engine_hours')->orderBy('date')->get();

        if ($rows->count() < 3) {
            return null;
        }

        $rates = [];
        for ($i = 1; $i < $rows->count(); $i++) {
            $delta = (float) $rows[$i]->engine_hours - (float) $rows[$i - 1]->engine_hours;

            if ($delta > 0) {
                $rates[] = (float) $rows[$i]->litres / $delta;
            }
        }

        if (count($rates) < 2) {
            return null;
        }

        $current = array_pop($rates);
        $history = array_sum($rates) / count($rates);

        if ($history <= 0) {
            return null;
        }

        $deviation = round(($current - $history) / $history * 100, 1);

        if (abs($deviation) < 15) {
            return null;
        }

        return [
            'litres_per_hour' => round($current, 2),
            'history' => round($history, 2),
            'deviation' => $deviation,
            'direction' => $deviation > 0 ? 'up' : 'down',
        ];
    }

    /**
     * The same balance per route — the competitor's «قطاع» is our route.
     *
     * Every route that has an active subscriber appears, including one whose houses are ALL flat: a route that
     * quietly vanishes from the list is exactly how a leak stays invisible. The flat draw uses the same factor
     * and the same network average as consumption() — one definition of it in the whole app.
     *
     * @return Collection<int, array{route: string, metered: float, flat: float, consumed: float, flats: int}>
     */
    public function perRoute(): Collection
    {
        $consumption = $this->consumption();

        $metered = Reading::query()
            ->where('readings.period', $this->period)
            ->join('subscribers', 'subscribers.id', '=', 'readings.subscriber_id')
            ->join('routes', 'routes.id', '=', 'subscribers.route_id')
            ->groupBy('routes.id', 'routes.name')
            ->selectRaw('routes.name as route_name, COALESCE(SUM(readings.consumption), 0) as kwh')
            ->pluck('kwh', 'route_name');

        $flats = Subscriber::query()->active()
            ->where('subscribers.type', Subscriber::TYPE_FLAT)
            ->join('routes', 'routes.id', '=', 'subscribers.route_id')
            ->groupBy('routes.id', 'routes.name')
            ->selectRaw('routes.name as route_name, COUNT(*) as flats')
            ->pluck('flats', 'route_name');

        // Every route that HAS houses, read or not: a route whose meters have not been read yet used to vanish
        // from the list entirely, which reads as "no such area" instead of "not read yet".
        $served = Subscriber::query()->active()
            ->join('routes', 'routes.id', '=', 'subscribers.route_id')
            ->groupBy('routes.id', 'routes.name')
            ->selectRaw('routes.name as route_name, COUNT(*) as houses')
            ->pluck('houses', 'route_name');

        return $metered->keys()
            ->merge($flats->keys())
            ->merge($served->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $name) use ($metered, $flats, $consumption): array {
                $meteredKwh = round((float) ($metered[$name] ?? 0), 1);
                $flatCount = (int) ($flats[$name] ?? 0);
                $flatKwh = ($consumption['average_metered'] === null || $flatCount === 0)
                    ? 0.0
                    : round($consumption['average_metered'] * $consumption['factor'] / 100 * $flatCount, 1);

                return [
                    'route' => $name,
                    'metered' => $meteredKwh,
                    'flat' => $flatKwh,
                    'consumed' => round($meteredKwh + $flatKwh, 1),
                    'flats' => $flatCount,
                    // Known = nothing rests on a basis we do not have: a flat house's draw with no metered
                    // average to anchor it is unknown, and the card says so instead of printing a zero.
                    'known' => $flatCount === 0 || $consumption['average_metered'] !== null,
                ];
            })
            ->values();
    }

    /** The devices that exist, newest figures first — the index a multi-machine office needs and a one-generator office never sees. */
    public function equipment(): Collection
    {
        return Equipment::query()->orderBy('name')->get();
    }

    /**
     * The board: the money (from the existing reports — never re-derived here) and the energy and cost cards,
     * each carrying its basis so the screen can label rather than imply.
     *
     * @return array<string, mixed>
     */
    public function board(): array
    {
        $money = ProfitLossReport::forMonth($this->period)->data();
        $production = $this->production();
        $consumption = $this->consumption();
        $losses = $this->losses();
        $fuel = $this->fuel();

        return [
            'period' => $this->period,
            'label' => period_label_i18n($this->period),
            'money' => [
                'collected' => (float) ($money['collected'] ?? 0),
                'earned' => (float) ($money['earned'] ?? 0),
                'held' => (float) ($money['held_for_subscribers'] ?? 0),
                'expenses' => (float) ($money['expenses'] ?? 0),
                'net' => (float) ($money['net'] ?? 0),
                'discounts' => (float) ($money['discounts'] ?? 0),
                'subsidies' => (float) ($money['subsidies'] ?? 0),
                'exempt_value' => (float) ($money['exempt_value'] ?? 0),
            ],
            'energy' => [
                'produced' => $production['kwh'],
                'production_basis' => $production['basis'],
                'production_basis_ar' => $production['basis_ar'],
                'produced_measured' => $production['measured'],
                'produced_from_litres' => $production['from_litres'],
                'consumed' => $consumption['kwh'],
                'consumed_metered' => $consumption['metered'],
                'consumed_flat' => $consumption['flat_estimated'],
                'flat_houses' => $consumption['flat_houses'],
                'unread_meters' => $consumption['unread_meters'],
                'estimated_readings' => $consumption['estimated_readings'],
                'loss_kwh' => $losses['kwh'],
                'loss_percent' => $losses['percent'],
                'loss_verdict' => $losses['verdict'],
                'loss_note' => $losses['note'],
                'loss_reason' => $losses['reason'],
                'loss_estimated_share' => $losses['estimated_share'],
            ],
            'fuel' => $fuel,
            'cost' => [
                'operating' => $this->operatingCosts(),
                'per_kwh' => $this->costPerKwh(),
                'profit_per_kwh' => $this->profitPerKwh(),
                'generosity' => $this->generosity(),
            ],
        ];
    }
}
