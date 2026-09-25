<?php

namespace Tests\Feature\Astrology;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Engines\FakeEngine;
use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\Services\TransitService;
use App\Astrology\Support\JulianDay;
use App\Astrology\ValueObjects\ChartRequest;
use App\Enums\CelestialBody;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Transits to a natal chart through the API, with the fake engine
 * (docs/spec/11, Phase 7a).
 */
class TransitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeEngine $engine;

    /** 5 October 2026, 12:00 in Belgrade. */
    private const AT = '2026-10-05T12:00';

    private const MOMENT = '2026-10-05 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
        $this->engine = app(EphemerisEngine::class);
    }

    /**
     * @param  array<string, mixed>  $birth
     */
    private function client(array $birth = []): int
    {
        return $this->actingAs($this->user)->postJson('/api/v1/clients', [
            'first_name' => 'Ana',
            'birth' => $birth + [
                'birth_date' => '1985-07-15',
                'birth_time' => '14:30',
                'time_accuracy' => 'exact',
                'birth_place' => 'Novi Sad',
                'birth_country_code' => 'RS',
                'latitude' => 45.25167,
                'longitude' => 19.83694,
                'birth_timezone' => 'Europe/Belgrade',
            ],
        ])->assertCreated()->json('data.id');
    }

    /** The natal longitude of a point, read from the natal chart. */
    private function natal(int $client, string $point): float
    {
        $chart = $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/chart")->assertOk()->json('data');

        return in_array($point, ['asc', 'mc'], true)
            ? $chart['angles'][$point]
            : collect($chart['positions'])->firstWhere('body', $point)['longitude'];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function contacts(int $client, string $query = '?at='.self::AT): Collection
    {
        return collect($this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/transits{$query}")
            ->assertOk()
            ->json('data.contacts'));
    }

    private static function momentJulianDay(): float
    {
        return JulianDay::fromMoment(CarbonImmutable::parse(self::MOMENT, 'UTC'));
    }

    public function test_transits_are_measured_against_the_natal_chart_with_the_dates_they_are_exact(): void
    {
        $client = $this->client();
        $sun = $this->natal($client, 'sun');

        // Saturn half a degree past the square to the natal Sun, moving on at 0.05° a day:
        // exact ten days before the moment, separating now.
        $this->engine->fix(CelestialBody::Saturn, $sun + 90.5, 0.05, self::momentJulianDay());

        $response = $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/transits?at=".self::AT)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.moment', '2026-10-05T10:00:00Z')
            ->assertJsonPath('data.timezone', 'Europe/Belgrade')
            ->assertJsonPath('data.zodiac_mode', 'tropical')
            ->assertJsonPath('data.search_days', TransitService::SEARCH_DAYS)
            ->assertJsonPath('data.engine.name', 'Fake engine')
            ->assertJsonPath('data.natal.status', 'ready')
            ->assertJsonPath('data.orbs.aspects.square.orb', 2)
            ->assertJsonCount(count(CelestialBody::natal()), 'data.positions');

        $saturn = collect($response->json('data.contacts'))->firstWhere(fn ($contact) => $contact['transit'] === 'saturn' && $contact['natal'] === 'sun');
        $this->assertSame('square', $saturn['type']);
        $this->assertEqualsWithDelta(0.5, $saturn['orb'], 1e-4);
        $this->assertFalse($saturn['applying']);
        $this->assertSame(['2026-09-25T10:00:00Z'], $saturn['exact']);

        // Every transiting planet stands in a natal house; fast planets get no exact dates.
        foreach ($response->json('data.positions') as $position) {
            $this->assertContains($position['house'], range(1, 12));
        }
        $this->assertTrue(collect($response->json('data.contacts'))
            ->filter(fn ($contact) => in_array($contact['transit'], ['sun', 'moon', 'mercury', 'venus', 'mars', 'true_node'], true))
            ->every(fn ($contact) => $contact['exact'] === null));

        // Closest first.
        $orbs = collect($response->json('data.contacts'))->pluck('orb')->all();
        $this->assertSame($orbs, collect($orbs)->sort()->values()->all());
    }

    public function test_the_workspace_transit_orbs_decide_what_counts(): void
    {
        $client = $this->client();
        $sun = $this->natal($client, 'sun');
        $this->engine->fix(CelestialBody::Jupiter, $sun + 121.4, 0.1, self::momentJulianDay());

        $trine = fn () => $this->contacts($client)->first(fn ($contact) => $contact['transit'] === 'jupiter' && $contact['natal'] === 'sun');
        $this->assertSame('trine', $trine()['type']);

        $this->actingAs($this->user)->patchJson('/api/v1/workspace', [
            'transit_orbs' => ['aspects' => ['trine' => ['enabled' => true, 'orb' => 1]], 'luminary_bonus' => 0],
        ])->assertOk();
        $this->assertNull($trine());

        // A bonus for the Sun and Moon brings it back.
        $this->actingAs($this->user)->patchJson('/api/v1/workspace', [
            'transit_orbs' => ['aspects' => ['trine' => ['enabled' => true, 'orb' => 1]], 'luminary_bonus' => 0.5],
        ])->assertOk();
        $this->assertSame('trine', $trine()['type']);
    }

    public function test_the_angles_take_transits_but_not_without_a_birth_time(): void
    {
        $timed = $this->client();
        $this->engine->fix(CelestialBody::Pluto, $this->natal($timed, 'asc') + 0.3, -0.01, self::momentJulianDay());

        $this->assertNotNull($this->contacts($timed)->first(fn ($contact) => $contact['transit'] === 'pluto' && $contact['natal'] === 'asc'));

        $untimed = $this->client(['time_accuracy' => 'unknown', 'birth_time' => null]);
        $response = $this->actingAs($this->user)->getJson("/api/v1/clients/{$untimed}/transits?at=".self::AT)->assertOk();

        $natalPoints = collect($response->json('data.contacts'))->pluck('natal')->unique();
        $this->assertNotContains('asc', $natalPoints);
        $this->assertNotContains('mc', $natalPoints);
        $this->assertNotContains('moon', $natalPoints);
        $this->assertNull($response->json('data.positions.0.house'));
    }

    public function test_without_a_moment_it_is_now_and_the_time_zone_can_be_given(): void
    {
        $client = $this->client();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:15:42', 'UTC'));

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/transits")
            ->assertJsonPath('data.moment', '2026-10-05T08:15:00Z');

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/transits?at=2026-10-05T12:00&timezone=America/New_York")
            ->assertJsonPath('data.moment', '2026-10-05T16:00:00Z')
            ->assertJsonPath('data.timezone', 'America/New_York');
    }

    public function test_one_engine_run_per_request_once_the_natal_chart_is_cached(): void
    {
        $client = $this->client();
        $this->natal($client, 'sun');
        $calls = $this->engine->calls;

        $this->contacts($client);

        $this->assertSame($calls + 1, $this->engine->calls);
    }

    public function test_invalid_moments_are_rejected(): void
    {
        $client = $this->client();

        foreach (['5 October', '1800-06-01T12:00', '2399-06-01T12:00'] as $at) {
            $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/transits?at={$at}")
                ->assertUnprocessable()->assertJsonValidationErrors('at');
        }

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/transits?timezone=Mars/Olympus")
            ->assertUnprocessable()->assertJsonValidationErrors('timezone');
    }

    public function test_incomplete_birth_data_says_what_is_missing(): void
    {
        $client = $this->actingAs($this->user)->postJson('/api/v1/clients', ['first_name' => 'Nobody'])->json('data.id');

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/transits")
            ->assertOk()
            ->assertJsonPath('data.status', 'incomplete')
            ->assertJsonPath('data.missing', ['birth_date', 'birth_time', 'location', 'timezone']);
    }

    public function test_an_engine_failure_is_a_503_without_its_details(): void
    {
        $client = $this->client();
        $this->natal($client, 'sun');

        $this->app->instance(EphemerisEngine::class, new class extends FakeEngine
        {
            public function series(ChartRequest $request, int $steps, float $stepDays = 1.0): array
            {
                throw new EphemerisException('swetest: ephemeris file seas_18.se1 not found');
            }
        });

        $response = $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/transits")->assertServiceUnavailable();
        $this->assertStringNotContainsString('swetest', $response->getContent());
    }

    public function test_a_related_person_has_transits_too_and_another_workspace_sees_none(): void
    {
        $client = $this->client();
        $person = $this->actingAs($this->user)->postJson('/api/v1/related-people', [
            'client_id' => $client,
            'relationship_type' => 'partner',
            'first_name' => 'Marko',
            'birth' => [
                'birth_date' => '1983-02-01', 'birth_time' => '08:00', 'time_accuracy' => 'exact',
                'birth_place' => 'Beograd', 'birth_country_code' => 'RS',
                'latitude' => 44.8, 'longitude' => 20.47, 'birth_timezone' => 'Europe/Belgrade',
            ],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->getJson("/api/v1/related-people/{$person}/transits?at=".self::AT)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.natal.status', 'ready');

        $stranger = User::factory()->withWorkspace()->create();
        $this->actingAs($stranger)->getJson("/api/v1/clients/{$client}/transits")->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/v1/related-people/{$person}/transits")->assertNotFound();
    }
}
