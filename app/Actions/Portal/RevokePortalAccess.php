<?php

namespace App\Actions\Portal;

use App\Enums\AuditEvent;
use App\Enums\PortalAccessStatus;
use App\Models\Client;
use App\Models\PortalAccess;
use App\Models\PortalInvitation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Portal\Push\PortalPush;
use Illuminate\Support\Facades\DB;

/**
 * Ends a client's portal access, or withdraws an invitation nobody accepted
 * yet (docs/spec/12). Nothing of the client's history changes; the portal
 * refuses the next request for this practice. The portal account itself stays
 * while it has other practices, and goes after `portal.account_days` otherwise.
 */
final class RevokePortalAccess
{
    public function handle(Client $client, User $by): ?PortalAccess
    {
        return DB::transaction(function () use ($client, $by) {
            $access = PortalAccess::query()->where('client_id', $client->getKey())->open()->lockForUpdate()->first();

            if ($access === null) {
                return null;
            }

            $wasInvited = $access->status === PortalAccessStatus::Invited;

            $access->forceFill([
                'status' => PortalAccessStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by' => $by->getKey(),
            ])->save();

            PortalInvitation::query()->open()->where('portal_access_id', $access->getKey())->update(['revoked_at' => now()]);

            // With no other practice left, the account's devices stop getting pushes (Phase 9c).
            if ($access->portal_user_id !== null && ! PortalAccess::query()->acrossPractices()
                ->where('portal_user_id', $access->portal_user_id)
                ->where('status', PortalAccessStatus::Active->value)
                ->exists()) {
                PortalPush::forgetAccount((int) $access->portal_user_id);
            }

            Audit::record($wasInvited ? AuditEvent::PortalInvitationRevoked : AuditEvent::PortalAccessRevoked, $access);

            return $access;
        });
    }
}
