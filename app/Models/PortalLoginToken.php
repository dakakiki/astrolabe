<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One request to sign in to the portal (docs/spec/12, "Prijava"): an emailed
 * link and a six-digit code for another device, together. Either works once,
 * for 15 minutes, and using one uses up both; five wrong codes end it. A new
 * request replaces the person's earlier open one.
 *
 * The link token is stored as its SHA-256 hash; the code — only a million
 * possibilities — as an HMAC with the application key, so a copy of the
 * database alone does not give it away.
 *
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property int $attempts
 */
class PortalLoginToken extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * A new link and code for the person (shown once, in the email).
     *
     * @return array{0: self, 1: string, 2: string} the row, the link token, the code
     */
    public static function issue(PortalUser $user, Request $request): array
    {
        $token = Str::random(48);
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        static::query()->open()->where('portal_user_id', $user->getKey())->update(['used_at' => now()]);

        $userAgent = $request->userAgent();

        $row = static::query()->create([
            'portal_user_id' => $user->getKey(),
            'token_hash' => self::hash($token),
            'code_hash' => self::codeHash($code),
            'expires_at' => now()->addMinutes(config('portal.sign_in_minutes')),
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);

        return [$row, $token, $code];
    }

    public static function findOpenByToken(mixed $token): ?self
    {
        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            return null;
        }

        return static::query()->open()->where('token_hash', self::hash($token))->first();
    }

    /** The person's latest request that can still be used with its code. */
    public static function openFor(PortalUser $user): ?self
    {
        return static::query()->open()->where('portal_user_id', $user->getKey())->latest('id')->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function codeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    public function codeMatches(string $code): bool
    {
        return hash_equals($this->code_hash, self::codeHash($code));
    }

    /**
     * Unused, unexpired, and with tries left.
     *
     * @param  Builder<PortalLoginToken>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', config('portal.code_attempts'));
    }

    /**
     * @return BelongsTo<PortalUser, $this>
     */
    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class);
    }

    /** The link in the email; the token after "#" (see PortalInvitation::url). */
    public static function url(string $token): string
    {
        return config('portal.url').'/sign-in/link#'.$token;
    }
}
