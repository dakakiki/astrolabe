<?php

namespace App\Http\Controllers\Portal;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Portal\ResolvePortalAccess;
use App\Models\PortalUser;
use App\Support\Audit\Audit;
use App\Support\Portal\PortalSessionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The portal session: who is signed in (always 200; for a guest it also sets
 * the CSRF cookie the SPA needs before its first POST), choosing the practice,
 * and signing out.
 */
class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => PortalSessionState::for($request)]);
    }

    /** Opens another of the person's practices; kept for this session. */
    public function practice(Request $request): JsonResponse
    {
        $data = $request->validate(['practice_id' => ['required', 'integer']]);

        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();

        if (! ResolvePortalAccess::usableFor($user)->contains('id', $data['practice_id'])) {
            throw ValidationException::withMessages(['practice_id' => __('portal.errors.portal_no_access')]);
        }

        $request->session()->put(ResolvePortalAccess::SESSION_KEY, (int) $data['practice_id']);

        return response()->json(['data' => PortalSessionState::for($request)]);
    }

    public function destroy(Request $request): Response
    {
        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();

        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Audit::portal(AuditEvent::PortalSignedOut, $user);

        return response()->noContent();
    }
}
