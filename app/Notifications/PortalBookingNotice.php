<?php

namespace App\Notifications;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Notifications\ClientAppointmentFacts;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the astrologer about what a client did in the portal (docs/spec/12,
 * "Mejlovi (9b)"; Settings → Notifications → "New portal booking"): a new
 * booking, a request to confirm or decline, a move, a cancellation. Generic on
 * purpose, like the reminders: the time on the astrologer's clock with its
 * zone, the service and a link to the calendar — never the client's name or
 * the message they left; the details open after signing in.
 *
 * Queued for the end of the astrologer's quiet hours when it happens inside
 * them (NotifyAstrologerOfPortalBooking).
 */
class PortalBookingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const BOOKED = 'booked';

    public const REQUESTED = 'requested';

    public const MOVED = 'moved';

    public const CANCELLED = 'cancelled';

    public int $tries = 3;

    // The account may be deleted (with its practice) before the queue gets here.
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly string $kind,
        public readonly int $appointmentId,
        public readonly ?string $service,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly ?string $previousStartsAt = null,
        public readonly ?string $previousEndsAt = null,
    ) {}

    /** A new booking — or a request, while the practice confirms bookings by hand. */
    public static function booked(Appointment $appointment): self
    {
        $kind = $appointment->status === AppointmentStatus::Requested ? self::REQUESTED : self::BOOKED;

        return self::make($kind, $appointment);
    }

    public static function moved(Appointment $appointment, CarbonImmutable $previousStart, CarbonImmutable $previousEnd): self
    {
        return self::make(self::MOVED, $appointment, $previousStart, $previousEnd);
    }

    public static function cancelled(Appointment $appointment): self
    {
        return self::make(self::CANCELLED, $appointment);
    }

    private static function make(string $kind, Appointment $appointment, ?CarbonImmutable $previousStart = null, ?CarbonImmutable $previousEnd = null): self
    {
        return new self(
            $kind,
            (int) $appointment->getKey(),
            ClientAppointmentFacts::serviceName($appointment),
            $appointment->starts_at->toIso8601ZuluString(),
            $appointment->ends_at->toIso8601ZuluString(),
            $previousStart?->toIso8601ZuluString(),
            $previousEnd?->toIso8601ZuluString(),
        );
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Checked again when the queue gets to it (it may have waited for quiet
     * hours to end): still the astrologer's appointment, the practice still
     * open, the email still wanted — and a request still waiting for an answer.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User || ! $notifiable->notificationPreferences()->portalBookings) {
            return false;
        }

        $appointment = Appointment::withoutGlobalScope(WorkspaceScope::class)->find($this->appointmentId);

        return $appointment !== null
            && (int) $appointment->assigned_user_id === (int) $notifiable->getKey()
            && Workspace::query()->whereKey($appointment->workspace_id)->whereNull('deletes_at')->exists()
            && ($this->kind !== self::REQUESTED || $appointment->status === AppointmentStatus::Requested);
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $zone = $notifiable->timezone ?: 'UTC';
        $start = $this->local($this->startsAt, $zone);
        $values = [
            'when' => $this->span($this->startsAt, $this->endsAt, $zone),
            'zone' => $zone,
        ];

        if ($this->kind === self::MOVED) {
            $values['from'] = $this->span((string) $this->previousStartsAt, (string) $this->previousEndsAt, $zone);
        }

        return (new MailMessage)
            ->subject(__("notifications.portal_booking.{$this->kind}.subject", ['when' => $start->isoFormat('ddd ll, LT')]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__("notifications.portal_booking.{$this->kind}.line", $values))
            ->lineIf(filled($this->service), __('notifications.portal_booking.service', ['service' => (string) $this->service]))
            ->lineIf($this->kind === self::REQUESTED, __('notifications.portal_booking.requested.confirm'))
            ->action(
                __($this->kind === self::REQUESTED ? 'notifications.portal_booking.requested.action' : 'notifications.portal_booking.action'),
                url('/calendar').'?'.http_build_query([
                    'date' => $start->format('Y-m-d'),
                    'appointment' => $this->appointmentId,
                ]),
            )
            ->line(__('notifications.portal_booking.private'))
            ->line(__('notifications.portal_booking.settings', ['url' => url('/settings/notifications')]));
    }

    /** "Monday, October 5, 2026, 3:00 PM – 4:00 PM" (the zone follows once). */
    private function span(string $startsAt, string $endsAt, string $zone): string
    {
        $start = $this->local($startsAt, $zone);
        $end = $this->local($endsAt, $zone);

        return __('notifications.portal_booking.span', [
            'day' => $start->isoFormat('dddd, LL'),
            'start' => $start->isoFormat('LT'),
            'end' => $end->isoFormat('LT'),
        ]);
    }

    private function local(string $iso, string $zone): CarbonImmutable
    {
        return CarbonImmutable::parse($iso)->setTimezone($zone)->locale(app()->getLocale());
    }
}
