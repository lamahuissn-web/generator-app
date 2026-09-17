<?php

namespace App\Http\Controllers\Collector;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('collector.login');
    }

    public function login(Request $request): RedirectResponse
    {
        // A collector types on a phone: strip the invisible characters a keyboard or autofill
        // leaves behind before the address is validated (see clean_email()).
        $request->merge(['email' => clean_email($request->input('email'))]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __('portal.auth_failed')]);
        }

        $user = Auth::user();

        if ($user->isInactive()) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => __('auth.account_disabled')]);
        }

        if ($user->isAdmin()) {
            Auth::logout();

            return redirect()->route('login')->withErrors(['email' => __('portal.auth_admin')]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('collector.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
