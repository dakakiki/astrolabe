<?php

namespace App\Actions\Portal;

use App\Enums\AuditEvent;
use App\Enums\ClientStatus;
use App\Enums\PortalAccessStatus;
use App\Models\Client;
use App\Models\PortalAccess;
use App\Models\PortalInvitation;
use App\Models\PortalUser;
use App\Models\User;
use App\Notifications\PortalInvite;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Invites a client to the practice's portal, or sends the invitation again
 * (docs/spec/12, "Pozivnica"). The invitation goes to the address on the client
 * record; a new one stops the earlier one. One open link per client: the rows
 * of the client are locked while it is decided.
 */
final class InviteToPortal
{
    public function handle(Client $client, User $by): PortalAccess
    {
        if (blank($client->email)) {
            throw ValidationException::withMessages(['email' => __('portal.invite.no_email')]);
        }

        if ($client->status === ClientStatus::Archived) {
            throw ValidationException::withMessages(['client' => __('portal.invite.archived')]);
        }

        [$access, $token, $invitation, $resend] = DB::transaction(function () use ($client, $by) {
            DB::table('clients')->where('id', $client->getKey())->lockForUpdate()->first();

            $access = PortalAccess::query()->where('client_id', $client->getKey())->open()->first();

            if ($access?->status === PortalAccessStatus::Active) {
                throw new ConflictHttpException(__('portal.invite.already_active'));
            }

            $resend = $access !== null;
            $access ??= new PortalAccess(['client_id' => $client->getKey(), 'status' => PortalAccessStatus::Invited]);
            $access->fill([
                'email' => PortalUser::normaliseEmail($client->email),
                'invited_by' => $by->getKey(),
                'invited_at' => now(),
            ])->save();

            [$invitation, $token] = PortalInvitation::issue($access);

            return [$access, $token, $invitation, $resend];
        });

        Notification::route('mail', $access->email)->notify(new PortalInvite(
            $client->loadMissing('workspace')->workspace->portalName(),
            $by->name,
            $token,
            $invitation->expires_at,
        ));
        $invitation->forceFill(['sent_at' => now()])->save();

        Audit::record(AuditEvent::PortalInvitationSent, $access, ['resend' => $resend ?: null]);

        return $access;
    }
}
