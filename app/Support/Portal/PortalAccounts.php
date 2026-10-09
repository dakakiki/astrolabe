<?php

namespace App\Support\Portal;

use App\Enums\PortalAccessStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Removing portal accounts that no practice opens any more (docs/spec/12):
 * right away when the client or the practice is deleted for good, and through
 * `data:prune` after `portal.account_days` when every link was revoked.
 *
 * An account goes with its sessions and sign-in tokens; the audit log keeps
 * its entries (the account emptied) until the log's own retention, and a
 * revoked link stays as the practice's history without the account.
 */
final class PortalAccounts
{
    /**
     * The given accounts that have no link left at all.
     *
     * @param  Collection<int, int>  $ids
     */
    public static function deleteUnlinked(Collection $ids): int
    {
        $ids = $ids->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        $linked = DB::table('portal_access')->whereIn('portal_user_id', $ids)->distinct()->pluck('portal_user_id');

        return self::delete($ids->diff($linked)->values());
    }

    /**
     * Accounts with no active link whose last link was revoked (or that were
     * made) before the cutoff.
     *
     * @return Collection<int, int>
     */
    public static function forgotten(CarbonImmutable $cutoff): Collection
    {
        return DB::table('portal_users')
            ->where('created_at', '<', $cutoff)
            ->whereNotExists(fn ($query) => $query->from('portal_access')
                ->whereColumn('portal_access.portal_user_id', 'portal_users.id')
                ->where(fn ($query) => $query
                    ->where('status', PortalAccessStatus::Active->value)
                    ->orWhere('revoked_at', '>=', $cutoff)))
            ->pluck('id');
    }

    /**
     * @param  Collection<int, int>  $ids
     */
    public static function delete(Collection $ids): int
    {
        if ($ids->isEmpty()) {
            return 0;
        }

        DB::table(config('portal.session.table'))->whereIn('user_id', $ids)->delete();

        // Sign-in tokens go with the account (foreign key); links and audit entries keep their rows.
        return DB::table('portal_users')->whereIn('id', $ids)->delete();
    }
}
