<?php

namespace App\Services;

use App\Models\AuditLog;
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
            ->collectedInRange($from->toDateString(), $to->toDateString());
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
    /**
     * What the collector still OWES THE OFFICE — every method, cash and electronic alike.
     *
     * The office's own rule, in its own words: «ما بهمني شو قبض الجابي سواء كاش او الكتروني، بس
     * يحاسب المكتب». A collector is accountable for the money he collected, not for the form it
     * arrived in: how the subscriber paid is a fact about the receipt, and it stays recorded there,
     * while the figure the office settles against is the total he has not handed over yet.
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

    /**
     * When each collector last handed cash over: [collector id => ['date' => 'Y-m-d', 'amount' => …]].
     *
     * The collectors table needs it to say "آخر تسليم — منذ ٥ أيام", which is the half of the question
     * «مين بدنا نستلم منو؟» that the in-hand figure alone cannot answer: two collectors can hold the
     * same amount and only one of them has been holding it for a fortnight.
     *
     * @return array<int, array{date: string, amount: float}>
     */
    public static function lastHandoverByCollector(): array
    {
        return CashHandover::query()
            ->selectRaw('collector_id, MAX(handover_date) as last_date')
            ->groupBy('collector_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->collector_id => [
                'date' => Carbon::parse((string) $row->last_date)->toDateString(),
                'amount' => (float) CashHandover::query()
                    ->where('collector_id', (int) $row->collector_id)
                    ->where('handover_date', $row->last_date)
                    ->sum('amount'),
            ]])
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> one row per route */
    public static function byRoute(Carbon $from, Carbon $to, ?int $collectorId = null): Collection
    {
        $rows = Payment::query()
            ->when($collectorId, fn ($q) => $q->where('collector_id', $collectorId))
            ->join('subscribers as s', 's.id', '=', 'payments.subscriber_id')
            ->leftJoin('routes as r', 'r.id', '=', 's.route_id')
            ->collectedInRange($from->toDateString(), $to->toDateString())
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
            ->selectRaw('DATE(COALESCE(collected_on, created_at)) as day, COUNT(*) as payments_count, COALESCE(SUM(amount), 0) as collected')
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
     * `description` stays the whole sentence it always was — screens and tests read it — and the
     * consumer-facing print view uses the extra display-only keys (`invoice_no` on a bill line,
     * `method`, `collector_name` and `invoices` on a payment line) to lay the same facts out in
     * columns instead of one run-on sentence. None of them feeds the arithmetic.
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     totals: array{opening: float, debit: float, credit: float, closing: float},
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
            'invoice_no' => self::invoiceNumber($bill),
        ])->concat($payments->map(fn (Payment $payment) => [
            'date' => $payment->collectedOn(),
            'kind' => 'payment',
            'label' => $payment->collectedOn()->format('Y-m-d'),
            'description' => self::paymentDescription($payment),
            'due' => 0.0,
            'settled' => (float) $payment->amount,
            'ref' => 'P'.$payment->id,
            'id' => $payment->id,
            'method' => $payment->methodLabel(),
            'collector_name' => $payment->collector?->name ?? __('col.unknown_collector'),
            'invoices' => self::settledInvoiceNumbers($payment),
        ]))->values();

        $statement = self::foldEntries($entries, $from, $bills->count() + $payments->count());

        // His own money, waiting with the office. The statement's own arithmetic (bills − payments)
        // cannot show it: a receipt now records the CASH that changed hands, so the part of it that
        // settled nothing is a balance of his rather than a payment (BillingService::accrueCredit).
        $statement['totals']['held'] = round(max(0, (float) $subscriber->credit), 2);

        return $statement;
    }

    /** The invoice number a bill is known by, falling back to its period. */
    public static function invoiceNumber(Bill $bill): string
    {
        return $bill->number ?: 'INV-'.$bill->period;
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
                'date' => $payment->collectedOn(),
                'kind' => 'payment',
                'label' => $payment->collectedOn()->format('Y-m-d'),
                'description' => $description,
                'due' => (float) $payment->amount,
                'settled' => 0.0,
                'detail' => $payment->note,
                'ref' => 'P'.$payment->id,
                'id' => $payment->id,
            ];
        })->concat($handovers->map(fn (CashHandover $handover) => [
            // The day the cash changed hands, not the moment somebody typed it in. A settlement
            // entered late for the 5th belongs to the 5th — the period's «سلّم» figure already counts
            // it there, and a ledger that filed it under today would contradict the row above it.
            'date' => $handover->handover_date?->copy()->startOfDay() ?? $handover->created_at,
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
     * What the collector actually took from subscribers in the range, one row per receipt, grouped
     * by the day he went out.
     *
     * This is the auditing view the office asked for — «اعرف متي الجابي اخذ مال من الزبون» — so a row
     * carries the moment (date AND time, not just the day), the receipt, who paid (with the meter or
     * the flat subscription), the area, the amount, the method with its second-currency detail, WHICH
     * invoice that money settled, the collector's own note, and who recorded it.
     *
     * The recorder is read from the audit log on purpose: a receipt entered by the collector in the
     * field and one entered at the office are not the same fact, and the operator has to be able to
     * tell them apart. When the trail has no actor it says «غير مسجّل» — a money screen may not guess.
     *
     * @return array{days: array<int, array<string, mixed>>, rows_total: int, total: float}
     */
    public static function collectorDocuments(
        User $collector,
        Carbon $from,
        Carbon $to,
        ?string $method = null,
        ?string $search = null,
    ): array {
        $term = $search !== null && trim($search) !== '' ? '%'.trim($search).'%' : null;

        $payments = Payment::query()
            ->where('collector_id', $collector->id)
            ->collectedInRange($from->toDateString(), $to->toDateString())
            ->when($method, fn ($q) => $q->where('method', $method))
            ->when($term, fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', $term)
                ->orWhereHas('subscriber', fn ($s) => $s->where('name', 'like', $term))))
            ->with([
                'subscriber:id,name,meter_no,route_id',
                'subscriber.route:id,name',
                'allocations.bill:id,number,period',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $recorders = self::recordersFor('payment', $payments->pluck('id')->all());

        $days = [];
        foreach ($payments as $payment) {
            $day = $payment->collectedOn()->format('Y-m-d');
            $days[$day] ??= ['day' => $day, 'rows' => [], 'total' => 0.0, 'count' => 0];

            $recorder = $recorders[$payment->id] ?? null;

            $days[$day]['rows'][] = [
                'id' => $payment->id,
                'ref' => 'P'.$payment->id,
                'number' => $payment->number,
                'at' => $payment->collectedOn(),
                // The clock time belongs to the collection only when it was entered that same day;
                // for a receipt written in this morning for the 31st, the date column says the truth
                // and a time beside it would contradict it.
                'time' => $payment->collectedOn()->isSameDay($payment->created_at) ? $payment->created_at?->format('H:i') : null,
                'subscriber' => $payment->subscriber?->name ?? '—',
                'meter_no' => $payment->subscriber?->meter_no,
                'route' => $payment->subscriber?->route?->name ?: __('col.no_route'),
                'amount' => (float) $payment->amount,
                'method' => $payment->methodLabel(),
                'method_key' => $payment->method,
                'lira' => $payment->lbp_amount
                    ? __('col.lira_detail', [
                        'amount' => number_format((float) $payment->lbp_amount),
                        'rate' => number_format((float) $payment->lbp_rate),
                    ])
                    : null,
                'invoices' => $payment->allocations
                    ->map(fn ($allocation) => $allocation->bill?->number ?: $allocation->bill?->period)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'note' => $payment->note,
                'recorder' => $recorder,
                'recorder_label' => self::recorderLabel($recorder, $collector),
            ];

            $days[$day]['total'] = round($days[$day]['total'] + (float) $payment->amount, 2);
            $days[$day]['count']++;
        }

        return [
            'days' => array_values($days),
            'rows_total' => $payments->count(),
            'total' => round((float) $payments->sum('amount'), 2),
        ];
    }

    /**
     * The append-only record of one collector's money: every event the program logged, to the
     * second, with the balance AFTER it.
     *
     * Read from the audit log rather than from the live movements, because the log is the only place
     * that still knows about a receipt that was reversed or a settlement that was deleted. A money
     * screen whose history can be edited away is not auditable, which is the whole point.
     *
     * Movements with no audit row (imported or seeded data) are listed too, marked «غير مسجّل»:
     * dropping them would make the closing balance disagree with the cash in hand, and that is the
     * one number the operator counts in the drawer. For the same reason the closing figure is the
     * invariant of this method — it must equal inHand(), whatever the log does or does not hold.
     *
     * Deltas: a receipt raises what he holds, a settlement lowers it, an EDITED settlement moves it
     * by the difference, a deleted settlement hands the amount back, and a voided receipt takes it
     * away — so a receipt that was created and then reversed ends at zero, not at a phantom debt.
     *
     * @return array{rows: array<int, array<string, mixed>>, closing: float, rows_total: int, recorded: int, unrecorded: int}
     */
    public static function collectorAuditTrail(User $collector, int $limit = 300): array
    {
        $payments = Payment::query()
            ->where('collector_id', $collector->id)
            ->with('subscriber:id,name')
            ->get();

        $handovers = CashHandover::query()->where('collector_id', $collector->id)->get();

        $paymentIds = $payments->pluck('id')->map(fn ($id) => (int) $id)->all();
        $handoverIds = $handovers->pluck('id')->map(fn ($id) => (int) $id)->all();

        // A settlement carries the collector in its payload. A receipt does not — unless it was
        // reversed — so the collector is resolved through the payment, and a receipt whose created
        // row names a payment that no longer exists is matched through that payment's own reversal.
        $reversedIds = AuditLog::query()
            ->where('action', 'payment.reversed')
            ->get()
            ->filter(fn (AuditLog $log) => (int) ($log->payload['collector_id'] ?? 0) === $collector->id)
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $logs = AuditLog::query()
            ->whereIn('action', ['payment.created', 'payment.reversed', 'handover.created', 'handover.updated', 'handover.deleted'])
            ->with('actor:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(function (AuditLog $log) use ($collector, $paymentIds, $handoverIds, $reversedIds) {
                $subjectId = (int) $log->subject_id;
                $payloadCollector = (int) ($log->payload['collector_id'] ?? 0);

                if (str_starts_with($log->action, 'handover.')) {
                    return $payloadCollector === $collector->id || in_array($subjectId, $handoverIds, true);
                }

                return $payloadCollector === $collector->id
                    || in_array($subjectId, $paymentIds, true)
                    || in_array($subjectId, $reversedIds, true);
            })
            ->values();

        // An edit changes the amount rather than adding a movement; the created line has to start at
        // the ORIGINAL amount and let the edit carry the difference, or the row is counted twice.
        $handoverBefore = [];
        foreach ($logs as $log) {
            if ($log->action !== 'handover.updated') {
                continue;
            }

            $ref = 'H'.(int) $log->subject_id;
            $handoverBefore[$ref] ??= (float) ($log->payload['before']['amount'] ?? 0);
        }

        $published = array_fill_keys(array_merge(
            array_map(fn (int $id) => 'P'.$id, $paymentIds),
            array_map(fn (int $id) => 'H'.$id, $handoverIds),
        ), false);

        $rows = [];
        foreach ($logs as $log) {
            $payload = $log->payload ?? [];
            $subjectId = (int) $log->subject_id;
            $isHandover = str_starts_with($log->action, 'handover.');
            $ref = ($isHandover ? 'H' : 'P').$subjectId;
            $actor = $log->actor?->name;

            switch ($log->action) {
                case 'payment.created':
                    $live = $payments->firstWhere('id', $subjectId);

                    if ($live) {
                        $published[$ref] = true;
                        $rows[] = self::trailLine('payment', $log->created_at, $live->number, [
                            'subscriber' => $live->subscriber?->name,
                            'amount' => (float) $live->amount,
                            'delta' => (float) $live->amount,
                            'note' => $live->note,
                            'actor' => $actor,
                        ]);
                    } else {
                        // The receipt itself is gone (reversed) — the applied part plus the excess is
                        // what it had put into his hand at the time.
                        $amount = round((float) ($payload['applied'] ?? 0) + (float) ($payload['excess'] ?? 0), 2);
                        $rows[] = self::trailLine('payment', $log->created_at, null, [
                            'subscriber' => Subscriber::whereKey((int) ($payload['subscriber_id'] ?? 0))->value('name'),
                            'amount' => $amount,
                            'delta' => $amount,
                            'note' => $payload['note'] ?? null,
                            'actor' => $actor,
                        ]);
                    }
                    break;

                case 'payment.reversed':
                    $amount = round((float) ($payload['amount'] ?? 0), 2);
                    $rows[] = self::trailLine('void', $log->created_at, null, [
                        'subscriber' => Subscriber::whereKey((int) ($payload['subscriber_id'] ?? 0))->value('name'),
                        'amount' => $amount,
                        'delta' => -$amount,
                        'note' => $payload['note'] ?? null,
                        'actor' => $actor,
                    ]);
                    break;

                case 'handover.created':
                    $live = $handovers->firstWhere('id', $subjectId);
                    $amount = $handoverBefore[$ref] ?? (float) ($live?->amount ?? $payload['amount'] ?? 0);

                    if ($live) {
                        $published[$ref] = true;
                    }

                    $rows[] = self::trailLine('handover', $log->created_at, null, [
                        'amount' => round($amount, 2),
                        'delta' => -round($amount, 2),
                        'note' => $live?->note ?? $payload['note'] ?? null,
                        'actor' => $actor,
                        'balance_recorded' => $payload['in_hand_after'] ?? null,
                    ]);
                    break;

                case 'handover.updated':
                    $before = (float) ($payload['before']['amount'] ?? 0);
                    $after = (float) ($payload['after']['amount'] ?? 0);
                    $rows[] = self::trailLine('edit', $log->created_at, null, [
                        'amount' => round($after, 2),
                        'delta' => -round($after - $before, 2),
                        'note' => $payload['after']['handover_date'] ?? null,
                        'actor' => $actor,
                        'balance_recorded' => null,
                        'replaced' => round($before, 2),
                    ]);
                    break;

                case 'handover.deleted':
                    $amount = round((float) ($payload['amount'] ?? 0), 2);
                    $rows[] = self::trailLine('delete', $log->created_at, null, [
                        'amount' => $amount,
                        'delta' => $amount,
                        'note' => $payload['note'] ?? null,
                        'actor' => $actor,
                        'balance_recorded' => null,
                    ]);
                    break;
            }
        }

        // Everything the log never saw — imported, seeded, or written before the trail existed.
        foreach ($payments as $payment) {
            if ($published['P'.$payment->id] ?? false) {
                continue;
            }

            $rows[] = self::trailLine('payment', $payment->created_at, $payment->number, [
                'subscriber' => $payment->subscriber?->name,
                'amount' => (float) $payment->amount,
                'delta' => (float) $payment->amount,
                'note' => $payment->note,
                'actor' => null,
            ]);
        }

        foreach ($handovers as $handover) {
            if ($published['H'.$handover->id] ?? false) {
                continue;
            }

            $rows[] = self::trailLine('handover', $handover->created_at, null, [
                'amount' => (float) $handover->amount,
                'delta' => -(float) $handover->amount,
                'note' => $handover->note,
                'actor' => null,
                'balance_recorded' => null,
            ]);
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['seq'] = $index;
        }

        usort($rows, fn ($a, $b) => [$a['at']?->timestamp ?? 0, $a['order'], $a['seq']]
            <=> [$b['at']?->timestamp ?? 0, $b['order'], $b['seq']]);

        $balance = 0.0;
        foreach ($rows as $index => $row) {
            // A balance the log recorded is taken as-is; otherwise it is folded from the movements,
            // which keeps the closing figure honest even for events whose record is gone.
            $balance = round($balance + $row['delta'], 2);
            $rows[$index]['balance'] = $row['balance_recorded'] ?? $balance;
        }

        $closing = round($balance, 2);
        $recorded = count(array_filter($rows, fn ($row) => $row['actor'] !== null));
        $total = count($rows);

        return [
            'rows' => array_slice(array_reverse($rows), 0, $limit),
            'closing' => $closing,
            'rows_total' => $total,
            'recorded' => $recorded,
            'unrecorded' => $total - $recorded,
        ];
    }

    /**
     * One line of the audit trail.
     *
     * `order` keeps the sort stable when two events share a timestamp: a receipt or a settlement
     * lands before the edit or the deletion that followed it in the same second.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function trailLine(string $kind, ?Carbon $at, ?string $number, array $data): array
    {
        return [
            'kind' => $kind,
            'at' => $at,
            'number' => $number,
            'detail' => $data['subscriber'] ?? null,
            'amount' => round((float) ($data['amount'] ?? 0), 2),
            'delta' => round((float) ($data['delta'] ?? 0), 2),
            'note' => $data['note'] ?? null,
            'actor' => $data['actor'] ?? null,
            'balance_recorded' => $data['balance_recorded'] ?? null,
            'replaced' => $data['replaced'] ?? null,
            'order' => in_array($kind, ['payment', 'handover'], true) ? 0 : 1,
            'seq' => 0,
        ];
    }

    /**
     * Who recorded each of these subjects, from the audit log: [subject id => User].
     *
     * @param  array<int, int>  $ids
     * @return array<int, User>
     */
    private static function recordersFor(string $prefix, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return AuditLog::query()
            ->where('action', $prefix.'.created')
            ->whereIn('subject_id', $ids)
            ->with('actor:id,name')
            ->get()
            ->filter(fn (AuditLog $log) => $log->actor !== null)
            ->mapWithKeys(fn (AuditLog $log) => [(int) $log->subject_id => $log->actor])
            ->all();
    }

    /** «الجابي بالميدان» / «المكتب (اسم)» / «غير مسجّل» — never a guess. */
    private static function recorderLabel(?User $actor, User $collector): string
    {
        if ($actor === null) {
            return __('col.recorder_unknown');
        }

        return (int) $actor->id === (int) $collector->id
            ? __('col.recorder_collector')
            : __('col.recorder_office', ['name' => $actor->name]);
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

        $invoices = self::settledInvoiceNumbers($payment);

        if ($invoices !== []) {
            $description .= ' — '.__('col.st_payment_for', ['invoices' => implode('، ', $invoices)]);
        }

        return $description;
    }

    /**
     * The invoice numbers a payment settled, oldest first.
     *
     * @return array<int, string>
     */
    public static function settledInvoiceNumbers(Payment $payment): array
    {
        return $payment->settledBills()
            ->map(fn (Bill $bill) => self::invoiceNumber($bill))
            ->all();
    }

    private static function billDescription(Bill $bill): string
    {
        $period = self::periodText($bill->period);

        if ($bill->isSpecial()) {
            return __('col.st_special_bill', [
                'title' => $bill->title ?: __('spc.default_title'),
                'number' => $bill->number ?: '#'.$bill->id,
            ]);
        }

        if ($bill->type === Subscriber::TYPE_FLAT) {
            return __('col.st_flat_bill', ['period' => $period]);
        }

        // The two amounts sit inside Arabic prose, so they are isolated: without it the bidi
        // algorithm strands the "$" on the far side of the digits and the printed line reads
        // "(9.00$)" instead of "($9.00)".
        return __('col.st_metered_bill', [
            'period' => $period,
            'consumption' => number_format((float) $bill->consumption, 1),
            'usage' => money_ltr($bill->usage_amount),
            'maintenance' => money_ltr($bill->maintenance_fee),
        ]);
    }

    /** '2026-09' rendered in the active UI language (month name, Latin digits). */
    public static function periodText(string $period): string
    {
        return app()->isLocale('ar') ? period_label_ar($period) : period_label($period);
    }
}
