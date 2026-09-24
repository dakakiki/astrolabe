<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uses the signed-in user's interface language for validation messages, emails
 * and other server-side text. Falls back to the application locale.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if ($locale !== null && array_key_exists($locale, config('astrolabe.locales'))) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
