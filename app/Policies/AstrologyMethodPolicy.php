<?php

namespace App\Policies;

use App\Models\AstrologyMethod;
use App\Models\User;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Built-in methods are read-only. A workspace's own methods can be managed by
 * its owner. Methods of other workspaces never reach a policy: the workspace
 * scope already turns them into a 404.
 */
class AstrologyMethodPolicy
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function create(User $user): bool
    {
        return $this->ownsCurrentWorkspace($user);
    }

    public function update(User $user, AstrologyMethod $method): bool
    {
        return $this->canManage($user, $method);
    }

    public function delete(User $user, AstrologyMethod $method): bool
    {
        return $this->canManage($user, $method);
    }

    private function canManage(User $user, AstrologyMethod $method): bool
    {
        return ! $method->isSystem()
            && $method->workspace_id === $this->current->id()
            && $this->ownsCurrentWorkspace($user);
    }

    private function ownsCurrentWorkspace(User $user): bool
    {
        $workspace = $this->current->get();

        return $workspace !== null && $user->ownsWorkspace($workspace);
    }
}
