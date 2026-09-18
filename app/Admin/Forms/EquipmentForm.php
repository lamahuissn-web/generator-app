<?php

namespace App\Admin\Forms;

use App\Admin\FormDefinition;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Model;

/**
 * One engineering device the office owns.
 *
 * A one-generator office never sees a list: the board and the sidebar jump straight to the single
 * file, so this form is only needed when a second machine exists or when the first one is named.
 * The fields are the identity the work order needs: type and capacity for the tariff, hours for the
 * next service, and the install date for the warranty.
 */
class EquipmentForm extends FormDefinition
{
    public function model(): string
    {
        return Equipment::class;
    }

    public function sections(): array
    {
        return [[
            'title' => __('eq.form_title'),
            'fields' => [
                [
                    'key' => 'name',
                    'label' => __('eq.name'),
                    'span' => 'full',
                    'rules' => ['required', 'string', 'max:120'],
                ],
                [
                    'key' => 'kind',
                    'label' => __('eq.kind'),
                    'type' => 'select',
                    'default' => Equipment::KIND_GENERATOR,
                    'options' => Equipment::kinds(),
                    'rules' => ['required', 'in:'.implode(',', array_keys(Equipment::kinds()))],
                ],
                [
                    'key' => 'capacity',
                    'label' => __('eq.capacity'),
                    'placeholder' => __('eq.capacity_ph'),
                    'helper' => __('eq.capacity_hint'),
                    'rules' => ['nullable', 'string', 'max:40'],
                ],
                [
                    'key' => 'serial',
                    'label' => __('eq.serial'),
                    'rules' => ['nullable', 'string', 'max:60'],
                ],
                [
                    'key' => 'installed_on',
                    'label' => __('eq.installed_on'),
                    'type' => 'date',
                    'rules' => ['nullable', 'date'],
                ],
                [
                    'key' => 'engine_hours_opening',
                    'label' => __('eq.engine_hours_opening'),
                    'type' => 'number',
                    'min' => 0,
                    'helper' => __('eq.engine_hours_hint'),
                    'rules' => ['nullable', 'numeric', 'min:0'],
                ],
                [
                    'key' => 'status',
                    'label' => __('t.status'),
                    'type' => 'select',
                    'default' => Equipment::STATUS_WORKING,
                    'options' => Equipment::statuses(),
                    'rules' => ['required', 'in:'.implode(',', array_keys(Equipment::statuses()))],
                ],
                [
                    'key' => 'notes',
                    'label' => __('t.note'),
                    'type' => 'textarea',
                    'span' => 'full',
                    'rules' => ['nullable', 'string', 'max:1000'],
                ],
            ],
        ]];
    }

    public function stateFrom(Model $record): array
    {
        return [
            'name' => $record->name,
            'kind' => $record->kind,
            'capacity' => $record->capacity,
            'serial' => $record->serial,
            'installed_on' => $record->installed_on?->toDateString(),
            'engine_hours_opening' => $record->engine_hours_opening,
            'status' => $record->status,
            'notes' => $record->notes,
        ];
    }

    public function save(array $state, ?Model $record): Model
    {
        $attributes = [
            'name' => $state['name'],
            'kind' => $state['kind'],
            'capacity' => $state['capacity'] ?: null,
            'serial' => $state['serial'] ?: null,
            'installed_on' => $state['installed_on'] ?: null,
            'engine_hours_opening' => filled($state['engine_hours_opening'] ?? null) ? (float) $state['engine_hours_opening'] : 0,
            'status' => $state['status'],
            'notes' => $state['notes'] ?: null,
        ];

        if ($record === null) {
            $attributes['created_by'] = auth()->id();

            return Equipment::create($attributes);
        }

        $record->fill($attributes)->save();

        return $record;
    }

    public function redirectAfterSave(Model $record): string
    {
        return route('admin.equipment.index');
    }

    public function recordTitle(Model $record): string
    {
        return (string) $record->name;
    }
}
