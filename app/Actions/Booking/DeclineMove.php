<?php

namespace App\Actions\Booking;

use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Events\Appointments\AppointmentMoveDeclined;
use App\Models\Appointment;
use App\Models\Client;
use App\Support\Activity\ActivityLog;
use App\Support\Booking\BookingConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The astrologer keeps a confirmed appointment at its time instead of the one
 * the client asked to move it to (docs/spec/12, "Pomeranje kao zahtev"): the
 * request is dropped, the time it held is free again, and the client hears so
 * with the reason — optional, written for the client (AppointmentMoveDeclined).
 */
class DeclineMove
{
    public function __construct(private readonly ActivityLog $activity) {}

    /**
     * @throws BookingConflict 409 `no_move_request`
     */
    public function handle(Appointment $appointment, ?string $reason): Appointment
    {
        return DB::transaction(function () use ($appointment, $reason) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());

            if (! $appointment->hasMoveRequest() || $appointment->status !== AppointmentStatus::Scheduled) {
                throw BookingConflict::noMoveRequest();
            }

            $requestedStart = CarbonImmutable::instance($appointment->proposed_starts_at);
            $requestedEnd = CarbonImmutable::instance($appointment->proposed_ends_at);

            $appointment->clearMoveRequest();
            $appointment->save();

            $client = Client::query()->findOrFail($appointment->client_id);

            $this->activity->record($client, ActivityType::AppointmentMoveDeclined, array_filter([
                'starts_at' => $appointment->starts_at->toIso8601ZuluString(),
                'requested' => $requestedStart->toIso8601ZuluString(),
                'requested_end' => $requestedEnd->toIso8601ZuluString(),
                'timezone' => $appointment->timezone,
                'reason' => $reason,
            ], fn ($value) => $value !== null), $appointment);

            $client->touchActivity();

            AppointmentMoveDeclined::dispatch($appointment, $requestedStart, $requestedEnd, $reason);

            return $appointment->load(['client.birthDetails', 'service', 'assignedUser', 'consultation']);
        });
    }
}
