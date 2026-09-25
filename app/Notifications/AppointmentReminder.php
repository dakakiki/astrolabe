<?php

namespace App\Notifications;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email before an appointment (docs/spec/10). Generic on purpose: the day,
 * the time on the astrologer's clock with its zone, and a link — never the
 * client's name or anything about them; the details open after signing in.
 */
class AppointmentReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $appointmentId,
        public readonly string $startsAt,
        public readonly string $endsAt,
    ) {}

    public static function for(Appointment $appointment): self
    {
        return new self(
            $appointment->getKey(),
            $appointment->starts_at->toIso8601ZuluString(),
            $appointment->ends_at->toIso8601ZuluString(),
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
     * Checked again when the queue gets to it: an appointment moved, cancelled
     * or handed to someone else in the meantime gets no stale reminder.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $appointment = Appointment::withoutGlobalScope(WorkspaceScope::class)->find($this->appointmentId);

        return $appointment !== null
            && $appointment->status === AppointmentStatus::Scheduled
            && (int) $appointment->assigned_user_id === (int) $notifiable->getKey()
            && $appointment->starts_at->toIso8601ZuluString() === $this->startsAt
            && $appointment->starts_at->isFuture();
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $zone = $notifiable->timezone ?: 'UTC';
        $start = CarbonImmutable::parse($this->startsAt)->setTimezone($zone)->locale(app()->getLocale());
        $end = CarbonImmutable::parse($this->endsAt)->setTimezone($zone)->locale(app()->getLocale());

        return (new MailMessage)
            ->subject(__('notifications.reminder.subject', ['when' => $start->isoFormat('ddd ll, LT')]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.reminder.line', [
                'day' => $start->isoFormat('dddd, LL'),
                'start' => $start->isoFormat('LT'),
                'end' => $end->isoFormat('LT'),
                'zone' => $zone,
            ]))
            ->action(__('notifications.reminder.action'), url('/calendar').'?'.http_build_query([
                'date' => $start->format('Y-m-d'),
                'appointment' => $this->appointmentId,
            ]))
            ->line(__('notifications.reminder.private'))
            ->line(__('notifications.settings', ['url' => url('/settings/notifications')]));
    }
}
