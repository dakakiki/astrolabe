<?php

namespace App\Actions\Booking;

use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Events\Appointments\AppointmentConfirmed;
use App\Models\Appointment;
use App\Models\Client;
use App\Support\Activity\ActivityLog;
use App\Support\Booking\BookingConflict;
use Illuminate\Support\Facades\DB;

/**
 * The astrologer confirms a request booked in the portal (Phase 9b): it becomes
 * `scheduled` (which plans both reminders), the timeline notes it, and the
 * client hears so (AppointmentConfirmed). Its time was held all along, so
 * nothing is checked for overlaps.
 */
class ConfirmAppointment
{
    public function __construct(private readonly ActivityLog $activity) {}

    public function handle(Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($appointment) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());

            if ($appointment->status !== AppointmentStatus::Requested) {
                throw BookingConflict::notRequested();
            }

            $appointment->status = AppointmentStatus::Scheduled;
            $appointment->save();

            $client = Client::query()->findOrFail($appointment->client_id);

            $this->activity->record($client, ActivityType::AppointmentConfirmed, [
                'starts_at' => $appointment->starts_at->toIso8601ZuluString(),
                'timezone' => $appointment->timezone,
            ], $appointment);

            $client->touchActivity();

            AppointmentConfirmed::dispatch($appointment);

            return $appointment->load(['client.birthDetails', 'service', 'assignedUser', 'consultation']);
        });
    }
}
