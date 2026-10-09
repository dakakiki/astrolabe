<?php

namespace App\Http\Middleware\Portal;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The astrologers' routes do not exist on the portal host (docs/spec/12,
 * "Poseban origin"). In the `web` and `api` groups, so it also covers routes
 * packages register (Fortify's sign-in, Sanctum's CSRF cookie); the portal's
 * own routes use the `portal` group and never pass through here.
 */
class RejectOnPortalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->getHost() === config('portal.domain'), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
