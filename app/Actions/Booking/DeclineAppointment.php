<?php

namespace App\Actions\Booking;

use App\Enums\ActivityType;
use App\Enums\AppointmentActor;
use App\Enums\AppointmentStatus;
use App\Events\Appointments\AppointmentDeclined;
use App\Models\Appointment;
use App\Models\Client;
use App\Support\Activity\ActivityLog;
use App\Support\Booking\BookingConflict;
use Illuminate\Support\Facades\DB;

/**
 * The astrologer turns down a request booked in the portal (Phase 9b): it is
 * cancelled by the practice with `declined_at`, its time is free again, and the
 * reason — optional, written for the client — goes to them (AppointmentDeclined).
 */
class DeclineAppointment
{
    public function __construct(private readonly ActivityLog $activity) {}

    public function handle(Appointment $appointment, ?string $reason): Appointment
    {
        return DB::transaction(function () use ($appointment, $reason) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());

            if ($appointment->status !== AppointmentStatus::Requested) {
                throw BookingConflict::notRequested();
            }

            $appointment->status = AppointmentStatus::Cancelled;
            $appointment->cancellation_reason = $reason;
            $appointment->cancelled_at = now();
            $appointment->cancelled_by = AppointmentActor::Practice;
            $appointment->declined_at = now();
            $appointment->save();

            $client = Client::query()->findOrFail($appointment->client_id);

            $this->activity->record($client, ActivityType::AppointmentCancelled, array_filter([
                'starts_at' => $appointment->starts_at->toIso8601ZuluString(),
                'timezone' => $appointment->timezone,
                'reason' => $reason,
                'declined' => true,
            ], fn ($value) => $value !== null), $appointment);

            $client->touchActivity();

            AppointmentDeclined::dispatch($appointment);

            return $appointment->load(['client.birthDetails', 'service', 'assignedUser', 'consultation']);
        });
    }
}
