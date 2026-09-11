<?php

namespace App\Admin\Forms;

use App\Admin\FormDefinition;
use App\Models\Tariff;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Model;

/**
 * A monthly kWh tariff.
 *
 * The tariff is what the next billing run prices with, so the period is validated against
 * the service's own PERIOD_REGEX and must be unique — two prices for one month would make
 * which one applies a coin toss.
 */
class TariffForm extends FormDefinition
{
    public function model(): string
    {
        return Tariff::class;
    }

    public function sections(): array
    {
        return [[
            'title' => __('mdl.tariff'),
            'description' => __('t.tariff_desc'),
            'fields' => [
                [
                    'key' => 'period',
                    'label' => __('t.period_ym'),
                    'default' => BillingService::currentPeriod(),
                    'placeholder' => BillingService::currentPeriod(),
                    'helper' => __('s.period_hint'),
                    'unique' => 'tariffs,period',
                    'rules' => ['required', 'regex:'.BillingService::PERIOD_REGEX],
                ],
                [
                    'key' => 'kwh_price',
                    'label' => __('gen.chip_tariff'),
                    'type' => 'money',
                    'min' => 0.0001,
                    'helper' => __('t.tariff_price_hint'),
                    'rules' => ['required', 'numeric', 'min:0.0001'],
                ],
                [
                    'key' => 'note',
                    'label' => __('t.note'),
                    'span' => 'full',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
            ],
        ]];
    }

    public function save(array $state, ?Model $record): Model
    {
        $attributes = [
            'period' => $state['period'],
            'kwh_price' => (float) $state['kwh_price'],
            'note' => $state['note'] ?: null,
        ];

        if ($record === null) {
            $attributes['created_by'] = auth()->id();

            return Tariff::create($attributes);
        }

        $record->fill($attributes)->save();

        return $record;
    }

    public function redirectAfterSave(Model $record): string
    {
        return route('admin.tariffs.index');
    }

    public function recordTitle(Model $record): string
    {
        return (string) $record->period;
    }
}
