<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An invitation to create an account during the closed beta (Phase 8a). The
 * operator sends it from the command line (`invitations:send`); the person
 * registers through the link with the same email address, once.
 *
 * Only the token's SHA-256 hash is stored, so the database alone opens nothing.
 */
class RegistrationInvitation extends Model
{
    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const USED = 'used';

    public const REVOKED = 'revoked';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * A new invitation and the token for its link (shown once, never stored).
     * Earlier open invitations for the same address stop working.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $email, int $days, ?string $note = null): array
    {
        $email = mb_strtolower(trim($email));
        $token = Str::random(48);

        static::query()->open()->where('email', $email)->update(['revoked_at' => now()]);

        $invitation = static::query()->create([
            'email' => $email,
            'token_hash' => self::hash($token),
            'note' => $note,
            'expires_at' => now()->addDays($days),
        ]);

        return [$invitation, $token];
    }

    public static function findByToken(?string $token): ?self
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
     * @param  Builder<RegistrationInvitation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function status(?CarbonImmutable $now = null): string
    {
        return match (true) {
            $this->accepted_at !== null => self::USED,
            $this->revoked_at !== null => self::REVOKED,
            $this->expires_at->lessThanOrEqualTo($now ?? CarbonImmutable::now()) => self::EXPIRED,
            default => self::VALID,
        };
    }

    public function isFor(string $email): bool
    {
        return mb_strtolower(trim($email)) === $this->email;
    }

    /**
     * The account created with this invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The link in the invitation email: the SPA's registration page. */
    public static function url(string $token): string
    {
        return url('/register').'?'.http_build_query(['invitation' => $token]);
    }
}
