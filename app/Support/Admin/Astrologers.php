<?php

namespace App\Support\Admin;

use App\Enums\AuditEvent;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The astrologers as the operator sees them (Phase 8c): the account, its
 * practice, and metadata only — how many clients, consultations and
 * appointments, how much disk the files take, when they last signed in. Never
 * a name of a client, birth data, notes, files or amounts: the astrologer is
 * the controller of that data, AstroLabe only processes it.
 */
final class Astrologers
{
    /**
     * Every account but the admins', each with the practice it owns (one per
     * astrologer until teams) and the figures, in one query.
     *
     * @return Builder<User>
     */
    public static function query(): Builder
    {
        $count = fn (string $table) => DB::table($table)
            ->selectRaw('count(*)')
            ->whereColumn("{$table}.workspace_id", 'practice.id')
            ->whereNull("{$table}.deleted_at");

        return User::query()
            ->where('users.is_admin', false)
            ->leftJoin('workspace_user as owner', fn ($join) => $join
                ->on('owner.user_id', '=', 'users.id')
                ->where('owner.role', WorkspaceRole::Owner->value))
            ->leftJoin('workspaces as practice', 'practice.id', '=', 'owner.workspace_id')
            ->select('users.*', 'practice.id as practice_id', 'practice.name as practice_name', 'practice.deletes_at as practice_deletes_at', 'practice.created_at as practice_created_at')
            ->selectSub($count('clients'), 'clients_count')
            ->selectSub($count('consultations'), 'consultations_count')
            ->selectSub($count('appointments'), 'appointments_count')
            // Deleted files are still on the disk until the retention removes them.
            ->selectSub(DB::table('attachments')->selectRaw('coalesce(sum(file_size), 0)')->whereColumn('attachments.workspace_id', 'practice.id'), 'storage_bytes')
            ->selectSub(DB::table('audit_logs')->selectRaw('max(created_at)')
                ->whereColumn('audit_logs.user_id', 'users.id')
                ->where('audit_logs.event', AuditEvent::Login->value), 'last_login_at');
    }

    /**
     * @param  Builder<User>  $query
     */
    public static function filter(Builder $query, ?string $search, ?string $status): void
    {
        if ($search !== null && $search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $query) => $query
                ->where('users.name', 'like', $like)
                ->orWhere('users.email', 'like', $like)
                ->orWhere('practice.name', 'like', $like));
        }

        match ($status) {
            'active' => $query->whereNull('users.suspended_at')->whereNotNull('users.email_verified_at'),
            'unverified' => $query->whereNull('users.email_verified_at'),
            'suspended' => $query->whereNotNull('users.suspended_at'),
            'closing' => $query->whereNotNull('practice.deletes_at'),
            'no_two_factor' => $query->whereNull('users.two_factor_confirmed_at'),
            default => null,
        };
    }
}
