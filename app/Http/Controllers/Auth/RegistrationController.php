<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use App\Models\RegistrationInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the registration page needs before showing the form: whether accounts
 * are by invitation (closed beta), and, for an invitation link, whether it
 * still works and which address it is for. The address is told only to
 * whoever holds a working link.
 */
class RegistrationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $token = $request->query('invitation');
        $invitation = null;

        if (is_string($token) && $token !== '') {
            $found = RegistrationInvitation::findByToken($token);
            $status = $found?->status() ?? 'invalid';

            $invitation = $status === RegistrationInvitation::VALID
                ? ['status' => $status, 'email' => $found->email, 'expires_at' => $found->expires_at->toIso8601ZuluString()]
                : ['status' => $status];
        }

        return response()->json(['data' => [
            'mode' => CreateNewUser::invitationsOnly() ? 'invite' : 'open',
            'invitation' => $invitation,
        ]]);
    }
}
