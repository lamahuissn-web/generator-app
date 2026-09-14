<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The panel. It was built at /manage while Filament still owned /admin, and
            // moved onto /admin once every screen was rebuilt — the route NAMES never
            // changed (`admin.`), so nothing but this prefix had to move with it.
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Two portals share the `auth` middleware, and Laravel's default guest redirect is
        // route('login') — which is the COLLECTOR sign-in. An unauthenticated visitor to the
        // panel must be sent to the panel's own door.
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request): string => $request->is('admin', 'admin/*')
            ? route('admin.login')
            : route('login'));

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureCollector::class,
            'locale' => \App\Http\Middleware\SetLocale::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
