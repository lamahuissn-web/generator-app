<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\Tariff;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Monthly kWh tariffs. The newest period is what the next billing run will price with. */
class TariffsTable extends TableDefinition
{
    public function model(): string
    {
        return Tariff::class;
    }

    public function query(): Builder
    {
        return Tariff::query()->with('createdBy:id,name');
    }

    public function searchable(): array
    {
        return ['period', 'note'];
    }

    public function defaultSort(): array
    {
        return ['period', 'desc'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'period', 'label' => __('t.period'), 'sortable' => true, 'searchable' => true],
            [
                'key' => 'kwh_price',
                'label' => __('gen.chip_tariff'),
                'type' => 'money',
                'sortable' => true,
                'align' => 'end',
                'render' => fn (Tariff $r): string => money($r->kwh_price),
            ],
            [
                'key' => 'note',
                'label' => __('t.note'),
                'on_mobile' => false,
                'render' => fn (Tariff $r): string => $r->note ?: '—',
            ],
            [
                'key' => 'created_by',
                'label' => __('t.by'),
                'on_mobile' => false,
                'render' => fn (Tariff $r): string => $r->createdBy?->name ?? '—',
            ],
            ['key' => 'created_at', 'label' => __('t.created'), 'type' => 'date', 'sortable' => true, 'hidden' => true, 'on_mobile' => false],
        ];
    }

    public function rowActions(): array
    {
        return [
            ['key' => 'edit', 'label' => __('t.edit'), 'icon' => 'ki-pencil', 'url' => fn (Tariff $r) => route('admin.tariffs.edit', $r)],
            ['key' => 'delete', 'label' => __('t.delete'), 'icon' => 'ki-trash', 'variant' => 'danger', 'method' => 'deleteRecord'],
        ];
    }

    public function canDelete(Model $row): bool
    {
        return true;
    }

    public function deleteWarning(Model $row): string
    {
        return __('a.del_tariff_guard', ['period' => $row->period]);
    }

    public function deleteRecord(Model $row): void
    {
        $row->delete();
    }
}
