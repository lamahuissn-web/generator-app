<?php

use App\Exceptions\ApiRefusal;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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
        // The app listens on 127.0.0.1 only and is reached through the Cloudflare
        // tunnel, which terminates TLS and forwards X-Forwarded-* headers. Without
        // this the panel redirects to http:// and prints http:// links.
        $middleware->trustProxies(at: '*');

        // Two portals share the `auth` middleware, and Laravel's default guest redirect is
        // route('login') — which is the COLLECTOR sign-in. An unauthenticated visitor to the
        // panel must be sent to the panel's own door.
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request): string => $request->is('admin', 'admin/*')
            ? route('admin.login')
            : route('login'));

        // The mobile API has no browser: it is answered in the language the phone asked for, and
        // always in JSON — an HTML login page or a 302 parses on a phone as an empty body.
        $middleware->api(prepend: [
            \App\Http\Middleware\ApiLocale::class,
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureCollector::class,
            'locale' => \App\Http\Middleware\SetLocale::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            // The API twins: the same decisions, JSON answers instead of redirects.
            'api.active' => \App\Http\Middleware\ApiActive::class,
            'api.role' => \App\Http\Middleware\ApiRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One shape for every failure the phone can see: {error: {code, message}} — a code to branch
        // on and the office's own sentence to show. The specific types come first; the catch-all that
        // keeps a stack trace off a collector's screen is registered last and stays silent while
        // APP_DEBUG is on, so a broken endpoint is loud in development and quiet in the street.
        $exceptions->render(fn (ApiRefusal $e, Request $request) => $e->toResponse($request));

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'invalid',
                    'message' => __('mobile.invalid'),
                    'fields' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => ['code' => 'unauthenticated', 'message' => __('mobile.unauthenticated')],
            ], 401);
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => ['code' => 'not_found', 'message' => __('mobile.not_found')],
            ], 404);
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => __('mobile.forbidden')],
            ], 403);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || config('app.debug')) {
                return null;
            }

            return response()->json([
                'error' => ['code' => 'server_error', 'message' => __('mobile.server_error')],
            ], 500);
        });
    })->create();
