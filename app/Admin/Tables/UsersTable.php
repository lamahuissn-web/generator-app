<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\BackupAttempt;
use App\Models\CashHandover;
use App\Models\Expense;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Audit;
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
        return ['name', 'username', 'email', 'phone'];
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
                'key' => 'status',
                'label' => __('t.status'),
                'type' => 'badge',
                'sortable' => true,
                'color' => fn (User $r): string => $r->isActive() ? 'success' : 'muted',
                'render' => fn (User $r): string => $r->isActive() ? __('t.account_active') : __('t.account_inactive'),
            ],
            [
                'key' => 'username',
                'label' => __('t.username'),
                'sortable' => true,
                'searchable' => true,
                'render' => fn (User $r): string => ltr_isolate((string) $r->username),
            ],
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

    /**
     * An account that NAMES a figure in the ledger cannot be deleted — the name IS the record.
     *
     * Every one of those columns is `nullOnDelete`, so deleting the account does not fail: it
     * quietly strips the name off the receipts he collected and the records he entered, and the
     * office keeps amounts it can no longer attribute to anybody. The way out is the status:
     * «معطّل» takes the access away at once and leaves his history with his name on it.
     */
    public function canDelete(Model $row): bool
    {
        return ! $row->is(auth()->user()) && ! $this->hasFootprint($row);
    }

    /** The refusal names the way out; deleting yourself is a different refusal with its own words. */
    public function deleteRefusal(Model $row): ?string
    {
        if ($row->is(auth()->user())) {
            return __('a.del_user_self');
        }

        return $this->hasFootprint($row) ? __('a.del_user_refused') : null;
    }

    /** Every column that names him, checked in one place so the rule is described once. */
    private function hasFootprint(User $row): bool
    {
        return $row->collectedPayments()->exists()
            || $row->enteredReadings()->exists()
            || CashHandover::query()->where('collector_id', $row->id)->orWhere('recorded_by', $row->id)->exists()
            || Expense::query()->where('entered_by', $row->id)->exists()
            || Subscriber::query()->where('created_by', $row->id)->exists()
            || Subscriber::query()->where('meter_broken_reported_by', $row->id)->exists()
            || Tariff::query()->where('created_by', $row->id)->exists()
            || BackupAttempt::query()->where('created_by', $row->id)->exists();
    }

    /** Only an account that names nothing gets this far, so the warning is the plain one. */
    public function deleteWarning(Model $row): string
    {
        return __('t.confirm_delete_record');
    }

    public function deleteRecord(Model $row): void
    {
        Audit::log('user.deleted', $row, [
            'name' => $row->name,
            'username' => $row->username,
            'email' => $row->email,
            'role' => $row->role,
        ]);

        $row->delete();
    }
}
