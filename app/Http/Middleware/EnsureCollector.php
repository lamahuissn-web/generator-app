<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCollector
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== $role) {
            if ($user?->isAdmin()) {
                return redirect('/admin');
            }
            abort(403, 'Collector access only.');
        }

        return $next($request);
    }
}
