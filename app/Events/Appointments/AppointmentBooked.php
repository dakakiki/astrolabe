<?php

namespace App\Events\Appointments;

use App\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client booked an appointment in the portal (Phase 9b) — `scheduled`, or
 * `requested` while the practice confirms bookings by hand. Dispatched once
 * the booking is committed; the emails to both sides hang on it.
 */
final class AppointmentBooked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment) {}
}
