<?php

namespace App\Admin\Forms;

use App\Admin\FormDefinition;
use App\Models\CashHandover;
use App\Models\User;
use App\Services\CashHandoverService;
use App\Services\CollectionService;
use Illuminate\Database\Eloquent\Model;

/**
 * Record cash a collector handed to the operator.
 *
 * The helper under the amount states what the collector is currently holding, because a
 * handover is almost always a correction of that figure — and the all-time balance is the
 * number that matters, not anything scoped to a report range.
 */
class HandoverForm extends FormDefinition
{
    /** Collector chosen in the form, so the helper text can follow the selection. */
    public ?int $collectorId = null;

    public function model(): string
    {
        return CashHandover::class;
    }

    public function sections(): array
    {
        return [[
            'title' => __('col.quick_handover'),
            'fields' => [
                [
                    'key' => 'collector_id',
                    'label' => __('col.collector'),
                    'type' => 'select',
                    'options_from' => fn (): array => User::query()->where('role', 'collector')
                        ->orderBy('name')->pluck('name', 'id')->all(),
                    'rules' => ['required', 'exists:users,id'],
                ],
                [
                    'key' => 'handover_date',
                    'label' => __('col.date'),
                    'type' => 'date',
                    'default' => now()->toDateString(),
                    'rules' => ['required', 'date'],
                ],
                [
                    'key' => 'amount',
                    'label' => __('col.amount'),
                    'type' => 'money',
                    'min' => 0.01,
                    'span' => 'full',
                    'helper' => __('col.handover_helper'),
                    'rules' => ['required', 'numeric', 'min:0.01'],
                ],
                [
                    'key' => 'note',
                    'label' => __('col.note'),
                    'span' => 'full',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
            ],
        ]];
    }

    public function defaults(?Model $subject = null): array
    {
        return [
            'collector_id' => null,
            'handover_date' => now()->toDateString(),
            'amount' => null,
            'note' => null,
        ];
    }

    public function stateFrom(Model $record): array
    {
        return [
            'collector_id' => $record->collector_id,
            'handover_date' => $record->handover_date?->toDateString(),
            'amount' => (float) $record->amount,
            'note' => $record->note,
        ];
    }

    /**
     * The amount is capped at the cash the collector is actually holding.
     *
     * The service refuses an over-handover too — that is the authoritative guard, and it covers
     * every caller — but a rule puts the reason on the field the operator is typing into instead
     * of at the top of the form. Both read the same figure and the same message, so they cannot
     * drift apart.
     */
    public function rules(array $state, ?Model $record = null): array
    {
        $rules = parent::rules($state, $record);

        if (empty($state['collector_id'])) {
            return $rules;   // no collector chosen yet: nothing to compare against
        }

        $available = CashHandoverService::availableFor(
            (int) $state['collector_id'],
            $record instanceof CashHandover ? $record : null,
        );

        $rules['state.amount'][] = function (string $attribute, mixed $value, \Closure $fail) use ($available): void {
            if (round((float) $value, 2) > $available) {
                $fail(__('refuse.handover_exceeds_in_hand', ['amount' => money_ltr($available)]));
            }
        };

        return $rules;
    }

    public function save(array $state, ?Model $record): Model
    {
        $data = [
            'collector_id' => (int) $state['collector_id'],
            'amount' => (float) $state['amount'],
            'handover_date' => $state['handover_date'],
            'note' => $state['note'] ?: null,
        ];

        return $record === null
            ? CashHandoverService::create($data, auth()->id())
            : CashHandoverService::update($record, $data);
    }

    public function redirectAfterSave(Model $record): string
    {
        return route('admin.handovers.index');
    }

    public function recordTitle(Model $record): string
    {
        return money($record->amount).' · '.($record->collector?->name ?? '');
    }

    /** Cash currently held by a collector, for the form's helper line. */
    public function inHandFor(?int $collectorId): float
    {
        return $collectorId ? CollectionService::inHand($collectorId) : 0.0;
    }
}
