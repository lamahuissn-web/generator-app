<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UsersTable extends TableDefinition
{
    public function model(): string
    {
        return User::class;
    }

    public function query(): Builder
    {
        return User::query();
    }

    public function searchable(): array
    {
        return ['name', 'email', 'phone'];
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
                'key' => 'email',
                'label' => __('t.email'),
                'searchable' => true,
                'render' => fn (User $r): string => ltr_isolate($r->email),
            ],
            [
                'key' => 'role',
                'label' => __('t.role'),
                'type' => 'badge',
                'sortable' => true,
                'color' => fn (User $r): string => $r->isAdmin() ? 'warning' : 'info',
                'render' => fn (User $r): string => $r->isAdmin() ? __('t.role_admin') : __('t.role_collector'),
            ],
            [
                'key' => 'phone',
                'label' => __('t.phone'),
                'on_mobile' => false,
                'render' => fn (User $r): string => $r->phone ? ltr_isolate($r->phone) : '—',
            ],
            ['key' => 'created_at', 'label' => __('t.created'), 'type' => 'date', 'sortable' => true, 'hidden' => true, 'on_mobile' => false],
        ];
    }

    public function filters(): array
    {
        return [[
            'key' => 'role',
            'label' => __('t.role'),
            'type' => 'select',
            'options' => [
                User::ROLE_ADMIN => __('t.role_admin'),
                User::ROLE_COLLECTOR => __('t.role_collector'),
            ],
            'apply' => fn (Builder $query, $value) => $query->where('role', $value),
        ]];
    }

    public function rowActions(): array
    {
        return [
            ['key' => 'edit', 'label' => __('t.edit'), 'icon' => 'ki-pencil', 'url' => fn (User $r) => route('admin.users.edit', $r)],
            [
                'key' => 'delete',
                'label' => __('t.delete'),
                'icon' => 'ki-trash',
                'variant' => 'danger',
                'method' => 'deleteRecord',
                // Deleting yourself locks the panel: the hidden() guard removes the button.
                'hidden' => fn (User $r): bool => $r->is(auth()->user()),
            ],
        ];
    }

    public function canDelete(Model $row): bool
    {
        return ! $row->is(auth()->user());
    }

    public function deleteWarning(Model $row): string
    {
        return __('a.del_user_guard');
    }

    public function deleteRecord(Model $row): void
    {
        $row->delete();
    }
}
