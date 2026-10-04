<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * The office reads Arabic; the phone says which language it wants.
 *
 * Refusals, month labels and validation sentences are all translated at the moment they are
 * produced, so the choice has to be made from the header the app sends — a refusal that arrives in
 * English to an Arabic-only collector is a message he cannot act on. When the app says nothing, the
 * program's own configured locale stands.
 */
class ApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $asked = strtolower((string) $request->header('Accept-Language', ''));

        if (str_contains($asked, 'ar')) {
            App::setLocale('ar');
        } elseif (str_contains($asked, 'en')) {
            App::setLocale('en');
        }

        return $next($request);
    }
}
