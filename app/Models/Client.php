<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person the astrologer works with.
 *
 * @property ClientStatus $status
 */
#[Fillable([
    'first_name', 'last_name', 'email', 'phone', 'country_code', 'timezone',
    'preferred_locale', 'status', 'internal_notes', 'assigned_user_id',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'last_activity_at' => 'datetime',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * @return HasOne<ClientBirthDetails, $this>
     */
    public function birthDetails(): HasOne
    {
        return $this->hasOne(ClientBirthDetails::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /**
     * @return BelongsToMany<AstrologyMethod, $this>
     */
    public function astrologyMethods(): BelongsToMany
    {
        return $this->belongsToMany(AstrologyMethod::class, 'client_astrology_method')->withPivot(['is_default', 'notes']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->saveQuietly();
    }
}
