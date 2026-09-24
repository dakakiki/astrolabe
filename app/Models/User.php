<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'locale', 'timezone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
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
        ];
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
