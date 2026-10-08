<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace) !== null;
    }

    /** Settings, chart defaults and the method selection belong to the owner. */
    public function update(User $user, Workspace $workspace): bool
    {
        return $user->ownsWorkspace($workspace);
    }

    /** Exporting everything the practice holds belongs to the owner (Phase 8b). */
    public function export(User $user, Workspace $workspace): bool
    {
        return $user->ownsWorkspace($workspace);
    }

    /** So does scheduling the practice for deletion, and cancelling it. */
    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->ownsWorkspace($workspace);
    }
}
