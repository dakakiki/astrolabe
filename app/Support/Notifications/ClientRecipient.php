<?php

namespace App\Support\Notifications;

use App\Models\Appointment;
use App\Models\PortalAccess;
use App\Models\PortalUser;
use Illuminate\Support\Collection;

/**
 * Who hears about a client's appointment by email (docs/spec/12, Phase 9b):
 * the portal account of the client's usable link (PortalAccess::usable() —
 * active, the client not archived, the practice not closing), and nobody while
 * there is none. A client without the portal gets no email from AstroLabe.
 *
 * Read across practices (queue workers and commands have no current practice),
 * always narrowed to one practice and one client record.
 */
final class ClientRecipient
{
    /** The client's usable link with its account and practice, or null. */
    public static function access(int $workspaceId, int $clientId): ?PortalAccess
    {
        return PortalAccess::query()
            ->acrossPractices()
            ->usable()
            ->where('portal_access.workspace_id', $workspaceId)
            ->where('portal_access.client_id', $clientId)
            ->whereNotNull('portal_access.portal_user_id')
            ->with(['portalUser', 'workspace'])
            ->orderBy('portal_access.id')
            ->first();
    }

    /**
     * Whether this account still reaches the client — checked again when a
     * queued email goes, so a link revoked in the meantime gets nothing.
     */
    public static function reaches(PortalUser $user, int $workspaceId, int $clientId): bool
    {
        return PortalAccess::query()
            ->acrossPractices()
            ->usable()
            ->where('portal_access.workspace_id', $workspaceId)
            ->where('portal_access.client_id', $clientId)
            ->where('portal_access.portal_user_id', $user->getKey())
            ->exists();
    }

    /**
     * The usable links for many appointments in one query (the reminder run),
     * keyed by self::key().
     *
     * @param  iterable<Appointment>  $appointments
     * @return Collection<string, PortalAccess>
     */
    public static function forMany(iterable $appointments): Collection
    {
        $clientIds = collect($appointments)->pluck('client_id')->unique()->values();

        if ($clientIds->isEmpty()) {
            return collect();
        }

        return PortalAccess::query()
            ->acrossPractices()
            ->usable()
            ->whereIn('portal_access.client_id', $clientIds)
            ->whereNotNull('portal_access.portal_user_id')
            ->with(['portalUser', 'workspace'])
            ->orderBy('portal_access.id')
            ->get()
            ->unique(fn (PortalAccess $access) => self::key($access->workspace_id, $access->client_id))
            ->keyBy(fn (PortalAccess $access) => self::key($access->workspace_id, $access->client_id));
    }

    public static function key(int $workspaceId, int $clientId): string
    {
        return $workspaceId.':'.$clientId;
    }
}
