<?php

namespace App\Actions\Booking;

use App\Actions\Appointments\CancelAppointment;
use App\Enums\AppointmentActor;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\BookingPolicy;
use App\Models\PortalUser;
use App\Support\Audit\Audit;
use App\Support\Booking\BookingConflict;
use App\Support\Portal\PortalContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The client cancels one of their appointments in the portal (Phase 9b), until
 * the policy's deadline before its start, with an optional message for the
 * astrologer. A request waiting for confirmation can be withdrawn the same way.
 */
class CancelPortalAppointment
{
    public function __construct(private readonly CancelAppointment $cancel) {}

    public function handle(PortalContext $context, PortalUser $user, Appointment $appointment, ?string $reason): Appointment
    {
        $policy = BookingPolicy::current();

        return DB::transaction(function () use ($context, $user, $appointment, $reason, $policy) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());

            if (! $appointment->status->isOpen()) {
                throw BookingConflict::notChangeable();
            }

            if (! $policy->clientMayCancel($appointment, CarbonImmutable::now())) {
                throw $policy->enabled ? BookingConflict::tooLate() : BookingConflict::disabled();
            }

            $appointment = $this->cancel->handle($appointment, $reason, AppointmentActor::Client);

            Audit::portal(AuditEvent::PortalAppointmentCancelled, $user, $appointment, workspace: $context->workspace());

            return $appointment->load('service');
        });
    }
}
