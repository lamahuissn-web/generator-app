<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\Route;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RoutesTable extends TableDefinition
{
    public function model(): string
    {
        return Route::class;
    }

    public function query(): Builder
    {
        return Route::query()
            ->withCount(['subscribers as active_count' => fn ($q) => $q->where('status', 'active')])
            ->withCount('subscribers');
    }

    public function searchable(): array
    {
        return ['name', 'notes'];
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => __('t.name'), 'sortable' => true, 'searchable' => true],
            [
                'key' => 'active_count',
                'label' => __('t.active_subs'),
                'type' => 'number',
                'sortable' => true,
                'align' => 'end',
                'render' => fn (Route $r): string => (string) $r->active_count,
            ],
            [
                'key' => 'subscribers_count',
                'label' => __('t.total'),
                'type' => 'number',
                'sortable' => true,
                'align' => 'end',
                'render' => fn (Route $r): string => (string) $r->subscribers_count,
            ],
            [
                'key' => 'notes',
                'label' => __('s.notes'),
                'on_mobile' => false,
                'render' => fn (Route $r): string => $r->notes ?: '—',
            ],
            ['key' => 'created_at', 'label' => __('t.created'), 'type' => 'date', 'sortable' => true, 'hidden' => true, 'on_mobile' => false],
        ];
    }

    public function rowActions(): array
    {
        return [
            [
                'key' => 'subscribers',
                'label' => __('mdl.subscribers'),
                'icon' => 'ki-users',
                'url' => fn (Route $r) => route('admin.subscribers.index', ['filters' => ['route_id' => $r->id]]),
            ],
            [
                'key' => 'edit',
                'label' => __('t.edit'),
                'icon' => 'ki-pencil',
                'url' => fn (Route $r) => route('admin.routes.edit', $r),
            ],
            [
                'key' => 'delete',
                'label' => __('t.delete'),
                'icon' => 'ki-trash',
                'variant' => 'danger',
                'method' => 'deleteRecord',
            ],
        ];
    }

    public function canDelete(Model $row): bool
    {
        return true;
    }

    public function deleteWarning(Model $row): string
    {
        return $row->subscribers()->exists()
            ? __('a.del_route_guard')
            : __('t.confirm_delete_record');
    }

    public function deleteRecord(Model $row): void
    {
        $row->delete();
    }
}
