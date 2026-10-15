<?php

namespace Tests\Feature\Portal;

use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Enums\AvailabilityKind;
use App\Enums\BookingSource;
use App\Enums\LocationType;
use App\Events\Appointments\AppointmentBooked;
use App\Events\Appointments\AppointmentCancelled;
use App\Events\Appointments\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\PortalUser;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;

/**
 * Booking in the client portal (docs/spec/12, "Zakazivanje (9b)"; acceptance
 * criteria 9b 1–3 and 5): the portal offers only the astrologer's real free
 * times — their week, time off and extra time, every appointment that holds
 * time (requests too), the buffers, the notice and the window — right across
 * a change of the clocks and in the client's own zone, checks the chosen time
 * again under the astrologer's lock, and lets exactly one of two bookings of
 * the same time through.
 *
 * Unless a test says otherwise it is Monday 19 October 2026, 08:00 in Belgrade
 * (CEST, UTC+2) — the week before the clocks go back — and the practice's
 * owner works in Belgrade.
 */
class PortalBookingTest extends TestCase
{
    use AddsWorkspaceMembers, BookingFixtures, InteractsWithPortal, RefreshDatabase;

    private User $vega;

    private Client $ana;

    private PortalUser $user;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-19 06:00:00', 'UTC'));
        Event::fake([AppointmentBooked::class, AppointmentRescheduled::class, AppointmentCancelled::class]);
        Notification::fake();

        $this->vega = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create();
        $this->ana = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->user = $this->portalUserFor($this->ana);
        $this->service = Service::factory()->bookable()->inWorkspace($this->vega->current_workspace_id)->create([
            'name' => 'Natal reading',
            'duration_minutes' => 60,
            'price_amount' => 9000,
            'currency' => 'EUR',
            'location_type' => LocationType::Either,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function book(array $data = []): TestResponse
    {
        return $this->portal('POST', 'appointments', $data + [
            'service_id' => $this->service->id,
            'starts_at' => '2026-10-20T08:30:00Z',
        ]);
    }

    private function slotsFor(Service $service): TestResponse
    {
        return $this->portal('GET', "booking/slots?service_id={$service->id}");
    }

    private function appointmentFor(Client $client, string $start, int $minutes = 60, array $attributes = [], ?User $astrologer = null): Appointment
    {
        return Appointment::factory()
            ->forClient($client, $astrologer ?? $this->vega)
            ->at($start, $minutes)
            ->create($attributes);
    }

    public function test_booking_shows_the_terms_and_only_active_bookable_services_with_their_price(): void
    {
        $practice = $this->vega->current_workspace_id;
        Service::factory()->bookable()->inWorkspace($practice)->create([
            'name' => 'Annual forecast',
            'description' => 'The year ahead, month by month.',
            'duration_minutes' => 90,
            'price_amount' => 12000,
            'currency' => 'JPY',
            'requires_deposit' => true,
            'location_type' => LocationType::InPerson,
        ]);
        Service::factory()->bookable()->inWorkspace($practice)->create(['name' => 'First talk', 'price_amount' => null]);
        Service::factory()->bookable()->inactive()->inWorkspace($practice)->create(['name' => 'Retired reading']);
        Service::factory()->inWorkspace($practice)->create(['name' => 'Private mentoring']);
        $other = User::factory()->withWorkspace(['name' => 'Other Practice'])->create();
        Service::factory()->bookable()->inWorkspace($other->current_workspace_id)->create(['name' => 'Reading elsewhere']);
        $this->bookingPolicy($other);

        $this->signInToPortal($this->user);

        // Until the practice switches booking on there is nothing to book, and Home offers nothing.
        $this->portal('GET', 'booking')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.services', []);
        $this->portal('GET', 'home')->assertJsonPath('data.booking', ['enabled' => false, 'services' => 0]);

        $this->bookingPolicy($this->vega, [
            'client_note' => 'A deposit of half the price is due a week before.',
            'client_reschedule_until_hours' => null,
            'client_cancel_until_hours' => 48,
        ]);

        $response = $this->portal('GET', 'booking')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.requires_confirmation', false)
            ->assertJsonPath('data.client_note', 'A deposit of half the price is due a week before.')
            ->assertJsonPath('data.reschedule_until_hours', null)
            ->assertJsonPath('data.cancel_until_hours', 48)
            ->assertJsonPath('data.max_days_ahead', 60)
            ->assertJsonPath('data.min_notice_minutes', 1440);

        // Active and bookable, of this practice only, by name.
        $this->assertSame(['Annual forecast', 'First talk', 'Natal reading'], array_column($response->json('data.services'), 'name'));
        $this->assertSame([
            'id' => Service::query()->withoutGlobalScopes()->where('name', 'Annual forecast')->value('id'),
            'name' => 'Annual forecast',
            'description' => 'The year ahead, month by month.',
            'duration_minutes' => 90,
            'location_type' => 'in_person',
            'requires_deposit' => true,
            'price' => ['amount' => 12000, 'currency' => 'JPY', 'decimals' => 0],
        ], $response->json('data.services.0'));
        $response->assertJsonPath('data.services.1.price', null)
            ->assertJsonPath('data.services.2.price', ['amount' => 9000, 'currency' => 'EUR', 'decimals' => 2])
            ->assertJsonPath('data.services.2.location_type', 'either')
            ->assertJsonPath('data.services.2.requires_deposit', false);

        $this->portal('GET', 'home')->assertJsonPath('data.booking', ['enabled' => true, 'services' => 3]);
    }

    public function test_while_booking_is_off_the_portal_offers_no_times_and_takes_no_bookings(): void
    {
        $this->workingWeek($this->vega, [2 => [['09:00', '13:00']]]);
        $this->signInToPortal($this->user);

        $this->slotsFor($this->service)->assertForbidden()->assertJsonPath('code', 'booking_disabled');
        $this->book()->assertForbidden()->assertJsonPath('code', 'booking_disabled')->assertJsonPath('message', __('booking.disabled'));

        $this->bookingPolicy($this->vega);
        $this->slotsFor($this->service)->assertOk();

        // Switched off again: the same, from the next request.
        $this->bookingPolicy($this->vega, ['enabled' => false]);
        $this->slotsFor($this->service)->assertForbidden()->assertJsonPath('code', 'booking_disabled');
        $this->book()->assertForbidden()->assertJsonPath('code', 'booking_disabled');

        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());
        Event::assertNotDispatched(AppointmentBooked::class);
    }

    public function test_free_times_follow_the_week_time_off_extra_time_and_appointments(): void
    {
        $member = $this->memberOf($this->vega);
        $boris = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create();
        // From Tuesday 06:00 UTC (24 hours' notice) to Thursday 06:00 UTC (three days ahead).
        $this->bookingPolicy($this->vega, ['max_days_ahead' => 3]);
        $this->workingWeek($this->vega, [
            1 => [['09:00', '17:00']],                    // Monday: all of it too soon
            2 => [['09:00', '13:00']],                    // 07:00–11:00 UTC
            3 => [['09:00', '13:00']],
            4 => [['08:00', '10:00']],                    // only 06:00 UTC is not too far
        ]);
        $this->availabilityException($this->vega, AvailabilityKind::Unavailable, '2026-10-20 09:30', '2026-10-20 10:00');
        // Extra time starts its own grid: 13:10, 13:40.
        $this->availabilityException($this->vega, AvailabilityKind::Available, '2026-10-21 13:10', '2026-10-21 14:40');

        // Another client's appointment and request hold their time with 15 minutes after
        // (and before the next one); a cancelled one and the other member's do not.
        $this->appointmentFor($boris, '2026-10-20 07:00');
        $this->appointmentFor($boris, '2026-10-21 08:00', attributes: ['status' => AppointmentStatus::Requested]);
        $this->appointmentFor($boris, '2026-10-21 10:00', attributes: ['status' => AppointmentStatus::Cancelled]);
        $this->appointmentFor($boris, '2026-10-20 10:00', astrologer: $member);

        $this->signInToPortal($this->user);

        $response = $this->slotsFor($this->service)
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 60)
            ->assertJsonPath('data.from', '2026-10-20T06:00:00Z')
            ->assertJsonPath('data.to', '2026-10-22T06:00:00Z')
            ->assertJsonPath('data.slots.0', ['starts_at' => '2026-10-20T08:30:00Z', 'ends_at' => '2026-10-20T09:30:00Z']);

        $this->assertSame([
            '2026-10-20T08:30:00Z',
            '2026-10-20T10:00:00Z',
            '2026-10-21T09:30:00Z',
            '2026-10-21T10:00:00Z',
            '2026-10-21T13:10:00Z',
            '2026-10-21T13:40:00Z',
            '2026-10-22T06:00:00Z',
        ], $this->starts($response));

        // A longer service fits fewer of them.
        $forecast = Service::factory()->bookable()->inWorkspace($this->vega->current_workspace_id)->create(['duration_minutes' => 90]);
        $this->assertSame(
            ['2026-10-21T09:30:00Z', '2026-10-21T13:10:00Z', '2026-10-22T06:00:00Z'],
            $this->starts($this->slotsFor($forecast)->assertJsonPath('data.duration_minutes', 90)),
        );

        // Times and nothing else: not whose they are, nor why the others are not free.
        $this->assertSame(['duration_minutes', 'from', 'to', 'slots'], array_keys($response->json('data')));
        foreach ($response->json('data.slots') as $slot) {
            $this->assertSame(['starts_at', 'ends_at'], array_keys($slot));
        }
    }

    public function test_free_times_are_right_across_the_autumn_change_of_the_clocks(): void
    {
        // Thursday 22 October; Belgrade goes from UTC+2 to UTC+1 on Sunday 25 October at 03:00,
        // while New York stays on UTC-4 until 1 November.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 06:00:00', 'UTC'));

        $this->assertAcrossTheChange(
            [
                // Friday 10:00–12:00 CEST.
                '2026-10-23T08:00:00Z', '2026-10-23T08:30:00Z', '2026-10-23T09:00:00Z',
                // Sunday 01:00 CEST to 04:00 CET: four real hours, as the night has an extra one.
                '2026-10-24T23:00:00Z', '2026-10-24T23:30:00Z', '2026-10-25T00:00:00Z', '2026-10-25T00:30:00Z',
                '2026-10-25T01:00:00Z', '2026-10-25T01:30:00Z', '2026-10-25T02:00:00Z',
                // Monday 10:00–12:00 CET.
                '2026-10-26T09:00:00Z', '2026-10-26T09:30:00Z', '2026-10-26T10:00:00Z',
            ],
            before: '2026-10-23T08:00:00Z',
            after: '2026-10-26T09:00:00Z',
            local: [['10:00', '04:00'], ['10:00', '05:00']],
        );
    }

    public function test_free_times_are_right_across_the_spring_change_of_the_clocks(): void
    {
        // Thursday 25 March 2027; Belgrade goes from UTC+1 to UTC+2 on Sunday 28 March at 02:00,
        // New York has been on UTC-4 since 14 March.
        $this->travelTo(CarbonImmutable::parse('2027-03-25 06:00:00', 'UTC'));

        $this->assertAcrossTheChange(
            [
                // Friday 10:00–12:00 CET.
                '2027-03-26T09:00:00Z', '2027-03-26T09:30:00Z', '2027-03-26T10:00:00Z',
                // Sunday 01:00 CET to 04:00 CEST: two real hours, as the night loses one.
                '2027-03-28T00:00:00Z', '2027-03-28T00:30:00Z', '2027-03-28T01:00:00Z',
                // Monday 10:00–12:00 CEST.
                '2027-03-29T08:00:00Z', '2027-03-29T08:30:00Z', '2027-03-29T09:00:00Z',
            ],
            before: '2027-03-26T09:00:00Z',
            after: '2027-03-29T08:00:00Z',
            local: [['10:00', '05:00'], ['10:00', '04:00']],
        );
    }

    /**
     * The astrologer works Friday and Monday 10:00–12:00 and Sunday 01:00–04:00 in
     * Belgrade; the client lives in New York. Free times over the change of the
     * clocks, then a booking on either side of it: each keeps the client's zone
     * and the astrologer's wall-clock hours.
     *
     * @param  list<string>  $expected
     * @param  array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}}  $local  [Belgrade, New York] times of `before` and `after`
     */
    private function assertAcrossTheChange(array $expected, string $before, string $after, array $local): void
    {
        $this->bookingPolicy($this->vega, ['max_days_ahead' => 5]);
        $this->workingWeek($this->vega, [5 => [['10:00', '12:00']], 7 => [['01:00', '04:00']], 1 => [['10:00', '12:00']]]);
        $this->user->forceFill(['timezone' => 'America/New_York'])->save();

        $this->signInToPortal($this->user);
        $this->assertSame($expected, $this->starts($this->slotsFor($this->service)->assertOk()));

        foreach ([$before, $after] as $i => $start) {
            $id = $this->book(['starts_at' => $start])->assertCreated()->assertJsonPath('data.starts_at', $start)->json('data.id');
            $appointment = Appointment::withoutGlobalScopes()->findOrFail($id);

            $this->assertSame('America/New_York', $appointment->timezone);
            $this->assertSame($local[$i][0], $appointment->starts_at->setTimezone('Europe/Belgrade')->format('H:i'));
            $this->assertSame($local[$i][1], $appointment->localStart()->format('H:i'));
        }

        // Friday and Monday are gone with their buffers; the night of the change is untouched.
        $this->assertSame(array_slice($expected, 3, -3), $this->starts($this->slotsFor($this->service)));
    }

    public function test_booking_a_free_time_schedules_it_with_the_owner_in_the_clients_zone(): void
    {
        $this->bookingPolicy($this->vega);
        $this->workingWeek($this->vega, [2 => [['09:00', '13:00']]]);
        $this->user->forceFill(['timezone' => 'Europe/London'])->save();
        $this->signInToPortal($this->user);

        $response = $this->book([
            'location_type' => 'in_person',
            'message' => 'Could we look at my career this time?',
        ])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-10-20T08:30:00Z')
            ->assertJsonPath('data.ends_at', '2026-10-20T09:30:00Z')
            ->assertJsonPath('data.duration_minutes', 60)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.upcoming', true)
            ->assertJsonPath('data.service', 'Natal reading')
            ->assertJsonPath('data.location_type', 'in_person')
            ->assertJsonPath('data.booked_in_portal', true)
            ->assertJsonPath('data.cancelled_by', null)
            ->assertJsonPath('data.declined', false)
            ->assertJsonPath('data.decline_reason', null)
            ->assertJsonPath('data.can_reschedule', true)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.reschedule_until', '2026-10-19T08:30:00Z')
            ->assertJsonPath('data.cancel_until', '2026-10-19T08:30:00Z');

        $this->assertArrayNotHasKey('client_note', $response->json('data'));
        $this->assertArrayNotHasKey('notes', $response->json('data'));

        $appointment = Appointment::withoutGlobalScopes()->sole();
        $this->assertSame($response->json('data.id'), $appointment->id);
        $this->assertSame($this->ana->id, $appointment->client_id);
        $this->assertSame($this->ana->workspace_id, $appointment->workspace_id);
        $this->assertSame($this->vega->id, $appointment->assigned_user_id);
        $this->assertNull($appointment->created_by);
        $this->assertSame($this->user->id, $appointment->portal_user_id);
        $this->assertSame(BookingSource::Portal, $appointment->booking_source);
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertSame(LocationType::InPerson, $appointment->location_type);
        $this->assertSame('Europe/London', $appointment->timezone);
        $this->assertSame('Could we look at my career this time?', $appointment->client_note);
        $this->assertSame($this->vega->notificationPreferences()->reminderMinutes, $appointment->reminder_minutes);

        // The message is for the astrologer: the client's own lists never show it.
        $this->assertStringNotContainsString('career', $response->getContent()
            .$this->portal('GET', 'appointments')->assertJsonPath('data.0.id', $appointment->id)->getContent()
            .$this->portal('GET', 'home')->assertJsonPath('data.next_appointment.id', $appointment->id)->getContent());

        // Audited as the client's, with the outcome and nothing they wrote.
        $audit = AuditLog::query()->where('event', AuditEvent::PortalAppointmentBooked)->sole();
        $this->assertSame($this->user->id, $audit->portal_user_id);
        $this->assertNull($audit->user_id);
        $this->assertSame($this->ana->workspace_id, $audit->workspace_id);
        $this->assertSame($appointment->id, $audit->subject_id);
        $this->assertSame(['status' => 'scheduled'], $audit->properties);

        Event::assertDispatched(AppointmentBooked::class, fn (AppointmentBooked $event) => $event->appointment->is($appointment));

        // The time is taken now, for everyone.
        $this->assertNotContains('2026-10-20T08:30:00Z', $this->starts($this->slotsFor($this->service)));
    }

    public function test_the_client_chooses_the_place_only_for_a_service_held_either_way(): void
    {
        $this->bookingPolicy($this->vega);
        $this->workingWeek($this->vega, [2 => [['09:00', '17:00']]]);
        $online = Service::factory()->bookable()->inWorkspace($this->vega->current_workspace_id)->create(['location_type' => LocationType::Online]);
        $this->signInToPortal($this->user);

        $this->book(['starts_at' => '2026-10-20T07:00:00Z'])->assertCreated()->assertJsonPath('data.location_type', 'online');
        $this->book(['starts_at' => '2026-10-20T09:00:00Z', 'location_type' => 'in_person'])->assertCreated()->assertJsonPath('data.location_type', 'in_person');
        $this->book(['service_id' => $online->id, 'starts_at' => '2026-10-20T11:00:00Z', 'location_type' => 'in_person'])
            ->assertCreated()
            ->assertJsonPath('data.location_type', 'online');
    }

    public function test_a_practice_that_confirms_by_hand_gets_a_request_that_holds_its_time(): void
    {
        $this->bookingPolicy($this->vega, ['requires_confirmation' => true]);
        $this->workingWeek($this->vega, [2 => [['09:00', '13:00']]]);
        $boris = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(['email' => 'boris@example.com']);
        $borisAccount = $this->portalUserFor($boris);

        $this->signInToPortal($this->user);
        $this->portal('GET', 'booking')->assertJsonPath('data.requires_confirmation', true);

        $id = $this->book()
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.upcoming', true)
            ->assertJsonPath('data.location_details', null)
            ->assertJsonPath('data.can_cancel', true)
            ->json('data.id');

        $this->assertSame(AppointmentStatus::Requested, Appointment::withoutGlobalScopes()->findOrFail($id)->status);
        $this->assertSame(['status' => 'requested'], AuditLog::query()->where('event', AuditEvent::PortalAppointmentBooked)->sole()->properties);

        // Waiting for the practice still counts as ahead, on Home and in the list.
        $this->portal('GET', 'home')->assertJsonPath('data.next_appointment.id', $id)->assertJsonPath('data.upcoming_count', 1);
        $this->portal('GET', 'appointments?when=upcoming')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.status', 'requested');
        $this->portal('GET', 'appointments?when=past')->assertJsonCount(0, 'data');
        $free = $this->starts($this->slotsFor($this->service));
        $this->assertNotContains('2026-10-20T08:30:00Z', $free);
        $this->assertContains('2026-10-20T10:00:00Z', $free);

        // Nobody else is offered that time, or can take it.
        $this->forgetPortalCookie();
        $this->signInToPortal($borisAccount);
        $this->assertSame($free, $this->starts($this->slotsFor($this->service)));
        $refused = $this->book()->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->assertSame($free, $this->starts($refused, 'slots'));
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_of_two_bookings_for_the_same_time_only_one_wins(): void
    {
        $this->bookingPolicy($this->vega);
        $this->workingWeek($this->vega, [2 => [['09:00', '13:00']]]);
        $this->signInToPortal($this->user);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->book()->assertCreated();

        // The astrologer's membership row is locked (the calendar's lock) before the row is written.
        $lock = array_key_first(array_filter($queries, fn (string $sql) => str_contains($sql, '`workspace_user`') && str_contains($sql, 'for update')));
        $insert = array_key_first(array_filter($queries, fn (string $sql) => str_starts_with($sql, 'insert into `appointments`')));
        $this->assertNotNull($lock, 'The booking must lock the astrologer before checking the time again.');
        $this->assertNotNull($insert);
        $this->assertLessThan($insert, $lock);

        // The second booking of the same time — another tab, another client — is refused
        // with what is still free, and that list is the one the portal would show now.
        $conflict = $this->book()
            ->assertStatus(409)
            ->assertJsonPath('code', 'slot_taken')
            ->assertJsonPath('message', __('booking.slot_taken'));

        $fresh = $this->starts($conflict, 'slots');
        $this->assertNotContains('2026-10-20T08:30:00Z', $fresh);
        $this->assertContains('2026-10-20T10:00:00Z', $fresh);
        $this->assertSame($this->starts($this->slotsFor($this->service)), $fresh);

        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
        Event::assertDispatchedTimes(AppointmentBooked::class, 1);
    }

    public function test_a_time_off_the_grid_too_soon_or_too_far_is_not_free(): void
    {
        $this->bookingPolicy($this->vega);
        $this->workingWeek($this->vega, array_fill(1, 5, [['09:00', '17:00']]));
        $this->signInToPortal($this->user);

        // From Tuesday 09:00 in Belgrade to the last whole day of the 60.
        $slots = $this->starts($this->slotsFor($this->service)->assertJsonPath('data.to', '2026-12-18T06:00:00Z'));
        $this->assertSame('2026-10-20T07:00:00Z', $slots[0]);
        $this->assertSame('2026-12-17T15:00:00Z', end($slots));

        foreach ([
            '2026-10-20T08:45:00Z',     // between two steps of the grid
            '2026-10-20T08:30:30Z',     // a second past one
            '2026-10-19T10:00:00Z',     // less than 24 hours ahead
            '2026-10-20T05:30:00Z',     // before the working day
            '2026-10-24T09:00:00Z',     // a Saturday
            '2026-12-18T08:00:00Z',     // past the 60 days
            '2027-01-05T09:00:00Z',
        ] as $start) {
            $refused = $this->book(['starts_at' => $start])->assertStatus(409)->assertJsonPath('code', 'slot_taken');
            $this->assertSame($slots, $this->starts($refused, 'slots'), $start);
        }

        // Any explicit offset names the same moment.
        $this->book(['starts_at' => '2026-10-20T10:30:00+02:00'])->assertCreated()->assertJsonPath('data.starts_at', '2026-10-20T08:30:00Z');
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_a_booking_is_checked_before_anything_is_written(): void
    {
        $this->bookingPolicy($this->vega);
        $this->workingWeek($this->vega, [2 => [['09:00', '13:00']]]);
        $practice = $this->vega->current_workspace_id;
        $inactive = Service::factory()->bookable()->inactive()->inWorkspace($practice)->create();
        $notBookable = Service::factory()->inWorkspace($practice)->create();
        $other = User::factory()->withWorkspace()->create();
        $elsewhere = Service::factory()->bookable()->inWorkspace($other->current_workspace_id)->create();
        config(['portal.limits.booking_per_account' => [100, 10]]);
        $this->signInToPortal($this->user);

        $this->portal('POST', 'appointments', [])->assertUnprocessable()->assertJsonValidationErrors(['service_id', 'starts_at']);

        foreach (['2026-10-20T08:30', '2026-10-20 08:30:00', 'tomorrow at noon', '2026-02-30T08:00:00Z', '1760000000'] as $start) {
            $this->book(['starts_at' => $start])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        }
        foreach (['either', 'moon'] as $place) {
            $this->book(['location_type' => $place])->assertUnprocessable()->assertJsonValidationErrors('location_type');
        }
        $this->book(['message' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->book(['service_id' => 'natal'])->assertUnprocessable()->assertJsonValidationErrors('service_id');

        // A service the client may not book is not there at all.
        foreach ([$inactive->id, $notBookable->id, $elsewhere->id, 999999999] as $service) {
            $this->book(['service_id' => $service])->assertNotFound();
            $this->portal('GET', "booking/slots?service_id={$service}")->assertNotFound();
        }

        $this->portal('GET', 'booking/slots')->assertUnprocessable();
        $this->portal('GET', "booking/slots?service_id={$this->service->id}&appointment_id=1")->assertUnprocessable();
        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());

        // Whose appointment it is never comes from the request.
        $boris = Client::factory()->inWorkspace($practice)->create();
        $this->book([
            'client_id' => $boris->id,
            'workspace_id' => $other->current_workspace_id,
            'assigned_user_id' => $other->id,
            'status' => 'completed',
            'message' => str_repeat('a', 1000),
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled');

        $appointment = Appointment::withoutGlobalScopes()->sole();
        $this->assertSame([$this->ana->id, $practice, $this->vega->id], [$appointment->client_id, $appointment->workspace_id, $appointment->assigned_user_id]);
    }

    public function test_a_repeated_booking_with_the_same_key_books_once(): void
    {
        $this->bookingPolicy($this->vega);
        $this->workingWeek($this->vega, [2 => [['09:00', '13:00']]]);
        $this->signInToPortal($this->user);
        $body = ['service_id' => $this->service->id, 'starts_at' => '2026-10-20T08:30:00Z'];

        $first = $this->portalWithKey('portal-booking-0001', 'POST', 'appointments', $body)->assertCreated();
        $again = $this->portalWithKey('portal-booking-0001', 'POST', 'appointments', $body)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame($first->getContent(), $again->getContent());
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::PortalAppointmentBooked)->count());
        Event::assertDispatchedTimes(AppointmentBooked::class, 1);

        // The same key for another booking is a mistake, not a replay.
        $this->portalWithKey('portal-booking-0001', 'POST', 'appointments', ['starts_at' => '2026-10-20T10:00:00Z'] + $body)->assertUnprocessable();
        $this->portalWithKey('short', 'POST', 'appointments', $body)->assertUnprocessable();

        // Without a key the same request is a second booking of a taken time.
        $this->book()->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_a_portal_account_and_an_astrologer_with_the_same_number_keep_their_keys_apart(): void
    {
        $astrologer = User::query()->find($this->user->id) ?? User::factory()->create(['id' => $this->user->id]);
        $this->assertSame($this->user->id, $astrologer->id);

        // One path on both hosts, so only whose key it is tells the two apart.
        $calls = 0;
        $probe = function () use (&$calls) {
            return response()->json(['call' => ++$calls], 201);
        };
        Route::domain(config('portal.domain'))
            ->middleware(['portal', 'portal.auth', 'idempotent'])
            ->post('api/portal/v1/idempotency-probe', $probe);
        Route::middleware('idempotent')->post('api/portal/v1/idempotency-probe', $probe);

        $this->actingAs($astrologer)
            ->postJson('/api/portal/v1/idempotency-probe', ['n' => 1], ['Idempotency-Key' => 'same-key-0001'])
            ->assertCreated()
            ->assertJsonPath('call', 1);

        $this->signInToPortal($this->user);
        $this->portalWithKey('same-key-0001', 'POST', 'idempotency-probe', ['n' => 1])
            ->assertCreated()
            ->assertJsonPath('call', 2)
            ->assertHeaderMissing('Idempotent-Replayed');
        $this->portalWithKey('same-key-0001', 'POST', 'idempotency-probe', ['n' => 1])
            ->assertJsonPath('call', 2)
            ->assertHeader('Idempotent-Replayed', 'true');
        $this->portalWithKey('same-key-0001', 'POST', 'idempotency-probe', ['n' => 2])->assertUnprocessable();

        // The astrologer's own key still answers with the astrologer's response.
        $this->actingAs($astrologer)
            ->postJson('/api/portal/v1/idempotency-probe', ['n' => 1], ['Idempotency-Key' => 'same-key-0001'])
            ->assertJsonPath('call', 1)
            ->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(2, $calls);
    }

    public function test_booking_moving_and_cancelling_are_limited_per_account(): void
    {
        $this->bookingPolicy($this->vega);
        $this->workingWeek($this->vega, [2 => [['09:00', '13:00']]]);
        $boris = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(['email' => 'boris@example.com']);
        $borisAccount = $this->portalUserFor($boris);
        $this->signInToPortal($this->user);

        for ($i = 0; $i < 10; $i++) {
            $this->portal('POST', 'appointments/999999999/cancel')->assertNotFound();
        }

        $this->book()->assertTooManyRequests()->assertJsonPath('message', __('portal.errors.too_many'));
        $this->portal('POST', 'appointments/999999999/reschedule', ['starts_at' => '2026-10-20T08:30:00Z'])->assertTooManyRequests();

        // Looking is not limited by it, and another account is not affected.
        $this->slotsFor($this->service)->assertOk();
        $this->forgetPortalCookie();
        $this->signInToPortal($borisAccount);
        $this->book()->assertCreated();

        // Ten minutes later the client can book again.
        $this->travel(11)->minutes();
        $this->forgetPortalCookie();
        $this->signInToPortal($this->user);
        $this->book(['starts_at' => '2026-10-20T10:00:00Z'])->assertCreated();
    }

    public function test_extra_time_inside_time_off_is_offered(): void
    {
        // A week's holiday (Monday 26 to Friday 30 October, whole days in Belgrade, UTC+1
        // by then) with Wednesday 10:00–12:00 kept free: free = week − time off + extra time.
        $this->bookingPolicy($this->vega);
        $this->workingWeek($this->vega, array_fill(1, 5, [['09:00', '17:00']]));
        $this->availabilityException($this->vega, AvailabilityKind::Unavailable, '2026-10-25 23:00', '2026-10-30 23:00');
        $this->availabilityException($this->vega, AvailabilityKind::Available, '2026-10-28 09:00', '2026-10-28 11:00');
        $this->signInToPortal($this->user);

        $holiday = array_values(array_filter(
            $this->starts($this->slotsFor($this->service)),
            fn (string $start) => $start >= '2026-10-25T23:00:00Z' && $start < '2026-10-30T23:00:00Z',
        ));
        $this->assertSame(['2026-10-28T09:00:00Z', '2026-10-28T09:30:00Z', '2026-10-28T10:00:00Z'], $holiday);

        $this->book(['starts_at' => '2026-10-28T09:30:00Z'])->assertCreated()->assertJsonPath('data.starts_at', '2026-10-28T09:30:00Z');
    }

    public function test_a_suspended_owner_takes_no_bookings_until_the_suspension_is_lifted(): void
    {
        $this->bookingPolicy($this->vega);
        $this->workingWeek($this->vega, [2 => [['09:00', '13:00']]]);
        $this->vega->forceFill(['suspended_at' => now(), 'suspension_reason' => 'Unpaid invoice'])->save();
        $this->signInToPortal($this->user);

        // The owner cannot sign in to see or answer a booking: the portal reads as closed.
        $this->portal('GET', 'booking')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.services', []);
        $this->portal('GET', 'home')->assertJsonPath('data.booking', ['enabled' => false, 'services' => 0]);
        $this->slotsFor($this->service)->assertForbidden()->assertJsonPath('code', 'booking_disabled');
        $this->book()->assertForbidden()->assertJsonPath('code', 'booking_disabled');
        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());
        Event::assertNotDispatched(AppointmentBooked::class);

        $this->vega->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();

        $this->portal('GET', 'booking')->assertJsonPath('data.enabled', true);
        $this->book()->assertCreated();
    }

    public function test_one_client_holds_a_limited_number_of_open_appointments(): void
    {
        $this->assertSame(10, config('portal.booking.max_open_per_client'));
        config(['portal.booking.max_open_per_client' => 3]);
        $this->bookingPolicy($this->vega);
        $this->workingWeek($this->vega, [2 => [['09:00', '17:00']]]);
        $boris = Client::factory()->inWorkspace($this->vega->current_workspace_id)->create(['email' => 'boris@example.com']);
        $borisAccount = $this->portalUserFor($boris);

        // Open and not ended: the practice's appointment and a request count; what is
        // cancelled, held or already over does not.
        $this->appointmentFor($this->ana, '2026-10-21 08:00');
        $this->appointmentFor($this->ana, '2026-10-22 08:00', attributes: ['status' => AppointmentStatus::Requested]);
        $this->appointmentFor($this->ana, '2026-10-23 08:00', attributes: ['status' => AppointmentStatus::Cancelled]);
        $this->appointmentFor($this->ana, '2026-10-12 08:00', attributes: ['status' => AppointmentStatus::Completed]);
        $this->appointmentFor($this->ana, '2026-10-16 08:00');
        $this->signInToPortal($this->user);

        $third = $this->book()->assertCreated()->json('data.id');

        $this->book(['starts_at' => '2026-10-20T10:00:00Z'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'too_many_open')
            ->assertJsonPath('message', __('booking.too_many_open', ['max' => 3]))
            ->assertJsonMissingPath('slots');
        $this->assertSame(6, Appointment::withoutGlobalScopes()->count());
        Event::assertDispatchedTimes(AppointmentBooked::class, 1);

        // Moving one is not a new booking.
        $this->portal('POST', "appointments/{$third}/reschedule", ['starts_at' => '2026-10-20T12:00:00Z'])
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-10-20T12:00:00Z');

        // Another client is not affected.
        $this->forgetPortalCookie();
        $this->signInToPortal($borisAccount);
        $this->book(['starts_at' => '2026-10-20T10:00:00Z'])->assertCreated();

        // Cancelling one makes room again.
        $this->forgetPortalCookie();
        $this->signInToPortal($this->user);
        $this->portal('POST', "appointments/{$third}/cancel")->assertOk();
        $this->book(['starts_at' => '2026-10-20T07:00:00Z'])->assertCreated();
    }
}
