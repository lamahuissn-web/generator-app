<?php

use App\Admin\AdminNav;
use App\Admin\ResourceRegistry;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\SubscriberController;
use App\Http\Controllers\Admin\SubscriberImportController;
use Illuminate\Support\Facades\Route;

/*
 | The panel, hand-built on Metronic. It was developed at /manage and moved onto /admin once
 | every screen was rebuilt; the route names kept the `admin.` prefix throughout, so only the
 | prefix in bootstrap/app.php had to change with it.
 */

Route::middleware(['guest', 'locale'])->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

// 'locale' is load-bearing: the layout's AR/EN button writes the preference and this
// middleware is the only thing that reads it back. Without it the panel is stuck on
// config('app.locale') and the button does nothing.
Route::middleware(['auth', 'active', 'role:admin', 'locale'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    // A native POST for the import's apply step: it must work in every browser, which a click handler
    // could not promise (see the controller).
    Route::post('/subscribers-import/apply', [SubscriberImportController::class, 'apply'])
        ->name('subscribers-import.apply');

    // Subscribers are hand-routed: their profile screen (identity + balance + three tabs)
    // has no equivalent among the generic list resources.
    Route::prefix('subscribers')->name('subscribers.')->group(function () {
        Route::get('/', [SubscriberController::class, 'index'])->name('index');
        Route::get('/create', [SubscriberController::class, 'create'])->name('create');
        Route::get('/{subscriber}', [SubscriberController::class, 'show'])->name('show');
        Route::get('/{subscriber}/edit', [SubscriberController::class, 'edit'])->name('edit');
    });

    // One-device shortcut: when exactly one device exists the sidebar goes straight to its file.
    Route::get('equipment/redirect', function () {
        if (\App\Models\Equipment::query()->count() === 1) {
            $only = \App\Models\Equipment::query()->first();
            if ($only) {
                return redirect()->route('admin.device-file', $only);
            }
        }

        return redirect()->route('admin.equipment.index');
    })->name('equipment.redirect');

    // One device's own page — identity, next service, fuel anomaly, and one timeline.
    // Constraint on the parameter so it does not swallow /equipment/create or /equipment/redirect.
    // Uses a controller view (admin.device-file) that embeds the Livewire component, not the
    // component directly — a class with a typed Eloquent mount param is not invokable as a route.
    Route::get('equipment/{equipment}', function (\App\Models\Equipment $equipment) {
        return view('admin.device-file', ['equipment' => $equipment]);
    })->where('equipment', '[0-9]+')->name('device-file');

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

    // ---- the three screens the merged page replaced ------------------------------------------
    // Their nav entries went with them, but bookmarks, the dashboard and the collector portal still
    // point at these paths, and the range and the collector ride along so an old link lands on the
    // same figures it used to show. Registered here rather than in the pages loop: they are
    // redirects, not screens.
    $legacy = fn (array $params = []) => fn () => redirect()->route('admin.collections', array_filter(
        array_merge(request()->only(['collector_id', 'from', 'to', 'all_time']), $params),
        fn ($value) => $value !== null && $value !== '',
    ));

    Route::get('handovers', $legacy())->name('handovers.index');
    Route::get('handovers/create', $legacy(['tab' => 'handovers']))->name('handovers.create');
    Route::get('handovers/{record}/edit', $legacy(['tab' => 'handovers']))->name('handovers.edit');
    Route::get('collector-statement', $legacy())->name('collector-statement');
    Route::get('collection-report', $legacy())->name('collection-report');

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

                // A create-only screen (a reading, a measurement of a moment) gets no edit
                // route at all, rather than an edit route that always 404s.
                if (empty($resource['create_only'])) {
                    Route::get('/{record}/edit', [ResourceController::class, 'edit'])->name('edit')->defaults('resource', $key);
                }
            }
        });
    }
});
