<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;
use App\Policies\Concerns\ChecksMembership;

/**
 * Services are part of how the practice is set up: every member sees them and
 * uses them on consultations, the owner manages them — like the workspace's
 * own astrology methods.
 */
class ServicePolicy
{
    use ChecksMembership;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Service $service): bool
    {
        return $this->isMember($user) && $this->inCurrentWorkspace($service);
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, Service $service): bool
    {
        return $this->isOwner($user) && $this->inCurrentWorkspace($service);
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->update($user, $service);
    }
}
