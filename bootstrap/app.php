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
            // The rebuilt panel. It lives at /manage while the Filament panel still owns
            // /admin; the prefix flips to 'admin' when the two reach parity. Names keep the
            // `admin.` prefix throughout so only this line changes.
            Route::middleware('web')
                ->prefix('manage')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Two portals share the `auth` middleware, and Laravel's default guest redirect is
        // route('login') — which is the COLLECTOR sign-in. An unauthenticated visitor to the
        // panel must be sent to the panel's own door.
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request): string => $request->is('manage', 'manage/*')
            ? route('admin.login')
            : route('login'));

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureCollector::class,
            'locale' => \App\Http\Middleware\SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
