<?php

namespace App\Policies;

use App\Models\RelatedPerson;
use App\Models\User;
use App\Policies\Concerns\ChecksMembership;

/**
 * Related people belong with the practice's clients, so every active member
 * works with them, as with clients. Finer rules arrive with teams (P2).
 */
class RelatedPersonPolicy
{
    use ChecksMembership;

    public function view(User $user, RelatedPerson $person): bool
    {
        return $this->isMember($user) && $this->inCurrentWorkspace($person);
    }

    public function update(User $user, RelatedPerson $person): bool
    {
        return $this->view($user, $person);
    }

    public function delete(User $user, RelatedPerson $person): bool
    {
        return $this->view($user, $person);
    }
}
