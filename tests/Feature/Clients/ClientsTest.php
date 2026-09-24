<?php

namespace Tests\Feature\Clients;

use App\Models\AstrologyMethod;
use App\Models\Client;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ImportsPlaces;
use Tests\TestCase;

class ClientsTest extends TestCase
{
    use ImportsPlaces, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importPlaces();
        $this->user = User::factory()->withWorkspace()->create();
    }

    private function client(array $attributes = []): Client
    {
        return Client::factory()->inWorkspace($this->user->current_workspace_id)->create($attributes);
    }

    public function test_a_client_is_created_with_tags_methods_and_birth_data_from_a_chosen_place(): void
    {
        $western = AstrologyMethod::withoutGlobalScopes()->where('slug', 'western')->value('id');

        $response = $this->actingAs($this->user)->postJson('/api/v1/clients', [
            'first_name' => 'Ana',
            'last_name' => 'Marković',
            'email' => 'ana@example.com',
            'preferred_locale' => 'sr',
            'tags' => ['VIP', 'returning', 'vip'],
            'method_ids' => [$western],
            'default_method_id' => $western,
            'birth' => [
                'birth_date' => '1985-07-15',
                'birth_time' => '14:30',
                'time_accuracy' => 'exact',
                'place_id' => 3194360,
                'data_source' => 'Birth certificate',
            ],
        ])->assertCreated();

        $response
            ->assertJsonPath('data.full_name', 'Ana Marković')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.tags', ['returning', 'VIP'])
            ->assertJsonPath('data.methods.0.slug', 'western')
            ->assertJsonPath('data.methods.0.is_default', true)
            ->assertJsonPath('data.birth.birth_place', 'Novi Sad, South Backa, Vojvodina')
            ->assertJsonPath('data.birth.birth_country_code', 'RS')
            ->assertJsonPath('data.birth.latitude', 45.25167)
            ->assertJsonPath('data.birth.birth_timezone', 'Europe/Belgrade')
            ->assertJsonPath('data.birth.geocode_source', 'geonames')
            ->assertJsonPath('data.birth.chart.ready', true)
            // Summer time in Yugoslavia in 1985: UTC+2.
            ->assertJsonPath('data.birth.moment.utc_offset', '+02:00')
            ->assertJsonPath('data.birth.moment.utc', '1985-07-15T12:30:00Z')
            ->assertJsonPath('data.birth.moment.is_dst', true);

        $this->assertSame($this->user->id, Client::withoutGlobalScopes()->first()->assigned_user_id);
    }

    public function test_hand_entered_coordinates_need_a_time_zone_and_are_marked_manual(): void
    {
        $payload = [
            'first_name' => 'Ivo',
            'birth' => [
                'birth_date' => '1990-01-10',
                'birth_time' => '06:15',
                'time_accuracy' => 'approximate',
                'birth_place' => 'Selo Donje, Serbia',
                'latitude' => 43.1234567,
                'longitude' => 21.7654321,
            ],
        ];

        $this->actingAs($this->user)->postJson('/api/v1/clients', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('birth.birth_timezone');

        $payload['birth']['birth_timezone'] = 'Europe/Belgrade';

        $this->actingAs($this->user)->postJson('/api/v1/clients', $payload)
            ->assertCreated()
            ->assertJsonPath('data.birth.geocode_source', 'manual')
            ->assertJsonPath('data.birth.latitude', 43.123457)
            ->assertJsonPath('data.birth.place_id', null);
    }

    public function test_the_time_is_required_unless_it_is_unknown(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/clients', [
            'first_name' => 'Mia',
            'birth' => ['birth_date' => '1990-01-10', 'time_accuracy' => 'exact', 'place_id' => 792680],
        ])->assertUnprocessable()->assertJsonValidationErrors('birth.birth_time');

        $this->actingAs($this->user)->postJson('/api/v1/clients', [
            'first_name' => 'Mia',
            'birth' => [
                'birth_date' => '1990-01-10',
                'birth_time' => '10:00',
                'time_accuracy' => 'unknown',
                'place_id' => 792680,
            ],
        ])->assertCreated()
            // An unknown time is not kept, whatever was sent.
            ->assertJsonPath('data.birth.birth_time', null)
            ->assertJsonPath('data.birth.chart.ready', true)
            ->assertJsonPath('data.birth.moment', null);
    }

    public function test_a_client_can_be_saved_without_a_location_and_is_told_what_is_missing(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/clients', [
            'first_name' => 'Lena',
            'birth' => ['birth_date' => '1990-01-10', 'birth_time' => '10:00', 'time_accuracy' => 'exact'],
        ])->assertCreated()
            ->assertJsonPath('data.birth.chart.ready', false)
            ->assertJsonPath('data.birth.chart.missing', ['location', 'timezone']);
    }

    public function test_a_chosen_place_stays_frozen_when_the_gazetteer_changes(): void
    {
        $id = $this->actingAs($this->user)->postJson('/api/v1/clients', [
            'first_name' => 'Ana',
            'birth' => ['birth_date' => '1985-07-15', 'birth_time' => '14:30', 'time_accuracy' => 'exact', 'place_id' => 3194360],
        ])->json('data.id');

        // A later GeoNames import moves the place and changes its zone.
        DB::table('places')->where('id', 3194360)->update(['latitude' => 10, 'timezone' => 'Europe/Paris']);

        $this->actingAs($this->user)->putJson("/api/v1/clients/{$id}/birth-details", [
            'birth_date' => '1985-07-15',
            'birth_time' => '14:35',
            'time_accuracy' => 'rectified',
            'place_id' => 3194360,
        ])->assertOk()
            ->assertJsonPath('data.birth_time', '14:35')
            ->assertJsonPath('data.time_accuracy', 'rectified')
            ->assertJsonPath('data.latitude', 45.25167)
            ->assertJsonPath('data.birth_timezone', 'Europe/Belgrade');

        // Choosing a different place copies that place in.
        $this->actingAs($this->user)->putJson("/api/v1/clients/{$id}/birth-details", [
            'birth_date' => '1985-07-15',
            'birth_time' => '14:35',
            'time_accuracy' => 'rectified',
            'place_id' => 3193044,
        ])->assertOk()->assertJsonPath('data.birth_place', 'Podgorica')->assertJsonPath('data.birth_timezone', 'Europe/Podgorica');
    }

    public function test_times_that_fall_on_a_clock_change_are_flagged(): void
    {
        $create = fn (string $date, string $time) => $this->actingAs($this->user)->postJson('/api/v1/clients', [
            'first_name' => 'Test',
            'birth' => ['birth_date' => $date, 'birth_time' => $time, 'time_accuracy' => 'exact', 'place_id' => 792680],
        ])->json('data.birth');

        // Clocks went forward 02:00 → 03:00 and back 03:00 → 02:00 in 2021.
        $this->assertSame('skipped', $create('2021-03-28', '02:30')['clock_change']);
        $this->assertSame('ambiguous', $create('2021-10-31', '02:30')['clock_change']);
        $this->assertNull($create('2021-10-31', '04:30')['clock_change']);

        $this->assertTrue($create('1955-05-01', '12:00')['zone_history_uncertain']);
        $this->assertFalse($create('1985-05-01', '12:00')['zone_history_uncertain']);
    }

    public function test_the_list_searches_filters_and_leaves_out_archived_clients(): void
    {
        $sasa = $this->client(['first_name' => 'Saša', 'last_name' => 'Jović', 'status' => 'active']);
        $this->client(['first_name' => 'Petar', 'last_name' => 'Ilić', 'status' => 'lead']);
        $this->client(['first_name' => 'Old', 'last_name' => 'Client', 'status' => 'archived']);
        $vip = new Tag(['name' => 'vip']);
        $vip->workspace_id = $this->user->current_workspace_id;
        $vip->save();
        $sasa->tags()->attach($vip);

        $names = fn (string $query) => collect($this->actingAs($this->user)->getJson('/api/v1/clients'.$query)->assertOk()->json('data'))
            ->pluck('full_name')->all();

        $this->assertSame(['Petar Ilić', 'Saša Jović'], $names(''));
        $this->assertSame(['Saša Jović'], $names('?search=sasa'));
        $this->assertSame(['Saša Jović'], $names('?search=sa%C5%A1a%20jo'));
        $this->assertSame(['Petar Ilić'], $names('?status=lead'));
        $this->assertSame(['Old Client'], $names('?status=archived'));
        $this->assertCount(3, $names('?status=all'));
        $this->assertSame(['Saša Jović'], $names('?tag=vip'));
    }

    public function test_the_list_is_paginated_on_the_server(): void
    {
        Client::factory()->count(30)->inWorkspace($this->user->current_workspace_id)->create();

        $this->actingAs($this->user)->getJson('/api/v1/clients?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 30);
    }

    public function test_private_notes_appear_only_on_the_single_client(): void
    {
        $client = $this->client(['internal_notes' => 'Private observation']);

        $this->actingAs($this->user)->getJson('/api/v1/clients')->assertJsonMissingPath('data.0.internal_notes');
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client->id}")
            ->assertJsonPath('data.internal_notes', 'Private observation');
    }

    public function test_a_client_can_be_archived_and_restored(): void
    {
        $client = $this->client();

        $this->actingAs($this->user)->postJson("/api/v1/clients/{$client->id}/archive")
            ->assertOk()->assertJsonPath('data.status', 'archived');
        $this->actingAs($this->user)->deleteJson("/api/v1/clients/{$client->id}/archive")
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_only_the_fields_sent_are_updated(): void
    {
        $client = $this->client(['first_name' => 'Ana', 'phone' => '+381 60 123']);

        $this->actingAs($this->user)->patchJson("/api/v1/clients/{$client->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Ana')
            ->assertJsonPath('data.phone', '+381 60 123')
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_input_is_validated(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/clients', [
            'first_name' => '',
            'email' => 'not-an-email',
            'country_code' => 'XX',
            'timezone' => 'Moon/Base',
            'status' => 'archived',
            'birth' => ['time_accuracy' => 'sort-of', 'birth_date' => '2999-01-01', 'latitude' => 95],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'first_name', 'email', 'country_code', 'timezone', 'status',
            'birth.time_accuracy', 'birth.birth_date', 'birth.latitude',
        ]);
    }
}
