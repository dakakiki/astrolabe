<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\PortalAccessStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Scopes\WorkspaceScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One client record of one practice, opened to one portal account
 * (docs/spec/12, `portal_access`). Made by the astrologer's invitation and
 * activated only by accepting it — never by a matching email address.
 *
 * A tenant model: on the astrologer's side the workspace scope applies as
 * everywhere. The portal reads a person's links across practices, and says so
 * with `acrossPractices()`, always together with the portal account.
 *
 * @property PortalAccessStatus $status
 * @property CarbonImmutable $invited_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $shared_seen_at
 */
class PortalAccess extends Model
{
    use BelongsToWorkspace;

    protected $table = 'portal_access';

    /** Set by the portal actions only, never from a request. */
    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return [
            'status' => PortalAccessStatus::class,
            'invited_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'shared_seen_at' => 'immutable_datetime',
        ];
    }

    /**
     * Without the workspace scope: the portal side, where one account's links
     * span practices. Always narrow it down to the account.
     *
     * @param  Builder<PortalAccess>  $query
     */
    public function scopeAcrossPractices(Builder $query): void
    {
        $query->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * Invited or active — at most one per client.
     *
     * @param  Builder<PortalAccess>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn($this->qualifyColumn('status'), PortalAccessStatus::openValues());
    }

    /**
     * Links the portal opens right now: active, the client neither archived
     * nor deleted, the practice not closing (Phase 8b). Checked on every
     * portal request, so revoking, archiving or closing works from the next one.
     *
     * @param  Builder<PortalAccess>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), PortalAccessStatus::Active->value)
            ->whereHas('client', fn (Builder $client) => $client->where('status', '!=', ClientStatus::Archived->value))
            ->whereHas('workspace', fn (Builder $workspace) => $workspace->whereNull('deletes_at'));
    }

    /**
     * The client record, read without the workspace scope (the portal has no
     * current practice yet when it lists them).
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * @return BelongsTo<PortalUser, $this>
     */
    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return HasMany<PortalInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(PortalInvitation::class);
    }
}
