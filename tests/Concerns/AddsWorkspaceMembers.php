<?php

namespace Tests\Concerns;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;

trait AddsWorkspaceMembers
{
    /** A second, active member (not the owner) of the owner's current workspace. */
    protected function memberOf(User $owner): User
    {
        $member = User::factory()->create(['current_workspace_id' => $owner->current_workspace_id]);

        $owner->currentWorkspace->users()->attach($member, [
            'role' => WorkspaceRole::Member->value,
            'status' => MembershipStatus::Active->value,
        ]);

        return $member;
    }
}
