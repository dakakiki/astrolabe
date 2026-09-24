<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's membership in a workspace (the workspace_user pivot).
 *
 * @property WorkspaceRole $role
 * @property MembershipStatus $status
 */
class Membership extends Pivot
{
    protected $table = 'workspace_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'status' => MembershipStatus::class,
        ];
    }
}
