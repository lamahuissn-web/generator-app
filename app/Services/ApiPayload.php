<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Subscriber;

/**
 * The shape the phone receives.
 *
 * Two rules decided here, once, so that no endpoint can invent its own:
 *
 *  - **Money crosses the wire as a decimal string** (`"52.50"`), never a float. The ledger is float
 *    on the inside — that is this program's known weak spot — and a JSON number is a float on the
 *    other side, which is how a balance ends up a cent off itself on a phone.
 *  - **A month is a machine value with a label beside it.** `period` is `2026-10` so the app can
 *    compute with it, `label` is «تشرين الأول ٢٠٢٦» so it can print it; the app never parses Arabic.
 */
final class ApiPayload
{
    /** @return array<string, mixed> */
    public static function area(?int $id, string $name, int $houses, int $active, string $owed, int $readingDone, int $readingTotal): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'houses' => $houses,
            'active' => $active,
            'owed' => $owed,
            'reading_done' => $readingDone,
            'reading_total' => $readingTotal,
        ];
    }

    /** @return array<string, mixed> */
    public static function house(Subscriber $subscriber, string $period, bool $withBills = false): array
    {
        $subscriber->loadMissing('route');

        $unpaid = $subscriber->unpaidBills();
        $reading = $subscriber->readingFor($period);

        $card = [
            'id' => $subscriber->id,
            'name' => $subscriber->name,
            'phone' => $subscriber->phone,
            'address' => $subscriber->address,
            'type' => $subscriber->type,
            'status' => $subscriber->status,
            'area_name' => $subscriber->route?->name ?? __('mobile.no_area'),
            'meter_no' => $subscriber->meter_no,
            'exempt' => $subscriber->isExempt(),
            'exempt_reason' => $subscriber->isExempt() ? $subscriber->exempt_reason : null,
            'flat_fee' => $subscriber->flat_fee === null ? null : self::dec($subscriber->flat_fee),
            'maintenance_fee' => self::dec($subscriber->effectiveMaintenanceFee()),
            'discount_percent' => self::dec($subscriber->effectiveDiscountPercent()),
            'balance' => self::dec($subscriber->outstanding()),
            'credit' => self::dec($subscriber->credit),
            'unpaid_months' => $unpaid
                ->map(fn (Bill $bill): array => [
                    'period' => (string) $bill->period,
                    'label' => self::label((string) $bill->period),
                    'remaining' => self::dec($bill->unpaidAmount()),
                    'type' => $bill->type,
                ])
                ->values()
                ->all(),
            'unpaid_months_count' => $unpaid->count(),
            'period_invoiced' => self::invoicedFor($subscriber, $period),
            'reading_done' => $reading !== null,
            'reading_value' => $reading === null ? null : self::dec($reading->current_reading),
            'reading_estimated' => (bool) ($reading?->is_estimated ?? false),
            'previous_value' => self::dec(BillingService::previousValue($subscriber, $period)),
        ];

        if ($withBills) {
            $card['bills'] = $subscriber->bills()
                ->orderBy('period')
                ->orderByRaw("type = '".Bill::TYPE_SPECIAL."'")
                ->orderBy('id')
                ->get()
                ->map(fn (Bill $bill): array => [
                    'id' => $bill->id,
                    'number' => $bill->number,
                    'period' => (string) $bill->period,
                    'label' => self::label((string) $bill->period),
                    'type' => $bill->type,
                    'total' => self::dec($bill->total),
                    'paid' => self::dec($bill->paid_amount),
                    'remaining' => self::dec($bill->unpaidAmount()),
                    'consumption' => $bill->consumption === null ? null : self::dec($bill->consumption),
                    'discount' => self::dec($bill->discount_amount),
                    'subsidy' => self::dec($bill->subsidy_amount),
                ])
                ->values()
                ->all();
        }

        return $card;
    }

    /** The one line a card is refreshed with after money moved. */
    /** @return array<string, mixed> */
    public static function houseSummary(Subscriber $subscriber): array
    {
        $subscriber->refresh();

        return [
            'id' => $subscriber->id,
            'balance' => self::dec($subscriber->outstanding()),
            'credit' => self::dec($subscriber->credit),
        ];
    }

    /** @param array{reading: Reading, bill: Bill} $result */
    /** @return array<string, mixed> */
    public static function reading(Reading $reading, Bill $bill, Subscriber $subscriber): array
    {
        return [
            'reading' => [
                'id' => $reading->id,
                'subscriber_id' => $reading->subscriber_id,
                'period' => (string) $reading->period,
                'label' => self::label((string) $reading->period),
                'current_reading' => self::dec($reading->current_reading),
                'previous_reading' => self::dec($reading->previous_reading),
                'consumption' => self::dec($reading->consumption),
                'estimated' => (bool) $reading->is_estimated,
                'note' => $reading->note,
                'entered_by' => $reading->entered_by,
                'created_at' => $reading->created_at?->toIso8601String(),
            ],
            'bill' => self::bill($bill),
            'house' => self::houseSummary($subscriber),
        ];
    }

    /** @return array<string, mixed> */
    public static function bill(Bill $bill): array
    {
        return [
            'id' => $bill->id,
            'number' => $bill->number,
            'period' => (string) $bill->period,
            'label' => self::label((string) $bill->period),
            'type' => $bill->type,
            'consumption' => $bill->consumption === null ? null : self::dec($bill->consumption),
            'kwh_price' => $bill->kwh_price === null ? null : number_format((float) $bill->kwh_price, 4, '.', ''),
            'usage_amount' => self::dec($bill->usage_amount),
            'maintenance_fee' => self::dec($bill->maintenance_fee),
            'flat_amount' => self::dec($bill->flat_amount),
            'credit_applied' => self::dec($bill->credit_applied),
            'credit_created' => self::dec($bill->credit_created),
            'subsidy_amount' => self::dec($bill->subsidy_amount),
            'discount_amount' => self::dec($bill->discount_amount),
            'total' => self::dec($bill->total),
            'paid_amount' => self::dec($bill->paid_amount),
        ];
    }

    /**
     * The receipt's own answer: what changed hands, what it settled, and what is left.
     *
     * @param  array<string, mixed>  $result  the engine's own result array
     * @return array<string, mixed>
     */
    public static function payment(Payment $payment, Subscriber $subscriber, array $result): array
    {
        $payment->loadMissing('allocations.bill');

        return [
            'payment' => [
                'id' => $payment->id,
                'number' => $payment->number,
                'subscriber_id' => $payment->subscriber_id,
                'collector_id' => $payment->collector_id,
                'amount' => self::dec($payment->amount),
                'applied' => self::dec($payment->applied ?? $payment->amount),
                'credited' => self::dec($result['credited'] ?? 0),
                'excess' => self::dec($result['excess'] ?? 0),
                'method' => $payment->method,
                'collected_on' => $payment->collectedOn()->toDateString(),
                'lbp_amount' => $payment->lbp_amount === null ? null : (int) $payment->lbp_amount,
                'lbp_rate' => $payment->lbp_rate === null ? null : (float) $payment->lbp_rate,
                'note' => $payment->note,
                'created_at' => $payment->created_at?->toIso8601String(),
            ],
            'settled' => $payment->allocations
                ->sortBy('id')
                ->map(fn ($allocation): array => [
                    'bill_id' => $allocation->bill_id,
                    'period' => $allocation->bill?->period === null ? null : (string) $allocation->bill->period,
                    'label' => $allocation->bill?->period === null ? null : self::label((string) $allocation->bill->period),
                    'amount' => self::dec($allocation->amount),
                    'remaining' => self::dec($allocation->bill?->fresh()?->unpaidAmount() ?? 0),
                ])
                ->values()
                ->all(),
            'house' => self::houseSummary($subscriber),
            'receipt_url' => '/api/v1/receipts/'.$payment->id,
        ];
    }

    /** Money as the wire carries it: two decimals, a string, no currency symbol. */
    public static function dec(float|int|string|null $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * The month as the office writes it: «تشرين الأول ٢٠٢٦» for an Arabic phone, `Oct 2026` otherwise.
     *
     * The program has two renderers and the panel already chooses between them by locale
     * (`period_label_ar` when the office reads Arabic). Found on the live app: the collector's sheet
     * said «Oct 2026» over a house, which is a month a household cannot read back.
     *
     * Public because the sheet itself (not only the rows inside it) has to name the cycle a collector
     * is walking.
     */
    public static function label(string $period): string
    {
        return app()->isLocale('ar') ? period_label_ar($period) : period_label($period);
    }

    /** A subscription invoice for the cycle — a one-off part is not this month's bill. */
    private static function invoicedFor(Subscriber $subscriber, string $period): bool
    {
        return $subscriber->bills()
            ->where('period', $period)
            ->where('type', '!=', Bill::TYPE_SPECIAL)
            ->exists();
    }
}
