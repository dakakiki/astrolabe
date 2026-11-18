<?php

namespace App\Actions\Booking;

use App\Enums\ActivityType;
use App\Enums\AppointmentActor;
use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Events\Appointments\AppointmentMoveRequested;
use App\Events\Appointments\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\BookingPolicy;
use App\Models\PortalUser;
use App\Support\Activity\ActivityLog;
use App\Support\Audit\Audit;
use App\Support\Booking\BookingAvailability;
use App\Support\Booking\BookingConflict;
use App\Support\Portal\PortalContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The client moves one of their appointments in the portal (Phase 9b), until
 * the policy's deadline before its start and only to a free time — checked
 * again under the astrologer's lock, leaving the appointment's own time out.
 * The appointment keeps its length, status and zone (a request stays a request);
 * the timeline records the move as made in the portal and the astrologer hears
 * about it (AppointmentRescheduled).
 *
 * A confirmed appointment in a practice that confirms bookings by hand is not
 * moved at once (docs/spec/12, "Pomeranje kao zahtev"): it keeps its time, the
 * new one is held as `proposed_starts_at` until the astrologer confirms or
 * declines the move (ConfirmMove, DeclineMove), and both hear of the request
 * (AppointmentMoveRequested). Asking again replaces the earlier request.
 */
class RescheduleAppointment
{
    public function __construct(private readonly ActivityLog $activity) {}

    public function handle(PortalContext $context, PortalUser $user, Appointment $appointment, CarbonImmutable $start): Appointment
    {
        $policy = BookingPolicy::current();
        $availability = $policy->enabled ? BookingAvailability::for($policy) : null;

        if ($availability === null) {
            throw BookingConflict::disabled();
        }

        $start = $start->utc();

        return DB::transaction(function () use ($context, $user, $appointment, $start, $policy, $availability) {
            BookAppointment::lockAstrologer($context->workspace()->getKey(), (int) $appointment->assigned_user_id);

            // Read again under the lock: the astrologer may have changed it a moment ago.
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());
            $now = CarbonImmutable::now();

            if (! $appointment->status->isOpen()) {
                throw BookingConflict::notChangeable();
            }

            if (! $policy->clientMayReschedule($appointment, $now)) {
                throw BookingConflict::tooLate();
            }

            // Picking the time it already has — or already asked for — changes nothing: no entry, no email.
            if ($appointment->starts_at->equalTo($start) || $appointment->proposed_starts_at?->equalTo($start)) {
                return $appointment->load('service');
            }

            $minutes = $appointment->durationMinutes();

            // Only the practice's booking astrologer has hours in the portal; the
            // appointment must be theirs for the free times to mean anything.
            if ((int) $appointment->assigned_user_id !== (int) $availability->astrologer()->getKey()) {
                throw BookingConflict::notChangeable();
            }

            if (! $availability->isFree($start, $minutes, $now, $appointment)) {
                throw BookingConflict::slotTaken($availability->slotsToMove($appointment, $now), $minutes);
            }

            if ($policy->requires_confirmation && $appointment->status === AppointmentStatus::Scheduled) {
                return $this->requestMove($context, $user, $appointment, $start, $minutes);
            }

            $previousStart = $appointment->starts_at;
            $previousEnd = $appointment->ends_at;

            $appointment->starts_at = $start;
            $appointment->ends_at = $start->addMinutes($minutes);
            $appointment->save();

            $this->activity->record($context->client(), ActivityType::AppointmentRescheduled, [
                'from' => $previousStart->toIso8601ZuluString(),
                'from_end' => $previousEnd->toIso8601ZuluString(),
                'to' => $appointment->starts_at->toIso8601ZuluString(),
                'to_end' => $appointment->ends_at->toIso8601ZuluString(),
                'timezone' => $appointment->timezone,
                'source' => 'portal',
            ], $appointment);

            $context->client()->touchActivity();

            Audit::portal(AuditEvent::PortalAppointmentRescheduled, $user, $appointment, workspace: $context->workspace());

            AppointmentRescheduled::dispatch($appointment, $previousStart, $previousEnd, AppointmentActor::Client);

            return $appointment->load('service');
        });
    }

    /** The confirmed appointment stays where it is; the new time waits for the astrologer. */
    private function requestMove(PortalContext $context, PortalUser $user, Appointment $appointment, CarbonImmutable $start, int $minutes): Appointment
    {
        $appointment->proposed_starts_at = $start;
        $appointment->proposed_ends_at = $start->addMinutes($minutes);
        $appointment->proposed_at = CarbonImmutable::now();
        $appointment->save();

        $this->activity->record($context->client(), ActivityType::AppointmentMoveRequested, [
            'from' => $appointment->starts_at->toIso8601ZuluString(),
            'from_end' => $appointment->ends_at->toIso8601ZuluString(),
            'to' => $appointment->proposed_starts_at->toIso8601ZuluString(),
            'to_end' => $appointment->proposed_ends_at->toIso8601ZuluString(),
            'timezone' => $appointment->timezone,
            'source' => 'portal',
        ], $appointment);

        $context->client()->touchActivity();

        Audit::portal(AuditEvent::PortalAppointmentMoveRequested, $user, $appointment, workspace: $context->workspace());

        AppointmentMoveRequested::dispatch($appointment);

        return $appointment->load('service');
    }
}
