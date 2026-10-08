<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Invitations\SendInvitation;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\RegistrationInvitation;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin's Invitations screen (Phase 8c): the same as `invitations:send`,
 * `invitations:list` and `invitations:revoke`, which stay. The link of a new
 * invitation is shown once, in case the email does not arrive.
 */
class AdminInvitationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invitations = RegistrationInvitation::query()
            ->with('user')
            ->orderByDesc('id')
            ->paginate(50);

        Audit::record(AuditEvent::AdminViewed, properties: ['screen' => 'invitations']);

        $now = CarbonImmutable::now();

        return response()->json([
            'data' => collect($invitations->items())->map(fn (RegistrationInvitation $invitation) => $this->row($invitation, $now)),
            'meta' => [
                'current_page' => $invitations->currentPage(),
                'from' => $invitations->firstItem(),
                'to' => $invitations->lastItem(),
                'last_page' => $invitations->lastPage(),
                'total' => $invitations->total(),
                'per_page' => $invitations->perPage(),
            ],
        ]);
    }

    public function store(Request $request, SendInvitation $send): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'note' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ], ['email.unique' => __('admin.has_account')]);

        $result = $send->handle(
            mb_strtolower(trim($data['email'])),
            (int) ($data['days'] ?? config('astrolabe.registration.invitation_days')),
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => $this->row($result['invitation'], CarbonImmutable::now()),
            'meta' => ['link' => $result['link'], 'sent' => $result['sent']],
        ], Response::HTTP_CREATED);
    }

    public function destroy(RegistrationInvitation $invitation): JsonResponse
    {
        abort_unless($invitation->status() === RegistrationInvitation::VALID, Response::HTTP_CONFLICT);

        $invitation->forceFill(['revoked_at' => now()])->save();
        Audit::record(AuditEvent::InvitationRevoked, $invitation);

        return response()->json(['data' => $this->row($invitation->load('user'), CarbonImmutable::now())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(RegistrationInvitation $invitation, CarbonImmutable $now): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'note' => $invitation->note,
            'status' => $invitation->status($now),
            'sent_at' => $invitation->created_at?->toIso8601ZuluString(),
            'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
            'accepted_at' => $invitation->accepted_at?->toIso8601ZuluString(),
            'revoked_at' => $invitation->revoked_at?->toIso8601ZuluString(),
            'user' => $invitation->user ? ['id' => $invitation->user->id, 'name' => $invitation->user->name] : null,
        ];
    }
}
