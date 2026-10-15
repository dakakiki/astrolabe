<?php

namespace App\Events\Appointments;

use App\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The astrologer confirmed a request booked in the portal (Phase 9b): it is
 * `scheduled` now, and the client hears so.
 */
final class AppointmentConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment) {}
}
