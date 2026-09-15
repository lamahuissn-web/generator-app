<?php

namespace App\Admin\Forms;

use App\Admin\FormDefinition;
use App\Models\Route;
use App\Models\Subscriber;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Create / edit a subscriber.
 *
 * The maintenance fee follows the same three-way model the billing service reads
 * (`auto` = the global default, `custom` = this subscriber's own amount, `exempt` = none),
 * and new subscribers default to `auto` in the FORM. The model-level default stays
 * `custom`, so any pre-existing row with a stored amount keeps billing as it always did.
 */
class SubscriberForm extends FormDefinition
{
    public function model(): string
    {
        return Subscriber::class;
    }

    public function sections(): array
    {
        return [
            [
                'title' => __('t.subscriber'),
                'fields' => [
                    [
                        'key' => 'route_id',
                        'label' => __('t.route'),
                        'type' => 'select',
                        'options_from' => fn (): array => Route::query()->orderBy('name')->pluck('name', 'id')->all(),
                        'rules' => ['required', 'exists:routes,id'],
                    ],
                    [
                        'key' => 'name',
                        'label' => __('t.name'),
                        'rules' => ['required', 'string', 'max:150'],
                    ],
                    ['key' => 'phone', 'label' => __('t.phone'), 'type' => 'tel', 'rules' => ['nullable', 'string', 'max:30']],
                    ['key' => 'address', 'label' => __('t.address'), 'rules' => ['nullable', 'string', 'max:200']],
                    [
                        'key' => 'type',
                        'label' => __('t.type'),
                        'type' => 'select',
                        'default' => Subscriber::TYPE_METERED,
                        'options' => [
                            Subscriber::TYPE_METERED => __('s.metered'),
                            Subscriber::TYPE_FLAT => __('s.flat_rate'),
                        ],
                        'rules' => ['required', 'in:'.Subscriber::TYPE_METERED.','.Subscriber::TYPE_FLAT],
                    ],
                    [
                        'key' => 'meter_no',
                        'label' => __('t.meter_no'),
                        'rules' => ['nullable', 'string', 'max:40'],
                        'unique' => 'subscribers,meter_no',
                        'helper' => __('a.meter_no_hint'),
                        'visible_when' => fn (array $state): bool => ($state['type'] ?? null) === Subscriber::TYPE_METERED,
                    ],
                    [
                        'key' => 'status',
                        'label' => __('t.status'),
                        'type' => 'select',
                        'default' => Subscriber::STATUS_ACTIVE,
                        'options' => [
                            Subscriber::STATUS_ACTIVE => __('portal.active'),
                            Subscriber::STATUS_INACTIVE => __('portal.inactive'),
                        ],
                        'rules' => ['required', 'in:'.Subscriber::STATUS_ACTIVE.','.Subscriber::STATUS_INACTIVE],
                    ],
                ],
            ],
            [
                'title' => __('s.metered'),
                'description' => __('s.metered_desc'),
                'visible_when' => fn (array $state): bool => ($state['type'] ?? null) === Subscriber::TYPE_METERED,
                'fields' => [
                    [
                        'key' => 'amperage',
                        'label' => __('t.amperage_a'),
                        'type' => 'number',
                        'min' => 1,
                        'max' => 200,
                        'helper' => __('s.ampere_hint'),
                        'rules' => ['nullable', 'integer', 'min:1', 'max:200'],
                    ],
                    [
                        // The dial as it stood when we took him on. Left empty for a new meter, which
                        // is exactly what the panel assumed before these fields existed.
                        'key' => 'opening_reading',
                        'label' => __('s.opening_reading'),
                        'type' => 'number',
                        'min' => 0,
                        'helper' => __('s.opening_reading_hint'),
                        'visible_when' => fn (array $state): bool => ($state['type'] ?? null) === Subscriber::TYPE_METERED,
                        'rules' => ['nullable', 'numeric', 'min:0'],
                    ],
                    [
                        'key' => 'opening_period',
                        'label' => __('s.opening_period'),
                        'helper' => __('s.opening_period_hint'),
                        'visible_when' => fn (array $state): bool => ($state['type'] ?? null) === Subscriber::TYPE_METERED,
                        'rules' => ['nullable', 'string'],
                    ],
                    [
                        'key' => 'maintenance_mode',
                        'label' => __('s.mode_label'),
                        'type' => 'select',
                        'default' => Subscriber::MAINT_AUTO,
                        'options' => [
                            Subscriber::MAINT_AUTO => __('s.mode_auto', ['fee' => money_ltr(BillingService::defaultMaintenanceFee())]),
                            Subscriber::MAINT_CUSTOM => __('s.mode_custom'),
                            Subscriber::MAINT_EXEMPT => __('s.mode_exempt'),
                        ],
                        'helper' => __('s.mode_hint'),
                        'rules' => ['required', 'in:'.Subscriber::MAINT_AUTO.','.Subscriber::MAINT_CUSTOM.','.Subscriber::MAINT_EXEMPT],
                    ],
                    [
                        'key' => 'maintenance_fee',
                        'label' => __('t.maint_fee'),
                        'type' => 'money',
                        'default' => 0,
                        'min' => 0,
                        'rules' => ['nullable', 'numeric', 'min:0'],
                        'visible_when' => fn (array $state): bool => ($state['maintenance_mode'] ?? null) === Subscriber::MAINT_CUSTOM,
                    ],
                ],
            ],
            [
                'title' => __('s.flat_rate'),
                'description' => __('s.flat_desc'),
                'visible_when' => fn (array $state): bool => ($state['type'] ?? null) === Subscriber::TYPE_FLAT,
                'fields' => [
                    [
                        'key' => 'flat_fee',
                        'label' => __('t.flat_fee'),
                        'type' => 'money',
                        'min' => 0,
                        'rules' => ['required', 'numeric', 'min:0'],
                    ],
                ],
            ],
            [
                'title' => __('s.notes'),
                'fields' => [
                    ['key' => 'notes', 'label' => __('s.notes'), 'type' => 'textarea', 'span' => 'full', 'rules' => ['nullable', 'string', 'max:1000']],
                ],
            ],
        ];
    }

    public function save(array $state, ?Model $record): Model
    {
        $isFlat = ($state['type'] ?? null) === Subscriber::TYPE_FLAT;

        $attributes = [
            'route_id' => $state['route_id'] ?? null,
            'name' => $state['name'],
            'phone' => $state['phone'] ?: null,
            'address' => $state['address'] ?: null,
            'status' => $state['status'] ?? Subscriber::STATUS_ACTIVE,
            'type' => $state['type'],
            'notes' => $state['notes'] ?: null,
        ];

        // An empty box means «no opening reading», never zero: zero is a real value on a new dial and
        // the two must not be confused.
        $openingRaw = $state['opening_reading'] ?? null;
        $attributes['opening_reading'] = ($openingRaw === null || $openingRaw === '') ? null : (float) $openingRaw;
        $attributes['opening_period'] = $attributes['opening_reading'] === null
            ? null
            : (Subscriber::cleanOpeningPeriod($state['opening_period'] ?? null) ?? now()->format('Y-m'));

        // A flat subscriber has no meter: null the meter fields instead of leaving the
        // values a hidden input still carries, otherwise the list prints "Meter 2202"
        // for a subscriber who has not had one for months.
        $attributes['meter_no'] = $isFlat ? null : ($state['meter_no'] ?: null);
        $attributes['amperage'] = $isFlat ? null : ($state['amperage'] ?: null);
        $attributes['maintenance_mode'] = $isFlat
            ? Subscriber::MAINT_CUSTOM
            : ($state['maintenance_mode'] ?? Subscriber::MAINT_AUTO);
        $attributes['maintenance_fee'] = $isFlat
            ? 0
            : ($attributes['maintenance_mode'] === Subscriber::MAINT_CUSTOM ? (float) ($state['maintenance_fee'] ?? 0) : 0);
        $attributes['flat_fee'] = $isFlat ? (float) ($state['flat_fee'] ?? 0) : null;

        if ($record === null) {
            $attributes['created_by'] = auth()->id();
            $record = Subscriber::create($attributes);
        } else {
            $record->fill($attributes)->save();
        }

        return $record;
    }

    public function redirectAfterSave(Model $record): string
    {
        return route('admin.subscribers.show', $record);
    }

    public function recordTitle(Model $record): string
    {
        return (string) $record->getAttribute('name');
    }
}
