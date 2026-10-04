<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiRefusal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which role may knock on which door, checked on every request.
 *
 * The token is not the permission: a token says who is holding the phone, and the row it belongs to
 * is read again here, because a role changed in the office must take effect without waiting for the
 * phone to sign in again. A refusal is 403 with a code, never the panel's redirect to /admin.
 */
class ApiRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! in_array($user->role, $roles, true)) {
            throw new ApiRefusal('forbidden', __('mobile.forbidden'), 403);
        }

        return $next($request);
    }
}
