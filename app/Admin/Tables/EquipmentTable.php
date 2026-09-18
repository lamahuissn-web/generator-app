<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\Equipment;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The devices an office owns.
 *
 * A one-generator office never sees this list: the board links straight to the single file, so this
 * screen exists only for offices that have more than one machine.
 */
class EquipmentTable extends TableDefinition
{
    public function model(): string
    {
        return Equipment::class;
    }

    public function query(): Builder
    {
        return Equipment::query();
    }

    public function searchable(): array
    {
        return ['name', 'serial', 'capacity', 'notes'];
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'name',
                'label' => __('eq.name'),
                'sortable' => true,
                'searchable' => true,
                'render' => fn (Equipment $r): string => $r->name,
            ],
            [
                'key' => 'kind',
                'label' => __('eq.kind'),
                'type' => 'badge',
                'sortable' => true,
                'color' => fn (Equipment $r): string => match ($r->kind) {
                    Equipment::KIND_GENERATOR => 'warning',
                    Equipment::KIND_PUMP => 'info',
                    Equipment::KIND_UPS => 'success',
                    default => 'muted',
                },
                'render' => fn (Equipment $r): string => $r->kindLabel(),
            ],
            [
                'key' => 'capacity',
                'label' => __('eq.capacity'),
                'on_mobile' => false,
                'render' => fn (Equipment $r): string => $r->capacity ?: '—',
            ],
            [
                'key' => 'installed_on',
                'label' => __('eq.installed_on'),
                'type' => 'date',
                'sortable' => true,
                'on_mobile' => false,
                'render' => fn (Equipment $r): string => $r->installed_on?->format('Y-m-d') ?? '—',
            ],
            [
                'key' => 'status',
                'label' => __('t.status'),
                'type' => 'badge',
                'sortable' => true,
                'color' => fn (Equipment $r): string => match ($r->status) {
                    Equipment::STATUS_WORKING => 'success',
                    Equipment::STATUS_SERVICE => 'warning',
                    default => 'destructive',
                },
                'render' => fn (Equipment $r): string => $r->statusLabel(),
            ],
            [
                'key' => 'serial',
                'label' => __('eq.serial'),
                'hidden' => true,
                'on_mobile' => false,
                'render' => fn (Equipment $r): string => $r->serial ?: '—',
            ],
        ];
    }

    public function rowUrl(Model $row): ?string
    {
        return $row instanceof Equipment
            ? route('admin.device-file', $row)
            : null;
    }

    public function filters(): array
    {
        return [
            [
                'key' => 'kind',
                'label' => __('eq.kind'),
                'type' => 'select',
                'options' => Equipment::kinds(),
                'apply' => fn (Builder $query, $value) => $query->where('kind', $value),
            ],
            [
                'key' => 'status',
                'label' => __('t.status'),
                'type' => 'select',
                'options' => Equipment::statuses(),
                'apply' => fn (Builder $query, $value) => $query->where('status', $value),
            ],
        ];
    }

    public function rowActions(): array
    {
        return [
            ['key' => 'edit', 'label' => __('t.edit'), 'icon' => 'ki-pencil', 'url' => fn (Equipment $r) => route('admin.equipment.edit', $r)],
            [
                'key' => 'delete', 'label' => __('t.delete'), 'icon' => 'ki-trash', 'variant' => 'danger',
                'method' => 'deleteRecord',
                // A machine with a file does not offer the button at all: a refusal after the click is a worse
                // way to teach the same rule.
                'hidden' => fn (Equipment $r): bool => ! $this->canDelete($r),
            ],
        ];
    }

    /**
     * A machine with a history is not deleted.
     *
     * Its refuellings and services ARE its file: deleting the device cascades its services away and leaves the
     * refuellings orphaned — still counted in the centre's fuel and production, so the month would keep the
     * consumption of a machine that no longer exists. A machine that stopped working is a STATE («متوقف»),
     * which keeps its file and lets the centre say which device is carrying the load.
     */
    public function canDelete(Model $row): bool
    {
        return $row->refuellings()->doesntExist() && $row->services()->doesntExist();
    }

    /** The table's own refusal line, in the office's words — the generic one explains nothing. */
    public function deleteRefusal(Model $row): ?string
    {
        return $this->canDelete($row) ? null : __('eq.delete_has_logs', ['name' => $row->name]);
    }

    public function deleteRecord(Model $row): void
    {
        if (! $this->canDelete($row)) {
            throw new \RuntimeException(__('eq.delete_has_logs', ['name' => $row->name]));
        }

        $row->delete();
    }
}
