<?php

namespace App\Events\Appointments;

use App\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The client asked in the portal to move a confirmed appointment while the practice confirms
 * bookings by hand (docs/spec/12, "Pomeranje kao zahtev"): it keeps its time, the proposed one
 * (`proposed_starts_at`) is held, the astrologer is asked and the client told it waits.
 */
final class AppointmentMoveRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment) {}
}
