<?php

use App\Admin\AdminNav;
use App\Admin\ResourceRegistry;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\SubscriberController;
use Illuminate\Support\Facades\Route;

/*
 | The panel. Built at /manage while the Filament panel still owns /admin; when this
 | reaches parity the prefix becomes 'admin' and the Filament provider is dropped.
 | Route names keep the `admin.` prefix either way, so nothing else has to change.
 */

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    // Subscribers are hand-routed: their profile screen (identity + balance + three tabs)
    // has no equivalent among the generic list resources.
    Route::prefix('subscribers')->name('subscribers.')->group(function () {
        Route::get('/', [SubscriberController::class, 'index'])->name('index');
        Route::get('/create', [SubscriberController::class, 'create'])->name('create');
        Route::get('/{subscriber}', [SubscriberController::class, 'show'])->name('show');
        Route::get('/{subscriber}/edit', [SubscriberController::class, 'edit'])->name('edit');
    });

    // The page screens (reports, the billing run, settings). Their metadata lives in
    // AdminNav — label, icon, group — so a screen is described once and the sidebar, the
    // heading and the route all read the same entry.
    // The route NAME is the slug, not the item's full route name: this file is already
    // loaded inside a name('admin.') group, so using the full name would nest it twice.
    foreach (AdminNav::pages() as $page) {
        Route::get(AdminNav::slug($page), fn () => view('admin.page', [
            'heading' => $page['label'],
            'subheading' => ($page['component'])::subheading(),
            'component' => $page['component'],
        ]))->name(AdminNav::slug($page));
    }

    // The registry-driven resources: one entry per screen, four routes each. The key rides
    // along as a route DEFAULT so the names stay readable (admin.routes.index, …) instead of
    // collapsing into one generic admin.resource.index?resource=routes.
    foreach (ResourceRegistry::all() as $key => $resource) {
        Route::prefix($key)->name($key.'.')->group(function () use ($key, $resource) {
            Route::get('/', [ResourceController::class, 'index'])->name('index')->defaults('resource', $key);

            // Only a resource with a form gets create/edit routes: registering them for a
            // read-only screen (bills, the audit log) leaves dead URLs that 404.
            if (! empty($resource['form'])) {
                Route::get('/create', [ResourceController::class, 'create'])->name('create')->defaults('resource', $key);
                Route::get('/{record}/edit', [ResourceController::class, 'edit'])->name('edit')->defaults('resource', $key);
            }
        });
    }
});
