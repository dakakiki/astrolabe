<?php

namespace App\Actions\Booking;

use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Events\Appointments\AppointmentMoveConfirmed;
use App\Models\Appointment;
use App\Models\Client;
use App\Support\Activity\ActivityLog;
use App\Support\Booking\BookingConflict;
use App\Support\Calendar\OverlappingAppointments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The astrologer accepts a client's request to move a confirmed appointment
 * (docs/spec/12, "Pomeranje kao zahtev"): it goes to the time the client asked
 * for (which plans both reminders again), the timeline records the move as made
 * in the portal, and the client hears so (AppointmentMoveConfirmed).
 *
 * The portal held the new time, but the calendar does not stop the astrologer
 * from putting something there meanwhile: an overlap is refused like in the
 * calendar (OverlappingAppointments) unless it is confirmed on purpose.
 */
class ConfirmMove
{
    public function __construct(private readonly ActivityLog $activity) {}

    /**
     * @throws BookingConflict 409 `no_move_request`, `move_passed`
     * @throws OverlappingAppointments
     */
    public function handle(Appointment $appointment, bool $allowOverlap = false): Appointment
    {
        return DB::transaction(function () use ($appointment, $allowOverlap) {
            if ($appointment->assigned_user_id !== null) {
                BookAppointment::lockAstrologer((int) $appointment->workspace_id, (int) $appointment->assigned_user_id);
            }

            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());

            if (! $appointment->hasMoveRequest() || $appointment->status !== AppointmentStatus::Scheduled) {
                throw BookingConflict::noMoveRequest();
            }

            if (! $appointment->proposed_starts_at->isFuture()) {
                throw BookingConflict::movePassed();
            }

            $overlapping = Appointment::query()
                ->with(['client', 'service', 'assignedUser'])
                ->where('assigned_user_id', $appointment->assigned_user_id)
                ->whereKeyNot($appointment->getKey())
                ->overlapping($appointment->proposed_starts_at, $appointment->proposed_ends_at)
                ->orderBy('starts_at')
                ->get();

            if ($overlapping->isNotEmpty() && ! $allowOverlap) {
                throw new OverlappingAppointments($overlapping);
            }

            $previousStart = $appointment->starts_at;
            $previousEnd = $appointment->ends_at;

            $appointment->starts_at = $appointment->proposed_starts_at;
            $appointment->ends_at = $appointment->proposed_ends_at;
            $appointment->clearMoveRequest();
            $appointment->save();

            $client = Client::query()->findOrFail($appointment->client_id);

            $this->activity->record($client, ActivityType::AppointmentRescheduled, [
                'from' => $previousStart->toIso8601ZuluString(),
                'from_end' => $previousEnd->toIso8601ZuluString(),
                'to' => $appointment->starts_at->toIso8601ZuluString(),
                'to_end' => $appointment->ends_at->toIso8601ZuluString(),
                'timezone' => $appointment->timezone,
                'source' => 'portal',
                'confirmed' => true,
            ], $appointment);

            $client->touchActivity();

            AppointmentMoveConfirmed::dispatch($appointment, CarbonImmutable::instance($previousStart), CarbonImmutable::instance($previousEnd));

            return $appointment->load(['client.birthDetails', 'service', 'assignedUser', 'consultation']);
        });
    }
}
