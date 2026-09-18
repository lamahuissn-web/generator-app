<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Equipment;
use App\Models\Refuelling;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;

/**
 * تعبئة مازوت — the owner's only new routine, two fields and fifteen seconds.
 *
 * Litres and price ride in from the pump receipt; hours and kWh are the advanced path for the
 * owner who wants the centre to speak in measured rather than estimated figures. The last price is
 * prefilled so a batch of refuellings typed from the same truck is one number after another.
 */
class RecordRefuelling extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Equipment::class;
    }

    public function label(): string
    {
        return __('eq.refuel');
    }

    public function icon(): ?string
    {
        return 'ki-delivery';
    }

    public function modalHeading(Model $subject): string
    {
        return __('eq.refuel_for', ['name' => $subject->name]);
    }

    public function modalDescription(): ?string
    {
        return __('eq.refuel_hint');
    }

    public function submitLabel(): string
    {
        return __('eq.refuel_save');
    }

    public function sections(): array
    {
        return [[
            'title' => '',
            'fields' => [
                [
                    'key' => 'date',
                    'label' => __('t.date'),
                    'type' => 'date',
                    'default' => now()->toDateString(),
                    'rules' => ['required', 'date'],
                ],
                [
                    'key' => 'litres',
                    'label' => __('eq.litres'),
                    'type' => 'number',
                    'min' => 0.01,
                    'rules' => ['required', 'numeric', 'min:0.01'],
                ],
                [
                    'key' => 'price_per_litre',
                    'label' => __('eq.price_per_litre'),
                    'type' => 'money',
                    'min' => 0,
                    'helper' => __('eq.price_hint'),
                    'rules' => ['required', 'numeric', 'min:0'],
                ],
                [
                    'key' => 'engine_hours',
                    'label' => __('eq.engine_hours'),
                    'type' => 'number',
                    'min' => 0,
                    'helper' => __('eq.engine_hours_adv_hint'),
                    'rules' => ['nullable', 'numeric', 'min:0'],
                ],
                [
                    'key' => 'kwh_produced',
                    'label' => __('eq.kwh_produced'),
                    'type' => 'number',
                    'min' => 0,
                    'helper' => __('eq.kwh_adv_hint'),
                    'rules' => ['nullable', 'numeric', 'min:0'],
                ],
                [
                    'key' => 'notes',
                    'label' => __('t.note'),
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
            ],
        ]];
    }

    public function defaults(?Model $subject = null): array
    {
        // The price he paid last time, or the office's own default from the settings screen: the first
        // refuelling of a fresh install must not open with an empty price box for him to look up.
        $lastPrice = Refuelling::query()->orderByDesc('date')->orderByDesc('id')->value('price_per_litre')
            ?? Setting::get('diesel_price_default');

        return [
            'date' => now()->toDateString(),
            'litres' => null,
            'price_per_litre' => filled($lastPrice) ? (string) $lastPrice : null,
            'engine_hours' => null,
            'kwh_produced' => null,
            'notes' => null,
        ];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Equipment $subject */
        $measured = filled($state['kwh_produced'] ?? null) && (float) $state['kwh_produced'] > 0;

        $refuelling = Refuelling::create([
            'equipment_id' => $subject->id,
            'date' => $state['date'],
            'litres' => (float) $state['litres'],
            'price_per_litre' => (float) $state['price_per_litre'],
            'engine_hours' => filled($state['engine_hours'] ?? null) ? (float) $state['engine_hours'] : null,
            // Zero is not a measurement: it means he did not write the kWh down, so the row stores nothing and
            // stays estimated, instead of claiming the machine produced nothing.
            'kwh_produced' => $measured ? (float) $state['kwh_produced'] : null,
            'kwh_source' => $measured ? Refuelling::SOURCE_MEASURED : Refuelling::SOURCE_ESTIMATED,
            'notes' => $state['notes'] ?: null,
            'created_by' => auth()->id(),
        ]);

        return ['ok' => true, 'message' => __('eq.refuel_ok', ['litres' => number_format((float) $refuelling->litres, 0)])];
    }
}
