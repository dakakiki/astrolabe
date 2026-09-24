<?php

namespace Tests\Feature\Astrology;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Engines\FakeEngine;
use App\Enums\CelestialBody;
use App\Models\ChartCalculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Angles, houses and aspects through the API, with the fake engine
 * (docs/spec/11, Phase 5). Accuracy against independent formulas lives in
 * HouseAccuracyTest, which needs the real engine.
 */
class FullNatalChartTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withWorkspace()->create();
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

    private function chart(int $client, string $query = ''): array
    {
        return $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/chart{$query}")->assertOk()->json('data');
    }

    public function test_a_chart_with_a_birth_time_has_angles_houses_and_aspects(): void
    {
        $chart = $this->chart($this->client());

        $this->assertSame(2, $chart['version']);
        $this->assertSame('placidus', $chart['house_system']);
        $this->assertSame(['system' => 'placidus', 'requested_system' => 'placidus'], array_intersect_key($chart['houses'], ['system' => 0, 'requested_system' => 0]));
        $this->assertCount(12, $chart['houses']['cusps']);
        $this->assertSame(['latitude' => 45.25167, 'longitude' => 19.83694], $chart['location']);

        // The first and tenth cusps of a quadrant system are the Ascendant and Midheaven.
        $this->assertEqualsWithDelta($chart['angles']['asc'], $chart['houses']['cusps'][0], 1e-6);
        $this->assertEqualsWithDelta($chart['angles']['mc'], $chart['houses']['cusps'][9], 1e-6);
        $this->assertEqualsWithDelta(fmod($chart['angles']['asc'] + 180, 360), $chart['angles']['dsc'], 1e-6);
        $this->assertEqualsWithDelta(fmod($chart['angles']['mc'] + 180, 360), $chart['angles']['ic'], 1e-6);

        foreach ($chart['positions'] as $position) {
            $this->assertThat($position['house'], $this->logicalAnd($this->greaterThanOrEqual(1), $this->lessThanOrEqual(12)));
        }

        $this->assertIsArray($chart['aspects']);
        $this->assertSame(['aspects', 'luminary_bonus'], array_keys($chart['aspect_settings']));
    }

    public function test_aspects_follow_the_positions_and_include_the_angles(): void
    {
        $this->engine
            ->fixAngles(ascendant: 100.0, midheaven: 10.0)
            ->fix(CelestialBody::Sun, 10.0, 1.0)
            ->fix(CelestialBody::Moon, 102.5, 13.0)
            ->fix(CelestialBody::Mars, 190.0, 0.6);

        $aspects = collect($this->chart($this->client())['aspects'])
            ->keyBy(fn (array $aspect) => "{$aspect['a']}-{$aspect['b']}");

        // Sun square Moon: 92.5° apart, the Moon moving away from exact.
        $this->assertSame('square', $aspects['sun-moon']['type']);
        $this->assertEqualsWithDelta(2.5, $aspects['sun-moon']['orb'], 1e-6);
        $this->assertFalse($aspects['sun-moon']['applying']);

        $this->assertSame('opposition', $aspects['sun-mars']['type']);
        $this->assertSame('conjunction', $aspects['sun-mc']['type']);
        $this->assertNull($aspects['sun-mc']['applying']);
        $this->assertSame('conjunction', $aspects['moon-asc']['type']);
        $this->assertArrayNotHasKey('asc-mc', $aspects->all());
        $this->assertEmpty($aspects->filter(fn (array $aspect) => in_array('mean_node', [$aspect['a'], $aspect['b']], true)));
    }

    public function test_an_unknown_time_has_no_angles_houses_or_moon_aspects(): void
    {
        $this->engine->fix(CelestialBody::Sun, 10.0, 1.0)->fix(CelestialBody::Moon, 12.0, 13.0);

        $chart = $this->chart($this->client(['birth_time' => null, 'time_accuracy' => 'unknown']));

        $this->assertNull($chart['house_system']);
        $this->assertNull($chart['houses']);
        $this->assertNull($chart['angles']);
        $this->assertSame([null], array_values(array_unique(array_column($chart['positions'], 'house'))));
        $this->assertNotNull($chart['moon_range']);
        $this->assertEmpty(array_filter($chart['aspects'], fn (array $aspect) => in_array('moon', [$aspect['a'], $aspect['b']], true)));
    }

    public function test_an_approximate_time_still_gets_the_full_chart(): void
    {
        $chart = $this->chart($this->client(['time_accuracy' => 'approximate']));

        $this->assertSame('approximate', $chart['time_accuracy']);
        $this->assertNotNull($chart['angles']);
        $this->assertCount(12, $chart['houses']['cusps']);
    }

    public function test_another_house_system_is_its_own_cached_calculation(): void
    {
        $client = $this->client();

        $placidus = $this->chart($client);
        $wholeSign = $this->chart($client, '?house_system=whole_sign');
        $again = $this->chart($client, '?house_system=whole_sign');

        $this->assertSame('whole_sign', $wholeSign['house_system']);
        $this->assertNotSame($placidus['id'], $wholeSign['id']);
        $this->assertSame($wholeSign['id'], $again['id']);
        $this->assertSame(2, $this->engine->calls);

        // Whole Sign: every cusp at 0° of a sign, the first one the rising sign.
        $this->assertEqualsWithDelta(floor($wholeSign['angles']['asc'] / 30) * 30, $wholeSign['houses']['cusps'][0], 1e-9);
        foreach ($wholeSign['houses']['cusps'] as $cusp) {
            $this->assertEqualsWithDelta(0.0, fmod($cusp, 30), 1e-9);
        }

        // The workspace default is untouched.
        $this->assertSame($placidus['id'], $this->chart($client)['id']);
        $this->actingAs($this->user)->getJson('/api/v1/workspace')->assertJsonPath('data.default_house_system', 'placidus');

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}/chart?house_system=made_up")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('house_system');
    }

    public function test_above_the_polar_circle_placidus_falls_back_to_porphyry(): void
    {
        // Tromsø, 69.65°N.
        $client = $this->client([
            'birth_place' => 'Tromsø', 'birth_country_code' => 'NO',
            'latitude' => 69.6496, 'longitude' => 18.956, 'birth_timezone' => 'Europe/Oslo',
        ]);

        $chart = $this->chart($client);
        $this->assertSame('placidus', $chart['house_system']);
        $this->assertSame('placidus', $chart['houses']['requested_system']);
        $this->assertSame('porphyry', $chart['houses']['system']);
        $this->assertSame(69.6496, $chart['location']['latitude']);

        // Systems that work there are drawn as asked.
        $this->assertSame('equal', $this->chart($client, '?house_system=equal')['houses']['system']);
    }

    public function test_the_workspace_orbs_shape_the_aspects_and_start_a_new_calculation(): void
    {
        $this->engine->fix(CelestialBody::Mars, 10.0, 0.5)->fix(CelestialBody::Jupiter, 17.0, 0.1);
        $client = $this->client();
        $pair = fn (array $chart) => collect($chart['aspects'])->first(fn (array $aspect) => $aspect['a'] === 'mars' && $aspect['b'] === 'jupiter');

        $before = $this->chart($client);
        $this->assertSame('conjunction', $pair($before)['type']);

        $this->actingAs($this->user)->patchJson('/api/v1/workspace', [
            'aspect_orbs' => ['aspects' => ['conjunction' => ['enabled' => true, 'orb' => 6]], 'luminary_bonus' => 1.5],
        ])->assertOk();

        $after = $this->chart($client);
        $this->assertNotSame($before['id'], $after['id']);
        $this->assertNull($pair($after));
        $this->assertSame(6.0, (float) $after['aspect_settings']['aspects']['conjunction']['orb']);
        $this->assertSame(2, ChartCalculation::withoutGlobalScopes()->count());
    }

    public function test_a_chart_stored_before_houses_existed_is_not_served_as_the_current_one(): void
    {
        $client = $this->client();
        $old = ChartCalculation::withoutGlobalScopes()->forceCreate([
            'workspace_id' => $this->user->current_workspace_id,
            'subject_type' => 'client',
            'subject_id' => $client,
            'chart_type' => 'natal',
            'input_hash' => str_repeat('a', 64),
            'julian_day_ut' => 2446262.02083333,
            'house_system' => 'placidus',
            'zodiac_mode' => 'tropical',
            'time_accuracy' => 'exact',
            'payload' => ['positions' => [['body' => 'sun', 'longitude' => 112.95, 'speed' => 0.95, 'retrograde' => false]]],
            'engine_name' => 'Fake engine',
            'engine_version' => '1',
            'calculated_at' => now()->subDay(),
        ]);

        $chart = $this->chart($client);

        $this->assertNotSame($old->id, $chart['id']);
        $this->assertSame(2, $chart['version']);
    }

    public function test_the_clock_change_day_counts_one_hour_between_1_30_and_3_30(): void
    {
        // Belgrade moved from CET to CEST at 02:00 on 31 March 1985.
        $before = $this->chart($this->client(['birth_date' => '1985-03-31', 'birth_time' => '01:30']));
        $after = $this->chart($this->client(['birth_date' => '1985-03-31', 'birth_time' => '03:30']));

        $this->assertEqualsWithDelta(2446155.52083333, $before['julian_day_ut'], 1e-8); // 00:30 UT
        $this->assertEqualsWithDelta(1 / 24, $after['julian_day_ut'] - $before['julian_day_ut'], 1e-8);
    }

    public function test_an_ambiguous_time_on_the_fall_back_night_is_read_as_standard_time_and_flagged(): void
    {
        // 02:30 happened twice on 29 September 1985 in Belgrade.
        $client = $this->client(['birth_date' => '1985-09-29', 'birth_time' => '02:30']);

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$client}")
            ->assertJsonPath('data.birth.clock_change', 'ambiguous');

        // 02:30 CET is 01:30 UT.
        $this->assertEqualsWithDelta(2446337.5625, $this->chart($client)['julian_day_ut'], 1e-8);
    }
}
