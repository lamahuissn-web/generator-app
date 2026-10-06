<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Collection;

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
    /**
     * How many receipts a household's file carries on the phone.
     *
     * The panel's log is complete and paged; a pocket file is not a search tool — it is what a manager reads
     * before taking a receipt back, and 50 covers a household's whole year at the usual rhythm.
     */
    private const FILE_RECEIPTS = 50;

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

    /**
     * One household, as the phone reads it.
     *
     * `$full` is the household's FILE — his invoices and his receipts — and it is asked for one house at a
     * time only. The area sheet asks for the card alone (`$full` false) for every house in a street, and a
     * whole ledger per house there would be a lot of bytes for figures nobody reads in a list.
     *
     * @return array<string,mixed>
     */
    public static function house(Subscriber $subscriber, string $period, bool $full = false): array
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

        if ($full) {
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

            // The household's receipts, newest first by the day they were taken — the same log the panel's
            // file shows. A reversed receipt is not here and should not be: reversing removes it from the
            // ledger, and the story of what happened to it lives in the audit trail.
            $card['payments'] = $subscriber->payments()
                ->with(['allocations.bill', 'collector'])
                ->orderByDesc('collected_on')
                ->orderByDesc('id')
                ->limit(self::FILE_RECEIPTS)
                ->get()
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'number' => $payment->number,
                    'amount' => self::dec($payment->amount),
                    'method' => $payment->method,
                    'method_label' => $payment->methodLabel(),
                    'collected_on' => $payment->collectedOn()->toDateString(),
                    'created_at' => $payment->created_at?->toIso8601String(),
                    'collector' => $payment->collector?->name,
                    'note' => $payment->note,
                    'lbp_amount' => $payment->lbp_amount === null ? null : (int) $payment->lbp_amount,
                    'lbp_rate' => $payment->lbp_rate === null ? null : (float) $payment->lbp_rate,
                    'settled' => $payment->allocations
                        ->sortBy('id')
                        ->map(fn ($allocation): array => [
                            'period' => $allocation->bill?->period === null ? null : (string) $allocation->bill->period,
                            'label' => $allocation->bill?->period === null ? null : self::label((string) $allocation->bill->period),
                            'amount' => self::dec($allocation->amount),
                        ])
                        ->values()
                        ->all(),
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

    /**
     * The collector's own money, as his dashboard shows it.
     *
     * The figures come from `CollectorStats` — the same service the office's dashboard reads — so the
     * phone and the desk cannot hold two different versions of «today».
     *
     * @param  array<string, mixed>  $stats
     * @param  \Illuminate\Support\Collection<int, \App\Models\CashHandover>  $handovers
     * @return array<string, mixed>
     */
    public static function stats(array $stats, Collection $handovers): array
    {
        return [
            'today' => [
                'total' => self::dec($stats['today_total'] ?? 0),
                'count' => (int) ($stats['today_count'] ?? 0),
            ],
            'month' => [
                'total' => self::dec($stats['month_total'] ?? 0),
                'count' => (int) ($stats['month_count'] ?? 0),
            ],
            'in_hand' => self::dec($stats['in_hand'] ?? 0),
            'handed_total' => self::dec($stats['handed_total'] ?? 0),
            'handovers' => $handovers->map(fn ($handover): array => [
                'date' => $handover->handover_date?->toDateString(),
                'amount' => self::dec($handover->amount),
                'note' => $handover->note,
            ])->values()->all(),
        ];
    }

    /**
     * One household, as a search answer: enough to recognise the door before walking to it.
     *
     * The balance is `houseSummary`'s — the same figure the household's own screen shows — because a
     * search result that disagrees with the screen it opens is worse than no search at all.
     *
     * @return array<string, mixed>
     */
    public static function searchRow(Subscriber $subscriber): array
    {
        $summary = self::houseSummary($subscriber);

        return [
            'id' => $subscriber->id,
            'name' => $subscriber->name,
            'meter_no' => $subscriber->meter_no,
            'type' => $subscriber->type,
            'status' => $subscriber->status,
            'route' => $subscriber->route?->name,
            'balance' => $summary['balance'],
            'credit' => $summary['credit'],
            'owes' => (float) $summary['balance'] > 0,
        ];
    }

    /**
     * The manager's board (spec 009, phase A): the office's month, and what each collector is holding.
     *
     * Every figure is a service's — `OfficeBoard`, `CollectionService`, `CollectorStats` — so the phone
     * and the desk cannot disagree. There is nothing to write here and nothing to compute: a manager
     * away from his desk reads, he does not post.
     *
     * @param  array<string, mixed>  $numbers  OfficeBoard::numbers()
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $collectors  CollectionService::byCollector()
     * @param  array<int, array{total: float, count: int}>  $today  CollectorStats::todayByCollector()
     * @param  \Illuminate\Support\Collection<int, Payment>  $recent
     * @return array<string, mixed>
     */
    public static function managerBoard(
        string $period,
        array $numbers,
        Collection $collectors,
        array $today,
        Collection $recent,
    ): array {
        return [
            'period' => $period,
            'label' => self::label($period),
            'money' => [
                'invoiced' => self::dec($numbers['invoiced']),
                'collected' => self::dec($numbers['collected']),
                'rate' => (float) $numbers['rate'],
                'rate_previous' => (float) $numbers['prev_rate'],
                'outstanding' => self::dec($numbers['outstanding']),
                'outstanding_inactive' => self::dec($numbers['outstanding_inactive']),
            ],
            'houses' => [
                'active' => (int) $numbers['active'],
                'metered' => (int) $numbers['metered'],
                'flat' => max(0, (int) $numbers['active'] - (int) $numbers['metered']),
            ],
            'kwh' => [
                'total' => self::dec($numbers['kwh']),
                'price' => self::dec($numbers['tariff']),
            ],
            'collectors' => $collectors
                ->map(function (array $collector) use ($today): array {
                    $id = (int) $collector['collector_id'];

                    return [
                        'id' => $id,
                        'name' => $collector['name'],
                        'today_total' => self::dec($today[$id]['total'] ?? 0),
                        'today_count' => (int) ($today[$id]['count'] ?? 0),
                        'collected' => self::dec($collector['collected']),
                        'payments_count' => (int) $collector['payments_count'],
                        'handed' => self::dec($collector['handed']),
                        'in_hand' => self::dec($collector['in_hand']),
                    ];
                })
                ->values()
                ->all(),
            'recent_payments' => $recent
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'subscriber_id' => $payment->subscriber_id,
                    'number' => $payment->number,
                    'amount' => self::dec($payment->amount),
                    'subscriber' => $payment->subscriber?->name,
                    'collector' => $payment->collector?->name,
                    'collected_on' => $payment->collectedOn()->toDateString(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * A handover as the manager's phone reads it back: what was recorded, and where the collector stands
     * now — the figure the office reconciles its drawer against.
     */
    public static function handover(CashHandover $handover, User $collector): array
    {
        return [
            'handover' => [
                'id' => $handover->id,
                'collector_id' => (int) $handover->collector_id,
                'collector' => $collector->name,
                'amount' => self::dec($handover->amount),
                'handover_date' => $handover->handover_date->toDateString(),
                'note' => $handover->note,
            ],
            'collector_in_hand' => self::dec(CollectionService::inHand($collector->id)),
        ];
    }

    /**
     * The identity of one receipt, read BEFORE it is removed from the ledger.
     *
     * A reversal deletes the row (that is what reversing means here), so the phone is answered from what
     * was read while it still existed: what went, whose it was, and the date it was taken.
     */
    public static function receiptIdentity(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'subscriber_id' => $payment->subscriber_id,
            'amount' => self::dec($payment->amount),
            'collector_id' => $payment->collector_id,
            'collector' => $payment->collector?->name,
            'collected_on' => $payment->collectedOn()->toDateString(),
        ];
    }

    /** A receipt that was taken back, and where the household stands now. */
    public static function reversed(array $receipt, Subscriber $subscriber): array
    {
        return [
            'reversed' => $receipt,
            'subscriber_balance' => self::dec($subscriber->outstanding()),
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
            // The receipt as words, so a collector can hand it to the household's phone in the street
            // rather than only print it later. Built from the same pieces as the paper (ReceiptText).
            'receipt_text' => ReceiptText::for($payment, $subscriber),
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
