<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Equipment;
use App\Models\EquipmentService;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * صيانة — what was done to this machine and when it is due again.
 *
 * Four kinds (oil, filter, repair, inspection), the hours on the clock, and what it cost. The cost
 * is the money record's link to expenses only when the office chooses: the service is the FIX, the
 * expense is the MONEY, and they need not be typed twice.
 */
class RecordService extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Equipment::class;
    }

    public function label(): string
    {
        return __('eq.service_action');
    }

    public function icon(): ?string
    {
        return 'ki-setting-2';
    }

    public function modalHeading(Model $subject): string
    {
        return __('eq.service_for', ['name' => $subject->name]);
    }

    public function modalDescription(): ?string
    {
        return __('eq.service_hint');
    }

    public function submitLabel(): string
    {
        return __('eq.service_save');
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
                    'key' => 'kind',
                    'label' => __('eq.service_kind'),
                    'type' => 'select',
                    'default' => EquipmentService::KIND_OIL,
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
                    'default' => true,
                    'helper' => __('eq.record_expense_hint'),
                    'rules' => ['boolean'],
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
        return [
            'date' => now()->toDateString(),
            'kind' => EquipmentService::KIND_OIL,
            'engine_hours' => null,
            'cost' => null,
            'record_expense' => true,
            'notes' => null,
        ];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Equipment $subject */
        $cost = filled($state['cost'] ?? null) ? round((float) $state['cost'], 2) : 0.0;

        $service = DB::transaction(function () use ($state, $subject, $cost): EquipmentService {
            // The money lives in `expenses`, not in a second ledger beside it: a service that cost something
            // writes the expense row and links to it, so the office records the payment ONCE and the month's
            // costs stay honest (a service log that also counted money would double the same dinar).
            // The link is OPTIONAL (FR-5): an office that already booked the repair by hand unticks the box, and
            // the same cost is not counted into `net` twice.
            $expense = $cost > 0 && (bool) ($state['record_expense'] ?? true)
                ? Expense::create([
                    'category' => self::expenseCategory((string) $state['kind']),
                    'description' => __('eq.service_expense_note', [
                        'device' => $subject->name,
                        'kind' => EquipmentService::kinds()[$state['kind']] ?? (string) $state['kind'],
                    ]),
                    'amount' => $cost,
                    'expense_date' => $state['date'],
                    'entered_by' => auth()->id(),
                ])
                : null;

            return EquipmentService::create([
                'equipment_id' => $subject->id,
                'date' => $state['date'],
                'kind' => $state['kind'],
                'engine_hours' => filled($state['engine_hours'] ?? null) ? (float) $state['engine_hours'] : null,
                'cost' => $cost,
                'expense_id' => $expense?->id,
                'notes' => $state['notes'] ?: null,
                'created_by' => auth()->id(),
            ]);
        });

        return ['ok' => true, 'message' => __('eq.service_ok', ['kind' => $service->kindLabel()])];
    }

    /** Which expense category a service belongs to, so the books read like the office's own language. */
    private static function expenseCategory(string $kind): string
    {
        return match ($kind) {
            EquipmentService::KIND_OIL, EquipmentService::KIND_FILTER => Expense::CAT_OIL,
            EquipmentService::KIND_REPAIR => Expense::CAT_REPAIR,
            default => Expense::CAT_OTHER,
        };
    }
}
