<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Subscriber;
use App\Services\ReadingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Record this subscriber's meter reading — the daily visit, from the profile the operator is
 * already looking at.
 *
 * The three modes and their fields come from ReadingService, so this modal and the standalone
 * readings screen offer exactly the same choices. The subject is fixed here (the subscriber is
 * the profile on screen) which is why modeFields() is asked for the fields without a picker.
 */
class RecordReading extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Subscriber::class;
    }

    public function label(): string
    {
        return __('a.record_reading');
    }

    public function icon(): ?string
    {
        return 'ki-bill';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.record_reading_for', ['name' => $subject->name]);
    }

    public function modalDescription(): ?string
    {
        return __('t.reading_mode_hint');
    }

    public function submitLabel(): string
    {
        return __('a.save_reading');
    }

    /** Nothing to read for a subscriber who is not on a meter. */
    public function visible(Model $subject): bool
    {
        return $subject->status === Subscriber::STATUS_ACTIVE;
    }

    public function sections(): array
    {
        return [[
            'title' => '',
            'fields' => ReadingService::modeFields(withSubject: false),
        ]];
    }

    public function defaults(?Model $subject = null): array
    {
        return [
            'period' => now()->format('Y-m'),
            'reading_mode' => ReadingService::MODE_NORMAL,
            'current_reading' => null,
            'estimated_kwh' => null,
            'estimate_reason' => __('t.estimate_reason_default'),
            'old_meter_no' => $subject instanceof Subscriber ? $subject->meter_no : null,
            'old_meter_reading' => null,
            'new_meter_no' => null,
            'new_meter_start' => 0,
            'note' => null,
        ];
    }

    /**
     * A swap's new meter number must be free.
     *
     * Two subscribers on one meter number is only discovered months later, when the consumption
     * reports make no sense and the bills cannot be reconciled.
     */
    public function rules(array $state, ?Model $record = null): array
    {
        return array_merge(parent::rules($state, $record), [
            'state.new_meter_no' => [
                'nullable', 'string', 'max:40',
                \Illuminate\Validation\Rule::unique('subscribers', 'meter_no')
                    ->ignore($this->subject?->getKey()),
            ],
            'state.period' => [
                'required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
            ],
        ]);
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Subscriber $subject */
        $result = ReadingService::record($subject, $state, auth()->id());

        $reading = $result['reading'];
        $bill = $result['bill'];

        return ['ok' => true, 'message' => ReadingService::summary(
            $reading,
            (float) $bill->total,
            (float) $bill->prev_debt,
        )];
    }
}
