<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Bill;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Waive part — or all — of one invoice: the office's deliberate act, with a reason.
 *
 * A discount REDUCES what the office charged. It is never written to the subscriber's
 * balance (subscribers.credit is his own money), and it never re-runs the ceiling: it
 * adjusts the total, and setting it to 0 restores it while still unpaid. Guarded to unpaid
 * bills (a paid invoice refuses by name) and to non-special invoices.
 */
class DiscountBill extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Bill::class;
    }

    public function label(): string
    {
        return __('a.discount_bill');
    }

    public function icon(): ?string
    {
        return 'ki-discount';
    }

    public function triggerClass(): string
    {
        return 'kt-btn-outline';
    }

    public function modalHeading(Model $subject): string
    {
        /** @var Bill $subject */
        return __('a.discount_heading', ['period' => period_label_i18n($subject->period)]);
    }

    public function modalDescription(): ?string
    {
        return __('a.discount_desc');
    }

    public function submitLabel(): string
    {
        return __('a.discount_apply');
    }

    public function visible(Model $subject): bool
    {
        /** @var Bill $subject */
        return ! $subject->isSpecial() && (float) $subject->paid_amount <= 0;
    }

    public function sections(): array
    {
        return [[
            'title' => '',
            'fields' => [
                [
                    'key' => 'discount_amount',
                    'label' => __('a.discount_amount'),
                    'type' => 'money',
                    'helper' => __('a.discount_amount_hint'),
                    'rules' => ['required', 'numeric', 'min:0'],
                    'min' => 0,
                ],
                [
                    'key' => 'discount_reason',
                    'label' => __('a.discount_reason'),
                    'type' => 'textarea',
                    'span' => 'full',
                    'helper' => __('a.discount_reason_hint'),
                    'rules' => ['required', 'string', 'max:200'],
                ],
                [
                    'key' => 'discount_category',
                    'label' => __('s.exempt_category'),
                    'type' => 'select',
                    'placeholder' => __('t.select_placeholder'),
                    'options_from' => fn (): array => discount_category_options(),
                    'rules' => ['nullable', 'string', 'max:40'],
                ],
            ],
        ]];
    }

    /**
     * Prefill the discount box with the invoice's own charge (discount + total), and its
     * existing reason: the operator then types less to grant a free month.
     */
    public function defaults(?Model $subject = null): array
    {
        if ($subject instanceof Bill) {
            $charge = round((float) $subject->total + (float) $subject->discount_amount, 2);

            return [
                'discount_amount' => $charge > 0 ? number_format($charge, 2, '.', '') : '0.00',
                'discount_reason' => $subject->discount_note ?? '',
                'discount_category' => $subject->discount_category ?? null,
            ];
        }

        return [
            'discount_amount' => '0.00',
            'discount_reason' => '',
            'discount_category' => null,
        ];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Bill $subject */
        $bill = BillingService::discountBill(
            $subject,
            (float) ($state['discount_amount'] ?? 0),
            (string) ($state['discount_reason'] ?? ''),
            ($state['discount_category'] ?? '') !== '' ? (string) $state['discount_category'] : null,
            auth()->id(),
        );

        return ['ok' => true, 'message' => __('a.discount_ok', [
            'amount' => money_ltr((float) $bill->discount_amount),
            'total' => money_ltr((float) $bill->total),
        ])];
    }
}
