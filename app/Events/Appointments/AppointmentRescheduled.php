<?php

namespace App\Events\Appointments;

use App\Enums\AppointmentActor;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An appointment moved (Phase 9b) — by the client in the portal, or by the
 * practice in the calendar. The client hears about a move by the practice only
 * when `notifyClient` (the astrologer's choice when moving it) and only while
 * they can use the portal; the astrologer hears about the client's moves.
 */
final class AppointmentRescheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly CarbonImmutable $previousStart,
        public readonly CarbonImmutable $previousEnd,
        public readonly AppointmentActor $by,
        public readonly bool $notifyClient = true,
    ) {}
}
