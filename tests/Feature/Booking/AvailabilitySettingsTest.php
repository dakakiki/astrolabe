<?php

namespace Tests\Feature\Booking;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\BookingPolicy;
use App\Models\User;
use App\Support\Booking\BookingAvailability;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * Settings → Availability (Phase 9b, docs/spec/12): each person's own working
 * week and the exceptions on top of it, which the portal's free times come from.
 */
class AvailabilitySettingsTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 15 October 2026, 12:00 in Belgrade; the clocks go back on 25 October.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'UTC'));
        $this->owner = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $rules  [weekday, start, end]
     */
    private function saveWeek(array $rules, string $zone = 'Europe/Belgrade', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->putJson('/api/v1/availability', [
            'timezone' => $zone,
            'rules' => array_map(fn (array $rule) => [
                'weekday' => $rule[0], 'start_time' => $rule[1], 'end_time' => $rule[2],
            ], $rules),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function addException(array $attributes, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->postJson('/api/v1/availability/exceptions', $attributes + [
            'kind' => 'unavailable',
            'all_day' => true,
            'timezone' => 'Europe/Belgrade',
        ]);
    }

    public function test_the_week_starts_empty_on_the_persons_own_clock(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/availability')
            ->assertOk()
            ->assertExactJson(['data' => ['timezone' => 'Europe/Belgrade', 'rules' => [], 'exceptions' => []]]);
    }

    public function test_the_week_is_replaced_as_a_whole_and_may_end_at_midnight(): void
    {
        $this->saveWeek([[5, '18:00', '24:00'], [1, '13:00', '17:00'], [1, '09:00', '12:00'], [3, '12:00', '15:00'], [3, '09:00', '12:00']], 'Europe/London')
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Europe/London')
            ->assertJsonPath('data.rules', [
                ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
                ['weekday' => 1, 'start_time' => '13:00', 'end_time' => '17:00'],
                ['weekday' => 3, 'start_time' => '09:00', 'end_time' => '12:00'],
                ['weekday' => 3, 'start_time' => '12:00', 'end_time' => '15:00'],
                ['weekday' => 5, 'start_time' => '18:00', 'end_time' => '24:00'],
            ]);

        $rows = AvailabilityRule::withoutGlobalScopes()->get();
        $this->assertCount(5, $rows);
        $this->assertSame([$this->owner->current_workspace_id], $rows->pluck('workspace_id')->unique()->values()->all());
        $this->assertSame([$this->owner->id], $rows->pluck('user_id')->unique()->values()->all());
        $this->assertSame(['Europe/London'], $rows->pluck('timezone')->unique()->values()->all());

        // The next save is the whole week again.
        $this->saveWeek([[2, '10:00', '14:00']])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Europe/Belgrade')
            ->assertJsonPath('data.rules', [['weekday' => 2, 'start_time' => '10:00', 'end_time' => '14:00']]);
        $this->assertSame(1, AvailabilityRule::withoutGlobalScopes()->count());

        // No working hours at all: nothing offered, and the person's own zone again.
        $this->saveWeek([], 'America/New_York')
            ->assertOk()
            ->assertJsonPath('data.rules', [])
            ->assertJsonPath('data.timezone', 'Europe/Belgrade');
        $this->assertSame(0, AvailabilityRule::withoutGlobalScopes()->count());
    }

    public function test_the_saved_week_is_what_the_portal_offers(): void
    {
        $this->saveWeek([[5, '22:00', '24:00']])->assertOk();
        $policy = new BookingPolicy(['enabled' => true, 'min_notice_minutes' => 0, 'buffer_after_minutes' => 0]);

        $slots = app(CurrentWorkspace::class)->run($this->owner->currentWorkspace, fn () => BookingAvailability::for($policy)
            ->slots(60, now(), from: CarbonImmutable::parse('2026-10-16 00:00', 'UTC'), to: CarbonImmutable::parse('2026-10-17 00:00', 'UTC')));

        // Friday 22:00, 22:30 and 23:00 in Belgrade (summer time), the last one ending at midnight.
        $this->assertSame(
            ['2026-10-16T20:00:00Z', '2026-10-16T20:30:00Z', '2026-10-16T21:00:00Z'],
            array_map(fn (CarbonImmutable $slot) => $slot->toIso8601ZuluString(), $slots),
        );
    }

    public function test_an_invalid_week_is_refused(): void
    {
        $this->actingAs($this->owner)->putJson('/api/v1/availability', ['timezone' => 'Mars/Olympus'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['timezone', 'rules']);

        $this->saveWeek([
            [0, '09:00', '12:00'],
            [8, '09:00', '12:00'],
            [1, '9:00', '12:00'],
            [1, '24:00', '24:00'],
            [2, '10:00', '24:30'],
            [2, '14:00', '13:00'],
            [3, '09:00', '09:00'],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'rules.0.weekday', 'rules.1.weekday', 'rules.2.start_time', 'rules.3.start_time',
            'rules.4.end_time', 'rules.5.end_time', 'rules.6.end_time',
        ]);

        $this->saveWeek([[4, '10:00', '24:00'], [4, '09:00', '12:00']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rules.0.start_time' => __('booking.availability.overlap')]);

        $this->saveWeek([[6, '09:00', '17:00'], [6, '09:00', '17:00']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rules.1.start_time');

        $eleven = array_map(fn (int $hour) => [7, sprintf('%02d:00', $hour), sprintf('%02d:30', $hour)], range(8, 18));
        $this->saveWeek($eleven)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rules' => __('booking.availability.too_many', ['max' => 10])]);
        $this->saveWeek(array_slice($eleven, 0, 10))->assertOk();

        $this->actingAs($this->owner)->putJson('/api/v1/availability', [
            'timezone' => 'Europe/Belgrade',
            'rules' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00', 'user_id' => 99]],
        ])->assertUnprocessable()->assertJsonValidationErrors('rules.0');

        // Nothing of the refused weeks was kept.
        $this->assertSame(10, AvailabilityRule::withoutGlobalScopes()->count());
    }

    public function test_time_off_and_extra_time_are_added_listed_and_removed(): void
    {
        $evening = $this->addException([
            'kind' => 'available',
            'all_day' => false,
            'starts_at' => '2026-10-20T18:00',
            'ends_at' => '2026-10-20T21:30',
            'note' => 'Late session for the workshop group',
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'available')
            ->assertJsonPath('data.starts_at', '2026-10-20T16:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-10-20T19:30:00Z')
            ->assertJsonPath('data.all_day', false)
            ->assertJsonPath('data.starts_local', '2026-10-20T18:00')
            ->assertJsonPath('data.ends_local', '2026-10-20T21:30')
            ->assertJsonPath('data.note', 'Late session for the workshop group')
            ->json('data.id');

        // Whole days run from midnight of the first to midnight after the last, on the zone's clock:
        // across the end of summer time that is three days of 24, 25 and 24 hours.
        $holiday = $this->addException(['starts_at' => '2026-10-24', 'ends_at' => '2026-10-26'])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-10-23T22:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-10-26T23:00:00Z')
            ->assertJsonPath('data.all_day', true)
            ->assertJsonPath('data.starts_local', '2026-10-24')
            ->assertJsonPath('data.ends_local', '2026-10-26')
            ->json('data.id');

        // One day each: the autumn day lasts 25 hours, the spring day 23.
        $this->addException(['starts_at' => '2026-10-25', 'ends_at' => '2026-10-25'])
            ->assertJsonPath('data.starts_at', '2026-10-24T22:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-10-25T23:00:00Z')
            ->assertJsonPath('data.ends_local', '2026-10-25');
        $spring = $this->addException(['starts_at' => '2027-03-28', 'ends_at' => '2027-03-28'])
            ->assertJsonPath('data.starts_at', '2027-03-27T23:00:00Z')
            ->assertJsonPath('data.ends_at', '2027-03-28T22:00:00Z')
            ->assertJsonPath('data.starts_local', '2027-03-28')
            ->assertJsonPath('data.ends_local', '2027-03-28')
            ->json('data.id');

        // Another zone is kept with it and read back on its clock.
        $this->addException(['starts_at' => '2026-11-03', 'ends_at' => '2026-11-03', 'timezone' => 'America/New_York'])
            ->assertJsonPath('data.starts_at', '2026-11-03T05:00:00Z')
            ->assertJsonPath('data.timezone', 'America/New_York')
            ->assertJsonPath('data.starts_local', '2026-11-03');

        // One that has already ended is no longer listed.
        AvailabilityException::withoutGlobalScopes()->forceCreate([
            'workspace_id' => $this->owner->current_workspace_id, 'user_id' => $this->owner->id, 'kind' => 'unavailable',
            'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-02 00:00:00', 'timezone' => 'UTC', 'all_day' => true,
        ]);

        $listed = $this->actingAs($this->owner)->getJson('/api/v1/availability')->assertOk()->json('data.exceptions');
        $this->assertSame(
            ['2026-10-20T16:00:00Z', '2026-10-23T22:00:00Z', '2026-10-24T22:00:00Z', '2026-11-03T05:00:00Z', '2027-03-27T23:00:00Z'],
            array_column($listed, 'starts_at'),
        );
        $this->assertSame($evening, $listed[0]['id']);

        $this->actingAs($this->owner)->deleteJson("/api/v1/availability/exceptions/{$holiday}")->assertNoContent();
        $this->actingAs($this->owner)->deleteJson("/api/v1/availability/exceptions/{$spring}")->assertNoContent();
        $this->actingAs($this->owner)->deleteJson("/api/v1/availability/exceptions/{$spring}")->assertNotFound();

        $this->assertCount(3, $this->actingAs($this->owner)->getJson('/api/v1/availability')->json('data.exceptions'));
    }

    public function test_invalid_exceptions_are_refused(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/availability/exceptions', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind', 'starts_at', 'ends_at', 'timezone']);

        $this->addException([
            'kind' => 'holiday', 'all_day' => 'often', 'timezone' => 'Mars/Olympus',
            'starts_at' => '2026-10-20', 'ends_at' => '2026-10-21', 'note' => str_repeat('x', 501),
        ])->assertUnprocessable()->assertJsonValidationErrors(['kind', 'all_day', 'timezone', 'note']);

        // Whole days are dates, other exceptions local date-times.
        $this->addException(['starts_at' => '2026-10-20T09:00', 'ends_at' => '2026-10-20T12:00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['starts_at', 'ends_at']);
        $this->addException(['all_day' => false, 'starts_at' => '2026-10-20', 'ends_at' => '2026-10-21'])
            ->assertUnprocessable()->assertJsonValidationErrors(['starts_at', 'ends_at']);
        $this->addException(['starts_at' => '2026-02-30', 'ends_at' => '2026-03-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        // It ends after it starts — the same day is fine for whole days.
        $this->addException(['all_day' => false, 'starts_at' => '2026-10-20T12:00', 'ends_at' => '2026-10-20T12:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at' => __('booking.availability.end_after_start')]);
        $this->addException(['starts_at' => '2026-10-21', 'ends_at' => '2026-10-20'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        // At most 366 days.
        $this->addException(['starts_at' => '2026-11-01', 'ends_at' => '2027-11-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at' => __('booking.availability.too_long', ['days' => 366])]);
        $this->addException(['starts_at' => '2026-11-01', 'ends_at' => '2027-11-01'])->assertCreated();

        $this->assertSame(1, AvailabilityException::withoutGlobalScopes()->count());
    }

    public function test_a_period_that_has_already_ended_is_refused(): void
    {
        // It is 12:00 in Belgrade: a period ending now or earlier changes nothing and would
        // never be listed, so it is not saved.
        $this->addException(['all_day' => false, 'starts_at' => '2026-10-15T09:00', 'ends_at' => '2026-10-15T12:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at' => __('booking.availability.ended')]);
        $this->addException(['starts_at' => '2026-10-14', 'ends_at' => '2026-10-14'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at' => __('booking.availability.ended')]);
        $this->assertSame(0, AvailabilityException::withoutGlobalScopes()->count());

        // Still running, on its own clock: today as a whole day, a morning until 12:01, and
        // 05:00–06:30 in New York (until 10:30 UTC).
        $this->addException(['starts_at' => '2026-10-15', 'ends_at' => '2026-10-15'])->assertCreated();
        $this->addException(['all_day' => false, 'starts_at' => '2026-10-15T09:00', 'ends_at' => '2026-10-15T12:01'])->assertCreated();
        $this->addException([
            'kind' => 'available',
            'all_day' => false,
            'starts_at' => '2026-10-15T05:00',
            'ends_at' => '2026-10-15T06:30',
            'timezone' => 'America/New_York',
        ])->assertCreated();

        $this->assertCount(3, $this->actingAs($this->owner)->getJson('/api/v1/availability')->json('data.exceptions'));
    }

    public function test_everyone_keeps_their_own_and_another_practice_sees_nothing(): void
    {
        $member = $this->memberOf($this->owner);
        $this->saveWeek([[1, '09:00', '17:00']])->assertOk();
        $ownerOff = $this->addException(['starts_at' => '2026-10-20', 'ends_at' => '2026-10-20'])->json('data.id');

        // The member's own week and time off leave the owner's alone.
        $this->saveWeek([[2, '10:00', '12:00']], 'Europe/London', $member)
            ->assertOk()
            ->assertJsonPath('data.rules', [['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00']]);
        $memberOff = $this->addException(['starts_at' => '2026-10-21', 'ends_at' => '2026-10-21'], $member)->json('data.id');

        $this->actingAs($member)->getJson('/api/v1/availability')
            ->assertJsonPath('data.timezone', 'Europe/London')
            ->assertJsonCount(1, 'data.exceptions')
            ->assertJsonPath('data.exceptions.0.id', $memberOff);
        $this->actingAs($this->owner)->getJson('/api/v1/availability')
            ->assertJsonPath('data.rules', [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']])
            ->assertJsonPath('data.exceptions.0.id', $ownerOff)
            ->assertJsonCount(1, 'data.exceptions');

        // Someone else's time off cannot be removed: not found, in the practice or outside it.
        $this->actingAs($this->owner)->deleteJson("/api/v1/availability/exceptions/{$memberOff}")->assertNotFound();
        $this->actingAs($member)->deleteJson("/api/v1/availability/exceptions/{$ownerOff}")->assertNotFound();

        $stranger = User::factory()->withWorkspace()->create(['timezone' => 'Asia/Tokyo']);
        $this->actingAs($stranger)->deleteJson("/api/v1/availability/exceptions/{$ownerOff}")->assertNotFound();
        $this->actingAs($stranger)->getJson('/api/v1/availability')
            ->assertExactJson(['data' => ['timezone' => 'Asia/Tokyo', 'rules' => [], 'exceptions' => []]]);

        $this->assertSame(2, AvailabilityException::withoutGlobalScopes()->count());
        $this->assertSame(2, AvailabilityRule::withoutGlobalScopes()->count());
    }

    public function test_one_person_in_two_practices_keeps_a_week_in_each(): void
    {
        $home = $this->owner->current_workspace_id;
        $elsewhere = User::factory()->withWorkspace()->create();
        $this->saveWeek([[1, '09:00', '17:00']])->assertOk();

        // The same person, now working in another practice.
        $elsewhere->currentWorkspace->users()->attach($this->owner, [
            'role' => WorkspaceRole::Member->value,
            'status' => MembershipStatus::Active->value,
        ]);
        $this->owner->forceFill(['current_workspace_id' => $elsewhere->current_workspace_id])->save();

        $this->actingAs($this->owner)->getJson('/api/v1/availability')->assertJsonPath('data.rules', []);
        $this->saveWeek([[3, '08:00', '10:00']])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$home, $elsewhere->current_workspace_id],
            AvailabilityRule::withoutGlobalScopes()->pluck('workspace_id')->all(),
        );
    }
}
