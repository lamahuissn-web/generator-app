<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiRefusal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A retired account stops working at the NEXT REQUEST, not at the next sign-in.
 *
 * The panel already holds this rule for a signed-in browser (see EnsureUserIsActive), and the
 * reason applies harder to a phone: a collector's phone is left in a van, and an account the office
 * retired in a hurry must stop taking money the same minute. Only the answer changes — JSON with a
 * code instead of a redirect.
 */
class ApiActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isInactive()) {
            throw new ApiRefusal('account_disabled', __('auth.account_disabled'), 403);
        }

        return $next($request);
    }
}
