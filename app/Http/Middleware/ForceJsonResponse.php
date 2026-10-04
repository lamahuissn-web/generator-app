<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app is not a browser: it must never be handed an HTML login page or a redirect.
 *
 * A phone that forgets `Accept: application/json` is the normal case for a first build, and a 302
 * to /collector/login parses on the other side as a body of zero fields — the real failure (an
 * expired token) then reads as an empty response, which is the hardest kind to diagnose from a
 * street. Every request under /api is answered as JSON.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
