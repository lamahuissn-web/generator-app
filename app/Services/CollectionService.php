<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Cash-side reporting: what was collected, per collector / per route / per day,
 * and how much of it each collector still has in hand after handovers.
 */
class CollectionService
{
    /** Payments collected in [$from, $to] (inclusive), optionally scoped. */
    public static function paymentsBetween(Carbon $from, Carbon $to, ?int $collectorId = null, ?int $routeId = null)
    {
        return Payment::query()
            ->when($collectorId, fn ($q) => $q->where('collector_id', $collectorId))
            ->when($routeId, fn ($q) => $q->whereHas('subscriber', fn ($s) => $s->where('route_id', $routeId)))
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    public static function collectedBetween(Carbon $from, Carbon $to, ?int $collectorId = null, ?int $routeId = null): float
    {
        return (float) self::paymentsBetween($from, $to, $collectorId, $routeId)->sum('amount');
    }

    /** Everything a collector ever collected (any date). */
    public static function collectedAllTime(?int $collectorId = null): float
    {
        return (float) Payment::query()
            ->when($collectorId, fn ($q) => $q->where('collector_id', $collectorId))
            ->sum('amount');
    }

    /** Everything a collector ever handed over (any date). */
    public static function handedOverAllTime(?int $collectorId = null): float
    {
        return (float) CashHandover::query()
            ->when($collectorId, fn ($q) => $q->where('collector_id', $collectorId))
            ->sum('amount');
    }

    /**
     * Cash the collector is still holding: all-time collections minus handovers.
     * This is the number that matters for reconciliation — it does NOT depend
     * on the report's date range.
     */
    public static function inHand(?int $collectorId = null): float
    {
        return round(self::collectedAllTime($collectorId) - self::handedOverAllTime($collectorId), 2);
    }

    /**
     * One row per collector: range activity plus the all-time cash position.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function byCollector(Carbon $from, Carbon $to): Collection
    {
        $range = self::paymentsBetween($from, $to)
            ->selectRaw('collector_id, COUNT(*) as payments_count, COALESCE(SUM(amount), 0) as collected')
            ->groupBy('collector_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->collector_id);

        $handedInRange = CashHandover::query()
            ->whereBetween('handover_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('collector_id, COALESCE(SUM(amount), 0) as handed')
            ->groupBy('collector_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->collector_id);

        $names = User::query()->where('role', User::ROLE_COLLECTOR)->orderBy('name')->pluck('name', 'id');

        // Include anyone with activity even if their role changed since.
        foreach ([...$range->keys(), ...$handedInRange->keys()] as $id) {
            if (! isset($names[$id]) && $id > 0) {
                $names[$id] = User::whereKey($id)->value('name') ?? __('col.unknown_collector');
            }
        }

        return collect($names)
            ->map(function (string $name, $id) use ($range, $handedInRange) {
                $id = (int) $id;
                $collected = (float) ($range->get($id)->collected ?? 0);
                $handed = (float) ($handedInRange->get($id)->handed ?? 0);

                return [
                    'collector_id' => $id,
                    'name' => $name,
                    'payments_count' => (int) ($range->get($id)->payments_count ?? 0),
                    'collected' => $collected,
                    'handed' => $handed,
                    'net_range' => round($collected - $handed, 2),
                    'in_hand' => self::inHand($id),
                ];
            })
            ->sortByDesc(fn ($row) => [$row['in_hand'], $row['collected']])
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> one row per route */
    public static function byRoute(Carbon $from, Carbon $to, ?int $collectorId = null): Collection
    {
        $rows = Payment::query()
            ->when($collectorId, fn ($q) => $q->where('collector_id', $collectorId))
            ->join('subscribers as s', 's.id', '=', 'payments.subscriber_id')
            ->leftJoin('routes as r', 'r.id', '=', 's.route_id')
            ->whereBetween('payments.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw("s.route_id, COALESCE(r.name, '') as route_name, COUNT(*) as payments_count, COALESCE(SUM(payments.amount), 0) as collected")
            ->groupBy('s.route_id', 'r.name')
            ->orderByDesc('collected')
            ->get();

        return $rows->map(fn ($row) => [
            'route_id' => $row->route_id,
            'name' => $row->route_name !== '' ? $row->route_name : __('col.no_route'),
            'payments_count' => (int) $row->payments_count,
            'collected' => (float) $row->collected,
        ]);
    }

    /** @return Collection<int, array<string, mixed>> one row per day, newest first */
    public static function byDay(Carbon $from, Carbon $to, ?int $collectorId = null, ?int $routeId = null): Collection
    {
        $rows = self::paymentsBetween($from, $to, $collectorId, $routeId)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as payments_count, COALESCE(SUM(amount), 0) as collected')
            ->groupBy('day')
            ->orderByDesc('day')
            ->get();

        return $rows->map(fn ($row) => [
            'day' => (string) $row->day,
            'payments_count' => (int) $row->payments_count,
            'collected' => (float) $row->collected,
        ]);
    }

    /** @return array{collected: float, handed: float, net: float, in_hand: float, payments_count: int} */
    public static function totals(Carbon $from, Carbon $to, ?int $collectorId = null, ?int $routeId = null): array
    {
        $query = self::paymentsBetween($from, $to, $collectorId, $routeId);

        $collected = (float) (clone $query)->sum('amount');
        $count = (clone $query)->count();

        $handed = (float) CashHandover::query()
            ->when($collectorId, fn ($q) => $q->where('collector_id', $collectorId))
            ->whereBetween('handover_date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');

        return [
            'collected' => round($collected, 2),
            'handed' => round($handed, 2),
            'net' => round($collected - $handed, 2),
            'in_hand' => self::inHand($collectorId),
            'payments_count' => $count,
        ];
    }

    /**
     * Account statement for one subscriber: bills as debits, payments as
     * credits, oldest first, with a running balance.
     *
     * The closing balance always equals Subscriber::outstanding(), because a
     * bill's total already includes any debt rolled over from earlier periods.
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     totals: array{debit: float, credit: float, closing: float},
     *     rows_shown: int,
     *     rows_total: int
     * }
     */
    public static function statement(Subscriber $subscriber, ?Carbon $from = null): array
    {
        $bills = $subscriber->bills()->get();
        $payments = $subscriber->payments()
            ->with(['collector:id,name', 'allocations.bill:id,number,period'])
            ->get();

        $entries = $bills->map(fn (Bill $bill) => [
            'date' => $bill->created_at,
            'kind' => 'bill',
            'label' => self::periodText($bill->period),
            'description' => self::billDescription($bill),
            'due' => (float) $bill->total,
            'settled' => 0.0,
            'ref' => 'B'.$bill->id,
            'id' => $bill->id,
        ])->concat($payments->map(fn (Payment $payment) => [
            'date' => $payment->created_at,
            'kind' => 'payment',
            'label' => $payment->created_at?->format('Y-m-d'),
            'description' => self::paymentDescription($payment),
            'due' => 0.0,
            'settled' => (float) $payment->amount,
            'ref' => 'P'.$payment->id,
            'id' => $payment->id,
        ]))->values();

        return self::foldEntries($entries, $from, $bills->count() + $payments->count());
    }

    /**
     * Cash ledger for one collector: every amount they took from a customer
     * (debit — it increases what they hold) and every handover to the operator
     * (credit), oldest first, with the running cash-in-hand balance.
     *
     * The closing balance always equals inHand($collector), regardless of $from,
     * because the pre-range movements collapse into the opening balance.
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     totals: array{opening: float, debit: float, credit: float, closing: float},
     *     rows_shown: int,
     *     rows_total: int
     * }
     */
    public static function collectorLedger(User $collector, ?Carbon $from = null): array
    {
        $payments = Payment::query()
            ->where('collector_id', $collector->id)
            ->with([
                'subscriber:id,name,route_id',
                'subscriber.route:id,name',
                'allocations.bill:id,number,period,subscriber_id',
            ])
            ->get();

        $handovers = CashHandover::query()
            ->where('collector_id', $collector->id)
            ->get();

        $entries = $payments->map(function (Payment $payment) {
            $invoices = $payment->allocations
                ->map(fn ($allocation) => $allocation->bill?->number ?: $allocation->bill?->period)
                ->filter()
                ->unique()
                ->values();

            $description = __('col.led_payment', [
                'subscriber' => $payment->subscriber?->name ?? '—',
                'route' => $payment->subscriber?->route?->name ?? __('col.no_route'),
            ]);
            if ($invoices->isNotEmpty()) {
                $description .= ' — '.__('col.led_periods', ['periods' => $invoices->implode('، ')]);
            }

            return [
                'date' => $payment->created_at,
                'kind' => 'payment',
                'label' => $payment->created_at?->format('Y-m-d'),
                'description' => $description,
                'due' => (float) $payment->amount,
                'settled' => 0.0,
                'detail' => $payment->note,
                'ref' => 'P'.$payment->id,
                'id' => $payment->id,
            ];
        })->concat($handovers->map(fn (CashHandover $handover) => [
            'date' => $handover->created_at,
            'kind' => 'handover',
            'label' => $handover->handover_date?->format('Y-m-d'),
            'description' => __('col.led_handover'),
            'due' => 0.0,
            'settled' => (float) $handover->amount,
            'detail' => $handover->note,
            'ref' => 'H'.$handover->id,
            'id' => $handover->id,
        ]))->values();

        return self::foldEntries($entries, $from, $payments->count() + $handovers->count());
    }

    /**
     * Apply the date filter and compute the running balance over $entries.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>, rows_shown: int, rows_total: int}
     */
    private static function foldEntries(Collection $entries, ?Carbon $from, int $rowsTotal): array
    {
        $entries = $entries->sort(fn ($a, $b) => [
            $a['date']?->timestamp ?? 0,
            $a['kind'] === 'handover' ? 1 : 0,
            $a['id'],
        ] <=> [
            $b['date']?->timestamp ?? 0,
            $b['kind'] === 'handover' ? 1 : 0,
            $b['id'],
        ])->values();

        // Opening balance = everything strictly before the requested start date.
        $opening = 0.0;
        if ($from) {
            $opening = (float) $entries
                ->filter(fn ($e) => $e['date'] && $e['date']->lt($from->copy()->startOfDay()))
                ->sum(fn ($e) => $e['due'] - $e['settled']);
            $entries = $entries->filter(fn ($e) => $e['date'] && $e['date']->gte($from->copy()->startOfDay()))->values();
        }

        $balance = round($opening, 2);
        $rows = [];
        foreach ($entries as $entry) {
            $balance = round($balance + $entry['due'] - $entry['settled'], 2);
            $rows[] = [...$entry, 'balance' => $balance];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'opening' => round($opening, 2),
                'debit' => round(array_sum(array_column($rows, 'due')), 2),
                'credit' => round(array_sum(array_column($rows, 'settled')), 2),
                'closing' => $balance,
            ],
            'rows_shown' => count($rows),
            'rows_total' => $rowsTotal,
        ];
    }

    /**
     * 'Payment (Cash USD) — collector: Jad — invoices: INV-...' — the method and the
     * settled invoices belong on every line where money is listed.
     */
    public static function paymentDescription(Payment $payment): string
    {
        $description = __('col.st_payment_desc', [
            'method' => $payment->methodLabel(),
            'collector' => $payment->collector?->name ?? __('col.unknown_collector'),
        ]);

        $invoices = $payment->settledBills()
            ->map(fn (Bill $bill) => $bill->number ?: 'INV-'.$bill->period)
            ->all();

        if ($invoices !== []) {
            $description .= ' — '.__('col.st_payment_for', ['invoices' => implode('، ', $invoices)]);
        }

        return $description;
    }

    private static function billDescription(Bill $bill): string
    {
        $period = self::periodText($bill->period);

        if ($bill->type === Subscriber::TYPE_FLAT) {
            return __('col.st_flat_bill', ['period' => $period]);
        }

        return __('col.st_metered_bill', [
            'period' => $period,
            'consumption' => number_format((float) $bill->consumption, 1),
            'usage' => money($bill->usage_amount),
            'maintenance' => money($bill->maintenance_fee),
        ]);
    }

    /** '2026-09' rendered in the active UI language (month name, Latin digits). */
    public static function periodText(string $period): string
    {
        return app()->isLocale('ar') ? period_label_ar($period) : period_label($period);
    }
}
