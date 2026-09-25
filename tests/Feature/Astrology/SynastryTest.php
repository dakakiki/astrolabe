<?php

namespace Tests\Feature\Astrology;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Engines\FakeEngine;
use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\ChartResult;
use App\Enums\CelestialBody;
use App\Models\Client;
use App\Models\RelatedPerson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Synastry and the composite chart through the API, with the fake engine
 * (docs/spec/11, Phase 7e): a client compared with a related person or another
 * client, from the two cached natal charts.
 */
class SynastryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeEngine $engine;

    private const BIRTH = [
        'birth_date' => '1985-07-15',
        'birth_time' => '14:30',
        'time_accuracy' => 'exact',
        'birth_place' => 'Novi Sad',
        'birth_country_code' => 'RS',
        'latitude' => 45.25167,
        'longitude' => 19.83694,
        'birth_timezone' => 'Europe/Belgrade',
    ];

    private const OTHER_BIRTH = [
        'birth_date' => '1983-02-01',
        'birth_time' => '08:00',
        'time_accuracy' => 'exact',
        'birth_place' => 'Beograd',
        'birth_country_code' => 'RS',
        'latitude' => 44.8,
        'longitude' => 20.47,
        'birth_timezone' => 'Europe/Belgrade',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
        $this->engine = app(EphemerisEngine::class);

        // The same Venus and Mars in every chart: Venus on Venus, Venus trine Mars (0.5°).
        $this->engine->fix(CelestialBody::Venus, 100.0, 1.2);
        $this->engine->fix(CelestialBody::Mars, 220.5, 0.6);
    }

    /**
     * @param  array<string, mixed>|null  $birth
     */
    private function client(string $name = 'Ana', ?array $birth = self::BIRTH): int
    {
        return $this->actingAs($this->user)->postJson('/api/v1/clients', array_filter([
            'first_name' => $name,
            'birth' => $birth,
        ]))->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>|null  $birth
     */
    private function person(int $client, ?array $birth = self::OTHER_BIRTH): int
    {
        return $this->actingAs($this->user)->postJson('/api/v1/related-people', array_filter([
            'client_id' => $client,
            'relationship_type' => 'partner',
            'first_name' => 'Marko',
            'last_name' => 'Petrović',
            'birth' => $birth,
        ]))->assertCreated()->json('data.id');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function contacts(array $data): Collection
    {
        return collect($data['contacts']);
    }

    public function test_a_client_is_compared_with_a_related_person(): void
    {
        $client = $this->client();
        $person = $this->person($client);

        $data = $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/synastry?with_person={$person}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.client.kind', 'client')
            ->assertJsonPath('data.client.id', $client)
            ->assertJsonPath('data.client.chart.status', 'ready')
            ->assertJsonPath('data.other.kind', 'person')
            ->assertJsonPath('data.other.id', $person)
            ->assertJsonPath('data.other.full_name', 'Marko Petrović')
            ->assertJsonPath('data.other.chart.status', 'ready')
            ->assertJsonPath('data.zodiac_mode', 'tropical')
            // The practice's natal orbs, not the transit ones.
            ->assertJsonPath('data.orbs.aspects.conjunction.orb', 8)
            ->json('data');

        // The other person's point first; Venus on Venus is exact, Venus trine Mars 0.5°.
        $venus = self::contacts($data)->firstWhere(fn ($contact) => $contact['a'] === 'venus' && $contact['b'] === 'venus');
        $this->assertSame(['type' => 'conjunction', 'orb' => 0], ['type' => $venus['type'], 'orb' => $venus['orb']]);
        $trine = self::contacts($data)->firstWhere(fn ($contact) => $contact['a'] === 'venus' && $contact['b'] === 'mars');
        $this->assertSame('trine', $trine['type']);
        $this->assertEqualsWithDelta(0.5, $trine['orb'], 1e-4);
        $this->assertArrayNotHasKey('applying', $trine);

        // Closest first, and angles on both sides with a known birth time.
        $orbs = self::contacts($data)->pluck('orb')->all();
        $this->assertSame($orbs, collect($orbs)->sort()->values()->all());
        $this->assertTrue(self::contacts($data)->contains(fn ($contact) => in_array($contact['a'], ['asc', 'mc'], true))
            || self::contacts($data)->contains(fn ($contact) => in_array($contact['b'], ['asc', 'mc'], true)));

        // Each person's points in the other's houses.
        foreach (['other_in_client', 'client_in_other'] as $overlay) {
            $this->assertSame(['sun', 'moon', 'mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune', 'pluto', 'chiron', 'true_node', 'mean_node', 'asc', 'mc'], array_keys($data['overlays'][$overlay]));
            foreach ($data['overlays'][$overlay] as $house) {
                $this->assertContains($house, range(1, 12));
            }
        }

        // The composite: every body halfway between, in its houses, with its own aspects.
        $composite = $data['composite'];
        $this->assertCount(count(CelestialBody::natal()), $composite['positions']);
        $this->assertEqualsWithDelta(100.0, collect($composite['positions'])->firstWhere('body', 'venus')['longitude'], 1e-6);
        $this->assertCount(12, $composite['houses']['cusps']);
        $this->assertSame('midpoint_cusps', $composite['houses']['method']);
        $this->assertNotNull($composite['angles']['asc']);
        $this->assertNull($composite['moon_range']);
        $this->assertSame('exact', $composite['time_accuracy']);
        $compositeTrine = collect($composite['aspects'])->firstWhere(fn ($aspect) => $aspect['a'] === 'venus' && $aspect['b'] === 'mars');
        $this->assertSame('trine', $compositeTrine['type']);
        $this->assertNull($compositeTrine['applying']);
    }

    public function test_a_client_is_compared_with_another_client_but_never_with_itself(): void
    {
        $ana = $this->client();
        $luka = $this->client('Luka', self::OTHER_BIRTH);

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$ana}/synastry?with_client={$luka}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.other.kind', 'client')
            ->assertJsonPath('data.other.full_name', 'Luka');

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$ana}/synastry?with_client={$ana}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('with_client');
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$ana}/synastry")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['with_person', 'with_client']);
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$ana}/synastry?with_client={$luka}&with_person=1")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('with_person');
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$ana}/synastry?with_person=999999")->assertNotFound();
    }

    public function test_an_unknown_birth_time_leaves_out_that_persons_angles_moon_and_houses(): void
    {
        $client = $this->client();
        $person = $this->person($client, ['time_accuracy' => 'unknown', 'birth_time' => null] + self::OTHER_BIRTH);

        $data = $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/synastry?with_person={$person}")
            ->assertOk()
            ->assertJsonPath('data.other.chart.time_accuracy', 'unknown')
            ->json('data');

        $theirs = self::contacts($data)->pluck('a')->unique();
        $this->assertNotContains('moon', $theirs);
        $this->assertNotContains('asc', $theirs);
        $this->assertNotContains('mc', $theirs);
        // The client's own angles still take contacts.
        $this->assertTrue(self::contacts($data)->contains(fn ($contact) => in_array($contact['b'], ['asc', 'mc'], true)));

        // They have no houses to fall into, and no single house for their Moon.
        $this->assertNull($data['overlays']['client_in_other']);
        $this->assertArrayNotHasKey('moon', $data['overlays']['other_in_client']);
        $this->assertArrayNotHasKey('asc', $data['overlays']['other_in_client']);

        // Nor has the composite; its Moon is a span, left out of its aspects.
        $composite = $data['composite'];
        $this->assertSame('unknown', $composite['time_accuracy']);
        $this->assertNull($composite['angles']);
        $this->assertNull($composite['houses']);
        $this->assertNotNull($composite['moon_range']);
        $this->assertFalse(collect($composite['aspects'])->contains(fn ($aspect) => $aspect['a'] === 'moon' || $aspect['b'] === 'moon'));
    }

    public function test_incomplete_birth_data_says_whose_and_what_is_missing(): void
    {
        $client = $this->client();
        $person = $this->person($client, null);

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/synastry?with_person={$person}")
            ->assertOk()
            ->assertJsonPath('data.status', 'incomplete')
            ->assertJsonPath('data.side', 'other')
            ->assertJsonPath('data.other.full_name', 'Marko Petrović')
            ->assertJsonPath('data.missing', ['birth_date', 'birth_time', 'location', 'timezone']);

        $nobody = $this->client('Nobody', null);
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$nobody}/synastry?with_client={$client}")
            ->assertOk()
            ->assertJsonPath('data.status', 'incomplete')
            ->assertJsonPath('data.side', 'client');
    }

    public function test_the_practices_natal_orbs_decide_what_counts(): void
    {
        $client = $this->client();
        $person = $this->person($client);
        $trines = fn () => collect($this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/synastry?with_person={$person}")
            ->assertOk()->json('data.contacts'))->where('type', 'trine')->where('a', 'venus')->where('b', 'mars');

        $this->assertCount(1, $trines());

        $this->actingAs($this->user)->patchJson('/api/v1/workspace', [
            'aspect_orbs' => ['aspects' => ['trine' => ['enabled' => true, 'orb' => 0.4]], 'luminary_bonus' => 1.5],
        ])->assertOk();

        $this->assertCount(0, $trines());
    }

    public function test_comparing_cached_charts_runs_no_engine(): void
    {
        $client = $this->client();
        $person = $this->person($client);

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/synastry?with_person={$person}")->assertOk();
        $calls = $this->engine->calls;

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/synastry?with_person={$person}")->assertOk();
        $this->assertSame($calls, $this->engine->calls);
    }

    public function test_an_engine_failure_is_a_503_without_its_details(): void
    {
        $client = $this->client();
        $person = $this->person($client);

        $this->app->instance(EphemerisEngine::class, new class extends FakeEngine
        {
            public function calculate(ChartRequest $request): ChartResult
            {
                throw new EphemerisException('swetest: ephemeris file sepl_18.se1 not found');
            }
        });

        $response = $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/synastry?with_person={$person}")
            ->assertServiceUnavailable();
        $this->assertStringNotContainsString('swetest', $response->getContent());
    }

    public function test_nobody_from_another_workspace_can_be_compared_or_compare(): void
    {
        $client = $this->client();
        $person = $this->person($client);

        $stranger = User::factory()->withWorkspace()->create();
        $strangersClient = Client::factory()->inWorkspace($stranger->current_workspace_id)->create();
        $strangersPerson = RelatedPerson::factory()->relatedTo($strangersClient)->create();

        $this->actingAs($stranger)->getJson("/api/v1/clients/{$client}/synastry?with_person={$person}")->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/v1/clients/{$strangersClient->id}/synastry?with_client={$client}")->assertNotFound();

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/synastry?with_person={$strangersPerson->id}")->assertNotFound();
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/synastry?with_client={$strangersClient->id}")->assertNotFound();
    }
}
