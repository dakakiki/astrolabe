<?php

namespace Tests\Feature\Booking;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\BookingPolicy;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * Settings → Booking (Phase 9b, docs/spec/12): how clients book in the portal,
 * read by every member and changed by the owner, with the defaults until then.
 */
class BookingPolicySettingsTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withWorkspace()->create(['name' => 'Vega Astrologer', 'timezone' => 'Europe/Belgrade']);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function change(array $changes, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->putJson('/api/v1/booking-policy', $changes);
    }

    /**
     * @return list<list<string>>
     */
    private function auditedFields(): array
    {
        return AuditLog::query()
            ->where('event', AuditEvent::PracticeSettingsChanged)
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $entry) => $entry->properties['fields'])
            ->all();
    }

    public function test_the_defaults_apply_until_the_owner_saves(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/booking-policy')
            ->assertOk()
            ->assertExactJson(['data' => [
                'enabled' => false,
                'min_notice_minutes' => 1440,
                'max_days_ahead' => 60,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 15,
                'slot_step_minutes' => 30,
                'requires_confirmation' => false,
                'client_reschedule_until_hours' => 24,
                'client_cancel_until_hours' => 24,
                'client_note' => null,
                'astrologer' => ['id' => $this->owner->id, 'name' => 'Vega Astrologer'],
                'bookable_services' => 0,
                'has_availability' => false,
                'portal_url' => config('portal.url'),
            ]]);

        $this->assertSame(0, BookingPolicy::withoutGlobalScopes()->count());
    }

    public function test_the_owner_changes_only_what_is_sent_and_the_audit_log_names_the_fields(): void
    {
        $this->change([
            'enabled' => true,
            'slot_step_minutes' => 15,
            'client_cancel_until_hours' => null,
            'client_note' => 'A deposit is asked for natal readings.',
            'buffer_after_minutes' => 15,
        ])->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.slot_step_minutes', 15)
            ->assertJsonPath('data.client_cancel_until_hours', null)
            ->assertJsonPath('data.client_reschedule_until_hours', 24)
            ->assertJsonPath('data.client_note', 'A deposit is asked for natal readings.')
            ->assertJsonPath('data.max_days_ahead', 60);

        $policy = BookingPolicy::withoutGlobalScopes()->sole();
        $this->assertSame($this->owner->current_workspace_id, $policy->workspace_id);
        $this->assertTrue($policy->enabled);
        $this->assertNull($policy->client_cancel_until_hours);

        $this->change(['max_days_ahead' => 30, 'requires_confirmation' => true, 'min_notice_minutes' => 0, 'client_reschedule_until_hours' => 0])
            ->assertOk()
            ->assertJsonPath('data.max_days_ahead', 30)
            ->assertJsonPath('data.requires_confirmation', true)
            ->assertJsonPath('data.min_notice_minutes', 0)
            ->assertJsonPath('data.client_reschedule_until_hours', 0)
            ->assertJsonPath('data.slot_step_minutes', 15)
            ->assertJsonPath('data.enabled', true);
        $this->assertSame(1, BookingPolicy::withoutGlobalScopes()->count());

        // The same values again change nothing, and nothing is logged.
        $this->change(['max_days_ahead' => 30, 'enabled' => true])->assertOk();

        // Field names only, never the values (the note is the practice's text).
        $this->assertSame([
            ['booking.enabled', 'booking.slot_step_minutes', 'booking.client_cancel_until_hours', 'booking.client_note'],
            ['booking.min_notice_minutes', 'booking.max_days_ahead', 'booking.requires_confirmation', 'booking.client_reschedule_until_hours'],
        ], $this->auditedFields());
        $entry = AuditLog::query()->where('event', AuditEvent::PracticeSettingsChanged)->latest('id')->first();
        $this->assertSame($this->owner->id, $entry->user_id);
        $this->assertSame($this->owner->current_workspace_id, $entry->subject_id);
        $this->assertStringNotContainsString('deposit', json_encode($entry->properties));
    }

    public function test_a_member_reads_the_rules_but_cannot_change_them(): void
    {
        $member = $this->memberOf($this->owner);
        $this->change(['enabled' => true])->assertOk();

        $this->actingAs($member)->getJson('/api/v1/booking-policy')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.astrologer.id', $this->owner->id);

        $this->change(['enabled' => false], $member)->assertForbidden();
        $this->change(['slot_step_minutes' => 7], $member)->assertForbidden();
        $this->assertTrue(BookingPolicy::withoutGlobalScopes()->sole()->enabled);
    }

    public function test_invalid_rules_are_refused(): void
    {
        $this->change([
            'enabled' => null,
            'min_notice_minutes' => 20161,
            'max_days_ahead' => 0,
            'buffer_before_minutes' => -5,
            'buffer_after_minutes' => 241,
            'slot_step_minutes' => 25,
            'requires_confirmation' => 'sometimes',
            'client_reschedule_until_hours' => 337,
            'client_cancel_until_hours' => 1.5,
            'client_note' => str_repeat('x', 2001),
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'enabled', 'min_notice_minutes', 'max_days_ahead', 'buffer_before_minutes', 'buffer_after_minutes',
            'slot_step_minutes', 'requires_confirmation', 'client_reschedule_until_hours', 'client_cancel_until_hours',
            'client_note',
        ]);

        $this->change(['max_days_ahead' => 366])->assertUnprocessable()->assertJsonValidationErrors('max_days_ahead');

        // The limits themselves are fine.
        $this->change([
            'min_notice_minutes' => 20160, 'max_days_ahead' => 365, 'buffer_before_minutes' => 240,
            'buffer_after_minutes' => 0, 'slot_step_minutes' => 120, 'client_cancel_until_hours' => 336,
        ])->assertOk();
    }

    public function test_the_page_learns_whether_booking_can_work_yet(): void
    {
        $workspace = $this->owner->current_workspace_id;
        Service::factory()->inWorkspace($workspace)->bookable()->create(['name' => 'Natal reading']);
        Service::factory()->inWorkspace($workspace)->bookable()->inactive()->create(['name' => 'Old reading']);
        Service::factory()->inWorkspace($workspace)->create(['name' => 'Private session']);
        $member = $this->memberOf($this->owner);

        // A member's working hours are their own: bookings go to the owner.
        $this->actingAs($member)->putJson('/api/v1/availability', [
            'timezone' => 'Europe/Belgrade',
            'rules' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']],
        ])->assertOk();

        $this->actingAs($this->owner)->getJson('/api/v1/booking-policy')
            ->assertJsonPath('data.bookable_services', 1)
            ->assertJsonPath('data.has_availability', false);

        $this->actingAs($this->owner)->putJson('/api/v1/availability', [
            'timezone' => 'Europe/Belgrade',
            'rules' => [['weekday' => 2, 'start_time' => '10:00', 'end_time' => '14:00']],
        ])->assertOk();

        $this->actingAs($member)->getJson('/api/v1/booking-policy')
            ->assertJsonPath('data.has_availability', true)
            ->assertJsonPath('data.astrologer.name', 'Vega Astrologer');
    }

    public function test_another_practice_neither_sees_nor_changes_the_rules(): void
    {
        $stranger = User::factory()->withWorkspace()->create();
        Service::factory()->inWorkspace($this->owner->current_workspace_id)->bookable()->create();
        $this->change(['enabled' => true, 'client_note' => 'Ours'])->assertOk();

        $this->actingAs($stranger)->getJson('/api/v1/booking-policy')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.client_note', null)
            ->assertJsonPath('data.bookable_services', 0)
            ->assertJsonPath('data.astrologer.id', $stranger->id);

        $this->change(['client_note' => 'Theirs'], $stranger)->assertOk();

        $this->assertSame(2, BookingPolicy::withoutGlobalScopes()->count());
        $this->actingAs($this->owner)->getJson('/api/v1/booking-policy')
            ->assertJsonPath('data.client_note', 'Ours')
            ->assertJsonPath('data.enabled', true);
    }
}
