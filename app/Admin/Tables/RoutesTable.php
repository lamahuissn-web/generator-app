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
                // An area that still holds houses does not offer the button at all: deleting it strands its
                // subscribers with no area, and the board would then attribute their consumption to no area.
                'hidden' => fn (Route $r): bool => ! $this->canDelete($r),
            ],
        ];
    }

    public function canDelete(Model $row): bool
    {
        return $row->subscribers()->doesntExist();
    }

    /** The refusal names the way out: move the houses first, or keep the area. */
    public function deleteRefusal(Model $row): ?string
    {
        return $this->canDelete($row) ? null : __('a.del_route_guard');
    }

    public function deleteWarning(Model $row): string
    {
        return $row->subscribers()->exists()
            ? __('a.del_route_guard')
            : __('t.confirm_delete_record');
    }

    public function deleteRecord(Model $row): void
    {
        if (! $this->canDelete($row)) {
            throw new \RuntimeException(__('a.del_route_guard'));
        }

        $row->delete();
    }
}
