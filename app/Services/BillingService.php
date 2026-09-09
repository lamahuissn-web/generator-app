<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public const PERIOD_REGEX = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    public static function kwhPrice(): float
    {
        return (float) Setting::get('kwh_price', 0.25);
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
        $price = self::kwhPrice();
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
