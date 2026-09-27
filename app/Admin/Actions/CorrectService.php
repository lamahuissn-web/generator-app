<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\EquipmentService;
use App\Services\CycleLock;
use App\Services\EquipmentLog;
use Illuminate\Database\Eloquent\Model;

/**
 * تصحيح صيانة — and the expense it produced moves with it.
 *
 * A service carries money: `equipment_services.expense_id` points at the `expenses` row the month's costs
 * are read from. So a correction here is two rows in one transaction (see `EquipmentLog::correctService()`):
 * the service is replaced, and its expense is updated, created, or — when the correction says the service no
 * longer cost anything — DELETED, because an expense left beside it would keep reporting a repair the office
 * has just corrected away.
 *
 * «تسجيل كمصروف» opens on the row's own current state, so re-saving a form the operator only glanced at
 * cannot silently add or drop the expense.
 */
class CorrectService extends ActionDefinition
{
    public function subjectModel(): string
    {
        return EquipmentService::class;
    }

    public function label(): string
    {
        return __('a.correct_service');
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
        return __('a.correct_service_heading', ['date' => $subject->date?->toDateString()]);
    }

    public function modalDescription(): ?string
    {
        return __('a.correct_service_desc');
    }

    public function submitLabel(): string
    {
        return __('a.correct_service');
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
                    'key' => 'kind',
                    'label' => __('eq.service_kind'),
                    'type' => 'select',
                    'options' => EquipmentService::kinds(),
                    'rules' => ['required', 'in:'.implode(',', array_keys(EquipmentService::kinds()))],
                ],
                [
                    'key' => 'engine_hours',
                    'label' => __('eq.engine_hours'),
                    'type' => 'number',
                    'min' => 0,
                    'helper' => __('eq.engine_hours_hint'),
                    'rules' => ['nullable', 'numeric', 'min:0'],
                ],
                [
                    'key' => 'cost',
                    'label' => __('t.amount'),
                    'type' => 'money',
                    'min' => 0,
                    'rules' => ['nullable', 'numeric', 'min:0'],
                ],
                [
                    'key' => 'record_expense',
                    'label' => __('eq.record_expense'),
                    'type' => 'checkbox',
                    'helper' => __('eq.record_expense_hint'),
                    'rules' => ['boolean'],
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

    /** See `CorrectRefuelling::rules()` — the field is always shown, required only in a closed month. */
    public function rules(array $state, ?Model $record = null): array
    {
        $rules = parent::rules($state, $record);

        if ($this->subject instanceof EquipmentService && CycleLock::isClosed($this->subject->date?->format('Y-m'))) {
            $rules['state.reason'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    public function defaults(?Model $subject = null): array
    {
        /** @var EquipmentService $subject */
        return [
            'date' => $subject->date?->toDateString(),
            'kind' => $subject->kind,
            'engine_hours' => $subject->engine_hours,
            'cost' => $subject->cost,
            // The row's own state, not «نعم»: the operator is correcting a figure, not deciding the money
            // question again by accident.
            'record_expense' => $subject->expense_id !== null,
            'notes' => $subject->notes,
            'reason' => null,
        ];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var EquipmentService $subject */
        $row = EquipmentLog::correctService($subject, $state, $state['reason'] ?? null);

        return ['ok' => true, 'message' => __('a.correct_service_ok', ['kind' => $row->kindLabel()])];
    }
}
