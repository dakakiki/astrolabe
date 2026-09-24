<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Every active member of the workspace works with its clients. Clients of other
 * workspaces never reach a policy: the workspace scope turns them into a 404.
 * Finer rules (members seeing only assigned clients) arrive with teams (P2).
 */
class ClientPolicy
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->isMember($user) && $client->workspace_id === $this->current->id();
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->view($user, $client);
    }

    private function isMember(User $user): bool
    {
        $workspace = $this->current->get();

        return $workspace !== null && $user->roleIn($workspace) !== null;
    }
}
