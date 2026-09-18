<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Subscriber;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Put a meter back on a flat-rate subscriber.
 *
 * The starting reading is recorded as a baseline (no charge) — without it the first cycle
 * would bill the new meter's whole value as consumption.
 */
class ConvertToMetered extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Subscriber::class;
    }

    public function label(): string
    {
        return __('a.convert_metered');
    }

    public function icon(): ?string
    {
        return 'ki-flash-circle';
    }

    public function triggerClass(): string
    {
        return 'kt-btn-outline';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.convert_metered_heading', ['name' => $subject->name]);
    }

    public function modalDescription(): ?string
    {
        return __('a.convert_metered_desc');
    }

    public function submitLabel(): string
    {
        return __('a.convert_metered');
    }

    public function visible(Model $subject): bool
    {
        return $subject->isFlat();
    }

    public function sections(): array
    {
        return [[
            'title' => '',
            'fields' => [
                [
                    'key' => 'meter_no',
                    'label' => __('t.meter'),
                    'helper' => __('a.meter_no_hint'),
                    'rules' => ['required', 'string', 'max:40'],
                ],
                [
                    'key' => 'initial_reading',
                    'label' => __('t.initial_reading'),
                    'type' => 'number',
                    'min' => 0,
                    'default' => 0,
                    'helper' => __('t.initial_reading_hint'),
                    'rules' => ['required', 'numeric', 'min:0'],
                ],
                [
                    'key' => 'amperage',
                    'label' => __('t.amperage'),
                    'type' => 'number',
                    'min' => 1,
                    'max' => 200,
                    'rules' => ['nullable', 'integer', 'min:1', 'max:200'],
                ],
                [
                    'key' => 'maintenance_fee',
                    'label' => __('t.maint_fee'),
                    'type' => 'money',
                    'min' => 0,
                    'helper' => __('a.maintenance_hint'),
                    'rules' => ['nullable', 'numeric', 'min:0'],
                ],
                [
                    'key' => 'reason',
                    'label' => __('t.reason'),
                    'default' => __('t.reason_new_meter'),
                    'span' => 'full',
                    'rules' => ['required', 'string', 'max:160'],
                ],
            ],
        ]];
    }

    public function defaults(?Model $subject = null): array
    {
        return [
            'meter_no' => null,
            'initial_reading' => 0,
            'amperage' => null,
            'maintenance_fee' => BillingService::defaultMaintenanceFee(),
            'reason' => __('t.reason_new_meter'),
        ];
    }

    /**
     * The meter number has to be unique, and it is the one rule the field schema cannot
     * express: a duplicate means two subscribers reading the same physical meter, which is
     * only discovered months later when the consumption reports make no sense.
     */
    public function rules(array $state, ?Model $record = null): array
    {
        return array_merge(parent::rules($state, $record), [
            'state.meter_no' => [
                'required', 'string', 'max:40',
                \Illuminate\Validation\Rule::unique('subscribers', 'meter_no')
                    ->ignore($record?->getKey() ?? $this->subject?->getKey()),
            ],
        ]);
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Subscriber $subject */
        $reading = $subject->convertToMetered(
            (string) $state['meter_no'],
            (float) $state['initial_reading'],
            isset($state['amperage']) && $state['amperage'] !== null ? (int) $state['amperage'] : null,
            isset($state['maintenance_fee']) && $state['maintenance_fee'] !== null ? (float) $state['maintenance_fee'] : null,
            (string) $state['reason'],
            null,
            auth()->id(),
        );

        return ['ok' => true, 'message' => __('a.convert_metered_ok', [
            'name' => $subject->name,
            'period' => $reading->period,
            'value' => number_format((float) $reading->current_reading, 0),
        ])];
    }
}
