<?php

namespace Tests\Feature\Astrology;

use App\Astrology\Engines\SwissEphemerisEngine;
use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\Services\TransitService;
use App\Astrology\ValueObjects\ChartRequest;
use App\Enums\AspectType;
use App\Enums\Ayanamsa;
use App\Enums\CelestialBody;
use App\Enums\ZodiacMode;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Accuracy against an independent source (docs/spec/06, "Tačnost proračuna"):
 * positions from the real Swiss Ephemeris must agree with NASA JPL Horizons to
 * within one arc-minute. Needs the swetest binary and data files, so it is
 * skipped where they are absent (CI uses the fake engine); run it locally and
 * before every deploy that changes the engine, the ephemeris files or tzdata.
 */
class SwissEphemerisReferenceTest extends TestCase
{
    private const TOLERANCE = 1 / 60;

    private function engine(?string $ephemerisPath = null): SwissEphemerisEngine
    {
        $binary = config('astrolabe.ephemeris.swetest');
        $path = config('astrolabe.ephemeris.path');

        if (! is_file($binary) || ! glob($path.'/*.se1')) {
            $this->markTestSkipped('Swiss Ephemeris is not installed here.');
        }

        return new SwissEphemerisEngine($binary, $ephemerisPath ?? $path);
    }

    /**
     * @return array<string, array{0: float, 1: array<string, float>}>
     */
    public static function referenceMoments(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../fixtures/ephemeris/jpl-horizons-reference.json'), true);

        return array_map(
            fn (array $moment) => [$moment['julian_day_ut'], $moment['longitudes']],
            $fixture['moments'],
        );
    }

    /**
     * @param  array<string, float>  $expected
     */
    #[DataProvider('referenceMoments')]
    public function test_positions_match_jpl_horizons_within_one_arc_minute(float $julianDay, array $expected): void
    {
        $bodies = array_map(fn (string $body) => CelestialBody::from($body), array_keys($expected));

        $result = $this->engine()->calculate(new ChartRequest($julianDay, null, null, null, ZodiacMode::Tropical, null, $bodies));

        foreach ($result->positions as $position) {
            $difference = abs(fmod($position->longitude - $expected[$position->body->value] + 540, 360) - 180);

            $this->assertLessThan(
                self::TOLERANCE,
                $difference,
                sprintf('%s is %.1f″ from JPL', $position->body->value, $difference * 3600),
            );
        }
    }

    public function test_sidereal_positions_are_shifted_by_the_ayanamsa(): void
    {
        $engine = $this->engine();
        $request = fn (ZodiacMode $mode) => new ChartRequest(2451545.0, null, null, null, $mode, Ayanamsa::Lahiri, [CelestialBody::Sun]);

        $tropical = $engine->calculate($request(ZodiacMode::Tropical))->positions[0]->longitude;
        $sidereal = $engine->calculate($request(ZodiacMode::Sidereal))->positions[0]->longitude;

        // The Lahiri ayanamsa was about 23°51′ in 2000.
        $this->assertEqualsWithDelta(23.86, $tropical - $sidereal, 0.02);
    }

    public function test_retrograde_motion_is_reported(): void
    {
        // Saturn was retrograde on 15 July 1985.
        $result = $this->engine()->calculate(new ChartRequest(2446262.0208333, null, null, null, ZodiacMode::Tropical, null, [CelestialBody::Saturn]));

        $this->assertTrue($result->positions[0]->isRetrograde());
    }

    public function test_missing_data_files_fail_loudly_instead_of_falling_back(): void
    {
        $this->expectException(EphemerisException::class);

        $this->engine(sys_get_temp_dir().'/no-ephemeris-here')
            ->calculate(new ChartRequest(2451545.0, null, null, null, ZodiacMode::Tropical, null, [CelestialBody::Sun]));
    }

    public function test_a_series_matches_single_calculations_day_by_day(): void
    {
        $engine = $this->engine();
        $bodies = [CelestialBody::Moon, CelestialBody::Saturn, CelestialBody::Chiron];
        $request = fn (float $julianDay) => new ChartRequest($julianDay, null, null, null, ZodiacMode::Sidereal, Ayanamsa::Lahiri, $bodies);

        $series = $engine->series($request(2461308.75), 5);

        $this->assertCount(5, $series);
        foreach ([0, 4] as $day) {
            $single = $engine->calculate($request(2461308.75 + $day))->positions;

            foreach ($bodies as $i => $body) {
                $this->assertSame($body, $series[$day][$i]->body);
                $this->assertEqualsWithDelta($single[$i]->longitude, $series[$day][$i]->longitude, 1e-6);
                $this->assertEqualsWithDelta($single[$i]->speed, $series[$day][$i]->speed, 1e-6);
            }
        }
    }

    public function test_an_hourly_year_comes_in_one_run_without_drifting(): void
    {
        $engine = $this->engine();
        $bodies = [CelestialBody::Sun, CelestialBody::Moon, CelestialBody::Mercury, CelestialBody::Chiron];
        $request = fn (float $julianDay) => new ChartRequest($julianDay, null, null, null, ZodiacMode::Tropical, null, $bodies);
        $steps = 365 * 24 + 1;

        // More lines than swetest prints one body per line (36,525), hence one line per moment.
        $series = $engine->series($request(2461308.5), $steps, 1 / 24);

        $this->assertCount($steps, $series);
        $last = $engine->calculate($request(2461308.5 + 365))->positions;

        foreach ($bodies as $i => $body) {
            $this->assertSame($body, $series[$steps - 1][$i]->body);
            $this->assertEqualsWithDelta($last[$i]->longitude, $series[$steps - 1][$i]->longitude, 1e-6);
        }
    }

    public function test_the_day_a_transit_is_exact_is_found_to_within_minutes(): void
    {
        $engine = $this->engine();
        $moment = 2461308.9166667;
        $saturn = fn (float $julianDay) => $engine->calculate(
            new ChartRequest($julianDay, null, null, null, ZodiacMode::Tropical, null, [CelestialBody::Saturn]),
        )->positions[0]->longitude;

        // A natal point where Saturn stands 3.4 days after the moment, and one 90° from it.
        $target = $saturn($moment + 3.4);
        $days = $engine->series(new ChartRequest($moment - 10, null, null, null, ZodiacMode::Tropical, null, [CelestialBody::Saturn]), 21);
        $longitudes = array_map(fn (array $positions) => $positions[0]->longitude, $days);

        $conjunction = TransitService::exactDays($longitudes, $target, AspectType::Conjunction, $moment - 10);
        $square = TransitService::exactDays($longitudes, $target - 90, AspectType::Square, $moment - 10);

        $this->assertCount(1, $conjunction);
        $this->assertEqualsWithDelta($moment + 3.4, $conjunction[0], 10 / 1440, 'within ten minutes');
        $this->assertEqualsWithDelta($moment + 3.4, $square[0], 10 / 1440);
    }
}
