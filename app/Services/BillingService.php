<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Tariff;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
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

    /** The meter value this cycle starts from (the previous reading, or 0 for the very first). */
    public static function previousValue(Subscriber $subscriber, string $period): float
    {
        return (float) (self::latestReadingBefore($subscriber, $period)?->current_reading ?? 0);
    }

    /**
     * Average consumption of the last few REAL readings — what to bill when the meter is
     * dead and nobody can supply a number. Baseline rows (0 kWh, nothing charged) and
     * earlier estimates are skipped so they cannot drag the average down.
     *
     * @return array{kwh: float, count: int, periods: array<int,string>}|null null without usable history
     */
    public static function suggestedConsumption(Subscriber $subscriber, string $period, int $sample = 3): ?array
    {
        $rows = $subscriber->readings()
            ->where('period', '<', $period)
            ->where('is_estimated', false)
            ->where('consumption', '>', 0)
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->limit($sample)
            ->get(['period', 'consumption']);

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'kwh' => round((float) $rows->avg('consumption'), 1),
            'count' => $rows->count(),
            'periods' => $rows->pluck('period')->all(),
        ];
    }

    /**
     * Record a meter reading for the period and create the matching bill.
     *
     * @param  array{estimated?: bool, estimate_reason?: ?string, estimate_extension?: bool, swap?: array{old_meter_no?: ?string, old_reading: float, new_meter_no: string, new_start?: float}}  $options
     * @return array{reading: Reading, bill: Bill}
     *
     * @throws \RuntimeException when a reading/bill already exists for that period
     *                           or a value is lower than the one it continues from
     */
    public static function enterMeteredReading(
        Subscriber $subscriber,
        string $period,
        float $currentReading,
        ?int $userId = null,
        ?string $note = null,
        array $options = [],
    ): array {
        if (! preg_match(self::PERIOD_REGEX, $period)) {
            throw new \RuntimeException(__('refuse.period_format', ['period' => $period]));
        }
        if (! $subscriber->isMetered()) {
            throw new \RuntimeException(__('refuse.flat_has_no_readings'));
        }
        if ($subscriber->readingFor($period)) {
            throw new \RuntimeException(__('refuse.reading_exists'));
        }

        $estimated = (bool) ($options['estimated'] ?? false);
        $estimateReason = $options['estimate_reason'] ?? null;
        $extension = (bool) ($options['estimate_extension'] ?? false);
        $swap = $options['swap'] ?? null;

        // The dead-meter policy: one estimated cycle per report.
        //
        // Refused here rather than only in the form, because the estimate is reachable from
        // three screens and a rule that lives in one of them is a rule the others break.
        $caseOpen = $subscriber->hasBrokenMeterReport();

        if ($estimated && $caseOpen && ! $subscriber->mayEstimateAgain() && ! $extension) {
            throw new \RuntimeException(__('refuse.estimate_policy', [
                'since' => period_label_i18n($subscriber->meter_broken_reported_at->format('Y-m')),
                'used' => $subscriber->brokenEstimatesUsed(),
            ]));
        }

        $previous = self::latestReadingBefore($subscriber, $period);
        $prevValue = (float) ($previous?->current_reading ?? 0);

        if ($swap) {
            // A replacement splits the cycle over two devices: the old meter's final
            // stretch plus whatever the new meter has already run up.
            $oldReading = (float) $swap['old_reading'];
            $newStart = (float) ($swap['new_start'] ?? 0);
            if ($oldReading < $prevValue) {
                throw new \RuntimeException(__('refuse.old_meter_lower'));
            }
            if ($currentReading < $newStart) {
                throw new \RuntimeException(__('refuse.new_meter_lower_than_start'));
            }
            $consumption = round(($oldReading - $prevValue) + ($currentReading - $newStart), 2);
        } else {
            if ($currentReading < $prevValue) {
                throw new \RuntimeException(__('refuse.reading_lower'));
            }
            $consumption = round($currentReading - $prevValue, 2);
        }

        $price = self::kwhPrice($period);
        $usage = round($consumption * $price, 2);
        $maintenance = $subscriber->effectiveMaintenanceFee();
        $prevDebt = self::outstandingBefore($subscriber, $period);
        $total = round($usage + $maintenance, 2);

        return DB::transaction(function () use (
            $subscriber, $period, $currentReading, $prevValue, $consumption,
            $userId, $note, $price, $usage, $maintenance, $prevDebt, $total,
            $estimated, $estimateReason, $swap, $caseOpen, $extension,
        ) {
            $reading = Reading::create([
                'subscriber_id' => $subscriber->id,
                'period' => $period,
                'current_reading' => $currentReading,
                'previous_reading' => $prevValue,
                'consumption' => $consumption,
                'entered_by' => $userId,
                'note' => $note,
                'is_estimated' => $estimated,
                'estimate_reason' => $estimated ? $estimateReason : null,
                'old_meter_no' => $swap['old_meter_no'] ?? null,
                'old_meter_reading' => $swap['old_reading'] ?? null,
                'new_meter_no' => $swap['new_meter_no'] ?? null,
                'new_meter_start' => $swap['new_start'] ?? null,
            ]);

            if ($swap) {
                // The device physically changed: later cycles continue on the new one.
                $subscriber->forceFill(['meter_no' => $swap['new_meter_no']])->save();
            }

            if ($estimated) {
                // The estimate answers this cycle, not the meter: the case stays open and the
                // count of estimated months climbs until somebody decides about the device.
                $subscriber->recordBrokenEstimate();
            } elseif ($swap || $subscriber->hasBrokenMeterReport()) {
                // A replaced device, or a real reading that shows the meter working, is what
                // closes a dead-meter case — the two things that are evidence about the meter
                // itself rather than about one month of it.
                $subscriber->clearMeterReport();
            }

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
                'estimated' => $estimated,
                'estimate_reason' => $estimated ? $estimateReason : null,
                // What the office did about a dead meter, and under which policy: a second
                // estimated month is the thing this trail exists to be able to explain.
                'meter_case_open' => $caseOpen,
                'estimate_extension' => $estimated && $extension,
                'estimates_used' => $estimated ? $subscriber->fresh()->brokenEstimatesUsed() : null,
                'meter_swap' => $swap ? [
                    'old_meter_no' => $swap['old_meter_no'] ?? null,
                    'old_reading' => (float) $swap['old_reading'],
                    'new_meter_no' => $swap['new_meter_no'],
                    'new_start' => (float) ($swap['new_start'] ?? 0),
                ] : null,
            ]);

            return ['reading' => $reading, 'bill' => $bill];
        });
    }

    /**
     * Bill a cycle off an estimate because the meter is dead. The operator supplies the
     * kWh (0 = maintenance only); the stored meter value becomes previous + kWh, and
     * there is NO catch-up later — the estimate becomes the new baseline.
     *
     * $extension is the office's deliberate exception to the one-estimate-per-case policy: it is
     * refused by enterMeteredReading unless stated explicitly, so a second estimated month can
     * only ever be a decision somebody made and the audit trail can point at.
     *
     * @return array{reading: Reading, bill: Bill}
     */
    public static function enterEstimatedReading(
        Subscriber $subscriber,
        string $period,
        float $kwh,
        ?string $reason = null,
        ?int $userId = null,
        bool $extension = false,
    ): array {
        $kwh = round(max(0, $kwh), 2);

        return self::enterMeteredReading(
            $subscriber,
            $period,
            round(self::previousValue($subscriber, $period) + $kwh, 2),
            $userId,
            $reason,
            ['estimated' => true, 'estimate_reason' => $reason, 'estimate_extension' => $extension],
        );
    }

    /**
     * A starting point that charges nothing — used when a subscriber gets a meter (or
     * gets one back after a broken stretch) so the next cycle measures from there
     * instead of billing the whole meter value as consumption.
     */
    public static function recordBaselineReading(
        Subscriber $subscriber,
        string $period,
        float $value,
        ?int $userId = null,
        ?string $note = null,
    ): Reading {
        if ($subscriber->readingFor($period)) {
            throw new \RuntimeException(__('refuse.reading_exists'));
        }

        $reading = Reading::create([
            'subscriber_id' => $subscriber->id,
            'period' => $period,
            'current_reading' => round($value, 2),
            'previous_reading' => 0,
            'consumption' => 0,
            'entered_by' => $userId,
            'note' => $note ?? __('rdg.baseline_note'),
        ]);

        Audit::log('reading.baseline', $reading, [
            'period' => $period,
            'value' => round($value, 2),
        ]);

        return $reading;
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
            throw new \RuntimeException(__('refuse.period_format', ['period' => $period]));
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
        // Special invoices are not the subscription bill: ignore them here, or one
        // October part would stop October's subscription bill from ever being raised.
        $existing = $subscriber->bills()
            ->where('period', $period)
            ->where('type', '!=', Bill::TYPE_SPECIAL)
            ->first();
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
            throw new \RuntimeException(__('refuse.flat_bill_only_for_flat'));
        }

        // Special invoices are not the subscription bill: ignore them here, or one
        // October part would stop October's subscription bill from ever being raised.
        $existing = $subscriber->bills()
            ->where('period', $period)
            ->where('type', '!=', Bill::TYPE_SPECIAL)
            ->first();
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
     * Record a collection. Flat-rate subscribers get their current-period bill
     * created on the spot. The amount is capped at the outstanding balance and
     * allocated oldest-bill-first (FIFO). $method records how the money arrived
     * (cash USD/LBP, Whish, OMT) — each method is its own cash box.
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
        string $method = Payment::METHOD_CASH_USD,
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
            throw new \RuntimeException(__('refuse.nothing_to_collect'));
        }

        return DB::transaction(function () use ($subscriber, $collectorId, $applied, $note, $excess, $outstanding, $method) {
            $payment = Payment::create([
                'subscriber_id' => $subscriber->id,
                'collector_id' => $collectorId,
                'amount' => $applied,
                'method' => $method,
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
                'method' => $method,
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
     * Correction: remove a reading and the bill it produced.
     *
     * $reason is required for a CLOSED cycle (see CycleLock): the reading being removed is part of a
     * month that was already invoiced and collected against, and "why" is the whole difference
     * between a correction and a loss. An open cycle needs no ceremony — it is the operator's own
     * day's work.
     *
     * @throws \RuntimeException when the bill is paid, when the cycle is closed and the correction
     *                           is not covered by an admin's written reason, or when there is no bill
     */
    public static function deleteReadingAndBill(Reading $reading, ?string $reason = null): void
    {
        $bill = Bill::where('reading_id', $reading->id)->first();

        if (! $bill) {
            throw new \RuntimeException(__('refuse.no_bill_for_reading'));
        }
        if ((float) $bill->paid_amount > 0) {
            throw new \RuntimeException(
                __('refuse.bill_paid_delete_reading', ['amount' => money_ltr((float) $bill->paid_amount)])
            );
        }

        // The closed-cycle gate. Last line of defence: the screens ask for the reason, but a
        // correction must not be reachable by any path that forgets to.
        if ($refusal = CycleLock::refusal($reading->period, $reason, auth()->user())) {
            throw new \RuntimeException($refusal);
        }

        $snapshot = [
            'subscriber_id' => $reading->subscriber_id,
            'period' => $reading->period,
            'current_reading' => $reading->current_reading,
            'consumption' => $reading->consumption,
            'bill_id' => $bill->id,
            'bill_total' => $bill->total,
            // Why a month that was already closed was opened again, and by whom.
            'cycle_closed' => CycleLock::isClosed($reading->period),
            'reason' => $reason,
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
            throw new \RuntimeException(__('refuse.metered_bill_delete_via_reading'));
        }
        if ((float) $bill->paid_amount > 0) {
            throw new \RuntimeException(
                __('refuse.bill_paid_delete', ['amount' => money_ltr((float) $bill->paid_amount)])
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

    /**
     * A special invoice: itemized charges outside the subscription (a part, a new meter,
     * a technician's fee). It lands on the subscriber's balance like any other bill, so
     * FIFO collection clears it with no special-case payment logic.
     *
     * prev_debt stays informational (it is NOT added to the total), exactly like the
     * subscription bills, and the statement's closing balance still equals outstanding().
     *
     * @param  array<int, array{description: string, quantity?: float|int|string|null, unit_price: float|int|string|null}>  $items
     * @return array{bill: Bill, items: Collection<int, BillItem>}
     *
     * @throws \RuntimeException when no usable line is given
     */
    public static function createSpecialInvoice(
        Subscriber $subscriber,
        array $items,
        ?string $title = null,
        ?string $note = null,
        ?string $period = null,
        ?int $userId = null,
    ): array {
        $period ??= self::currentPeriod();
        if (! preg_match(self::PERIOD_REGEX, $period)) {
            throw new \RuntimeException(__('refuse.period_format', ['period' => $period]));
        }

        $clean = [];
        foreach ($items as $item) {
            $description = trim((string) ($item['description'] ?? ''));
            $quantity = round((float) ($item['quantity'] ?? 1), 2);
            $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);

            if ($description === '') {
                continue;
            }
            if ($quantity <= 0) {
                throw new \RuntimeException(__('refuse.line_quantity'));
            }
            if ($unitPrice < 0) {
                throw new \RuntimeException(__('refuse.line_price'));
            }

            $clean[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => round($quantity * $unitPrice, 2),
            ];
        }

        if ($clean === []) {
            throw new \RuntimeException(__('refuse.invoice_no_lines'));
        }

        $total = round(array_sum(array_column($clean, 'amount')), 2);
        if ($total <= 0) {
            throw new \RuntimeException(__('refuse.invoice_total'));
        }

        $prevDebt = self::outstandingBefore($subscriber, $period);

        return DB::transaction(function () use ($subscriber, $clean, $title, $note, $period, $userId, $total, $prevDebt) {
            $bill = Bill::create([
                'subscriber_id' => $subscriber->id,
                'period' => $period,
                'type' => Bill::TYPE_SPECIAL,
                'title' => $title ?: __('spc.default_title'),
                'note' => $note,
                'reading_id' => null,
                'consumption' => null,
                'kwh_price' => null,
                'usage_amount' => 0,
                'maintenance_fee' => 0,
                'flat_amount' => 0,
                'items_amount' => $total,
                'prev_debt' => $prevDebt,
                'total' => $total,
                'paid_amount' => 0,
            ]);

            // Number needs the id, so it is set right after create (same transaction).
            // Keyed on the PERIOD (not the issue date) so documents group by month like the
            // subscription invoices and the statement's period column stays meaningful.
            $bill->forceFill([
                'number' => 'INV-SP-'.$period.'-'.str_pad((string) $bill->id, 4, '0', STR_PAD_LEFT),
            ])->save();

            $created = collect();
            foreach ($clean as $index => $line) {
                $created->push(BillItem::create([
                    'bill_id' => $bill->id,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'sort' => $index,
                ]));
            }

            Audit::log('bill.special_created', $bill, [
                'subscriber_id' => $subscriber->id,
                'period' => $period,
                'title' => $bill->title,
                'items' => $clean,
                'total' => $total,
                'prev_debt' => $prevDebt,
                'by' => $userId,
            ]);

            return ['bill' => $bill, 'items' => $created];
        });
    }

    /**
     * Correction: delete a special invoice. Guarded like the others — only while unpaid.
     *
     * @throws \RuntimeException when the bill is not special or has been paid
     */
    public static function deleteSpecialInvoice(Bill $bill): void
    {
        if (! $bill->isSpecial()) {
            throw new \RuntimeException(__('refuse.only_special_invoices'));
        }
        if ((float) $bill->paid_amount > 0) {
            throw new \RuntimeException(
                __('refuse.invoice_paid_delete', ['amount' => money_ltr((float) $bill->paid_amount)])
            );
        }

        DB::transaction(function () use ($bill) {
            Audit::log('bill.special_deleted', $bill, [
                'subscriber_id' => $bill->subscriber_id,
                'number' => $bill->number,
                'title' => $bill->title,
                'total' => $bill->total,
                'items' => $bill->items->map(fn (BillItem $item) => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                ])->all(),
            ]);

            $bill->delete();   // bill_items cascade
        });
    }
}
