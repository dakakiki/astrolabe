<?php

namespace App\Http\Middleware\Portal;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed in to the portal (the `portal` guard), or 401. Unlike `auth:portal`
 * it leaves the default guard alone: code that asks `auth()` for the
 * astrologer — the audit log, `created_by` columns — must never get a portal
 * account back.
 */
class AuthenticatePortal
{
    public const CODE = 'portal_unauthenticated';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('portal')->check()) {
            return response()->json(['message' => __('portal.errors.unauthenticated'), 'code' => self::CODE], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
