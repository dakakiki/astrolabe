<?php

namespace App\Actions\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Enums\BookingSource;
use App\Enums\LocationType;
use App\Events\Appointments\AppointmentBooked;
use App\Models\Appointment;
use App\Models\BookingPolicy;
use App\Models\PortalUser;
use App\Models\Service;
use App\Support\Audit\Audit;
use App\Support\Booking\BookingAvailability;
use App\Support\Booking\BookingConflict;
use App\Support\Portal\PortalContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A client books a free time in the portal (docs/spec/12, "Zakazivanje (9b)").
 *
 * Inside one transaction, after locking the astrologer's membership row (the
 * lock the calendar takes too), the time is checked again against everything
 * that holds time now — the portal never allows an overlap. A time that went in
 * the meantime is a 409 with a fresh list of free times (BookingConflict); a
 * client who already holds the most open appointments allowed gets a 409 too.
 *
 * The appointment goes to the practice's booking astrologer, in the client's
 * zone, `requested` when the practice confirms by hand and `scheduled`
 * otherwise; the client's message is for the astrologer only. The astrologer's
 * usual reminder applies. The audit and the emails follow (AppointmentBooked).
 */
class BookAppointment
{
    public function handle(
        PortalContext $context,
        PortalUser $user,
        Service $service,
        CarbonImmutable $start,
        ?LocationType $location = null,
        ?string $message = null,
    ): Appointment {
        $policy = BookingPolicy::current();
        $availability = $policy->enabled ? BookingAvailability::for($policy) : null;

        if ($availability === null || ! $service->is_active || ! $service->bookable_in_portal) {
            throw BookingConflict::disabled();
        }

        $astrologer = $availability->astrologer();
        $minutes = $service->duration_minutes;
        $start = $start->utc();

        return DB::transaction(function () use ($context, $user, $service, $start, $location, $message, $policy, $availability, $astrologer, $minutes) {
            self::lockAstrologer($context->workspace()->getKey(), $astrologer->getKey());

            $now = CarbonImmutable::now();

            self::ensureRoomFor($context, $now);

            if (! $availability->isFree($start, $minutes, $now)) {
                throw BookingConflict::slotTaken($availability->slots($minutes, $now), $minutes);
            }

            $appointment = new Appointment;
            $appointment->forceFill([
                'client_id' => $context->client()->getKey(),
                'service_id' => $service->getKey(),
                'assigned_user_id' => $astrologer->getKey(),
                'created_by' => null,
                'portal_user_id' => $user->getKey(),
                'starts_at' => $start,
                'ends_at' => $start->addMinutes($minutes),
                'timezone' => $user->timezone ?: $context->workspace()->timezone,
                'status' => $policy->requires_confirmation ? AppointmentStatus::Requested : AppointmentStatus::Scheduled,
                'location_type' => self::location($service, $location),
                'booking_source' => BookingSource::Portal,
                'client_note' => $message,
                'reminder_minutes' => $astrologer->notificationPreferences()->reminderMinutes,
            ])->save();

            $context->client()->touchActivity();

            Audit::portal(AuditEvent::PortalAppointmentBooked, $user, $appointment, [
                'status' => $appointment->status->value,
            ], $context->workspace());

            AppointmentBooked::dispatch($appointment);

            return $appointment->load('service');
        });
    }

    /**
     * Calendar and portal queue on the same row, so the last check before
     * writing sees every appointment written before it.
     */
    public static function lockAstrologer(int $workspaceId, int $astrologerId): void
    {
        DB::table('workspace_user')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $astrologerId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * One client holds at most `portal.booking.max_open_per_client` open
     * appointments that have not ended — theirs from the portal and the
     * practice's alike — so one account cannot fill the booking window. Counted
     * under the astrologer's lock, which every portal booking takes, so two
     * bookings at once by the same client cannot both slip under the cap.
     */
    private static function ensureRoomFor(PortalContext $context, CarbonImmutable $now): void
    {
        $max = max(0, (int) config('portal.booking.max_open_per_client', 10));

        $open = Appointment::query()
            ->where('client_id', $context->client()->getKey())
            ->whereIn('status', AppointmentStatus::openValues())
            ->where('ends_at', '>', $now)
            ->count();

        if ($open >= $max) {
            throw BookingConflict::tooManyOpen($max);
        }
    }

    /** The service's place; the client picks when it is held either way (online unless they say). */
    private static function location(Service $service, ?LocationType $chosen): LocationType
    {
        return match ($service->location_type) {
            LocationType::Either => $chosen === LocationType::InPerson ? LocationType::InPerson : LocationType::Online,
            default => $service->location_type,
        };
    }
}
