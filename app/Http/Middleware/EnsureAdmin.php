<?php

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operator's admin (Phase 8c): only the admin account, only with two-factor
 * sign-in on, and only while it keeps working — after `idle_minutes` without an
 * admin request the session is signed out (401). Astrologers get 403; the
 * admin's own routes never resolve a practice.
 */
class EnsureAdmin
{
    public const TWO_FACTOR_REQUIRED = 'admin_two_factor_required';

    private const LAST_SEEN = 'admin.last_seen';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->isAdmin() || $user->isSuspended()) {
            return response()->json(['message' => __('admin.only')], Response::HTTP_FORBIDDEN);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => __('admin.two_factor_required'),
                'code' => self::TWO_FACTOR_REQUIRED,
            ], Response::HTTP_FORBIDDEN);
        }

        if ($request->hasSession()) {
            $now = CarbonImmutable::now()->getTimestamp();
            $last = $request->session()->get(self::LAST_SEEN);

            if (is_int($last) && $now - $last > config('astrolabe.admin.idle_minutes') * 60) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json(['message' => __('admin.idle')], Response::HTTP_UNAUTHORIZED);
            }

            $request->session()->put(self::LAST_SEEN, $now);
        }

        return $next($request);
    }
}
