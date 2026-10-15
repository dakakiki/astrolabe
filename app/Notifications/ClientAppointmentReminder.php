<?php

namespace App\Notifications;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\PortalUser;
use App\Models\Scopes\WorkspaceScope;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The client's reminder a day before an appointment (Phase 9b, ClientReminders;
 * sent by `notifications:send-client-reminders`). Only to a client who can use
 * the portal and has not switched reminders off in Portal → Profile, and only
 * for the appointment as it still is.
 */
class ClientAppointmentReminder extends ClientAppointmentEmail
{
    /**
     * Checked again when the queue gets to it: an appointment moved, cancelled
     * or past, reminders switched off or the portal closed in the meantime
     * gets no stale reminder.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof PortalUser || ! $notifiable->appointment_reminders) {
            return false;
        }

        $appointment = Appointment::withoutGlobalScope(WorkspaceScope::class)->find($this->appointment->appointmentId);

        return $appointment !== null
            && $appointment->status === AppointmentStatus::Scheduled
            && $appointment->starts_at->toIso8601ZuluString() === $this->appointment->startsAt
            && $appointment->starts_at->isFuture()
            && parent::shouldSend($notifiable, $channel);
    }

    /**
     * @param  PortalUser  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $zone = $this->zone($notifiable);

        $mail = $this->message($notifiable, __('portal.mail.reminder.subject', ['when' => $this->shortWhen($zone)]))
            ->line(__('portal.mail.reminder.line', [
                'practice' => $this->appointment->practice,
                'when' => $this->when($zone),
            ]))
            ->lines($this->details())
            ->lines($this->changeUntil($zone));

        return $this->withLink($mail)->line(__('portal.mail.reminder.off'));
    }
}
