<?php

namespace Tests\Feature\Astrology;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Engines\FakeEngine;
use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\ValueObjects\ChartRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sky calendar through the API, with the fake engine (docs/spec/02,
 * "Nebo"; Phase 7d). The fake planets keep their mean motion, so there are
 * aspects, ingresses and lunations here but no stations.
 */
class SkyCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-06 09:30:00');
        $this->user = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
    }

    public function test_a_month_of_the_sky_on_the_astrologers_clock(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/sky?from=2026-10-05')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-10-05')
            ->assertJsonPath('data.days', 30)
            ->assertJsonPath('data.timezone', 'Europe/Belgrade')
            ->assertJsonPath('data.start', '2026-10-04T22:00:00Z')
            ->assertJsonPath('data.end', '2026-11-03T23:00:00Z')
            ->assertJsonPath('data.zodiac_mode', 'tropical')
            ->assertJsonPath('data.arc_days', 365)
            ->assertJsonPath('data.engine.name', 'Fake engine')
            ->assertJsonPath('data.positions_at', '2026-10-06T09:30:00Z')
            ->assertJsonCount(11, 'data.positions');

        $events = $response->json('data.events');
        $moments = array_column($events, 'at');
        $sorted = $moments;
        sort($sorted);

        $this->assertNotEmpty($events);
        $this->assertSame($sorted, $moments, 'earliest first');
        $this->assertGreaterThanOrEqual('2026-10-04T22:00:00Z', $moments[0]);
        $this->assertLessThan('2026-11-03T23:00:00Z', end($moments));
        $this->assertEqualsCanonicalizing(['aspect', 'ingress', 'lunation'], array_values(array_unique(array_column($events, 'type'))));

        $sun = collect($events)->firstWhere(fn (array $event) => $event['type'] === 'ingress' && $event['body'] === 'sun');
        $this->assertSame(['scorpio', false], [$sun['sign'], $sun['retrograde']]);
        $this->assertStringStartsWith('2026-10-21T', $sun['at']);

        $aspect = collect($events)->firstWhere('type', 'aspect');
        $this->assertArrayHasKey('aspect', $aspect);
        $this->assertCount(2, $aspect['bodies']);
        $this->assertArrayNotHasKey('jd', $aspect);
        $this->assertArrayNotHasKey('pair', $aspect);
    }

    public function test_signs_follow_the_workspaces_zodiac(): void
    {
        $this->user->currentWorkspace->forceFill(['default_zodiac_mode' => 'sidereal', 'default_ayanamsa' => 'lahiri'])->save();

        $events = $this->actingAs($this->user)->getJson('/api/v1/sky?from=2026-10-05')
            ->assertOk()
            ->assertJsonPath('data.zodiac_mode', 'sidereal')
            ->json('data.events');

        $sun = collect($events)->firstWhere(fn (array $event) => $event['type'] === 'ingress' && $event['body'] === 'sun');
        $this->assertSame('libra', $sun['sign']);
        $this->assertStringStartsWith('2026-10-15T', $sun['at']);
    }

    public function test_today_by_default_and_only_the_offered_periods(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/sky?days=7')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-10-06')
            ->assertJsonPath('data.start', '2026-10-05T22:00:00Z')
            ->assertJsonPath('data.end', '2026-10-12T22:00:00Z');

        $this->actingAs($this->user)->getJson('/api/v1/sky?days=8&from=1700-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['days', 'from']);

        $this->actingAs($this->user)->getJson('/api/v1/sky?days=365&from=2026-01-01')
            ->assertOk()
            ->assertJsonPath('data.positions_at', '2026-10-06T09:30:00Z');

        // Outside the period, positions are those at its start.
        $this->actingAs($this->user)->getJson('/api/v1/sky?days=7&from=2027-03-01&timezone=America/New_York')
            ->assertOk()
            ->assertJsonPath('data.positions_at', '2027-03-01T05:00:00Z');
    }

    public function test_it_needs_a_verified_user_and_says_little_when_the_engine_fails(): void
    {
        $this->getJson('/api/v1/sky')->assertUnauthorized();

        $this->app->instance(EphemerisEngine::class, new class extends FakeEngine
        {
            public function series(ChartRequest $request, int $steps, float $stepDays = 1.0): array
            {
                throw new EphemerisException('swetest: ephemeris file sepl_18.se1 not found');
            }
        });

        $response = $this->actingAs($this->user)->getJson('/api/v1/sky')->assertServiceUnavailable();
        $this->assertStringNotContainsString('swetest', $response->getContent());
    }
}
