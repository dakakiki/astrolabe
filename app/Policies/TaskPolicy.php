<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksMembership;

/**
 * Tasks are the practice's shared to-do list: every active member sees and
 * works with them, like with consultations. They are internal and never reach
 * the client. Finer rules (only one's own) arrive with teams (P2).
 */
class TaskPolicy
{
    use ChecksMembership;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->isMember($user) && $this->inCurrentWorkspace($task);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }
}
