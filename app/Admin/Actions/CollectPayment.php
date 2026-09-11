<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Take a cash payment from a subscriber.
 *
 * The amount defaults to the full balance, and the service decides how the money is
 * allocated (FIFO across unpaid bills) — this screen only collects the number.
 */
class CollectPayment extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Subscriber::class;
    }

    public function label(): string
    {
        return __('a.collect');
    }

    public function icon(): ?string
    {
        return 'ki-wallet';
    }

    public function triggerClass(): string
    {
        return '';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.collect_for', ['name' => $subject->name]);
    }

    public function modalDescription(): ?string
    {
        return __('a.collect_desc');
    }

    public function submitLabel(): string
    {
        return __('a.record_collection');
    }

    public function visible(Model $subject): bool
    {
        return $subject->outstanding() > 0 && $subject->status === Subscriber::STATUS_ACTIVE;
    }

    public function sections(): array
    {
        return [[
            'title' => '',
            'fields' => [
                [
                    'key' => 'amount',
                    'label' => __('t.amount_received'),
                    'type' => 'money',
                    'default' => 0,
                    'span' => 'full',
                    'helper' => __('a.collect_helper'),
                    'rules' => ['required', 'numeric', 'min:0.01'],
                ],
                [
                    'key' => 'method',
                    'label' => __('t.payment_method'),
                    'type' => 'select',
                    'default' => Payment::METHOD_CASH_USD,
                    'options_from' => fn (): array => Payment::methodOptions(),
                    'rules' => ['required'],
                ],
                [
                    'key' => 'note',
                    'label' => __('t.note'),
                    'rules' => ['nullable', 'string', 'max:200'],
                ],
            ],
        ]];
    }

    /**
     * The amount a collector normally takes: the whole outstanding balance. Prefilled so the
     * common case is one click, still editable for a part payment.
     */
    public function defaults(?Model $subject = null): array
    {
        return [
            'amount' => $subject instanceof Subscriber
                ? number_format($subject->outstanding(), 2, '.', '')
                : null,
            'method' => Payment::METHOD_CASH_USD,
            'note' => null,
        ];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Subscriber $subject */
        $result = BillingService::collect(
            $subject,
            (float) $state['amount'],
            auth()->id(),
            $state['note'] ?: null,
            null,
            $state['method'] ?? Payment::METHOD_CASH_USD,
        );

        $message = __('a.collect_ok', [
            'amount' => money_ltr($result['applied']),
            'remaining' => money_ltr($result['remaining']),
            'method' => $result['payment']->methodLabel(),
        ]);

        if (($result['excess'] ?? 0) > 0) {
            $message .= ' '. __('a.collect_excess', ['excess' => money_ltr($result['excess'])]);
        }

        return ['ok' => true, 'message' => $message];
    }
}
