<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Subscriber;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Model;

/**
 * The meter is gone for good: bill a fixed monthly amount instead.
 *
 * The released meter number and the reason stay in the audit trail, and the reason is
 * required — this changes what the subscriber is charged every month from now on.
 */
class ConvertToFlat extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Subscriber::class;
    }

    public function label(): string
    {
        return __('a.convert_flat');
    }

    public function icon(): ?string
    {
        return 'ki-chart-line-down';
    }

    public function triggerClass(): string
    {
        return 'kt-btn-outline';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.convert_flat_heading', ['name' => $subject->name]);
    }

    public function modalDescription(): ?string
    {
        return __('a.convert_flat_desc');
    }

    public function submitLabel(): string
    {
        return __('a.convert_flat');
    }

    public function visible(Model $subject): bool
    {
        return $subject->isMetered();
    }

    public function sections(): array
    {
        return [[
            'title' => '',
            'fields' => [
                [
                    'key' => 'flat_fee',
                    'label' => __('t.flat_fee'),
                    'type' => 'money',
                    'min' => 0.01,
                    'helper' => __('t.flat_fee_hint'),
                    'rules' => ['required', 'numeric', 'min:0.01'],
                ],
                [
                    'key' => 'reason',
                    'label' => __('t.reason'),
                    'default' => __('t.reason_broken_meter'),
                    'rules' => ['required', 'string', 'max:160'],
                ],
            ],
        ]];
    }

    public function defaults(?Model $subject = null): array
    {
        return [
            'flat_fee' => $subject instanceof Subscriber && $subject->flat_fee !== null
                ? (float) $subject->flat_fee
                : null,
            'reason' => __('t.reason_broken_meter'),
        ];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Subscriber $subject */
        $subject->convertToFlat((float) $state['flat_fee'], (string) $state['reason'], auth()->id());

        return ['ok' => true, 'message' => __('a.convert_flat_ok', [
            'name' => $subject->name,
            'fee' => money_ltr((float) $state['flat_fee']),
        ])];
    }
}
