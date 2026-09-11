<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Subscriber;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Model;

/**
 * A special invoice: itemized charges outside the subscription — a part, a new meter, a
 * technician's fee. Lands on the subscriber's balance and is collected like any other bill.
 */
class SpecialInvoice extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Subscriber::class;
    }

    public function label(): string
    {
        return __('a.special_invoice');
    }

    public function icon(): ?string
    {
        return 'ki-plus-squared';
    }

    public function triggerClass(): string
    {
        return 'kt-btn-outline';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.special_invoice_for', ['name' => $subject->name]);
    }

    public function modalDescription(): ?string
    {
        return __('a.special_invoice_desc');
    }

    public function submitLabel(): string
    {
        return __('a.special_invoice_create');
    }

    public function visible(Model $subject): bool
    {
        return $subject->status === Subscriber::STATUS_ACTIVE;
    }

    public function sections(): array
    {
        return [[
            'title' => '',
            'fields' => [
                [
                    'key' => 'title',
                    'label' => __('t.invoice_subject'),
                    'default' => __('spc.default_title'),
                    'span' => 'full',
                    'rules' => ['required', 'string', 'max:120'],
                ],
                [
                    'key' => 'items',
                    'label' => __('t.invoice_items'),
                    'type' => 'repeater',
                    'span' => 'full',
                ],
                [
                    'key' => 'note',
                    'label' => __('t.note'),
                    'type' => 'textarea',
                    'span' => 'full',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
            ],
        ]];
    }

    public function defaults(?Model $subject = null): array
    {
        return [
            'title' => __('spc.default_title'),
            'items' => [['description' => '', 'quantity' => 1, 'unit_price' => 0]],
            'note' => null,
        ];
    }

    /**
     * At least one real line, each with a positive price.
     *
     * Without this an empty repeater produces a $0.00 invoice that shows up as an unpaid bill
     * on the subscriber's account and in the overdue report forever.
     */
    public function rules(array $state, ?Model $record = null): array
    {
        return array_merge(parent::rules($state, $record), [
            'state.items' => ['required', 'array', 'min:1'],
            'state.items.*.description' => ['required', 'string', 'max:160'],
            'state.items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'state.items.*.unit_price' => ['required', 'numeric', 'min:0.01'],
        ]);
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Subscriber $subject */
        $result = BillingService::createSpecialInvoice(
            $subject,
            $state['items'] ?? [],
            $state['title'] ?? null,
            $state['note'] ?: null,
            null,
            auth()->id(),
        );

        return ['ok' => true, 'message' => __('a.special_invoice_ok', [
            'number' => ltr_isolate($result['bill']->number),
            'total' => money_ltr($result['bill']->total),
        ])];
    }
}
