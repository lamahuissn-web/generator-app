<?php

namespace App\Admin;

use App\Models\User;

/**
 * The admin sidebar, as data.
 *
 * The nav is a flat description rather than markup so the layout stays presentation-only,
 * the translation keys are resolved at render time (so a locale switch needs no redeploy),
 * and adding a screen is one array entry instead of a Blade edit.
 *
 * `match` holds the ROUTE NAME patterns that light the item up. Route names rather than
 * paths: the panel was built at /manage and moved to /admin once every screen was rebuilt,
 * and a path-based match would have silently stopped highlighting everything on the day of
 * the swap. `admin.subscribers.*` covers the detail and edit pages too.
 */
class AdminNav
{
    /** @return array<int, array{label: string, items: array<int, array<string, mixed>>}> */
    public static function groups(): array
    {
        return [
            [
                'label' => __('nav.network'),
                'items' => [
                    [
                        'label' => __('mdl.routes'),
                        'icon' => 'ki-map',
                        'route' => 'admin.routes.index',
                        'match' => ['admin.routes.*'],
                    ],
                    [
                        'label' => __('mdl.subscribers'),
                        'icon' => 'ki-users',
                        'route' => 'admin.subscribers.index',
                        'match' => ['admin.subscribers.*'],
                    ],
                ],
            ],
            [
                'label' => __('nav.billing'),
                'items' => [
                    [
                        'label' => __('mdl.generate_bills'),
                        'icon' => 'ki-plus-squared',
                        'route' => 'admin.generate-bills',
                        'match' => ['admin.generate-bills'],
                        'component' => \App\Livewire\Admin\Pages\GenerateBills::class,
                    ],
                    [
                        'label' => __('mdl.readings'),
                        'icon' => 'ki-bill',
                        'route' => 'admin.readings.index',
                        'match' => ['admin.readings.*'],
                    ],
                    [
                        'label' => __('mdl.bills'),
                        'icon' => 'ki-receipt-square',
                        'route' => 'admin.bills.index',
                        'match' => ['admin.bills.*'],
                    ],
                    [
                        'label' => __('mdl.payments'),
                        'icon' => 'ki-wallet',
                        'route' => 'admin.payments.index',
                        'match' => ['admin.payments.*'],
                    ],
                ],
            ],
            [
                'label' => __('nav.finance'),
                'items' => [
                    [
                        'label' => __('mdl.tariffs'),
                        'icon' => 'ki-dollar',
                        'route' => 'admin.tariffs.index',
                        'match' => ['admin.tariffs.*'],
                    ],
                    [
                        'label' => __('mdl.expenses'),
                        'icon' => 'ki-chart-line-down',
                        'route' => 'admin.expenses.index',
                        'match' => ['admin.expenses.*'],
                    ],
                    [
                        'label' => __('mdl.handovers'),
                        'icon' => 'ki-bank',
                        'route' => 'admin.handovers.index',
                        'match' => ['admin.handovers.*'],
                    ],
                    [
                        'label' => __('mdl.collector_statement'),
                        'icon' => 'ki-note-2',
                        'route' => 'admin.collector-statement',
                        'match' => ['admin.collector-statement'],
                        'component' => \App\Livewire\Admin\Pages\CollectorStatement::class,
                    ],
                    [
                        'label' => __('mdl.profit_loss'),
                        'icon' => 'ki-chart-pie-3',
                        'route' => 'admin.profit-loss',
                        'match' => ['admin.profit-loss'],
                        'component' => \App\Livewire\Admin\Pages\ProfitLoss::class,
                    ],
                    [
                        'label' => __('mdl.overdue_report'),
                        'icon' => 'ki-information',
                        'route' => 'admin.overdue-report',
                        'match' => ['admin.overdue-report'],
                        'component' => \App\Livewire\Admin\Pages\OverdueReport::class,
                    ],
                    [
                        'label' => __('col.title'),
                        'icon' => 'ki-percentage',
                        'route' => 'admin.collection-report',
                        'match' => ['admin.collection-report'],
                        'component' => \App\Livewire\Admin\Pages\CollectionReport::class,
                    ],
                ],
            ],
            [
                'label' => __('nav.system'),
                'items' => [
                    [
                        'label' => __('mdl.users'),
                        'icon' => 'ki-user',
                        'route' => 'admin.users.index',
                        'match' => ['admin.users.*'],
                    ],
                    [
                        'label' => __('mdl.settings'),
                        'icon' => 'ki-setting-2',
                        'route' => 'admin.settings',
                        'match' => ['admin.settings'],
                        'component' => \App\Livewire\Admin\Pages\Settings::class,
                    ],
                    [
                        'label' => __('mdl.audit_log'),
                        'icon' => 'ki-shield-tick',
                        'route' => 'admin.audit-logs.index',
                        'match' => ['admin.audit-logs.*'],
                    ],
                ],
                'adminOnly' => true,
            ],
        ];
    }

    /**
     * The screens backed by a Livewire page component.
     *
     * The nav already carries each screen's label, icon, group and ROUTE NAME; an item with
     * a `component` is a page rather than a data-table resource, so routes/admin.php turns
     * this list into routes. One description of a screen, not two that can disagree — and
     * adding a screen stays a single array entry.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pages(): array
    {
        return collect(self::groups())
            ->flatMap(fn (array $group) => $group['items'])
            ->filter(fn (array $item) => ! empty($item['component']) && ! empty($item['route']))
            ->values()
            ->all();
    }

    /** 'admin.profit-loss' -> 'profit-loss': the URL segment the route is mounted at. */
    public static function slug(array $item): string
    {
        return str($item['route'])->after('admin.')->toString();
    }

    /** Sidebar for the signed-in user: non-admins never see the admin nav at all. */
    public static function forUser(?User $user): array
    {
        return array_values(array_filter(
            self::groups(),
            fn (array $group): bool => empty($group['adminOnly']) || (bool) $user?->isAdmin(),
        ));
    }

    /**
     * Is this screen registered yet?
     *
     * The nav lists the whole panel up front, but a screen exists only once its route does.
     * Checking here keeps the shell usable while the panel is built out one screen at a
     * time — otherwise a single unbuilt route 500s every page that renders the sidebar.
     */
    public static function exists(array $item): bool
    {
        return \Illuminate\Support\Facades\Route::has($item['route']);
    }

    public static function isActive(array $item): bool
    {
        foreach ($item['match'] as $pattern) {
            if (request()->routeIs($pattern)) {
                return true;
            }
        }

        return false;
    }
}
