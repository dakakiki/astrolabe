<?php

namespace App\Support\Portal;

use App\Enums\AuditEvent;
use App\Http\Middleware\Portal\ResolvePortalAccess;
use App\Models\PortalAccess;
use App\Models\PortalUser;
use App\Models\Workspace;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Who is signed in to the portal and which practices they can open — what the
 * portal SPA starts from (`GET /session`, and the answer to every sign-in).
 *
 * `state`: `guest`, `ready` (a practice is open), `choose` (several, none
 * chosen yet) or `no_access` (signed in, but no practice opens now).
 */
final class PortalSessionState
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        /** @var PortalUser|null $user */
        $user = Auth::guard('portal')->user();

        if ($user === null) {
            return ['state' => 'guest', 'user' => null, 'practices' => [], 'current_practice_id' => null];
        }

        $usable = ResolvePortalAccess::usableFor($user);
        $chosen = $request->session()->get(ResolvePortalAccess::SESSION_KEY);
        $current = $usable->firstWhere('id', $chosen) ?? ($usable->count() === 1 ? $usable->first() : null);

        return [
            'state' => $current !== null ? 'ready' : ($usable->isEmpty() ? 'no_access' : 'choose'),
            'user' => [
                'email' => $user->email,
                'name' => $user->name,
                'timezone' => $user->timezone,
                'locale' => $user->locale,
            ],
            'practices' => $usable->map(fn (PortalAccess $access) => ['id' => $access->getKey()] + self::practice($access->workspace))->values()->all(),
            'current_practice_id' => $current?->getKey(),
        ];
    }

    /**
     * How a practice shows itself in the portal: display name, logo, colour.
     *
     * @return array<string, mixed>
     */
    public static function practice(Workspace $workspace): array
    {
        return [
            'name' => $workspace->portalName(),
            'logo_url' => PracticeLogo::portalUrl($workspace),
            'brand_color' => $workspace->brand_color,
            'timezone' => $workspace->timezone,
        ];
    }

    /**
     * Signs the person in to a fresh session (new id and CSRF token), opening
     * the given practice if there is one.
     */
    public static function signIn(Request $request, PortalUser $user, string $method, ?PortalAccess $access = null): void
    {
        Auth::guard('portal')->login($user);

        $session = $request->session();
        $session->regenerateToken();
        $session->forget(ResolvePortalAccess::SESSION_KEY);

        if ($access !== null) {
            $session->put(ResolvePortalAccess::SESSION_KEY, $access->getKey());
        }

        $user->forceFill([
            'last_signed_in_at' => now(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Audit::portal(AuditEvent::PortalSignedIn, $user, properties: ['method' => $method], workspace: $access?->workspace);
    }
}
