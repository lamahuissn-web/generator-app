<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A retired account stops working at the NEXT REQUEST, not at the next sign-in.
 *
 * Refusing the door alone would leave whoever is already signed in — a phone in a van, a tab left
 * open in the office — working until they happened to sign out, which is exactly the account an
 * office retires in a hurry. So the check sits on the request as well.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isInactive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(
                $request->is('admin', 'admin/*') ? route('admin.login') : route('login'),
            )->withErrors(['identity' => __('auth.account_disabled')]);
        }

        return $next($request);
    }
}
