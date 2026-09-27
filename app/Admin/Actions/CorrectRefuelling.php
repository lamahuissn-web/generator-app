<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Refuelling;
use App\Services\CycleLock;
use App\Services\EquipmentLog;
use Illuminate\Database\Eloquent\Model;

/**
 * تصحيح تعبئة — the way back from a typo in what the machine drank.
 *
 * The form is the add form prefilled with the row's own values, and one save REPLACES the row: the old one
 * is removed and the new one written in the same transaction (see `EquipmentLog`). The owner experiences an
 * edit; the database keeps no half-corrected line, and the figures the row feeds (produced kWh, the loss,
 * cost per kWh, the next service date, the litres-per-hour anomaly) are re-derived from the new values.
 *
 * The السبب field is always on screen and required only where it is owed — in a month CycleLock has already
 * closed, since that month was reported and collected against.
 */
class CorrectRefuelling extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Refuelling::class;
    }

    public function label(): string
    {
        return __('a.correct_refuel');
    }

    public function icon(): ?string
    {
        return 'ki-pencil';
    }

    public function triggerClass(): string
    {
        return 'kt-btn-outline';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.correct_refuel_heading', ['date' => $subject->date?->toDateString()]);
    }

    public function modalDescription(): ?string
    {
        return __('a.correct_refuel_desc');
    }

    public function submitLabel(): string
    {
        return __('a.correct_refuel');
    }

    /** المدير فقط — the button is not rendered for anyone else, and `EquipmentLog` refuses at the write site. */
    public function visible(Model $subject): bool
    {
        return (bool) auth()->user()?->isAdmin();
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
                [
                    'key' => 'reason',
                    'label' => __('t.reason'),
                    'helper' => __('t.reason_hint_closed'),
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
            ],
        ]];
    }

    /**
     * A closed month's correction needs the reason in words; an open one is the operator's own day's work.
     *
     * The field stays visible in both cases so the operator can see WHAT is being asked for, and the same
     * gate is repeated in `EquipmentLog::correctRefuelling()` — a rule that lives only in the form is a rule
     * the next caller does not have.
     */
    public function rules(array $state, ?Model $record = null): array
    {
        $rules = parent::rules($state, $record);

        if ($this->subject instanceof Refuelling && CycleLock::isClosed($this->subject->date?->format('Y-m'))) {
            $rules['state.reason'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    public function defaults(?Model $subject = null): array
    {
        /** @var Refuelling $subject */
        return [
            'date' => $subject->date?->toDateString(),
            'litres' => $subject->litres,
            'price_per_litre' => $subject->price_per_litre,
            'engine_hours' => $subject->engine_hours,
            'kwh_produced' => $subject->kwh_produced,
            'notes' => $subject->notes,
            'reason' => null,
        ];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Refuelling $subject */
        $row = EquipmentLog::correctRefuelling($subject, $state, $state['reason'] ?? null);

        return ['ok' => true, 'message' => __('a.correct_refuel_ok', ['litres' => number_format((float) $row->litres, 0)])];
    }
}
