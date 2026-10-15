<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser that switched web push on in the client PWA (Phase 9c,
 * docs/spec/12, "Web push"). Belongs to the portal account and to the portal
 * session it was made in: it lives only while that session does, so signing
 * out, "Sign out everywhere" or the session expiring stops the pushes to that
 * device. Not a practice's data — no `workspace_id`, not in the practice export.
 *
 * The endpoint is the push service's address for the browser and works like a
 * key; it is never sent back to the SPA (devices are shown by its hash).
 *
 * @property int $portal_user_id
 * @property string|null $portal_session_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property string|null $user_agent
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $created_at
 */
class PortalPushSubscription extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['endpoint', 'public_key', 'auth_token', 'portal_session_id'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * @return BelongsTo<PortalUser, $this>
     */
    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class);
    }

    /**
     * Subscriptions whose portal session still exists and has not run past the
     * portal's session lifetime — the only ones a push goes to.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $table = config('portal.session.table');
        $since = now()->subMinutes((int) config('portal.session.lifetime'))->getTimestamp();

        $query->whereExists(fn ($sessions) => $sessions
            ->from($table)
            ->whereColumn("{$table}.id", 'portal_push_subscriptions.portal_session_id')
            ->whereColumn("{$table}.user_id", 'portal_push_subscriptions.portal_user_id')
            ->where("{$table}.last_activity", '>=', $since));
    }
}
