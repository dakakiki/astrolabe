<?php

namespace App\Policies;

use App\Models\ClientRelationship;
use App\Models\User;
use App\Policies\Concerns\ChecksMembership;

/**
 * A link between a client and a related person or another client; whoever
 * works with the client works with its links.
 */
class ClientRelationshipPolicy
{
    use ChecksMembership;

    public function update(User $user, ClientRelationship $relationship): bool
    {
        return $this->isMember($user) && $this->inCurrentWorkspace($relationship);
    }

    public function delete(User $user, ClientRelationship $relationship): bool
    {
        return $this->update($user, $relationship);
    }
}
