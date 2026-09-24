<?php

namespace App\Models;

use App\Enums\Ayanamsa;
use App\Enums\HouseSystem;
use App\Enums\ZodiacMode;
use Database\Factories\WorkspaceFactory;
use DateTimeZone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Throwable;

/**
 * The business space of one astrologer or studio; the tenant boundary.
 *
 * @property HouseSystem $default_house_system
 * @property ZodiacMode $default_zodiac_mode
 * @property Ayanamsa|null $default_ayanamsa
 */
#[Fillable([
    'name', 'default_locale', 'timezone', 'default_currency',
    'default_house_system', 'default_zodiac_mode', 'default_ayanamsa',
])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->slug ??= static::uniqueSlugFor($workspace->name);
        });
    }

    protected function casts(): array
    {
        return [
            'default_house_system' => HouseSystem::class,
            'default_zodiac_mode' => ZodiacMode::class,
            'default_ayanamsa' => Ayanamsa::class,
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    /**
     * The methods this workspace works with, built-in or its own.
     *
     * @return BelongsToMany<AstrologyMethod, $this>
     */
    public function astrologyMethods(): BelongsToMany
    {
        return $this->belongsToMany(AstrologyMethod::class, 'workspace_astrology_method')
            ->withPivot('is_default');
    }

    /**
     * Methods this workspace created itself.
     *
     * @return HasMany<AstrologyMethod, $this>
     */
    public function ownAstrologyMethods(): HasMany
    {
        return $this->hasMany(AstrologyMethod::class);
    }

    /** The country of the practice's time zone, e.g. "RS" for Europe/Belgrade; null for UTC. */
    public function countryCode(): ?string
    {
        try {
            $code = (new DateTimeZone($this->timezone))->getLocation()['country_code'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return $code && $code !== '??' ? $code : null;
    }

    /**
     * Slugs will appear in public booking URLs (Phase 9), so they are stable,
     * never reused and not derived from anything the user can later change.
     */
    public static function uniqueSlugFor(string $name): string
    {
        $base = Str::limit(Str::slug($name), 60, '') ?: 'practice';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (static::where('slug', $slug)->exists());

        return $slug;
    }
}
