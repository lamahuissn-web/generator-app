<?php

namespace App\Admin;

use App\Admin\Forms\ExpenseForm;
use App\Admin\Forms\ReadingForm;
use App\Admin\Forms\RouteForm;
use App\Admin\Forms\TariffForm;
use App\Admin\Forms\UserForm;
use App\Admin\Tables\AuditLogsTable;
use App\Admin\Tables\BillsTable;
use App\Admin\Tables\ExpensesTable;
use App\Admin\Tables\PaymentsTable;
use App\Admin\Tables\ReadingsTable;
use App\Admin\Tables\RoutesTable;
use App\Admin\Tables\TariffsTable;
use App\Admin\Tables\UsersTable;

/**
 * The panel's list resources, as data.
 *
 * One entry per screen, and the route file loops over it, so adding a screen is one array
 * entry plus its two classes — not a controller, a route line, and a pair of views. A
 * resource with no `form` is read-only (the list is the whole screen).
 */
class ResourceRegistry
{
    /**
     * @return array<string, array{table: class-string<TableDefinition>, form?: class-string<FormDefinition>, title: string, singular: string, description?: string}>
     */
    public static function all(): array
    {
        return [
            'routes' => [
                'table' => RoutesTable::class,
                'form' => RouteForm::class,
                'title' => __('mdl.routes'),
                'singular' => __('mdl.route'),
                'description' => __('t.routes_sub'),
            ],
            'readings' => [
                'table' => ReadingsTable::class,
                'form' => ReadingForm::class,
                // Create-only: a reading is a measurement of a moment. Correcting one means
                // deleting it (and the bill it produced) and recording it again, so an edit
                // screen would invite an edit that left the old bill behind.
                'create_only' => true,
                'title' => __('mdl.readings'),
                'singular' => __('mdl.reading'),
                'description' => __('t.readings_sub'),
                'header' => 'admin.resource.readings-header',
                'actions_view' => 'admin.resource.readings-actions',
            ],
            'bills' => [
                'table' => BillsTable::class,
                'form' => null,
                'title' => __('mdl.bills'),
                'singular' => __('mdl.bill'),
                'description' => __('t.bills_sub'),
            ],
            'payments' => [
                'table' => PaymentsTable::class,
                'form' => null,
                'title' => __('mdl.payments'),
                'singular' => __('mdl.payment'),
                'description' => __('t.payments_sub'),
            ],
            'tariffs' => [
                'table' => TariffsTable::class,
                'form' => TariffForm::class,
                'title' => __('mdl.tariffs'),
                'singular' => __('mdl.tariff'),
                'description' => __('t.tariffs_sub'),
            ],
            'expenses' => [
                'table' => ExpensesTable::class,
                'form' => ExpenseForm::class,
                'title' => __('mdl.expenses'),
                'singular' => __('mdl.expense'),
                'description' => __('t.expenses_sub'),
            ],
            'users' => [
                'table' => UsersTable::class,
                'form' => UserForm::class,
                'title' => __('mdl.users'),
                'singular' => __('mdl.user'),
                'description' => __('t.users_sub'),
                'admin_only' => true,
            ],
            'audit-logs' => [
                'table' => AuditLogsTable::class,
                'form' => null,
                'title' => __('mdl.audit_log'),
                'singular' => __('mdl.audit_log'),
                'description' => __('t.audit_logs_sub'),
                'admin_only' => true,
            ],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $key): array
    {
        $all = self::all();

        if (! isset($all[$key])) {
            abort(404);
        }

        return $all[$key];
    }

    /** Resources the signed-in user may open. */
    public static function forUser(?\App\Models\User $user): array
    {
        return array_filter(
            self::all(),
            fn (array $r): bool => empty($r['admin_only']) || (bool) $user?->isAdmin(),
        );
    }
}
