<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\AcceptPortalInvitation;
use App\Http\Controllers\Controller;
use App\Models\PortalInvitation;
use App\Support\Portal\PortalSessionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The page an invitation email opens (docs/spec/12, "Pozivnica"). The token
 * comes in the body, never the URL; showing the page uses nothing up, and only
 * "Accept and continue" (a POST) does — mail scanners open links on their own.
 */
class InvitationController extends Controller
{
    /** The practice that invites, and whether the invitation still works. */
    public function show(Request $request): JsonResponse
    {
        $invitation = $this->find($request);
        $access = $invitation->access;

        return response()->json(['data' => [
            'status' => AcceptPortalInvitation::statusOf($invitation, $access),
            'practice' => $access?->workspace ? PortalSessionState::practice($access->workspace) : null,
            'email' => $access ? self::mask($access->email) : null,
            // Whoever is signed in on this device already is the invited address (or not: accepting switches).
            'for_signed_in' => $access !== null && Auth::guard('portal')->user()?->email === $access->email,
        ]]);
    }

    public function accept(Request $request, AcceptPortalInvitation $accept): JsonResponse
    {
        $access = $accept->handle($this->find($request));

        PortalSessionState::signIn($request, $access->portalUser, 'invitation', $access);

        return response()->json(['data' => PortalSessionState::for($request)]);
    }

    private function find(Request $request): PortalInvitation
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);

        return PortalInvitation::findByToken($data['token']) ?? abort(404, __('portal.invitation.not_found'));
    }

    /** "m•••@example.com": enough to recognise one's own address, not to read it. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
