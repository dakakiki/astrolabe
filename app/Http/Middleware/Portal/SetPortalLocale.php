<?php

namespace App\Http\Middleware\Portal;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side text (validation messages, emails) in the portal account's
 * language, when it chose one we have; English otherwise.
 */
class SetPortalLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Auth::guard('portal')->user()?->locale;

        if ($locale !== null && array_key_exists($locale, config('astrolabe.locales'))) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
