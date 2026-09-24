<?php

namespace App\Actions\Appointments;

use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Support\Activity\ActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an appointment with a reason (docs/spec/10): the row stays, its time
 * becomes free, and the timeline records when and why.
 */
class CancelAppointment
{
    public function __construct(private readonly ActivityLog $activity) {}

    public function handle(Appointment $appointment, string $reason): Appointment
    {
        return DB::transaction(function () use ($appointment, $reason) {
            $appointment->status = AppointmentStatus::Cancelled;
            $appointment->cancellation_reason = $reason;
            $appointment->cancelled_at = now();
            $appointment->save();

            $client = Client::query()->findOrFail($appointment->client_id);

            $this->activity->record($client, ActivityType::AppointmentCancelled, [
                'starts_at' => $appointment->starts_at->toIso8601ZuluString(),
                'timezone' => $appointment->timezone,
                'reason' => $reason,
            ], $appointment);

            $client->touchActivity();

            return $appointment->load(['client.birthDetails', 'service', 'assignedUser', 'consultation']);
        });
    }
}
