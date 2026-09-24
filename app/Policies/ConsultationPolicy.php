<?php

namespace App\Policies;

use App\Models\Consultation;
use App\Models\User;
use App\Policies\Concerns\ChecksMembership;

/**
 * Consultations are the practice's shared record: every active member works
 * with them, like with clients. Finer rules arrive with teams (P2).
 */
class ConsultationPolicy
{
    use ChecksMembership;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Consultation $consultation): bool
    {
        return $this->isMember($user) && $this->inCurrentWorkspace($consultation);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Consultation $consultation): bool
    {
        return $this->view($user, $consultation);
    }

    public function delete(User $user, Consultation $consultation): bool
    {
        return $this->view($user, $consultation);
    }
}
