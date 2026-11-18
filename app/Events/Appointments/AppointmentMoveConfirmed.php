<?php

namespace App\Events\Appointments;

use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The astrologer accepted the client's move request: the appointment is at the new time now,
 * and the client hears so.
 */
final class AppointmentMoveConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly CarbonImmutable $previousStart,
        public readonly CarbonImmutable $previousEnd,
    ) {}
}
