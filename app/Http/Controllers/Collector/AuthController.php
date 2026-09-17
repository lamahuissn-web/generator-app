<?php

namespace App\Http\Controllers\Collector;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        // A collector types on a phone: a username, an address, an invisible character his keyboard
        // left behind — one identity, read the same way the panel reads it (User::credentialFor()).
        $request->validate([
            'identity' => ['required', 'string', 'max:255'],
            'password' => ['required'],
        ]);

        $credential = User::credentialFor((string) $request->input('identity'));

        if ($credential === null || ! Auth::attempt($credential + ['password' => (string) $request->input('password')])) {
            return back()
                ->withInput($request->only('identity'))
                ->withErrors(['identity' => __('portal.auth_failed')]);
        }

        $user = Auth::user();

        if ($user->isInactive()) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['identity' => __('auth.account_disabled')]);
        }

        if ($user->isAdmin()) {
            Auth::logout();

            return redirect()->route('login')->withErrors(['identity' => __('portal.auth_admin')]);
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
