<?php

namespace Tests\Unit;

use App\Astrology\Services\TransitService;
use App\Astrology\Support\JulianDay;
use App\Astrology\ValueObjects\Houses;
use App\Enums\AspectType;
use PHPUnit\Framework\TestCase;

/**
 * When a slow transit is exact, found from daily positions (docs/spec/11,
 * Phase 7a): crossings of the aspect's angle by the signed distance,
 * interpolated between days, including retrograde loops and the 0°/360° seam.
 */
class TransitSearchTest extends TestCase
{
    private const START = 2461000.5;

    /**
     * @return list<float> one longitude per day
     */
    private static function moving(float $from, float $perDay, int $days): array
    {
        return array_map(fn (int $day) => fmod($from + $perDay * $day + 720, 360), range(0, $days - 1));
    }

    public function test_a_steady_transit_is_exact_where_it_crosses_the_angle(): void
    {
        // 0.1° a day from 10°: at 20.05° after 100.5 days.
        $found = TransitService::exactDays(self::moving(10, 0.1, 200), 20.05, AspectType::Conjunction, self::START);

        $this->assertCount(1, $found);
        $this->assertEqualsWithDelta(self::START + 100.5, $found[0], 1e-9);
    }

    public function test_a_square_is_found_on_either_side_of_the_natal_point(): void
    {
        // From 250° on at 0.6° a day: 280° (natal 10° − 90°) on day 50, the conjunction at
        // 10° on day 200 (not a square), 100° (natal + 90°) on day 350.
        $longitudes = self::moving(250, 0.6, 400);
        $found = TransitService::exactDays($longitudes, 10, AspectType::Square, self::START);

        $this->assertCount(2, $found);
        $this->assertEqualsWithDelta(self::START + 50, $found[0], 1e-6);
        $this->assertEqualsWithDelta(self::START + 350, $found[1], 1e-6);
    }

    public function test_a_retrograde_loop_is_exact_three_times(): void
    {
        // Forward past 100°, back over it while retrograde, forward again.
        $longitudes = [];
        foreach (range(0, 399) as $day) {
            $longitudes[] = 100 + 2 * sin($day / 50) - 0.001 * $day;
        }

        $found = TransitService::exactDays($longitudes, 100.5, AspectType::Conjunction, self::START);

        $this->assertCount(3, $found);
        $this->assertTrue($found[0] < $found[1] && $found[1] < $found[2]);
    }

    public function test_the_opposition_and_the_seam_at_zero_aries_are_no_trouble(): void
    {
        // Moving across 0° Aries toward the natal point's opposite at 2°.
        $found = TransitService::exactDays(self::moving(355, 0.25, 60), 182, AspectType::Opposition, self::START);
        $this->assertCount(1, $found);
        $this->assertEqualsWithDelta(self::START + 28, $found[0], 1e-6);

        // A conjunction across 0° Aries.
        $found = TransitService::exactDays(self::moving(355, 0.25, 60), 0.5, AspectType::Conjunction, self::START);
        $this->assertEqualsWithDelta(self::START + 22, $found[0], 1e-6);

        // Passing opposite the natal point flips the signed distance from +180° to −180°:
        // that seam is not a conjunction.
        $this->assertSame([], TransitService::exactDays(self::moving(355, 0.25, 60), 176, AspectType::Conjunction, self::START));
    }

    public function test_julian_days_turn_back_into_moments(): void
    {
        $this->assertSame('2000-01-01T12:00:00Z', JulianDay::toMoment(2451545.0)->toIso8601ZuluString());
        $this->assertSame('2026-09-25T10:00:00Z', JulianDay::toMoment(2461308.9166667)->toIso8601ZuluString());
    }

    public function test_a_longitude_is_placed_in_the_houses_of_a_stored_chart(): void
    {
        $cusps = array_map(fn (int $i) => fmod(350 + 30 * $i, 360), range(0, 11));

        $this->assertSame(1, Houses::numberFor($cusps, 355));
        $this->assertSame(1, Houses::numberFor($cusps, 10));
        $this->assertSame(2, Houses::numberFor($cusps, 20));
        $this->assertSame(12, Houses::numberFor($cusps, 349.9));
    }
}
