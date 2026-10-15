<?php

namespace App\Notifications;

use App\Models\PortalUser;
use App\Support\Notifications\ClientAppointmentFacts;
use App\Support\Notifications\ClientChannels;
use App\Support\Notifications\ClientRecipient;
use App\Support\Portal\Push\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An email to a client about one of their appointments (docs/spec/12, "Mejlovi
 * (9b)"), sent to their portal account. Generic on purpose: the practice, the
 * day and time on the client's clock with the zone written out, the service,
 * online or in person, and a link to the portal — never notes, the client's
 * own message or anything the practice keeps internal.
 *
 * Since Phase 9c the same notice may go as a web push instead of or beside
 * the email (the client's choice, ClientChannels) — even more generic: no
 * practice, no service, only what happened and when.
 *
 * Goes only while the client can use the portal: checked when it is queued
 * (the listeners, the reminder run) and again when the queue gets to it.
 */
abstract class ClientAppointmentEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // The portal account may be deleted (with the client or the practice) before the queue gets here.
    public bool $deleteWhenMissingModels = true;

    /**
     * Whether an email was queued beside the push when the notice was sent
     * (set in via(), which runs before Laravel copies the notice into one job
     * per channel). The push job decides on its email fallback from this, not
     * from the client's setting by then — which may have changed in between.
     */
    public ?bool $mailQueued = null;

    // Not readonly: the queue rebuilds a notification from the subclass's scope, and PHP
    // refuses to set a parent's readonly property from there (SerializesModels::__unserialize).
    public function __construct(public ClientAppointmentFacts $appointment) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Email, web push or both — the client's choice in Portal → Profile (Phase 9c),
     * email whenever no device has push on (ClientChannels).
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = $notifiable instanceof PortalUser ? ClientChannels::for($notifiable) : ['mail'];
        $this->mailQueued = in_array('mail', $channels, true);

        return $channels;
    }

    /**
     * The push for the same notice (Phase 9c): a generic title and the time on
     * the client's clock — never the practice, the service or a message.
     * Tapping it opens the client's appointments.
     */
    public function toPortalPush(PortalUser $notifiable): PushMessage
    {
        [$kind, $body] = $this->push($this->zone($notifiable));

        return new PushMessage(
            __("portal.push.{$kind}.title"),
            $body,
            '/appointments',
            'appointment-'.$this->appointment->appointmentId,
        );
    }

    /**
     * The push's kind (its title in `portal.push.*`) and its line.
     *
     * @return array{0: string, 1: string}
     */
    abstract protected function push(string $zone): array;

    /**
     * "Today at 3:00 PM", "Tomorrow at 3:00 PM" or "Mon, Oct 5, 2026 at 3:00 PM", on
     * the client's clock. A push may reach a phone that was off up to `portal.push.ttl`
     * later; "today" and "tomorrow" are used only while that cannot be after the
     * client's midnight — otherwise the date, which is never wrong.
     */
    protected function pushWhen(string $zone): string
    {
        $start = $this->local($this->appointment->startsAt, $zone);
        $now = CarbonImmutable::now($zone);
        $today = $now->startOfDay();
        $time = $start->isoFormat('LT');
        $relative = $now->addSeconds((int) config('portal.push.ttl'))->lessThan($today->addDay());

        return match (true) {
            $relative && $start->startOfDay()->equalTo($today) => __('portal.push.when.today', ['time' => $time]),
            $relative && $start->startOfDay()->equalTo($today->addDay()) => __('portal.push.when.tomorrow', ['time' => $time]),
            default => __('portal.push.when.day', ['day' => $start->isoFormat('ddd, ll'), 'time' => $time]),
        };
    }

    /** A link revoked, a client archived or a practice closing in the meantime stops it. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof PortalUser
            && ClientRecipient::reaches($notifiable, $this->appointment->workspaceId, $this->appointment->clientId);
    }

    /** The client's zone, or the practice's until they choose one (docs/spec/12). */
    protected function zone(PortalUser $notifiable): string
    {
        return $notifiable->timezone ?: $this->appointment->practiceZone;
    }

    /** Subject, greeting and the first line; the email's own lines follow. */
    protected function message(PortalUser $notifiable, string $subject): MailMessage
    {
        return (new MailMessage)
            ->subject($subject)
            ->greeting(filled($notifiable->name)
                ? __('portal.mail.booking.greeting', ['name' => $notifiable->name])
                : __('portal.mail.booking.greeting_anonymous'));
    }

    /** "Monday, October 5, 2026, 3:00 PM – 4:00 PM (Europe/Belgrade)". */
    protected function when(string $zone, ?string $startsAt = null, ?string $endsAt = null): string
    {
        $start = $this->local($startsAt ?? $this->appointment->startsAt, $zone);
        $end = $this->local($endsAt ?? $this->appointment->endsAt, $zone);

        return __('portal.mail.booking.when', [
            'day' => $start->isoFormat('dddd, LL'),
            'start' => $start->isoFormat('LT'),
            'end' => $end->isoFormat('LT'),
            'zone' => $zone,
        ]);
    }

    /** "Mon, Oct 5, 2026, 3:00 PM" for a subject line. */
    protected function shortWhen(string $zone): string
    {
        return $this->local($this->appointment->startsAt, $zone)->isoFormat('ddd ll, LT');
    }

    /**
     * What and where: the service (when there is one) and online or in person.
     * The meeting link or address is in the portal, not in an email.
     *
     * @return list<string>
     */
    protected function details(): array
    {
        $lines = [];

        if (filled($this->appointment->service)) {
            $lines[] = __('portal.mail.booking.service', ['service' => $this->appointment->service]);
        }

        $lines[] = __('portal.mail.booking.where.'.($this->appointment->location === 'in_person' ? 'in_person' : 'online'));

        return $lines;
    }

    /**
     * Until when the portal lets the client move or cancel it — said only while
     * that moment is still ahead.
     *
     * @return list<string>
     */
    protected function changeUntil(string $zone): array
    {
        $now = CarbonImmutable::now();
        $move = $this->ahead($this->appointment->rescheduleUntil, $now);
        $cancel = $this->ahead($this->appointment->cancelUntil, $now);
        $format = fn (CarbonImmutable $moment) => $this->local($moment->toIso8601ZuluString(), $zone)->isoFormat('dddd, LL, LT').' ('.$zone.')';

        if ($move !== null && $cancel !== null && $move->equalTo($cancel)) {
            return [__('portal.mail.booking.change_until', ['when' => $format($move)])];
        }

        return array_values(array_filter([
            $move === null ? null : __('portal.mail.booking.move_until', ['when' => $format($move)]),
            $cancel === null ? null : __('portal.mail.booking.cancel_until', ['when' => $format($cancel)]),
        ]));
    }

    /** The button: the client's appointments in the portal. */
    protected function withLink(MailMessage $mail): MailMessage
    {
        return $mail
            ->action(__('portal.mail.booking.action'), config('portal.url').'/appointments')
            ->line(__('portal.mail.booking.private'));
    }

    private function local(string $iso, string $zone): CarbonImmutable
    {
        return CarbonImmutable::parse($iso)->setTimezone($zone)->locale(app()->getLocale());
    }

    private function ahead(?string $iso, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($iso === null) {
            return null;
        }

        $moment = CarbonImmutable::parse($iso);

        return $moment > $now ? $moment : null;
    }
}
