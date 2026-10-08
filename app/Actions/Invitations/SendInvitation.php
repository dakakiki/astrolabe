<?php

namespace App\Actions\Invitations;

use App\Enums\AuditEvent;
use App\Models\RegistrationInvitation;
use App\Notifications\RegistrationInvite;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Invites one address to the closed beta (Phase 8a), from `invitations:send` or
 * the admin's Invitations screen (Phase 8c): a new link replaces the address's
 * open one, the email goes out at once, and the link comes back too — in case
 * the email does not arrive.
 */
final class SendInvitation
{
    /**
     * @return array{invitation: RegistrationInvitation, link: string, sent: bool}
     */
    public function handle(string $email, int $days, ?string $note = null): array
    {
        [$invitation, $token] = RegistrationInvitation::issue($email, $days, $note);
        Audit::record(AuditEvent::InvitationSent, $invitation, ['days' => $days]);

        try {
            Notification::route('mail', $invitation->email)->notifyNow(new RegistrationInvite($token, $invitation->expires_at));
            $sent = true;
        } catch (Throwable $e) {
            Log::warning('An invitation email could not be sent', ['invitation' => $invitation->getKey(), 'exception' => $e::class]);
            $sent = false;
        }

        return ['invitation' => $invitation, 'link' => RegistrationInvitation::url($token), 'sent' => $sent];
    }
}
