<?php

namespace App\Http\Controllers\Portal;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\PortalUser;
use App\Support\Audit\Audit;
use App\Support\Portal\PortalSessionManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Portal → Security: where the person is signed in, and "Sign out everywhere"
 * (docs/spec/12). Session ids are never sent — they are the keys to the sessions.
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $current = $request->session()->getId();

        $sessions = $this->sessions()
            ->orderByDesc('last_activity')
            ->get(['id', 'user_agent', 'last_activity'])
            ->map(fn (object $session) => [
                'current' => hash_equals($session->id, $current),
                'user_agent' => $session->user_agent !== null ? mb_substr($session->user_agent, 0, 255) : null,
                'last_active_at' => now()->setTimestamp($session->last_activity)->toIso8601ZuluString(),
            ])
            // This device first, then the others by when they were last used.
            ->sortByDesc('current')
            ->values();

        return response()->json(['data' => $sessions]);
    }

    /** Every session of the account, this one too. */
    public function destroy(Request $request): Response
    {
        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();
        $count = $this->sessions()->delete();

        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Audit::portal(AuditEvent::PortalSessionsRevoked, $user, properties: ['sessions' => $count]);

        return response()->noContent();
    }

    private function sessions(): Builder
    {
        $config = app(PortalSessionManager::class)->getSessionConfig();

        return DB::connection($config['connection'] ?? null)
            ->table($config['table'])
            ->where('user_id', Auth::guard('portal')->id());
    }
}
