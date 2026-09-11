<?php

namespace App\Reports;

use App\Models\Bill;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ReadingQuality;
use Illuminate\Support\Collection;

/**
 * A cycle's readings, from the office's side: who is in, who is missing, and how long the
 * missing ones have been missing.
 *
 * The billing run already reports coverage as a count, and a count cannot be acted on:
 * "3 readings missing" is the same sentence whether those are three doors on one street or
 * three houses nobody has read since June. This report names them, ages the gap, and keeps
 * three different kinds of trouble apart, because each one is answered by a different person:
 *
 *  - NEVER/STALE: nobody has read this house in months. Either the door is always shut or
 *    somebody is drawing power that is not being billed, and only a visit settles it.
 *  - BROKEN: the collector already reported the meter dead. It is waiting for a decision
 *    (estimate the cycle, or replace the device), and until then the house cannot be billed.
 *  - SUSPECT: a reading was taken and looks wrong — zero consumption, or far above this
 *    house's own average. Money is about to be invoiced off it.
 *
 * Everything is read in a fixed number of queries (three, plus one per grouping) and joined
 * in PHP: this list is every metered subscriber in the network, and a per-row query would
 * make the screen the slowest thing in the panel.
 */
final class ReadingBoard
{
    /** The collector reported the meter dead; the office has not decided yet. */
    public const ALERT_BROKEN = 'broken';

    /** A metered subscriber with no reading at all, ever. */
    public const ALERT_NEVER = 'never';

    /** No reading for STALE_AFTER cycles or more. */
    public const ALERT_STALE = 'stale';

    /** No reading this cycle, recent history. */
    public const ALERT_PENDING = 'pending';

    /** This cycle's reading was recorded and looks wrong. */
    public const ALERT_SUSPECT = 'suspect';

    /** Recorded, and ordinary. */
    public const ALERT_OK = 'ok';

    /**
     * Cycles without a reading before it stops looking like an oversight.
     *
     * A generator network runs monthly: at three missed cycles a house has consumed a
     * quarter of a year unbilled, which is past "the collector will get to it" and worth a
     * deliberate visit.
     */
    public const STALE_AFTER = 3;

    public const STATE_ALL = 'all';

    public const STATE_ATTENTION = 'attention';

    public const STATE_PENDING = 'pending';

    /** The unfiltered rows, built once per report instance. */
    private ?Collection $allCache = null;

    public function __construct(
        private readonly string $period,
        private readonly ?int $routeId = null,
        private readonly string $state = self::STATE_ATTENTION,
    ) {}

    public static function make(
        string $period,
        ?int $routeId = null,
        string $state = self::STATE_ATTENTION,
    ): self {
        return new self($period, $routeId, $state);
    }

    /** @return array<string, string> the state picker, in the order they are offered */
    public static function stateOptions(): array
    {
        return [
            self::STATE_ATTENTION => __('rdgb.state_attention'),
            self::STATE_PENDING => __('rdgb.state_pending'),
            self::STATE_ALL => __('rdgb.state_all'),
        ];
    }

    /** How serious an alert is, for the sort. Lower is worse. */
    public static function rank(string $alert): int
    {
        return match ($alert) {
            self::ALERT_BROKEN => 0,
            self::ALERT_NEVER => 1,
            self::ALERT_STALE => 2,
            self::ALERT_PENDING => 3,
            self::ALERT_SUSPECT => 4,
            default => 5,
        };
    }

    /** Months from $period up to $current, both 'Y-m'. */
    public static function monthsBetween(string $current, string $period): int
    {
        [$currentYear, $currentMonth] = array_map('intval', explode('-', $current));
        [$periodYear, $periodMonth] = array_map('intval', explode('-', $period));

        return ($currentYear * 12 + $currentMonth) - ($periodYear * 12 + $periodMonth);
    }

    /**
     * The cycle's reading state for every metered, active subscriber in scope, unfiltered.
     *
     * Memoised: the screen asks for the displayed rows, the summary and (once) the CSV in one
     * render, and each of those needs the same three queries joined in PHP.
     *
     * @return Collection<int, array<string, mixed>> worst first
     */
    private function all(): Collection
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }

        $subscribers = Subscriber::query()
            ->metered()
            ->active()
            ->with('route:id,name')
            ->when($this->routeId, fn ($query) => $query->where('route_id', $this->routeId))
            ->orderBy('name')
            ->get();

        $ids = $subscribers->pluck('id')->all();

        // One query for every reading up to this cycle. The newest row per subscriber is
        // either this cycle's reading or the value the cycle would continue from, and the
        // older ones are the history ReadingQuality averages over.
        $readings = Reading::query()
            ->whereIn('subscriber_id', $ids)
            ->where('period', '<=', $this->period)
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->get([
                'id', 'subscriber_id', 'period', 'current_reading', 'previous_reading',
                'consumption', 'is_estimated',
            ])
            ->groupBy('subscriber_id');

        $bills = Bill::query()
            ->whereIn('subscriber_id', $ids)
            ->where('period', $this->period)
            ->get(['subscriber_id', 'total', 'prev_debt'])
            ->keyBy('subscriber_id');

        $rows = $subscribers->map(function (Subscriber $subscriber) use ($readings, $bills): array {
            /** @var Collection<int, Reading> $history */
            $history = $readings->get($subscriber->id) ?? collect();
            /** @var ?Reading $newest */
            $newest = $history->first();

            $recorded = $newest !== null && $newest->period === $this->period;
            $lastPeriod = $newest?->period;
            $gap = $lastPeriod === null ? null : self::monthsBetween($this->period, $lastPeriod);
            $consumption = $recorded ? (float) $newest->consumption : null;

            // The quality rule the collector's screen warns with, applied to what has
            // already been billed: a zero or a jump is the same fact on both screens.
            $verdict = $recorded ? ReadingQuality::verdict($subscriber, $this->period, (float) $consumption) : null;
            $bill = $bills->get($subscriber->id);

            return [
                'id' => $subscriber->id,
                'name' => $subscriber->name,
                'route' => $subscriber->route?->name,
                'meter_no' => $subscriber->meter_no,
                'phone' => $subscriber->phone,
                'amperage' => $subscriber->amperage,
                'recorded' => $recorded,
                'last_period' => $lastPeriod,
                'gap' => $gap,
                'current' => $recorded ? (float) $newest->current_reading : null,
                'previous' => $recorded
                    ? (float) $newest->previous_reading
                    : (float) ($newest?->current_reading ?? 0),
                'consumption' => $consumption,
                'estimated' => $recorded && (bool) $newest->is_estimated,
                'verdict' => $verdict,
                'average' => $verdict === ReadingQuality::JUMP
                    ? ReadingQuality::average($subscriber, $this->period)
                    : null,
                'total' => $recorded ? (float) ($bill?->total ?? 0) + (float) ($bill?->prev_debt ?? 0) : null,
                'broken' => $subscriber->hasBrokenMeterReport(),
                'alert' => match (true) {
                    $subscriber->hasBrokenMeterReport() => self::ALERT_BROKEN,
                    $lastPeriod === null => self::ALERT_NEVER,
                    ! $recorded && $gap >= self::STALE_AFTER => self::ALERT_STALE,
                    ! $recorded => self::ALERT_PENDING,
                    $verdict !== null => self::ALERT_SUSPECT,
                    default => self::ALERT_OK,
                },
            ];
        });

        return $this->allCache = $rows
            ->sortBy([
                fn (array $a, array $b) => self::rank($a['alert']) <=> self::rank($b['alert']),
                // Within one alert, the longest gap first: the oldest unread house is the
                // one losing the most money per day it stays unread.
                fn (array $a, array $b) => ($b['gap'] ?? PHP_INT_MAX) <=> ($a['gap'] ?? PHP_INT_MAX),
                fn (array $a, array $b) => strcmp((string) $a['name'], (string) $b['name']),
            ])
            ->values();
    }

    /** The rows the state filter keeps, worst first. */
    public function rows(): Collection
    {
        return $this->all()->filter(fn (array $row): bool => $this->keeps($row))->values();
    }

    /** Does the state filter keep this row? */
    private function keeps(array $row): bool
    {
        return match ($this->state) {
            self::STATE_ALL => true,
            self::STATE_PENDING => ! $row['recorded'],
            default => $row['alert'] !== self::ALERT_OK,
        };
    }

    /** The KPI figures: how much of the cycle is in, and how much of the trouble there is. */
    public function summary(): array
    {
        // Counted over EVERY row, not just the filtered ones: a filter narrows what is
        // listed, it does not change what is true about the cycle.
        $all = $this->all();
        $recorded = $all->where('recorded', true)->count();
        $total = $all->count();

        return [
            'total' => $total,
            'recorded' => $recorded,
            'pending' => $total - $recorded,
            'percent' => $total === 0 ? 100 : (int) round($recorded / $total * 100),
            'stale' => $all->whereIn('alert', [self::ALERT_STALE, self::ALERT_NEVER])->count(),
            'broken' => $all->where('alert', self::ALERT_BROKEN)->count(),
            'suspect' => $all->where('alert', self::ALERT_SUSPECT)->count(),
        ];
    }

    /**
     * Coverage per area — BillingService's own figures, not a second implementation of the
     * same count, so this screen and the billing-run gate can never disagree.
     *
     * @return array{total: int, recorded: int, pending: int, routes: array<int, array<string, mixed>>}
     */
    public function coverage(): array
    {
        return BillingService::readingProgress($this->period, $this->routeId);
    }

    /**
     * Who actually recorded this cycle's readings, and how many.
     *
     * The only per-collector reading the data supports: a subscriber is not assigned to a
     * collector anywhere in this app (money is), so the honest question is who walked the
     * route, not whose route it was.
     *
     * @return Collection<int, array{name: string, phone: ?string, count: int, estimated: int}>
     */
    public function byCollector(): Collection
    {
        $counts = Reading::query()
            ->where('period', $this->period)
            ->when(
                $this->routeId,
                fn ($query) => $query->whereHas(
                    'subscriber',
                    fn ($subscriber) => $subscriber->where('route_id', $this->routeId),
                ),
            )
            ->selectRaw('entered_by, COUNT(*) as total, SUM(is_estimated) as estimated')
            ->groupBy('entered_by')
            ->get();

        $users = User::query()
            ->whereIn('id', $counts->pluck('entered_by')->filter()->all())
            ->get(['id', 'name', 'phone'])
            ->keyBy('id');

        return $counts
            ->map(function ($row) use ($users): array {
                $user = $users->get($row->entered_by);

                return [
                    'name' => $user?->name ?? __('rdgb.no_user'),
                    'phone' => $user?->phone,
                    'count' => (int) $row->total,
                    'estimated' => (int) $row->estimated,
                ];
            })
            ->sortByDesc('count')
            ->values();
    }

    /** The list as a spreadsheet: the office works through it by phone. */
    public function csv(): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            __('t.name'), __('t.route'), __('t.meter'), __('t.phone'),
            __('rdgb.last_reading'), __('t.status'), __('t.kwh'), __('t.total'),
        ]);

        foreach ($this->rows() as $row) {
            fputcsv($handle, [
                $row['name'],
                $row['route'] ?? '',
                $row['meter_no'] ?? '',
                $row['phone'] ?? '',
                $row['last_period'] ?? '',
                $this->alertLabel($row),
                $row['consumption'] === null ? '' : number_format((float) $row['consumption'], 1, '.', ''),
                $row['total'] === null ? '' : number_format((float) $row['total'], 2, '.', ''),
            ]);
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /** The chip text for a row's alert — one wording, CSV and table alike. */
    public function alertLabel(array $row): string
    {
        return match ($row['alert']) {
            self::ALERT_BROKEN => __('t.meter_broken_badge'),
            self::ALERT_NEVER => __('rdgb.chip_never'),
            self::ALERT_STALE => plural('rdgb.chip_gap', (int) $row['gap']),
            self::ALERT_SUSPECT => ReadingQuality::label((string) $row['verdict'], $row['average']),
            self::ALERT_PENDING => __('rdgb.chip_pending'),
            default => __('rdgb.chip_ok'),
        };
    }
}
