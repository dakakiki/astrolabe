<?php

namespace App\Http\Resources;

use App\Enums\ClientStatus;
use App\Enums\PortalAccessStatus;
use App\Models\Client;
use App\Models\PortalAccess;
use App\Models\PortalInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The "Client portal" card on the client profile (docs/spec/12, "Pozivnica"):
 * the client's newest link to the portal and what can be done with it.
 *
 * @mixin Client
 */
class ClientPortalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PortalAccess|null $access */
        $access = PortalAccess::query()
            ->where('client_id', $this->id)
            ->with('portalUser:id,email')
            ->latest('id')
            ->first();

        $invitation = $access?->status === PortalAccessStatus::Invited
            ? PortalInvitation::query()->where('portal_access_id', $access->id)->latest('id')->first()
            : null;

        $archived = $this->status === ClientStatus::Archived;
        $status = $access?->status->value ?? 'none';

        return [
            'status' => $status,
            // An archived client's access rests until the client is restored.
            'paused' => $archived && $status === PortalAccessStatus::Active->value,
            'email' => $access?->email,
            'account_email' => $access?->portalUser?->email,
            'invited_at' => $access?->invited_at?->toIso8601ZuluString(),
            'invitation' => $invitation === null ? null : [
                'sent_at' => $invitation->sent_at?->toIso8601ZuluString(),
                'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
                'expired' => $invitation->status() === PortalInvitation::EXPIRED,
            ],
            'accepted_at' => $access?->accepted_at?->toIso8601ZuluString(),
            'last_seen_at' => $access?->last_seen_at?->toIso8601ZuluString(),
            'revoked_at' => $access?->revoked_at?->toIso8601ZuluString(),
            'client_email' => $this->email,
            'can_invite' => filled($this->email) && ! $archived && $status !== PortalAccessStatus::Active->value,
            'portal_url' => config('portal.url'),
        ];
    }
}
