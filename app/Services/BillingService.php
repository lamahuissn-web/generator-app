<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Tariff;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public const PERIOD_REGEX = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /** Billing-run outcomes. */
    public const RUN_CREATE = 'create';

    public const RUN_EXISTS = 'exists';

    public const RUN_NO_READING = 'no_reading';

    public const RUN_INACTIVE = 'inactive';

    public const RUN_ZERO = 'zero';

    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    /**
     * kWh price for a period. The ministry tariff changes every cycle, so a
     * tariff row for the period wins; otherwise the global setting applies.
     */
    public static function kwhPrice(?string $period = null): float
    {
        if ($period !== null) {
            $tariff = Tariff::priceFor($period);
            if ($tariff !== null) {
                return $tariff;
            }
        }

        return (float) Setting::get('kwh_price', 0.25);
    }

    /** Where the price for a period comes from — used by the UI. */
    public static function priceSource(string $period): string
    {
        return Tariff::priceFor($period) === null ? 'setting' : 'tariff';
    }

    /** Human-readable bill number: INV-2026-09-0012 (period + subscriber). */
    public static function billNumber(string $period, int $subscriberId): string
    {
        return 'INV-'.$period.'-'.str_pad((string) $subscriberId, 4, '0', STR_PAD_LEFT);
    }

    /** Human-readable receipt number: REC-2026-09-0007 (month + payment id). */
    public static function receiptNumber(CarbonInterface $date, int $paymentId): string
    {
        return 'REC-'.$date->format('Y-m').'-'.str_pad((string) $paymentId, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Metering coverage of a cycle: how many metered subscribers have a reading
     * for $period, overall and per route. Drives the "readings complete"
     * gate on the billing-run page.
     *
     * @return array{total: int, recorded: int, pending: int, routes: array<int, array{id: null|int, name: string, total: int, recorded: int, pending: int}>}
     */
    public static function readingProgress(string $period, ?int $routeId = null): array
    {
        $subscribers = Subscriber::query()
            ->metered()
            ->active()
            ->with(['route:id,name'])
            ->when($routeId, fn ($q) => $q->where('route_id', $routeId))
            ->get();

        $recorded = Reading::query()
            ->where('period', $period)
            ->whereIn('subscriber_id', $subscribers->pluck('id'))
            ->pluck('subscriber_id')
            ->flip();

        $routes = [];
        foreach ($subscribers as $subscriber) {
            $key = $subscriber->route_id ?? 0;
            $routes[$key] ??= [
                'id' => $subscriber->route_id,
                'name' => $subscriber->route?->name ?? __('col.no_route'),
                'total' => 0,
                'recorded' => 0,
                'pending' => 0,
            ];
            $routes[$key]['total']++;
            $recorded->has($subscriber->id) ? $routes[$key]['recorded']++ : $routes[$key]['pending']++;
        }

        usort($routes, fn ($a, $b) => [$b['pending'], $a['name']] <=> [$a['pending'], $b['name']]);

        $total = $subscribers->count();
        $recordedCount = $subscribers->filter(fn ($subscriber) => $recorded->has($subscriber->id))->count();

        return [
            'total' => $total,
            'recorded' => $recordedCount,
            'pending' => $total - $recordedCount,
            'routes' => array_values($routes),
        ];
    }

    /** Global default maintenance fee, or 0 when unset. */
    public static function defaultMaintenanceFee(): float
    {
        return (float) Setting::get('default_maintenance_fee', 0);
    }

    /** Sum of unpaid amounts on bills strictly older than $period. */
    public static function outstandingBefore(Subscriber $subscriber, string $period): float
    {
        return (float) $subscriber->bills()
            ->where('period', '<', $period)
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as o')
            ->value('o');
    }

    /** Latest reading recorded before $period (any gap allowed). */
    public static function latestReadingBefore(Subscriber $subscriber, string $period): ?Reading
    {
        return $subscriber->readings()
            ->where('period', '<', $period)
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Record a meter reading for the period and create the matching bill.
     *
     * @return array{reading: Reading, bill: Bill}
     *
     * @throws \RuntimeException when a reading/bill already exists for that period
     *                           or the new reading is lower than the previous one
     */
    public static function enterMeteredReading(
        Subscriber $subscriber,
        string $period,
        float $currentReading,
        ?int $userId = null,
        ?string $note = null,
    ): array {
        if (! preg_match(self::PERIOD_REGEX, $period)) {
            throw new \RuntimeException("Invalid period format: {$period}");
        }
        if (! $subscriber->isMetered()) {
            throw new \RuntimeException('Flat-rate subscribers have no meter readings.');
        }
        if ($subscriber->readingFor($period)) {
            throw new \RuntimeException('A reading for this period already exists.');
        }

        $previous = self::latestReadingBefore($subscriber, $period);
        $prevValue = (float) ($previous?->current_reading ?? 0);
        if ($currentReading < $prevValue) {
            throw new \RuntimeException('The new reading cannot be lower than the previous one.');
        }

        $consumption = round($currentReading - $prevValue, 2);
        $price = self::kwhPrice($period);
        $usage = round($consumption * $price, 2);
        $maintenance = $subscriber->effectiveMaintenanceFee();
        $prevDebt = self::outstandingBefore($subscriber, $period);
        $total = round($usage + $maintenance, 2);

        return DB::transaction(function () use (
            $subscriber, $period, $currentReading, $prevValue, $consumption,
            $userId, $note, $price, $usage, $maintenance, $prevDebt, $total,
        ) {
            $reading = Reading::create([
                'subscriber_id' => $subscriber->id,
                'period' => $period,
                'current_reading' => $currentReading,
                'previous_reading' => $prevValue,
                'consumption' => $consumption,
                'entered_by' => $userId,
                'note' => $note,
            ]);

            $bill = Bill::create([
                'number' => self::billNumber($period, $subscriber->id),
                'subscriber_id' => $subscriber->id,
                'period' => $period,
                'type' => Subscriber::TYPE_METERED,
                'reading_id' => $reading->id,
                'consumption' => $consumption,
                'kwh_price' => $price,
                'usage_amount' => $usage,
                'maintenance_fee' => $maintenance,
                'flat_amount' => 0,
                'prev_debt' => $prevDebt,
                'total' => $total,
                'paid_amount' => 0,
            ]);

            Audit::log('reading.created', $reading, [
                'period' => $period,
                'current_reading' => $currentReading,
                'consumption' => $consumption,
                'bill_id' => $bill->id,
                'total' => $total,
                'prev_debt' => $prevDebt,
            ]);

            return ['reading' => $reading, 'bill' => $bill];
        });
    }

    /**
     * Preview (default) or commit the monthly billing run for $period for every
     * subscriber, or only the ones on $routeId.
     *
     * Rules (agreed with the operator):
     *  - subscribers already billed for that period are left untouched;
     *  - inactive subscribers are never billed;
     *  - metered subscribers without a reading for the period are skipped, not
     *    billed a zero consumption;
     *  - a bill that would total $0.00 is skipped.
     *
     * `summary` always describes the plan the run found (before writing), while
     * `created` counts what this call actually wrote.
     *
     * @return array{period: string, route_id: ?int, rows: array<int,array<string,mixed>>, summary: array<string,int>, invoiced: float, created: int}
     *
     * @throws \RuntimeException on a malformed period
     */
    public static function periodRun(string $period, ?int $routeId = null, bool $commit = false, ?int $userId = null): array
    {
        if (! preg_match(self::PERIOD_REGEX, $period)) {
            throw new \RuntimeException("Invalid period format: {$period}");
        }

        $subscribers = Subscriber::query()
            ->with(['route:id,name', 'readings' => fn ($q) => $q->where('period', $period)])
            ->when($routeId, fn ($q) => $q->where('route_id', $routeId))
            ->orderBy('route_id')
            ->orderBy('name')
            ->get();

        $rows = $subscribers->map(fn (Subscriber $subscriber) => self::previewRunRow($subscriber, $period))->all();

        $created = 0;
        if ($commit) {
            foreach ($rows as $i => $row) {
                if ($row['state'] !== self::RUN_CREATE) {
                    continue;
                }
                $subscriber = $subscribers->firstWhere('id', $row['subscriber_id']);
                $bill = self::createRunBill($subscriber, $period, $row, $userId);
                $rows[$i]['bill_id'] = $bill->id;
                $created++;
            }
        }

        $summary = array_fill_keys([
            self::RUN_CREATE, self::RUN_EXISTS, self::RUN_NO_READING, self::RUN_INACTIVE, self::RUN_ZERO,
        ], 0);

        $invoiced = 0.0;
        foreach ($rows as $row) {
            $summary[$row['state']]++;
            if (in_array($row['state'], [self::RUN_CREATE, self::RUN_EXISTS], true)) {
                $invoiced += (float) $row['total'];
            }
        }

        return [
            'period' => $period,
            'route_id' => $routeId,
            'rows' => $rows,
            'summary' => $summary,
            'invoiced' => round($invoiced, 2),
            'created' => $created,
        ];
    }

    /** What the run would do for one subscriber — never writes anything. */
    private static function previewRunRow(Subscriber $subscriber, string $period): array
    {
        $existing = $subscriber->bills()->where('period', $period)->first();
        $prevDebt = self::outstandingBefore($subscriber, $period);
        $reading = $subscriber->readings->first();

        $row = [
            'subscriber_id' => $subscriber->id,
            'name' => $subscriber->name,
            'route' => $subscriber->route?->name,
            'type' => $subscriber->type,
            'phone' => $subscriber->phone,
            'meter_no' => $subscriber->meter_no,
            'prev_debt' => $prevDebt,
            'reading' => $reading?->current_reading,
            'reading_prev' => $reading?->previous_reading,
            'consumption' => null,
            'usage' => 0.0,
            'maintenance' => 0.0,
            'flat' => 0.0,
            'total' => 0.0,
            'bill_id' => null,
            'paid_amount' => 0.0,
        ];

        if ($existing) {
            $existing->loadMissing('reading');

            return [...$row, ...[
                'state' => self::RUN_EXISTS,
                'reading' => $existing->reading?->current_reading ?? $existing->consumption,
                'reading_prev' => $existing->reading?->previous_reading,
                'consumption' => $existing->consumption,
                'usage' => (float) $existing->usage_amount,
                'maintenance' => (float) $existing->maintenance_fee,
                'flat' => (float) $existing->flat_amount,
                'total' => (float) $existing->total,
                'paid_amount' => (float) $existing->paid_amount,
                'bill_id' => $existing->id,
            ]];
        }

        if ($subscriber->isMetered()) {
            $consumption = $reading ? (float) $reading->consumption : null;
            $usage = $consumption === null ? 0.0 : round($consumption * self::kwhPrice($period), 2);
            $maintenance = $subscriber->effectiveMaintenanceFee();
        } else {
            $consumption = null;
            $usage = 0.0;
            $maintenance = 0.0;
        }

        $flat = $subscriber->isFlat() ? (float) ($subscriber->flat_fee ?? 0) : 0.0;
        $row['consumption'] = $consumption;
        $row['usage'] = $usage;
        $row['maintenance'] = $maintenance;
        $row['flat'] = $flat;
        $row['total'] = round($usage + $maintenance + $flat, 2);

        $state = match (true) {
            $subscriber->status !== Subscriber::STATUS_ACTIVE => self::RUN_INACTIVE,
            $subscriber->isMetered() && $reading === null => self::RUN_NO_READING,
            $row['total'] <= 0 => self::RUN_ZERO,
            default => self::RUN_CREATE,
        };

        return [...$row, 'state' => $state];
    }

    /** Persist the bill a committed run row describes. */
    private static function createRunBill(Subscriber $subscriber, string $period, array $row, ?int $userId): Bill
    {
        return DB::transaction(function () use ($subscriber, $period, $row, $userId) {
            $isMetered = $subscriber->isMetered();

            $bill = Bill::create([
                'number' => self::billNumber($period, $subscriber->id),
                'subscriber_id' => $subscriber->id,
                'period' => $period,
                'type' => $subscriber->type,
                'reading_id' => $subscriber->readings->first()?->id,
                'consumption' => $isMetered ? $row['consumption'] : null,
                'kwh_price' => $isMetered ? self::kwhPrice($period) : null,
                'usage_amount' => $row['usage'],
                'maintenance_fee' => $row['maintenance'],
                'flat_amount' => $row['flat'],
                'prev_debt' => $row['prev_debt'],
                'total' => $row['total'],
                'paid_amount' => 0,
            ]);

            Audit::log('bill.generated', $bill, [
                'period' => $period,
                'new_total' => $row['total'],
                'prev_debt' => $row['prev_debt'],
                'type' => $subscriber->type,
                'run' => true,
                'by' => $userId,
            ]);

            return $bill;
        });
    }

    /** Create (or return existing) flat-rate bill for the period. */
    public static function ensureFlatBill(Subscriber $subscriber, string $period): Bill
    {
        if (! $subscriber->isFlat()) {
            throw new \RuntimeException('Flat bills only apply to flat-rate subscribers.');
        }

        $existing = $subscriber->bills()->where('period', $period)->first();
        if ($existing) {
            return $existing;
        }

        $flatFee = (float) ($subscriber->flat_fee ?? 0);
        $prevDebt = self::outstandingBefore($subscriber, $period);

        return DB::transaction(function () use ($subscriber, $period, $flatFee, $prevDebt) {
            return Bill::create([
                'number' => self::billNumber($period, $subscriber->id),
                'subscriber_id' => $subscriber->id,
                'period' => $period,
                'type' => Subscriber::TYPE_FLAT,
                'reading_id' => null,
                'consumption' => null,
                'kwh_price' => null,
                'usage_amount' => 0,
                'maintenance_fee' => 0,
                'flat_amount' => $flatFee,
                'prev_debt' => $prevDebt,
                'total' => $flatFee,
                'paid_amount' => 0,
            ]);
        });
    }

    /**
     * Record a cash collection. Flat-rate subscribers get their current-period
     * bill created on the spot. The amount is capped at the outstanding balance
     * and allocated oldest-bill-first (FIFO).
     *
     * @return array{payment: Payment, applied: float, excess: float, remaining: float, bill: ?Bill}
     *
     * @throws \RuntimeException when the subscriber has nothing outstanding
     */
    public static function collect(
        Subscriber $subscriber,
        float $amount,
        ?int $collectorId = null,
        ?string $note = null,
        ?string $period = null,
    ): array {
        $period ??= self::currentPeriod();
        $amount = round(max(0, (float) $amount), 2);
        $bill = null;

        if ($subscriber->isFlat()) {
            $bill = self::ensureFlatBill($subscriber, $period);
        }

        $outstanding = $subscriber->outstanding();
        $applied = min($amount, $outstanding);
        $excess = round($amount - $applied, 2);

        if ($applied <= 0) {
            throw new \RuntimeException('This subscriber has no outstanding balance to collect.');
        }

        return DB::transaction(function () use ($subscriber, $collectorId, $applied, $note, $excess, $outstanding) {
            $payment = Payment::create([
                'subscriber_id' => $subscriber->id,
                'collector_id' => $collectorId,
                'amount' => $applied,
                'method' => 'cash',
                'note' => $note,
            ]);

            // Receipt number mirrors the invoice series (REC-2026-09-0007).
            $payment->forceFill([
                'number' => self::receiptNumber($payment->created_at ?? now(), $payment->id),
            ])->save();

            $remainingToAllocate = $applied;
            foreach ($subscriber->unpaidBills() as $unpaidBill) {
                if ($remainingToAllocate <= 0) {
                    break;
                }
                $take = min($remainingToAllocate, $unpaidBill->unpaidAmount());
                if ($take <= 0) {
                    continue;
                }
                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'bill_id' => $unpaidBill->id,
                    'amount' => $take,
                ]);
                $unpaidBill->increment('paid_amount', $take);
                $remainingToAllocate = round($remainingToAllocate - $take, 2);
            }

            Audit::log('payment.created', $payment, [
                'subscriber_id' => $subscriber->id,
                'applied' => $applied,
                'excess' => $excess,
                'remaining' => round($outstanding - $applied, 2),
                'note' => $note,
            ]);

            return [
                'payment' => $payment,
                'applied' => $applied,
                'excess' => $excess,
                'remaining' => round($outstanding - $applied, 2),
            ];
        });
    }

    /**
     * Reverse a mistaken payment: restore every allocated amount to its bill,
     * then delete the payment. Fully audited.
     *
     * @throws \RuntimeException when the payment no longer exists
     */
    public static function reversePayment(Payment $payment): void
    {
        $payment->loadMissing('allocations');

        DB::transaction(function () use ($payment) {
            foreach ($payment->allocations as $allocation) {
                $bill = Bill::find($allocation->bill_id);
                if ($bill) {
                    $bill->decrement('paid_amount', (float) $allocation->amount);
                }
                $allocation->delete();
            }

            Audit::log('payment.reversed', $payment, [
                'subscriber_id' => $payment->subscriber_id,
                'amount' => (float) $payment->amount,
                'collector_id' => $payment->collector_id,
                'note' => $payment->note,
            ]);

            $payment->delete();
        });
    }

    /**
     * Correction: delete a mistaken meter reading and its bill. Only allowed
     * while the generated bill has NOT been (partially) paid — otherwise the
     * collector/admin must reverse the related payments first.
     *
     * @throws \RuntimeException when the reading has no bill, or its bill was paid
     */
    public static function deleteReadingAndBill(Reading $reading): void
    {
        $bill = Bill::where('reading_id', $reading->id)->first();

        if (! $bill) {
            throw new \RuntimeException('No bill found for this reading.');
        }
        if ((float) $bill->paid_amount > 0) {
            throw new \RuntimeException(
                'This bill has been paid ('.money($bill->paid_amount).'). Reverse the related payments before deleting the reading.'
            );
        }

        $snapshot = [
            'subscriber_id' => $reading->subscriber_id,
            'period' => $reading->period,
            'current_reading' => $reading->current_reading,
            'consumption' => $reading->consumption,
            'bill_id' => $bill->id,
            'bill_total' => $bill->total,
        ];

        DB::transaction(function () use ($reading, $bill, $snapshot) {
            Audit::log('reading.deleted', $reading, $snapshot);
            $bill->delete(); // cascades nothing here (unpaid)
            $reading->delete();
        });
    }

    /**
     * Correction: delete a mistaken flat-rate bill (no reading involved).
     * Guarded the same way — only while unpaid.
     *
     * @throws \RuntimeException when the bill is not flat or has been paid
     */
    public static function deleteFlatBill(Bill $bill): void
    {
        if ($bill->type !== Subscriber::TYPE_FLAT) {
            throw new \RuntimeException('Metered bills are deleted through their reading.');
        }
        if ((float) $bill->paid_amount > 0) {
            throw new \RuntimeException(
                'This bill has been paid ('.money($bill->paid_amount).'). Reverse the related payments before deleting it.'
            );
        }

        $snapshot = [
            'subscriber_id' => $bill->subscriber_id,
            'period' => $bill->period,
            'flat_amount' => $bill->flat_amount,
            'total' => $bill->total,
        ];

        DB::transaction(function () use ($bill, $snapshot) {
            Audit::log('bill.deleted', $bill, $snapshot);
            $bill->delete();
        });
    }
}
