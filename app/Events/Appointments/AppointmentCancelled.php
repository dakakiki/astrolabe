<?php

namespace App\Events\Appointments;

use App\Enums\AppointmentActor;
use App\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An appointment was cancelled (Phase 9b) — by the client in the portal, or by
 * the practice. Not dispatched for a declined request (AppointmentDeclined).
 * The practice's reason is internal and never goes to the client; the client
 * hears about a cancellation by the practice only when `notifyClient`.
 */
final class AppointmentCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly AppointmentActor $by,
        public readonly bool $notifyClient = true,
    ) {}
}
