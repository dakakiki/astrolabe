<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PortalUserFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

/**
 * A person who signs in to the client portal (docs/spec/12) — with an emailed
 * link or six-digit code, never a password. Belongs to no practice: it sees a
 * practice only through an accepted invitation (`portal_access`), and one
 * account may be linked to several practices.
 *
 * Signs in through the `portal` guard, whose session has its own cookie and
 * table; an astrologer's account and session never mix with it.
 *
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $last_signed_in_at
 */
#[Fillable(['name', 'locale', 'timezone'])]
class PortalUser extends Model implements AuthenticatableContract, HasLocalePreference
{
    /** @use HasFactory<PortalUserFactory> */
    use Authenticatable, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'last_signed_in_at' => 'immutable_datetime',
        ];
    }

    /** Addresses are kept as typed, in lower case: one account per address. */
    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function findByEmail(string $email): ?self
    {
        return static::query()->where('email', self::normaliseEmail($email))->first();
    }

    /**
     * Every link to a practice this account ever had (across practices, so
     * without the workspace scope).
     *
     * @return HasMany<PortalAccess, $this>
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(PortalAccess::class)->acrossPractices();
    }

    /** Whether any practice can be opened right now (see PortalAccess::scopeUsable). */
    public function hasUsableAccess(): bool
    {
        return PortalAccess::query()->acrossPractices()->usable()->where('portal_user_id', $this->getKey())->exists();
    }

    /** No password, so no "remember me" token either. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
}
