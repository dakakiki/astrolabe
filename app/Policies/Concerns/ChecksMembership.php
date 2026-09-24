<?php

namespace App\Policies\Concerns;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Membership checks shared by the practice-data policies. Records of other
 * workspaces never get here — the tenant scope turns them into a 404 — so the
 * workspace comparison is a second line of defence, not the first.
 */
trait ChecksMembership
{
    protected function isMember(User $user): bool
    {
        return app(CurrentWorkspace::class)->roleOf($user) !== null;
    }

    protected function isOwner(User $user): bool
    {
        return app(CurrentWorkspace::class)->roleOf($user) === WorkspaceRole::Owner;
    }

    protected function inCurrentWorkspace(Model $model): bool
    {
        return $model->getAttribute('workspace_id') === app(CurrentWorkspace::class)->id();
    }
}
