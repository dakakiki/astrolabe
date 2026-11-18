<?php

namespace App\Events\Appointments;

use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The astrologer kept the appointment at its time instead of the one the client asked for;
 * the client hears so, with the astrologer's message if there is one.
 */
final class AppointmentMoveDeclined implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly CarbonImmutable $requestedStart,
        public readonly CarbonImmutable $requestedEnd,
        public readonly ?string $reason = null,
    ) {}
}
