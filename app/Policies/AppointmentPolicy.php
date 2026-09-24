<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
use App\Policies\Concerns\ChecksMembership;

/**
 * The calendar is the practice's shared schedule: every active member works
 * with it, like with clients and consultations. Finer rules (seeing only one's
 * own appointments) arrive with teams (P2).
 */
class AppointmentPolicy
{
    use ChecksMembership;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $this->isMember($user) && $this->inCurrentWorkspace($appointment);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->view($user, $appointment);
    }
}
