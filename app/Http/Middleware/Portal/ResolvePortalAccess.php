<?php

namespace App\Http\Middleware\Portal;

use App\Models\PortalAccess;
use App\Models\PortalUser;
use App\Support\Portal\PortalContext;
use App\Support\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the practice a portal request acts in (docs/spec/12, "Prijava"): the
 * one chosen in this session if it is still open, otherwise the only one.
 * Checked again on every request — a revoked link, an archived client or a
 * practice scheduled for deletion closes the portal from the next request.
 *
 * Sets the tenant scope to that practice, so every tenant query is scoped as
 * in the application, and PortalContext to the link (client and practice).
 */
class ResolvePortalAccess
{
    public const SESSION_KEY = 'portal.access_id';

    public const NO_ACCESS = 'portal_no_access';

    public const CHOOSE = 'portal_choose_practice';

    /** How often "last seen" is written, so every click does not write a row. */
    private const SEEN_EVERY_MINUTES = 5;

    public function __construct(
        private readonly PortalContext $context,
        private readonly CurrentWorkspace $workspace,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();
        $usable = self::usableFor($user);
        $chosen = $request->session()->get(self::SESSION_KEY);

        $access = $usable->firstWhere('id', $chosen) ?? ($usable->count() === 1 ? $usable->first() : null);

        if ($access === null) {
            $code = $usable->isEmpty() ? self::NO_ACCESS : self::CHOOSE;

            return response()->json(['message' => __("portal.errors.{$code}"), 'code' => $code], Response::HTTP_FORBIDDEN);
        }

        if ($access->getKey() !== $chosen) {
            $request->session()->put(self::SESSION_KEY, $access->getKey());
        }

        $this->markSeen($access);
        $this->context->set($access);
        $this->workspace->set($access->workspace);

        try {
            return $next($request);
        } finally {
            $this->workspace->set(null);
            $this->context->set(null);
        }
    }

    /**
     * The person's links that open a practice now, with the client and practice.
     *
     * @return Collection<int, PortalAccess>
     */
    public static function usableFor(PortalUser $user): Collection
    {
        return PortalAccess::query()
            ->acrossPractices()
            ->usable()
            ->where('portal_user_id', $user->getKey())
            ->with(['client', 'workspace'])
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->get();
    }

    private function markSeen(PortalAccess $access): void
    {
        if ($access->last_seen_at?->greaterThan(now()->subMinutes(self::SEEN_EVERY_MINUTES))) {
            return;
        }

        // Not through the model: "seen" is no change to the link itself.
        DB::table('portal_access')->where('id', $access->getKey())->update(['last_seen_at' => now()]);
        $access->last_seen_at = now();
        $access->syncOriginalAttribute('last_seen_at');
    }
}
