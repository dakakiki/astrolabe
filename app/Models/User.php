<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Support\Notifications\AppointmentReminders;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\TaskDigest;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'locale', 'timezone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
            'next_digest_at' => 'immutable_datetime',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'is_admin' => 'boolean',
            'suspended_at' => 'immutable_datetime',
        ];
    }

    /** Two-factor sign-in is on once the first code was confirmed (Settings → Security). */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * The operator's account (Phase 8c): made only by `admin:create`, never a
     * member of a practice, and never set from a request.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin === true;
    }

    /** Suspended by the operator: signing in and every request stop until restored. */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * The next morning email follows the person's preferences and zone; so do
     * their unsent appointment reminders.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if (! $user->exists || $user->isDirty(['notification_preferences', 'timezone'])) {
                $user->next_digest_at = TaskDigest::nextAt($user, CarbonImmutable::now());
            }
        });

        static::saved(function (User $user) {
            if ($user->wasChanged(['notification_preferences', 'timezone'])) {
                AppointmentReminders::replan($user);
            }
        });
    }

    /** Stored preferences laid over the defaults (Settings → Notifications). */
    public function notificationPreferences(): NotificationPreferences
    {
        return NotificationPreferences::fromArray($this->notification_preferences);
    }

    /** Email goes out in the person's own language. */
    public function preferredLocale(): string
    {
        return $this->locale ?: config('app.locale');
    }

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    /**
     * Workspaces this user may currently act in.
     *
     * @return BelongsToMany<Workspace, $this>
     */
    public function activeWorkspaces(): BelongsToMany
    {
        return $this->workspaces()->wherePivot('status', MembershipStatus::Active->value);
    }

    /**
     * The workspace last used by this user. Only a hint: membership is always
     * re-checked by ResolveCurrentWorkspace before it is trusted.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /**
     * Versions of the Terms, the DPA and the privacy policy this person
     * accepted or saw (Phase 8c, `LegalDocuments`).
     *
     * @return HasMany<LegalAcceptance, $this>
     */
    public function legalAcceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }

    public function roleIn(Workspace $workspace): ?WorkspaceRole
    {
        $membership = $this->activeWorkspaces()
            ->whereKey($workspace->getKey())
            ->first()?->membership;

        return $membership?->role;
    }

    public function ownsWorkspace(Workspace $workspace): bool
    {
        return $this->roleIn($workspace) === WorkspaceRole::Owner;
    }
}
