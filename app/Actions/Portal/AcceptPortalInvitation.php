<?php

namespace App\Actions\Portal;

use App\Enums\AuditEvent;
use App\Enums\ClientStatus;
use App\Enums\PortalAccessStatus;
use App\Models\PortalAccess;
use App\Models\PortalInvitation;
use App\Models\PortalUser;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accepting an invitation (docs/spec/12, "Pozivnica", step 4): finds or makes
 * the portal account for the invited address, activates the link to exactly
 * the invited client record, and uses the invitation up — all under a lock, so
 * a link clicked twice activates once. The address proved itself by receiving
 * the email. Signing in is the controller's part.
 */
final class AcceptPortalInvitation
{
    public function handle(PortalInvitation $invitation): PortalAccess
    {
        return DB::transaction(function () use ($invitation) {
            $invitation = PortalInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());
            $access = PortalAccess::query()->acrossPractices()->lockForUpdate()->findOrFail($invitation->portal_access_id);

            if (self::statusOf($invitation, $access) !== PortalInvitation::VALID) {
                throw ValidationException::withMessages(['token' => __('portal.invitation.not_valid')]);
            }

            $user = PortalUser::findByEmail($access->email) ?? tap(new PortalUser, function (PortalUser $user) use ($access) {
                $user->forceFill(['email' => $access->email])->save();
            });

            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

            $access->forceFill([
                'portal_user_id' => $user->getKey(),
                'status' => PortalAccessStatus::Active,
                'accepted_at' => now(),
            ])->save();

            $invitation->forceFill(['used_at' => now()])->save();

            Audit::portal(AuditEvent::PortalAccessAccepted, $user, $access, workspace: $access->workspace);

            return $access->setRelation('portalUser', $user);
        });
    }

    /**
     * What the invitation page says: valid, expired, used, revoked (a newer one
     * was sent, or the access withdrawn), or unavailable (the client is
     * archived or gone, the practice is closing).
     */
    public static function statusOf(PortalInvitation $invitation, ?PortalAccess $access): string
    {
        $status = $invitation->status();

        if ($status !== PortalInvitation::VALID) {
            return $status;
        }

        if ($access === null || $access->status !== PortalAccessStatus::Invited) {
            return PortalInvitation::REVOKED;
        }

        $client = $access->client;

        if ($client === null || $client->status === ClientStatus::Archived || $access->workspace?->isPendingDeletion()) {
            return PortalInvitation::UNAVAILABLE;
        }

        return PortalInvitation::VALID;
    }
}
