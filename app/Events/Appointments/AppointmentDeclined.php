<?php

namespace App\Events\Appointments;

use App\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The astrologer turned down a request booked in the portal (Phase 9b): it is
 * cancelled with `declined_at`, and its `cancellation_reason` — written for the
 * client — goes to them.
 */
final class AppointmentDeclined implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment) {}
}
