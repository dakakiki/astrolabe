<?php

namespace Tests\Feature\Astrology;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Engines\FakeEngine;
use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\ChartResult;
use App\Enums\CelestialBody;
use App\Models\ChartCalculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The natal chart through the API, with the fake engine (docs/spec/11).
 */
class NatalChartTest extends TestCase
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

    public function test_the_chart_lists_every_body_and_says_what_produced_it(): void
    {
        $this->engine->fix(CelestialBody::Saturn, 231.55, -0.0166);
        $id = $this->client();

        $response = $this->actingAs($this->user)->getJson("/api/v1/clients/{$id}/chart")->assertOk();

        $response->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.chart_type', 'natal')
            ->assertJsonPath('data.zodiac_mode', 'tropical')
            // 14:30 Belgrade summer time is 12:30 UT.
            ->assertJsonPath('data.julian_day_ut', 2446262.02083333)
            ->assertJsonPath('data.engine.name', 'Fake engine')
            ->assertJsonPath('data.engine.version', '1')
            ->assertJsonPath('data.engine.tzdata', timezone_version_get())
            ->assertJsonPath('data.moon_range', null);

        $positions = collect($response->json('data.positions'))->keyBy('body');
        $this->assertSame(array_map(fn ($body) => $body->value, CelestialBody::natal()), $positions->keys()->all());
        $this->assertTrue($positions['saturn']['retrograde']);
        $this->assertSame(231.55, $positions['saturn']['longitude']);
    }

    public function test_the_same_input_is_calculated_once(): void
    {
        $id = $this->client();

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$id}/chart")->assertOk();
        $this->actingAs($this->user)->getJson("/api/v1/clients/{$id}/chart")->assertOk();

        $this->assertSame(1, $this->engine->calls);
        $this->assertSame(1, ChartCalculation::withoutGlobalScopes()->count());
    }

    public function test_a_changed_birth_time_gives_a_new_chart_and_keeps_the_old_one(): void
    {
        $id = $this->client();
        $first = $this->actingAs($this->user)->getJson("/api/v1/clients/{$id}/chart")->json('data.id');

        $this->actingAs($this->user)->putJson("/api/v1/clients/{$id}/birth-details", [
            'birth_date' => '1985-07-15',
            'birth_time' => '14:42',
            'time_accuracy' => 'rectified',
            'birth_place' => 'Novi Sad',
            'latitude' => 45.25167,
            'longitude' => 19.83694,
            'birth_timezone' => 'Europe/Belgrade',
        ])->assertOk();

        $second = $this->actingAs($this->user)->getJson("/api/v1/clients/{$id}/chart")
            ->assertJsonPath('data.time_accuracy', 'rectified')
            ->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, ChartCalculation::withoutGlobalScopes()->count());
    }

    public function test_the_workspace_zodiac_shapes_the_chart(): void
    {
        $id = $this->client();
        $tropical = collect($this->actingAs($this->user)->getJson("/api/v1/clients/{$id}/chart")->json('data.positions'))->keyBy('body');

        $this->actingAs($this->user)->patchJson('/api/v1/workspace', [
            'default_house_system' => 'whole_sign',
            'default_zodiac_mode' => 'sidereal',
            'default_ayanamsa' => 'lahiri',
        ])->assertOk();

        $response = $this->actingAs($this->user)->getJson("/api/v1/clients/{$id}/chart")
            ->assertJsonPath('data.zodiac_mode', 'sidereal')
            ->assertJsonPath('data.ayanamsa', 'lahiri');
        $sidereal = collect($response->json('data.positions'))->keyBy('body');

        $this->assertEqualsWithDelta(FakeEngine::AYANAMSA, $tropical['sun']['longitude'] - $sidereal['sun']['longitude'], 1e-6);
    }

    public function test_an_unknown_time_uses_noon_ut_and_gives_the_moon_as_a_range(): void
    {
        $id = $this->client(['birth_time' => null, 'time_accuracy' => 'unknown']);

        $response = $this->actingAs($this->user)->getJson("/api/v1/clients/{$id}/chart")
            ->assertJsonPath('data.time_accuracy', 'unknown');
        $this->assertEqualsWithDelta(2446262.0, $response->json('data.julian_day_ut'), 1e-9);

        // Local midnight to midnight in Belgrade: 24 hours of lunar motion.
        $range = $response->json('data.moon_range');
        $span = fmod($range['to'] - $range['from'] + 360, 360);
        $this->assertEqualsWithDelta(13.176396, $span, 1e-6);
    }

    public function test_incomplete_birth_data_says_what_is_missing(): void
    {
        $id = $this->actingAs($this->user)->postJson('/api/v1/clients', [
            'first_name' => 'Lena',
            'birth' => ['birth_date' => '1990-01-10', 'birth_time' => '10:00', 'time_accuracy' => 'exact'],
        ])->json('data.id');

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$id}/chart")
            ->assertOk()
            ->assertJsonPath('data.status', 'incomplete')
            ->assertJsonPath('data.missing', ['location', 'timezone']);

        $this->assertSame(0, $this->engine->calls);
    }

    public function test_an_engine_failure_is_reported_without_details(): void
    {
        $id = $this->client();

        $this->app->instance(EphemerisEngine::class, new class extends FakeEngine
        {
            public function calculate(ChartRequest $request): ChartResult
            {
                throw new EphemerisException('swetest not found at /secret/path');
            }
        });

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$id}/chart")
            ->assertStatus(503)
            ->assertJsonPath('message', __('charts.unavailable'))
            ->assertDontSee('secret');
    }

    public function test_another_workspaces_client_chart_is_not_found(): void
    {
        $id = $this->client();
        $stranger = User::factory()->withWorkspace()->create();

        $this->actingAs($stranger)->getJson("/api/v1/clients/{$id}/chart")->assertNotFound();
        $this->assertSame(0, $this->engine->calls);
    }
}
