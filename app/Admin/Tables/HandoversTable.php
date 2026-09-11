<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\CashHandover;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Cash handed from a collector to the operator.
 *
 * "Handed over" is what the collector physically gave; the collector's balance is
 * all-time (everything they ever collected minus everything they ever handed over), so it is
 * never derived from the range being displayed.
 */
class HandoversTable extends TableDefinition
{
    public function model(): string
    {
        return CashHandover::class;
    }

    public function query(): Builder
    {
        return CashHandover::query()->with(['collector:id,name', 'recordedBy:id,name']);
    }

    public function searchable(): array
    {
        return ['note', 'collector.name'];
    }

    public function defaultSort(): array
    {
        return ['handover_date', 'desc'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'handover_date', 'label' => __('col.date'), 'type' => 'date', 'sortable' => true],
            [
                'key' => 'collector.name',
                'label' => __('col.collector'),
                'sortable' => true,
                'searchable' => true,
                'render' => fn (CashHandover $r): string => $r->collector?->name ?? __('col.unknown_collector'),
            ],
            [
                'key' => 'amount',
                'label' => __('col.amount'),
                'type' => 'money',
                'sortable' => true,
                'align' => 'end',
                'render' => fn (CashHandover $r): string => money($r->amount),
            ],
            [
                'key' => 'note',
                'label' => __('col.note'),
                'on_mobile' => false,
                'render' => fn (CashHandover $r): string => $r->note ?: '—',
            ],
            [
                'key' => 'recorded_by',
                'label' => __('col.recorded_by'),
                'on_mobile' => false,
                'render' => fn (CashHandover $r): string => $r->recordedBy?->name ?? '—',
            ],
            ['key' => 'created_at', 'label' => __('t.created'), 'type' => 'date', 'sortable' => true, 'hidden' => true, 'on_mobile' => false],
        ];
    }

    public function filters(): array
    {
        return [
            [
                'key' => 'collector_id',
                'label' => __('col.collector'),
                'type' => 'select',
                'options_from' => fn (): array => User::query()->where('role', 'collector')
                    ->orderBy('name')->pluck('name', 'id')->all(),
                'apply' => fn (Builder $query, $value) => $query->where('collector_id', $value),
            ],
            [
                'key' => 'month',
                'label' => __('col.month'),
                'type' => 'select',
                'options_from' => fn (): array => CashHandover::query()
                    ->selectRaw("substr(handover_date, 1, 7) as m")
                    ->distinct()->orderByDesc('m')->pluck('m', 'm')->all(),
                'apply' => fn (Builder $query, $value) => $query->where('handover_date', 'like', $value.'%'),
            ],
        ];
    }

    public function rowActions(): array
    {
        return [
            ['key' => 'edit', 'label' => __('t.edit'), 'icon' => 'ki-pencil', 'url' => fn (CashHandover $r) => route('admin.handovers.edit', $r)],
            ['key' => 'delete', 'label' => __('t.delete'), 'icon' => 'ki-trash', 'variant' => 'danger', 'method' => 'deleteRecord'],
        ];
    }

    public function canDelete(Model $row): bool
    {
        return true;
    }

    /** Deleting a handover puts the money back in the collector's hand, and must say so. */
    public function deleteWarning(Model $row): string
    {
        return __('col.delete_warning', [
            'amount' => money_ltr($row->amount),
            'name' => $row->collector?->name ?? __('col.unknown_collector'),
            'in_hand' => money_ltr(\App\Services\CollectionService::inHand($row->collector_id) + (float) $row->amount),
        ]);
    }

    public function deleteRecord(Model $row): void
    {
        \App\Services\CashHandoverService::delete($row);
    }
}
