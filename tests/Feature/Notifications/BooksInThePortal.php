<?php

namespace Tests\Feature\Notifications;

use App\Actions\Booking\BookAppointment;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\BookingPolicy;
use App\Models\Client;
use App\Models\PortalAccess;
use App\Models\PortalUser;
use App\Models\Service;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Portal\PortalContext;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\Concerns\InteractsWithPortal;

/**
 * A practice that takes bookings in the client portal (Phase 9b), for the email
 * tests: the owner in Belgrade working 09:00–17:00 every day, a bookable
 * service, and a client with a portal account in New York. Bookings go through
 * the actions the portal calls, inside the practice, as the portal does.
 */
trait BooksInThePortal
{
    use InteractsWithPortal;

    private User $astrologer;

    private Workspace $practice;

    private Client $client;

    private PortalUser $portalUser;

    private Service $service;

    private function setUpPractice(): void
    {
        // Friday 2 October 2026: 10:00 in Belgrade, 4:00 in New York.
        $this->travelTo('2026-10-02 08:00:00');

        $this->astrologer = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create([
            'name' => 'Vera Vega',
            'timezone' => 'Europe/Belgrade',
        ]);
        $this->practice = Workspace::query()->findOrFail($this->astrologer->current_workspace_id);
        $this->practice->forceFill(['display_name' => 'Vega Astrology', 'timezone' => 'Europe/Belgrade'])->save();

        $this->client = Client::factory()->inWorkspace($this->practice->id)->create([
            'first_name' => 'Ana',
            'last_name' => 'Marković',
            'email' => 'ana@example.com',
        ]);
        $this->portalUser = $this->portalUserFor($this->client);
        $this->portalUser->forceFill(['name' => 'Ana', 'timezone' => 'America/New_York'])->save();

        $this->service = Service::factory()->bookable()->inWorkspace($this->practice->id)->create([
            'name' => 'Natal reading',
            'duration_minutes' => 60,
        ]);

        $this->inPractice(function () {
            BookingPolicy::query()->create(['enabled' => true]);

            foreach (range(1, 7) as $weekday) {
                AvailabilityRule::query()->create([
                    'user_id' => $this->astrologer->id,
                    'weekday' => $weekday,
                    'start_time' => '09:00',
                    'end_time' => '17:00',
                    'timezone' => 'Europe/Belgrade',
                ]);
            }
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inPractice(callable $callback): mixed
    {
        return app(CurrentWorkspace::class)->run($this->practice->fresh(), $callback);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function policy(array $attributes): void
    {
        $this->inPractice(fn () => BookingPolicy::current()->fill($attributes)->save());
    }

    /** As ResolvePortalAccess sets it for the client's request. */
    private function portalContext(): PortalContext
    {
        $context = new PortalContext;
        $context->set(PortalAccess::query()
            ->acrossPractices()
            ->with(['client', 'workspace'])
            ->where('client_id', $this->client->id)
            ->where('portal_user_id', $this->portalUser->id)
            ->firstOrFail());

        return $context;
    }

    /** The client books the service at a UTC moment, leaving a message for the astrologer. */
    private function book(string $utcStart = '2026-10-05 13:00', ?string $message = 'Please call me on Viber first.'): Appointment
    {
        return $this->inPractice(fn () => app(BookAppointment::class)->handle(
            $this->portalContext(),
            $this->portalUser->fresh(),
            $this->service,
            CarbonImmutable::parse($utcStart, 'UTC'),
            null,
            $message,
        ));
    }

    /** An appointment the astrologer put in the calendar for the client. */
    private function appointmentByThePractice(string $utcStart = '2026-10-05 13:00'): Appointment
    {
        return Appointment::factory()->forClient($this->client, $this->astrologer)->at($utcStart)->create([
            'service_id' => $this->service->id,
            'timezone' => 'Europe/Belgrade',
            'notes' => 'Internal: Saturn return, go gently.',
        ]);
    }

    /** Everything an email says, in one string. */
    private function mailText(MailMessage $mail): string
    {
        return implode(' ', [
            $mail->subject,
            $mail->greeting,
            ...$mail->introLines,
            $mail->actionText,
            ...$mail->outroLines,
        ]);
    }
}
