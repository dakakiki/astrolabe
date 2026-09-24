<?php

namespace Tests\Feature\RelatedPeople;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Engines\FakeEngine;
use App\Enums\CelestialBody;
use App\Enums\RelationshipType;
use App\Models\ActivityEvent;
use App\Models\ChartCalculation;
use App\Models\Client;
use App\Models\ClientRelationship;
use App\Models\RelatedPerson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ImportsPlaces;
use Tests\TestCase;

/**
 * Related people and links between clients (docs/spec/02, "Povezane osobe").
 */
class RelatedPeopleTest extends TestCase
{
    use ImportsPlaces, RefreshDatabase;

    private User $user;

    private Client $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importPlaces();
        $this->user = User::factory()->withWorkspace()->create();
        $this->ana = $this->client('Ana', 'Marković');
    }

    private function client(string $first, string $last = 'Test'): Client
    {
        return Client::factory()->inWorkspace($this->user->current_workspace_id)->create([
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function addPerson(array $attributes = []): int
    {
        return $this->actingAs($this->user)->postJson('/api/v1/related-people', $attributes + [
            'client_id' => $this->ana->id,
            'relationship_type' => 'partner',
            'first_name' => 'Marko',
            'last_name' => 'Petrović',
            'birth' => [
                'birth_date' => '1983-03-02',
                'birth_time' => '06:15',
                'time_accuracy' => 'exact',
                'place_id' => 3194360,
            ],
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relationshipsOf(Client $client): array
    {
        return $this->actingAs($this->user)->getJson("/api/v1/clients/{$client->id}/relationships")->assertOk()->json('data');
    }

    public function test_a_person_is_added_to_a_client_with_frozen_birth_data(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/related-people', [
            'client_id' => $this->ana->id,
            'relationship_type' => 'partner',
            'notes' => 'Married 2010',
            'first_name' => 'Marko',
            'last_name' => 'Petrović',
            'birth' => ['birth_date' => '1983-03-02', 'birth_time' => '06:15', 'time_accuracy' => 'exact', 'place_id' => 3194360],
        ])->assertCreated();

        $response->assertJsonPath('data.full_name', 'Marko Petrović')
            ->assertJsonPath('data.birth.birth_place', 'Novi Sad, South Backa, Vojvodina')
            ->assertJsonPath('data.birth.latitude', 45.25167)
            ->assertJsonPath('data.birth.birth_timezone', 'Europe/Belgrade')
            ->assertJsonPath('data.birth.geocode_source', 'geonames')
            ->assertJsonPath('data.birth.chart.ready', true)
            ->assertJsonPath('data.relationships.0.relationship_type', 'partner')
            ->assertJsonPath('data.relationships.0.notes', 'Married 2010')
            ->assertJsonPath('data.relationships.0.client.full_name', 'Ana Marković');

        $list = $this->relationshipsOf($this->ana);
        $this->assertCount(1, $list);
        $this->assertSame('person', $list[0]['kind']);
        $this->assertSame('outgoing', $list[0]['direction']);
        $this->assertSame('Marko Petrović', $list[0]['party']['full_name']);
        $this->assertSame('1983-03-02', $list[0]['party']['birth']['birth_date']);
        $this->assertTrue($list[0]['party']['birth']['chart_ready']);

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$this->ana->id}")->assertJsonPath('data.stats.related', 1);
    }

    public function test_a_person_without_birth_data_is_allowed(): void
    {
        $id = $this->actingAs($this->user)->postJson('/api/v1/related-people', [
            'client_id' => $this->ana->id,
            'relationship_type' => 'child',
            'first_name' => 'Luka',
        ])->assertCreated()->assertJsonPath('data.birth', null)->json('data.id');

        $this->actingAs($this->user)->getJson("/api/v1/related-people/{$id}/chart")
            ->assertOk()
            ->assertJsonPath('data.status', 'incomplete')
            ->assertJsonPath('data.missing', ['birth_date', 'birth_time', 'location', 'timezone']);

        $this->assertNull($this->relationshipsOf($this->ana)[0]['party']['birth']);
    }

    public function test_invalid_people_and_links_are_rejected(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/related-people', [
            'relationship_type' => 'cousin',
            'birth' => ['time_accuracy' => 'exact', 'birth_date' => '1990-01-01'],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'client_id', 'relationship_type', 'first_name', 'birth.birth_time',
        ]);
    }

    public function test_the_person_has_a_chart_of_their_own_that_stays_off_the_timeline(): void
    {
        /** @var FakeEngine $engine */
        $engine = app(EphemerisEngine::class);
        $engine->fix(CelestialBody::Venus, 12.5, 1.2);
        $id = $this->addPerson();

        $this->actingAs($this->user)->getJson("/api/v1/related-people/{$id}/chart")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.houses.system', 'placidus');

        $venus = collect($this->actingAs($this->user)->getJson("/api/v1/related-people/{$id}/chart?house_system=whole_sign")
            ->assertOk()
            ->assertJsonPath('data.houses.system', 'whole_sign')
            ->json('data.positions'))->firstWhere('body', 'venus');
        $this->assertSame(12.5, $venus['longitude']);

        $this->assertSame(2, ChartCalculation::withoutGlobalScopes()->where('subject_type', 'related_person')->where('subject_id', $id)->count());
        $this->assertSame(0, ActivityEvent::withoutGlobalScopes()->where('event_type', 'chart_calculated')->count());
    }

    public function test_the_persons_details_and_birth_data_are_updated(): void
    {
        $id = $this->addPerson();

        $this->actingAs($this->user)->patchJson("/api/v1/related-people/{$id}", [
            'email' => 'marko@example.com',
            'birth' => ['birth_date' => '1983-03-02', 'time_accuracy' => 'unknown', 'place_id' => 3194360],
        ])->assertOk()
            ->assertJsonPath('data.first_name', 'Marko')
            ->assertJsonPath('data.email', 'marko@example.com')
            ->assertJsonPath('data.birth.birth_time', null)
            ->assertJsonPath('data.birth.time_accuracy', 'unknown');
    }

    public function test_clients_are_linked_to_each_other_and_each_sees_the_link_its_own_way(): void
    {
        $marko = $this->client('Marko');

        $this->actingAs($this->user)->postJson("/api/v1/clients/{$this->ana->id}/relationships", [
            'related_client_id' => $marko->id,
            'relationship_type' => 'child',
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'client')
            ->assertJsonPath('data.party.full_name', 'Marko Test')
            ->assertJsonPath('data.relationship_type', 'child');

        // On Marko's profile, Ana is his parent.
        $fromMarko = $this->relationshipsOf($marko);
        $this->assertSame('incoming', $fromMarko[0]['direction']);
        $this->assertSame('parent', $fromMarko[0]['relationship_type']);
        $this->assertSame('Ana Marković', $fromMarko[0]['party']['full_name']);
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$marko->id}")->assertJsonPath('data.stats.related', 1);

        // Linking them again, from either side, is refused.
        $this->actingAs($this->user)->postJson("/api/v1/clients/{$marko->id}/relationships", [
            'related_client_id' => $this->ana->id,
            'relationship_type' => 'parent',
        ])->assertUnprocessable()->assertJsonValidationErrors(['related_client_id' => __('clients.already_linked')]);

        $this->actingAs($this->user)->postJson("/api/v1/clients/{$this->ana->id}/relationships", [
            'related_client_id' => $this->ana->id,
            'relationship_type' => 'friend',
        ])->assertUnprocessable()->assertJsonValidationErrors(['related_client_id' => __('clients.cannot_link_self')]);
    }

    public function test_a_link_edited_from_the_other_side_is_stored_the_right_way_round(): void
    {
        $marko = $this->client('Marko');
        $relationship = new ClientRelationship(['relationship_type' => RelationshipType::Partner]);
        $relationship->workspace_id = $this->ana->workspace_id;
        $relationship->client()->associate($this->ana);
        $relationship->relatedClient()->associate($marko);
        $relationship->save();

        // Marko's profile says Ana is his parent.
        $this->actingAs($this->user)->patchJson("/api/v1/client-relationships/{$relationship->id}", [
            'relationship_type' => 'parent',
            'notes' => 'Adopted',
            'as_seen_by' => $marko->id,
        ])->assertOk()
            ->assertJsonPath('data.relationship_type', 'parent')
            ->assertJsonPath('data.direction', 'incoming');

        $this->assertSame(RelationshipType::Child, $relationship->fresh()->relationship_type);
        $this->assertSame('child', $this->relationshipsOf($this->ana)[0]['relationship_type']);
        $this->assertSame('Adopted', $this->relationshipsOf($this->ana)[0]['notes']);

        $this->actingAs($this->user)->patchJson("/api/v1/client-relationships/{$relationship->id}", [
            'relationship_type' => 'friend',
            'as_seen_by' => $this->client('Stranger')->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('as_seen_by');
    }

    public function test_a_person_can_belong_with_two_clients(): void
    {
        $id = $this->addPerson(['relationship_type' => 'child']);
        $petar = $this->client('Petar');

        $this->actingAs($this->user)->postJson("/api/v1/clients/{$petar->id}/relationships", [
            'related_person_id' => $id,
            'relationship_type' => 'child',
        ])->assertCreated()->assertJsonPath('data.party.full_name', 'Marko Petrović');

        $this->actingAs($this->user)->getJson("/api/v1/related-people/{$id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.relationships');

        $this->actingAs($this->user)->postJson("/api/v1/clients/{$petar->id}/relationships", [
            'related_person_id' => $id,
            'relationship_type' => 'child',
        ])->assertUnprocessable()->assertJsonValidationErrors('related_person_id');
    }

    public function test_removing_the_last_link_removes_the_person(): void
    {
        $id = $this->addPerson();
        $petar = $this->client('Petar');
        $this->actingAs($this->user)->postJson("/api/v1/clients/{$petar->id}/relationships", [
            'related_person_id' => $id,
            'relationship_type' => 'friend',
        ])->assertCreated();

        $links = ClientRelationship::withoutGlobalScopes()->where('related_person_id', $id)->pluck('id');

        $this->actingAs($this->user)->deleteJson("/api/v1/client-relationships/{$links[0]}")->assertNoContent();
        $this->assertNotSoftDeleted('related_people', ['id' => $id]);

        $this->actingAs($this->user)->deleteJson("/api/v1/client-relationships/{$links[1]}")->assertNoContent();
        $this->assertSoftDeleted('related_people', ['id' => $id]);
        $this->actingAs($this->user)->getJson("/api/v1/related-people/{$id}")->assertNotFound();
    }

    public function test_deleting_a_person_removes_their_links(): void
    {
        $id = $this->addPerson();

        $this->actingAs($this->user)->deleteJson("/api/v1/related-people/{$id}")->assertNoContent();

        $this->assertSoftDeleted('related_people', ['id' => $id]);
        $this->assertSame([], $this->relationshipsOf($this->ana));
    }

    public function test_a_person_becomes_a_client_without_entering_anything_again(): void
    {
        $id = $this->addPerson(['email' => 'marko@example.com']);
        $petar = $this->client('Petar');
        $this->actingAs($this->user)->postJson("/api/v1/clients/{$petar->id}/relationships", [
            'related_person_id' => $id,
            'relationship_type' => 'sibling',
        ])->assertCreated();

        // A later GeoNames import moves the place; the converted client keeps the frozen copy.
        DB::table('places')->where('id', 3194360)->update(['latitude' => 10, 'timezone' => 'Europe/Paris']);

        $response = $this->actingAs($this->user)->postJson("/api/v1/related-people/{$id}/convert")->assertCreated();
        $clientId = $response->json('data.id');

        $response->assertJsonPath('data.full_name', 'Marko Petrović')
            ->assertJsonPath('data.email', 'marko@example.com')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.birth.birth_date', '1983-03-02')
            ->assertJsonPath('data.birth.birth_time', '06:15')
            ->assertJsonPath('data.birth.birth_place', 'Novi Sad, South Backa, Vojvodina')
            ->assertJsonPath('data.birth.latitude', 45.25167)
            ->assertJsonPath('data.birth.birth_timezone', 'Europe/Belgrade')
            ->assertJsonPath('data.birth.place_id', 3194360)
            ->assertJsonPath('data.birth.geocode_source', 'geonames');

        // Ana and Petar now see the new client where the person was.
        $fromAna = $this->relationshipsOf($this->ana);
        $this->assertSame(['client', $clientId, 'partner'], [$fromAna[0]['kind'], $fromAna[0]['party']['id'], $fromAna[0]['relationship_type']]);
        $this->assertSame('sibling', $this->relationshipsOf($petar)[0]['relationship_type']);

        // And the new client sees both of them, the other way round.
        $fromMarko = collect($this->relationshipsOf(Client::withoutGlobalScopes()->findOrFail($clientId)))
            ->mapWithKeys(fn ($link) => [$link['party']['full_name'] => $link['relationship_type']])->all();
        $this->assertSame(['Ana Marković' => 'partner', 'Petar Test' => 'sibling'], $fromMarko);

        $this->assertSoftDeleted('related_people', ['id' => $id, 'converted_client_id' => $clientId]);
        $this->assertTrue(ActivityEvent::withoutGlobalScopes()->where('client_id', $clientId)->where('event_type', 'client_created')->exists());

        // The chart comes out the same as before.
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$clientId}/chart")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.location.latitude', 45.25167);
    }

    public function test_the_person_is_shown_with_every_client_they_belong_with(): void
    {
        $person = RelatedPerson::factory()->relatedTo($this->ana, RelationshipType::Parent)->create(['first_name' => 'Vera']);

        $this->actingAs($this->user)->getJson("/api/v1/related-people/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Vera')
            ->assertJsonPath('data.birth', null)
            ->assertJsonPath('data.relationships.0.relationship_type', 'parent')
            ->assertJsonPath('data.relationships.0.client.id', $this->ana->id);
    }
}
