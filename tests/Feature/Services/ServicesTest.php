<?php

namespace Tests\Feature\Services;

use App\Models\ActivityEvent;
use App\Models\AstrologyMethod;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * Services and their use on consultations (docs/spec/02, "Usluge").
 */
class ServicesTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function service(array $attributes = []): Service
    {
        return Service::factory()->inWorkspace($this->owner->current_workspace_id)->create($attributes);
    }

    private function client(): Client
    {
        return Client::factory()->inWorkspace($this->owner->current_workspace_id)->create(['first_name' => 'Ana']);
    }

    public function test_a_service_is_created_with_its_price_colour_and_methods(): void
    {
        $horary = AstrologyMethod::withoutGlobalScopes()->where('slug', 'horary')->value('id');

        $response = $this->actingAs($this->owner)->postJson('/api/v1/services', [
            'name' => 'Natal reading',
            'description' => 'The full birth chart, explained.',
            'duration_minutes' => 90,
            'price' => ['amount' => 12000, 'currency' => 'EUR'],
            'location_type' => 'either',
            'color' => 'teal',
            'requires_deposit' => true,
            'method_ids' => [$horary],
        ])->assertCreated();

        $response->assertJsonPath('data.name', 'Natal reading')
            ->assertJsonPath('data.duration_minutes', 90)
            ->assertJsonPath('data.price', ['amount' => 12000, 'currency' => 'EUR'])
            ->assertJsonPath('data.location_type', 'either')
            ->assertJsonPath('data.color', 'teal')
            ->assertJsonPath('data.requires_deposit', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.in_use', false)
            ->assertJsonPath('data.methods.0.slug', 'horary');

        $this->assertDatabaseHas('services', [
            'id' => $response->json('data.id'),
            'workspace_id' => $this->owner->current_workspace_id,
            'price_amount' => 12000,
            'currency' => 'EUR',
        ]);
    }

    public function test_a_service_without_a_price_keeps_the_practice_currency(): void
    {
        $this->owner->currentWorkspace->update(['default_currency' => 'RSD']);

        $this->actingAs($this->owner)->postJson('/api/v1/services', [
            'name' => 'Short question',
            'duration_minutes' => 20,
            'location_type' => 'online',
        ])->assertCreated()
            ->assertJsonPath('data.price', null)
            ->assertJsonPath('data.currency', 'RSD')
            ->assertJsonPath('data.color', null);
    }

    public function test_invalid_services_are_rejected(): void
    {
        $this->service(['name' => 'Natal reading']);

        $this->actingAs($this->owner)->postJson('/api/v1/services', [
            'name' => 'Natal reading',
            'duration_minutes' => 0,
            'price' => ['amount' => -5, 'currency' => 'XXX'],
            'location_type' => 'moon',
            'color' => '#ff0000',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'name', 'duration_minutes', 'price.amount', 'price.currency', 'location_type', 'color',
        ]);

        // Money is whole units of the smallest denomination, never a float.
        $this->actingAs($this->owner)->postJson('/api/v1/services', [
            'name' => 'Solar return',
            'duration_minutes' => 60,
            'price' => ['amount' => 49.5, 'currency' => 'EUR'],
            'location_type' => 'online',
        ])->assertUnprocessable()->assertJsonValidationErrors('price.amount');
    }

    public function test_a_patch_changes_only_what_it_sends(): void
    {
        $service = $this->service(['name' => 'Natal reading', 'duration_minutes' => 60, 'price_amount' => 9000]);

        $this->actingAs($this->owner)->patchJson("/api/v1/services/{$service->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.name', 'Natal reading')
            ->assertJsonPath('data.price.amount', 9000);

        // Its own name is not a duplicate.
        $this->actingAs($this->owner)->patchJson("/api/v1/services/{$service->id}", ['name' => 'Natal reading', 'price' => null])
            ->assertOk()
            ->assertJsonPath('data.price', null)
            ->assertJsonPath('data.currency', 'EUR');
    }

    public function test_the_list_shows_active_services_first_and_filters_by_status(): void
    {
        $this->service(['name' => 'Zodiac basics']);
        $this->service(['name' => 'Archive reading', 'is_active' => false]);
        $this->service(['name' => 'Career chart']);

        $names = fn (string $query = '') => collect($this->actingAs($this->owner)->getJson('/api/v1/services'.$query)
            ->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Career chart', 'Zodiac basics', 'Archive reading'], $names());
        $this->assertSame(['Career chart', 'Zodiac basics'], $names('?status=active'));
        $this->assertSame(['Archive reading'], $names('?status=inactive'));
    }

    public function test_members_use_services_but_only_the_owner_manages_them(): void
    {
        $service = $this->service();
        $member = $this->memberOf($this->owner);

        $this->actingAs($member)->getJson('/api/v1/services')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($member)->getJson("/api/v1/services/{$service->id}")->assertOk();
        $this->actingAs($member)->postJson('/api/v1/services', [
            'name' => 'Mine', 'duration_minutes' => 30, 'location_type' => 'online',
        ])->assertForbidden();
        $this->actingAs($member)->patchJson("/api/v1/services/{$service->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($member)->deleteJson("/api/v1/services/{$service->id}")->assertForbidden();
    }

    public function test_an_unused_service_is_deleted_but_one_in_use_only_deactivated(): void
    {
        $unused = $this->service();
        $used = $this->service();
        $consultation = Consultation::factory()->forClient($this->client())->create(['service_id' => $used->id]);
        // A deleted consultation still refers to the service.
        $consultation->delete();

        $this->actingAs($this->owner)->deleteJson("/api/v1/services/{$unused->id}")->assertNoContent();
        $this->assertDatabaseMissing('services', ['id' => $unused->id]);

        $this->actingAs($this->owner)->getJson("/api/v1/services/{$used->id}")->assertJsonPath('data.in_use', true);
        $this->actingAs($this->owner)->deleteJson("/api/v1/services/{$used->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', __('services.in_use'));
        $this->assertDatabaseHas('services', ['id' => $used->id]);
    }

    public function test_a_consultation_takes_the_service_and_its_duration(): void
    {
        $service = $this->service(['name' => 'Natal reading', 'duration_minutes' => 90, 'color' => 'rose']);

        $response = $this->actingAs($this->owner)->postJson('/api/v1/consultations', [
            'client_id' => $this->client()->id,
            'service_id' => $service->id,
            'status' => 'completed',
            'starts_at' => '2026-07-15T14:30',
        ])->assertCreated();

        $response->assertJsonPath('data.service', [
            'id' => $service->id, 'name' => 'Natal reading', 'color' => 'rose', 'is_active' => true,
        ])
            ->assertJsonPath('data.duration_minutes', 90)
            ->assertJsonPath('data.title', null);

        // A duration that was given wins over the service's.
        $this->actingAs($this->owner)->postJson('/api/v1/consultations', [
            'client_id' => $this->client()->id,
            'service_id' => $service->id,
            'status' => 'draft',
            'duration_minutes' => 45,
        ])->assertCreated()->assertJsonPath('data.duration_minutes', 45);
    }

    public function test_an_inactive_service_stays_on_a_consultation_but_is_not_chosen_anew(): void
    {
        $service = $this->service();
        $consultation = Consultation::factory()->forClient($this->client())->create(['service_id' => $service->id]);
        $service->update(['is_active' => false]);

        // Other changes keep the inactive service, also when it is sent back unchanged.
        $this->actingAs($this->owner)->patchJson("/api/v1/consultations/{$consultation->id}", [
            'service_id' => $service->id,
            'topics' => 'Relocation',
        ])->assertOk()->assertJsonPath('data.service.is_active', false);

        $this->actingAs($this->owner)->postJson('/api/v1/consultations', [
            'client_id' => $consultation->client_id,
            'service_id' => $service->id,
            'status' => 'draft',
        ])->assertUnprocessable()->assertJsonValidationErrors(['service_id' => __('consultations.service_inactive')]);

        // Removing the service is always possible.
        $this->actingAs($this->owner)->patchJson("/api/v1/consultations/{$consultation->id}", ['service_id' => null])
            ->assertOk()->assertJsonPath('data.service', null);
    }

    public function test_consultations_are_filtered_and_found_by_service(): void
    {
        $natal = $this->service(['name' => 'Natal reading']);
        $horary = $this->service(['name' => 'Horary question']);
        $client = $this->client();
        Consultation::factory()->forClient($client)->create(['service_id' => $natal->id, 'title' => null]);
        Consultation::factory()->forClient($client)->create(['service_id' => $horary->id, 'title' => null]);
        Consultation::factory()->forClient($client)->create(['service_id' => null, 'title' => 'Chat']);

        $ids = fn (string $query) => collect($this->actingAs($this->owner)->getJson('/api/v1/consultations'.$query)
            ->assertOk()->json('data'))->pluck('service.name')->all();

        $this->assertSame(['Horary question'], $ids("?service_id={$horary->id}"));
        $this->assertSame(['Natal reading'], $ids('?search=natal'));
    }

    public function test_the_timeline_names_an_untitled_consultation_after_its_service_and_follows_a_rename(): void
    {
        $service = $this->service(['name' => 'Natal reading']);
        $consultation = Consultation::factory()->forClient($this->client())->create([
            'service_id' => $service->id,
            'title' => null,
        ]);

        $event = fn () => ActivityEvent::withoutGlobalScopes()
            ->where('subject_type', 'consultation')->where('subject_id', $consultation->id)->sole();

        $this->assertSame('Natal reading', $event()->summary);
        $this->assertSame('Natal reading', $event()->metadata['service']);

        $this->actingAs($this->owner)->patchJson("/api/v1/services/{$service->id}", ['name' => 'Birth chart reading'])->assertOk();

        $this->assertSame('Birth chart reading', $event()->summary);
    }

    public function test_reference_data_lists_what_a_service_may_be(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/reference-data')
            ->assertOk()
            ->assertJsonPath('data.services.location_types', ['online', 'in_person', 'either'])
            ->assertJsonPath('data.services.colors.0', 'indigo')
            ->assertJsonPath('data.currency_decimals.EUR', 2)
            ->assertJsonPath('data.currency_decimals.JPY', 0)
            ->assertJsonPath('data.relationship_types.0', 'partner');
    }
}
