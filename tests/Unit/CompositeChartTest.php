<?php

namespace Tests\Unit;

use App\Astrology\Services\CompositeChart;
use App\Enums\TimeAccuracy;
use App\Models\ChartCalculation;
use Tests\TestCase;

/**
 * The composite chart on made-up charts (docs/spec/11, Phase 7e): midpoints
 * on the shorter arc, houses that stay in order, Whole Sign from the composite
 * Ascendant, Porphyry when the two systems differ, and the Moon as a span
 * without a birth time.
 */
class CompositeChartTest extends TestCase
{
    /**
     * @param  array<string, float>  $bodies  body => longitude
     * @param  array{0: float, 1: float}|null  $angles  [asc, mc]; null for an unknown birth time
     * @param  list<float>|null  $cusps
     * @param  array{from: float, to: float}|null  $moonRange
     */
    private static function chart(
        array $bodies,
        ?array $angles = null,
        ?array $cusps = null,
        string $system = 'placidus',
        ?array $moonRange = null,
        TimeAccuracy $accuracy = TimeAccuracy::Exact,
    ): ChartCalculation {
        $payload = [
            'version' => 2,
            'positions' => array_map(
                fn (string $body, float $longitude) => ['body' => $body, 'longitude' => $longitude, 'speed' => 1.0, 'retrograde' => false, 'house' => null],
                array_keys($bodies),
                $bodies,
            ),
            'houses' => $angles ? [
                'system' => $system,
                'requested_system' => 'placidus',
                'cusps' => $cusps ?? array_map(fn (int $i) => fmod($angles[0] + 30 * $i, 360), range(0, 11)),
            ] : null,
            'angles' => $angles ? ['asc' => $angles[0], 'mc' => $angles[1]] : null,
        ];

        if ($moonRange) {
            $payload['moon_range'] = $moonRange;
        }

        return (new ChartCalculation)->forceFill([
            'payload' => $payload,
            'time_accuracy' => $moonRange ? TimeAccuracy::Unknown : $accuracy,
        ]);
    }

    /**
     * @param  array<string, mixed>  $composite
     */
    private static function longitude(array $composite, string $body): float
    {
        return collect($composite['positions'])->firstWhere('body', $body)['longitude'];
    }

    public function test_a_midpoint_is_on_the_shorter_arc_even_across_0_aries(): void
    {
        $this->assertEqualsWithDelta(30.0, CompositeChart::midpoint(10, 50), 1e-9);
        $this->assertEqualsWithDelta(10.0, CompositeChart::midpoint(350, 30), 1e-9);
        $this->assertEqualsWithDelta(10.0, CompositeChart::midpoint(30, 350), 1e-9);
        $this->assertEqualsWithDelta(355.0, CompositeChart::midpoint(340, 10), 1e-9);
        // Exactly opposite: the midpoint 90° after the first point.
        $this->assertEqualsWithDelta(90.0, CompositeChart::midpoint(0, 180), 1e-9);
        $this->assertEqualsWithDelta(270.0, CompositeChart::midpoint(180, 0), 1e-9);
    }

    public function test_each_body_stands_halfway_between_the_two_and_does_not_move(): void
    {
        $composite = CompositeChart::of(
            self::chart(['sun' => 350.0, 'moon' => 100.0, 'venus' => 200.0, 'chiron' => 10.0]),
            self::chart(['sun' => 30.0, 'moon' => 120.0, 'venus' => 20.0]),
        );

        $this->assertSame(['sun', 'moon', 'venus'], array_column($composite['positions'], 'body'));
        $this->assertEqualsWithDelta(10.0, self::longitude($composite, 'sun'), 1e-9);
        $this->assertEqualsWithDelta(110.0, self::longitude($composite, 'moon'), 1e-9);
        $this->assertEqualsWithDelta(290.0, self::longitude($composite, 'venus'), 1e-9);
        $this->assertNull($composite['positions'][0]['speed']);
        $this->assertFalse($composite['positions'][0]['retrograde']);
        $this->assertNull($composite['moon_range']);
        $this->assertNull($composite['houses']);
        $this->assertSame('exact', $composite['time_accuracy']);
    }

    public function test_the_midheaven_stays_above_the_composite_horizon_and_the_cusps_stay_in_order(): void
    {
        // Ascendants 0° and 170° meet at 85°. The Midheavens' own midpoint (185°) would
        // sit below that horizon; each MC is 270° and 290° on from its Ascendant, so 280°.
        $first = self::chart(['sun' => 0.0], [0.0, 270.0], [0, 20, 50, 90, 130, 160, 180, 200, 230, 270, 310, 340]);
        $second = self::chart(['sun' => 0.0], [170.0, 100.0], [170, 200, 240, 280, 310, 340, 350, 20, 60, 100, 130, 160]);

        $composite = CompositeChart::of($first, $second);

        $this->assertEqualsWithDelta(85.0, $composite['angles']['asc'], 1e-9);
        $this->assertEqualsWithDelta(5.0, $composite['angles']['mc'], 1e-9);
        $this->assertEqualsWithDelta(265.0, $composite['angles']['dsc'], 1e-9);
        $this->assertEqualsWithDelta(185.0, $composite['angles']['ic'], 1e-9);

        $cusps = $composite['houses']['cusps'];
        $this->assertCount(12, $cusps);
        $this->assertEqualsWithDelta(85.0, $cusps[0], 1e-9);
        $this->assertEqualsWithDelta(5.0, $cusps[9], 1e-9);
        // Every house goes forward from the one before, all the way round.
        $spans = array_map(fn (int $i) => fmod($cusps[($i + 1) % 12] - $cusps[$i] + 360, 360), range(0, 11));
        $this->assertEqualsWithDelta(360.0, array_sum($spans), 1e-6);
        $this->assertSame(CompositeChart::MIDPOINT_CUSPS, $composite['houses']['method']);
        $this->assertSame('placidus', $composite['houses']['system']);
    }

    public function test_whole_sign_houses_count_signs_from_the_composite_ascendant(): void
    {
        $first = self::chart(['sun' => 12.0], [95.0, 5.0], array_map(fn (int $i) => fmod(90 + 30 * $i, 360), range(0, 11)), 'whole_sign');
        $second = self::chart(['sun' => 14.0], [145.0, 55.0], array_map(fn (int $i) => fmod(120 + 30 * $i, 360), range(0, 11)), 'whole_sign');

        $composite = CompositeChart::of($first, $second);

        // Composite Ascendant 120° (0° Leo): the first house is Leo, not 105° between the sign boundaries.
        $this->assertEqualsWithDelta(120.0, $composite['angles']['asc'], 1e-9);
        $this->assertSame([120.0, 150.0, 180.0, 210.0, 240.0, 270.0, 300.0, 330.0, 0.0, 30.0, 60.0, 90.0], $composite['houses']['cusps']);
        $this->assertSame(CompositeChart::WHOLE_SIGNS, $composite['houses']['method']);
        // The Sun at 13° Aries is in the ninth house from Leo.
        $this->assertSame(9, $composite['positions'][0]['house']);
    }

    public function test_charts_drawn_in_different_systems_give_porphyry_from_the_composite_angles(): void
    {
        $first = self::chart(['sun' => 0.0], [0.0, 270.0]);
        $second = self::chart(['sun' => 0.0], [40.0, 300.0], null, 'porphyry');

        $composite = CompositeChart::of($first, $second);

        $this->assertSame('porphyry', $composite['houses']['system']);
        $this->assertSame('placidus', $composite['houses']['requested_system']);
        $this->assertSame(CompositeChart::PORPHYRY_FROM_ANGLES, $composite['houses']['method']);
        // ASC 20°, MC 285°: the quadrant from the ASC to the IC (105°) is split into thirds of 28.33°.
        $this->assertEqualsWithDelta(20.0, $composite['houses']['cusps'][0], 1e-6);
        $this->assertEqualsWithDelta(48.3333333, $composite['houses']['cusps'][1], 1e-6);
        $this->assertEqualsWithDelta(105.0, $composite['houses']['cusps'][3], 1e-6);
        $this->assertEqualsWithDelta(285.0, $composite['houses']['cusps'][9], 1e-6);
    }

    public function test_without_one_birth_time_there_are_no_houses_and_the_moon_is_a_span(): void
    {
        $known = self::chart(['sun' => 10.0, 'moon' => 100.0], [0.0, 270.0]);
        $unknown = self::chart(['sun' => 20.0, 'moon' => 48.0], moonRange: ['from' => 40.0, 'to' => 54.0]);

        $composite = CompositeChart::of($known, $unknown);

        $this->assertNull($composite['angles']);
        $this->assertNull($composite['houses']);
        $this->assertSame('unknown', $composite['time_accuracy']);
        // The unknown Moon's middle is 47°; halfway to 100° is 73.5°, and the span of 14° becomes 7°.
        $this->assertEqualsWithDelta(73.5, self::longitude($composite, 'moon'), 1e-9);
        $this->assertEqualsWithDelta(70.0, $composite['moon_range']['from'], 1e-9);
        $this->assertEqualsWithDelta(77.0, $composite['moon_range']['to'], 1e-9);
        $this->assertNull($composite['positions'][1]['house']);

        // The span leaves the Moon out of the composite's aspects.
        $this->assertSame(['sun'], array_column(CompositeChart::points($composite), 'key'));
    }

    public function test_the_less_certain_birth_time_decides(): void
    {
        $composite = CompositeChart::of(
            self::chart(['sun' => 0.0], [0.0, 270.0], accuracy: TimeAccuracy::Approximate),
            self::chart(['sun' => 0.0], [10.0, 280.0], accuracy: TimeAccuracy::Rectified),
        );

        $this->assertSame('approximate', $composite['time_accuracy']);
    }

    public function test_the_points_that_take_aspects_leave_out_the_mean_node_and_take_the_angles(): void
    {
        $composite = CompositeChart::of(
            self::chart(['sun' => 0.0, 'moon' => 90.0, 'mean_node' => 30.0, 'true_node' => 31.0], [0.0, 270.0]),
            self::chart(['sun' => 20.0, 'moon' => 110.0, 'mean_node' => 50.0, 'true_node' => 51.0], [20.0, 290.0]),
        );

        $points = CompositeChart::points($composite);

        $this->assertSame(['sun', 'moon', 'true_node', 'asc', 'mc'], array_column($points, 'key'));
        $this->assertTrue($points[0]['luminary']);
        $this->assertTrue($points[3]['angle']);
        $this->assertNull($points[0]['speed']);
    }
}
