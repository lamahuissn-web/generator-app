<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && in_array($user->locale, ['en', 'ar'], true)) {
            App::setLocale($user->locale);
        } elseif ($request->session()->has('locale') && in_array($request->session()->get('locale'), ['en', 'ar'], true)) {
            App::setLocale($request->session()->get('locale'));
        } else {
            App::setLocale(config('app.locale', 'ar'));
        }

        // Words follow the UI language, but numbers stay Western (0-9) always —
        // Arabic-Indic digits would break receipts, addresses and money columns.
        \Illuminate\Support\Number::useLocale('en');

        return $next($request);
    }
}
