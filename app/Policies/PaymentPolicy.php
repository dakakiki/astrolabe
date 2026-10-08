<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksMembership;

/**
 * Payments are the practice's own record of what clients paid: every active
 * member sees and records them, like consultations. They are internal and
 * never reach the client (docs/spec/09). Finer rules — who may see the money —
 * arrive with teams and roles (P2).
 */
class PaymentPolicy
{
    use ChecksMembership;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->isMember($user) && $this->inCurrentWorkspace($payment);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    /** A payment kept after its client was deleted is a record only; it may still be removed. */
    public function update(User $user, Payment $payment): bool
    {
        return $this->view($user, $payment) && ! $payment->isAnonymised();
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->view($user, $payment);
    }
}
