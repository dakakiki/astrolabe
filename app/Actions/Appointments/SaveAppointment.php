<?php

namespace App\Actions\Appointments;

use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Enums\LocationType;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Support\Activity\ActivityLog;
use App\Support\Calendar\OverlappingAppointments;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates an appointment (docs/spec/10). Only the keys present in
 * the input are changed.
 *
 * - The start is wall-clock time in a zone, stored in UTC; the end follows
 *   from the duration (the service's, when none is given for a new one).
 * - Before writing, the astrologer's other appointments are checked for
 *   overlaps inside the transaction, with the astrologer's membership row
 *   locked, so two parallel requests cannot both take the same time unnoticed.
 *   An overlap is refused unless the request says it is intended.
 * - Moving an appointment keeps the row and records "moved from X to Y".
 * - A new appointment gets the astrologer's usual reminder unless the request
 *   names one (or none); the reminder moves with it (Appointment::booted).
 */
class SaveAppointment
{
    private const FIELDS = ['service_id', 'location_type', 'location_details', 'notes', 'assigned_user_id', 'reminder_minutes'];

    public function __construct(
        private readonly ActivityLog $activity,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated
     *
     * @throws OverlappingAppointments
     */
    public function handle(Appointment $appointment, array $input, bool $allowOverlap = false): Appointment
    {
        return DB::transaction(function () use ($appointment, $input, $allowOverlap) {
            $creating = ! $appointment->exists;
            $previousStart = $appointment->starts_at;
            $previousEnd = $appointment->ends_at;

            $appointment->fill(Arr::only($input, self::FIELDS));

            if ($appointment->isDirty('service_id')) {
                $appointment->unsetRelation('service');
            }

            if ($creating) {
                $appointment->client_id = (int) $input['client_id'];
                $appointment->created_by = auth()->id();
                $appointment->assigned_user_id ??= auth()->id();
                $appointment->booking_source = BookingSource::Manual;
                $appointment->status = AppointmentStatus::Scheduled;
                $appointment->location_type ??= $this->defaultLocation($appointment->service);

                if (! array_key_exists('reminder_minutes', $input)) {
                    $appointment->reminder_minutes = $appointment->assignedUser?->notificationPreferences()->reminderMinutes;
                }
            }

            if (array_key_exists('status', $input)) {
                $this->applyStatus($appointment, AppointmentStatus::from($input['status']));
            }

            if ($creating || array_intersect_key($input, array_flip(['starts_at', 'timezone', 'duration_minutes'])) !== []) {
                $this->applyTime($appointment, $input);
            }

            if ($appointment->status->occupiesTime()
                && ($creating || $appointment->isDirty(['starts_at', 'ends_at', 'status', 'assigned_user_id']))) {
                $this->guardOverlaps($appointment, $allowOverlap);
            }

            $moved = ! $creating && ($appointment->isDirty('starts_at') || $appointment->isDirty('ends_at'));
            $appointment->save();

            $client = Client::query()->findOrFail($appointment->client_id);

            if ($moved) {
                $this->activity->record($client, ActivityType::AppointmentRescheduled, [
                    'from' => $previousStart->toIso8601ZuluString(),
                    'from_end' => $previousEnd->toIso8601ZuluString(),
                    'to' => $appointment->starts_at->toIso8601ZuluString(),
                    'to_end' => $appointment->ends_at->toIso8601ZuluString(),
                    'timezone' => $appointment->timezone,
                ], $appointment);
            }

            $client->touchActivity();

            return $appointment->load(['client.birthDetails', 'service', 'assignedUser', 'consultation']);
        });
    }

    /**
     * Wall-clock start in a zone → UTC, and the end from the duration. A new
     * zone alone keeps the wall-clock time, as for consultations.
     *
     * @param  array<string, mixed>  $input
     */
    private function applyTime(Appointment $appointment, array $input): void
    {
        $zone = $input['timezone'] ?? $appointment->timezone ?? auth()->user()?->timezone ?? 'UTC';
        $local = $input['starts_at'] ?? $appointment->localStart()->format('Y-m-d\TH:i');
        $minutes = (int) ($input['duration_minutes']
            ?? ($appointment->exists ? $appointment->durationMinutes() : $appointment->service?->duration_minutes));

        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $local, $zone)->utc();

        $appointment->timezone = $zone;
        $appointment->starts_at = $start;
        $appointment->ends_at = $start->addMinutes($minutes);
    }

    /** Cancelling has its own action; any other status clears an earlier cancellation. */
    private function applyStatus(Appointment $appointment, AppointmentStatus $status): void
    {
        $appointment->status = $status;

        if ($status !== AppointmentStatus::Cancelled) {
            $appointment->cancellation_reason = null;
            $appointment->cancelled_at = null;
        }
    }

    private function guardOverlaps(Appointment $appointment, bool $allowOverlap): void
    {
        // One astrologer at a time: parallel requests for the same person queue here.
        DB::table('workspace_user')
            ->where('workspace_id', $appointment->workspace_id ?? $this->current->id())
            ->where('user_id', $appointment->assigned_user_id)
            ->lockForUpdate()
            ->first();

        $overlapping = Appointment::query()
            ->with(['client', 'service', 'assignedUser'])
            ->where('assigned_user_id', $appointment->assigned_user_id)
            ->when($appointment->exists, fn ($query) => $query->whereKeyNot($appointment->getKey()))
            ->overlapping($appointment->starts_at, $appointment->ends_at)
            ->orderBy('starts_at')
            ->get();

        if ($overlapping->isNotEmpty() && ! $allowOverlap) {
            throw new OverlappingAppointments($overlapping);
        }
    }

    /** Where a new appointment is held when the form does not say: the service's place, online if it allows both. */
    private function defaultLocation(?Service $service): LocationType
    {
        return $service?->location_type === LocationType::InPerson ? LocationType::InPerson : LocationType::Online;
    }
}
