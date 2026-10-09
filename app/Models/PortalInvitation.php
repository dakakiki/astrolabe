<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An emailed invitation to the client portal (docs/spec/12, "Pozivnica"). Works
 * once, for a week; a newer invitation to the same client, or revoking the
 * access, stops it. Only the token's SHA-256 hash is stored.
 *
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $revoked_at
 */
class PortalInvitation extends Model
{
    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const USED = 'used';

    public const REVOKED = 'revoked';

    /** The client was archived or deleted, or the practice is closing. */
    public const UNAVAILABLE = 'unavailable';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * A new invitation for the link and the token for its email (never stored).
     * Earlier open invitations of the same link stop working.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(PortalAccess $access): array
    {
        $token = Str::random(48);

        static::query()->open()->where('portal_access_id', $access->getKey())->update(['revoked_at' => now()]);

        $invitation = static::query()->create([
            'portal_access_id' => $access->getKey(),
            'token_hash' => self::hash($token),
            'expires_at' => now()->addDays(config('portal.invitation_days')),
        ]);

        return [$invitation, $token];
    }

    public static function findByToken(mixed $token): ?self
    {
        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            return null;
        }

        return static::query()->where('token_hash', self::hash($token))->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Neither used, revoked nor expired.
     *
     * @param  Builder<PortalInvitation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function status(?CarbonImmutable $now = null): string
    {
        return match (true) {
            $this->used_at !== null => self::USED,
            $this->revoked_at !== null => self::REVOKED,
            $this->expires_at->lessThanOrEqualTo($now ?? CarbonImmutable::now()) => self::EXPIRED,
            default => self::VALID,
        };
    }

    /**
     * @return BelongsTo<PortalAccess, $this>
     */
    public function access(): BelongsTo
    {
        return $this->belongsTo(PortalAccess::class, 'portal_access_id')->acrossPractices();
    }

    /**
     * The link in the email. The token travels after "#", so it never reaches
     * a server log; the portal page reads it and asks before using it (POST),
     * since mail scanners open links on their own.
     */
    public static function url(string $token): string
    {
        return config('portal.url').'/invitation#'.$token;
    }
}
