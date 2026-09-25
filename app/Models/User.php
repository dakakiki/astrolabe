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
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'locale', 'timezone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        ];
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
