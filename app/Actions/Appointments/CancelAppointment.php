<?php

namespace App\Actions\Appointments;

use App\Enums\ActivityType;
use App\Enums\AppointmentActor;
use App\Enums\AppointmentStatus;
use App\Events\Appointments\AppointmentCancelled;
use App\Models\Appointment;
use App\Models\Client;
use App\Support\Activity\ActivityLog;
use App\Support\Booking\BookingConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an appointment with a reason (docs/spec/10): the row stays, its time
 * becomes free, and the timeline records when and why.
 *
 * Either side may cancel (Phase 9b): the practice with its own reason, which
 * stays internal, or the client in the portal (CancelPortalAppointment), whose
 * message is optional. `cancelled_by` keeps who it was; AppointmentCancelled
 * (after the commit) tells the other side — the client only when `$notifyClient`.
 *
 * The row is read again under a lock before anything is written: with two
 * sides able to change it, the client may have cancelled (or the practice
 * answered) a moment after the request was checked. The practice then gets
 * the same 422 as for an appointment that was not scheduled to begin with, and
 * nothing is written, recorded or announced a second time.
 */
class CancelAppointment
{
    public function __construct(private readonly ActivityLog $activity) {}

    /**
     * @throws ValidationException when the practice cancels what is no longer scheduled
     * @throws BookingConflict 409 `not_changeable` when the client cancels what is no longer open
     */
    public function handle(
        Appointment $appointment,
        ?string $reason,
        AppointmentActor $by = AppointmentActor::Practice,
        bool $notifyClient = true,
    ): Appointment {
        return DB::transaction(function () use ($appointment, $reason, $by, $notifyClient) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());

            self::ensureCancellable($appointment, $by);

            $appointment->status = AppointmentStatus::Cancelled;
            $appointment->cancellation_reason = $reason;
            $appointment->cancelled_at = now();
            $appointment->cancelled_by = $by;
            $appointment->save();

            $client = Client::query()->findOrFail($appointment->client_id);

            $this->activity->record($client, ActivityType::AppointmentCancelled, array_filter([
                'starts_at' => $appointment->starts_at->toIso8601ZuluString(),
                'timezone' => $appointment->timezone,
                'reason' => $reason,
                'source' => $by === AppointmentActor::Client ? 'portal' : null,
            ], fn ($value) => $value !== null), $appointment);

            $client->touchActivity();

            AppointmentCancelled::dispatch($appointment, $by, $notifyClient);

            return $appointment->load(['client.birthDetails', 'service', 'assignedUser', 'consultation']);
        });
    }

    /**
     * The practice cancels only a scheduled appointment (a request is declined,
     * CancelAppointmentRequest); the client any open one (CancelPortalAppointment).
     */
    private static function ensureCancellable(Appointment $appointment, AppointmentActor $by): void
    {
        if ($by === AppointmentActor::Client) {
            if (! $appointment->status->isOpen()) {
                throw BookingConflict::notChangeable();
            }

            return;
        }

        if ($appointment->status !== AppointmentStatus::Scheduled) {
            throw ValidationException::withMessages(['status' => __($appointment->status === AppointmentStatus::Requested
                ? 'appointments.decline_instead'
                : 'appointments.not_cancellable')]);
        }
    }
}
