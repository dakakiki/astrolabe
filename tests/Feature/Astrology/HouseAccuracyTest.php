<?php

namespace Tests\Feature\Astrology;

use App\Astrology\Engines\SwissEphemerisEngine;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\Houses;
use App\Enums\Ayanamsa;
use App\Enums\CelestialBody;
use App\Enums\HouseSystem;
use App\Enums\ZodiacMode;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Angles and houses from the real Swiss Ephemeris, checked against an
 * independent calculation (docs/spec/11, "Testovi tačnosti"):
 *
 * - sidereal time, obliquity and nutation from the textbook formulas in
 *   J. Meeus, Astronomical Algorithms (2nd ed.), ch. 12 and 22;
 * - the Ascendant and Midheaven from spherical trigonometry;
 * - Equal, Whole Sign and Porphyry cusps from the angles;
 * - Placidus by its definition: cusps 11, 12, 2 and 3 trisect the semi-arcs,
 *   solved here by iteration.
 *
 * Tolerance: one arc-minute. Measured agreement is about 0.3″. Inside the
 * polar circles Placidus and Koch must fall back to Porphyry, never fail.
 * Skipped where swetest is not installed (CI uses the fake engine).
 */
class HouseAccuracyTest extends TestCase
{
    private const TOLERANCE = 1 / 60;

    private function engine(): SwissEphemerisEngine
    {
        $binary = config('astrolabe.ephemeris.swetest');
        $path = config('astrolabe.ephemeris.path');

        if (! is_file($binary) || ! glob($path.'/*.se1')) {
            $this->markTestSkipped('Swiss Ephemeris is not installed here.');
        }

        return new SwissEphemerisEngine($binary, $path);
    }

    private function houses(float $julianDay, float $latitude, float $longitude, HouseSystem $system, ZodiacMode $zodiac = ZodiacMode::Tropical): Houses
    {
        return $this->engine()->calculate(new ChartRequest(
            $julianDay, $latitude, $longitude, $system, $zodiac, Ayanamsa::Lahiri, [CelestialBody::Sun], includeHouses: true,
        ))->houses;
    }

    /**
     * Moments and places in both hemispheres, from the equator to 64°N.
     *
     * @return array<string, array{0: float, 1: float, 2: float}>
     */
    public static function places(): array
    {
        return [
            'Novi Sad, 15 July 1985 12:30 UT' => [2446262.02083333, 45.25167, 19.83694],
            'Buenos Aires, 25 June 1978 18:00 UT' => [2443685.25, -34.6037, -58.3816],
            'Sydney, 1 January 2000 12:00 UT' => [2451545.0, -33.8688, 151.2093],
            'Helsinki, 1 January 1900 00:00 UT' => [2415020.5, 60.1699, 24.9384],
            'Reykjavik, 3 October 2039 09:36 UT' => [2466154.9, 64.1466, -21.9426],
            'Quito, 23 May 1968 19:12 UT' => [2440000.3, -0.1807, -78.4678],
            'Cape Town, 9 March 1952 03:00 UT' => [2434080.625, -33.9249, 18.4241],
        ];
    }

    #[DataProvider('places')]
    public function test_the_ascendant_and_midheaven_match_spherical_trigonometry(float $julianDay, float $latitude, float $longitude): void
    {
        $houses = $this->houses($julianDay, $latitude, $longitude, HouseSystem::Placidus);
        [$armc, $obliquity] = self::localSiderealTime($julianDay, $longitude);

        $this->assertWithinTolerance($armc, $houses->armc, 'ARMC');
        $this->assertWithinTolerance(self::ascendant($armc, $latitude, $obliquity), $houses->ascendant, 'Ascendant');
        $this->assertWithinTolerance(self::midheaven($armc, $obliquity), $houses->midheaven, 'Midheaven');
        $this->assertSame(HouseSystem::Placidus, $houses->system);
    }

    #[DataProvider('places')]
    public function test_placidus_cusps_trisect_the_semi_arcs(float $julianDay, float $latitude, float $longitude): void
    {
        $houses = $this->houses($julianDay, $latitude, $longitude, HouseSystem::Placidus);
        [$armc, $obliquity] = self::localSiderealTime($julianDay, $longitude);

        foreach ([11, 12, 2, 3] as $cusp) {
            $this->assertWithinTolerance(self::placidusCusp($cusp, $armc, $latitude, $obliquity), $houses->cusps[$cusp - 1], "Placidus cusp {$cusp}");
        }

        // The opposite cusps lie exactly across the chart.
        foreach ([2, 3, 5, 6] as $cusp) {
            $this->assertWithinTolerance($houses->cusps[$cusp - 1] + 180, $houses->cusps[$cusp + 5], "cusps {$cusp} and ".($cusp + 6));
        }
    }

    #[DataProvider('places')]
    public function test_equal_whole_sign_and_porphyry_cusps_follow_from_the_angles(float $julianDay, float $latitude, float $longitude): void
    {
        $equal = $this->houses($julianDay, $latitude, $longitude, HouseSystem::Equal);
        foreach ($equal->cusps as $index => $cusp) {
            $this->assertWithinTolerance($equal->ascendant + 30 * $index, $cusp, 'Equal cusp '.($index + 1));
        }

        $wholeSign = $this->houses($julianDay, $latitude, $longitude, HouseSystem::WholeSign);
        foreach ($wholeSign->cusps as $index => $cusp) {
            $this->assertWithinTolerance(floor($wholeSign->ascendant / 30) * 30 + 30 * $index, $cusp, 'Whole Sign cusp '.($index + 1));
        }

        $this->assertPorphyry($this->houses($julianDay, $latitude, $longitude, HouseSystem::Porphyry));
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: HouseSystem}>
     */
    public static function polarCharts(): array
    {
        return [
            'Tromsø 69.6°N, Placidus' => [69.6496, 18.956, HouseSystem::Placidus],
            'Tromsø 69.6°N, Koch' => [69.6496, 18.956, HouseSystem::Koch],
            'Longyearbyen 78.2°N, Placidus' => [78.2232, 15.6267, HouseSystem::Placidus],
            'McMurdo 77.8°S, Placidus' => [-77.846, 166.676, HouseSystem::Placidus],
            'Just inside the polar circle, 66.6°N' => [66.6, 25.0, HouseSystem::Placidus],
        ];
    }

    #[DataProvider('polarCharts')]
    public function test_inside_the_polar_circles_placidus_and_koch_fall_back_to_porphyry(float $latitude, float $longitude, HouseSystem $system): void
    {
        foreach ([2446262.02083333, 2451545.0, 2460000.25] as $julianDay) {
            $houses = $this->houses($julianDay, $latitude, $longitude, $system);

            $this->assertSame(HouseSystem::Porphyry, $houses->system);
            $this->assertSame($system, $houses->requestedSystem);
            $this->assertTrue($houses->fellBack());
            $this->assertPorphyry($houses);
        }
    }

    public function test_other_systems_are_drawn_as_asked_at_high_latitudes(): void
    {
        foreach ([HouseSystem::Regiomontanus, HouseSystem::Campanus, HouseSystem::Equal, HouseSystem::WholeSign, HouseSystem::Porphyry] as $system) {
            $houses = $this->houses(2446262.02083333, 69.6496, 18.956, $system);

            $this->assertSame($system, $houses->system, $system->value);
            $this->assertFalse($houses->fellBack());
        }

        // Just outside the polar circle Placidus still works.
        $this->assertSame(HouseSystem::Placidus, $this->houses(2446262.02083333, 66.0, 18.956, HouseSystem::Placidus)->system);
    }

    public function test_sidereal_angles_are_shifted_by_the_same_ayanamsa_as_the_planets(): void
    {
        $engine = $this->engine();
        $request = fn (ZodiacMode $zodiac) => new ChartRequest(
            2446262.02083333, 45.25167, 19.83694, HouseSystem::Placidus, $zodiac, Ayanamsa::Lahiri, [CelestialBody::Sun], includeHouses: true,
        );

        $tropical = $engine->calculate($request(ZodiacMode::Tropical));
        $sidereal = $engine->calculate($request(ZodiacMode::Sidereal));
        $ayanamsa = $tropical->positions[0]->longitude - $sidereal->positions[0]->longitude;

        $this->assertWithinTolerance($tropical->houses->ascendant - $ayanamsa, $sidereal->houses->ascendant, 'sidereal Ascendant');
        $this->assertWithinTolerance($tropical->houses->midheaven - $ayanamsa, $sidereal->houses->midheaven, 'sidereal Midheaven');
        $this->assertWithinTolerance($tropical->houses->cusps[10] - $ayanamsa, $sidereal->houses->cusps[10], 'sidereal cusp 11');
        // Sidereal time itself does not depend on the zodiac.
        $this->assertWithinTolerance($tropical->houses->armc, $sidereal->houses->armc, 'ARMC');
    }

    public function test_without_a_birth_time_no_houses_are_asked_for(): void
    {
        $result = $this->engine()->calculate(new ChartRequest(
            2446262.0, 45.25167, 19.83694, HouseSystem::Placidus, ZodiacMode::Tropical, null, [CelestialBody::Sun], includeHouses: false,
        ));

        $this->assertNull($result->houses);
    }

    /* ---------------- Independent calculation ---------------- */

    /**
     * Local apparent sidereal time (the ARMC) and the true obliquity, in degrees.
     *
     * @return array{0: float, 1: float}
     */
    private static function localSiderealTime(float $julianDay, float $longitude): array
    {
        $days = $julianDay - 2451545.0;
        $t = $days / 36525;

        // Meeus 12.4: mean sidereal time at Greenwich.
        $mean = 280.46061837 + 360.98564736629 * $days + 0.000387933 * $t ** 2 - $t ** 3 / 38710000;

        // Meeus ch. 22: nutation (to 0.5″) and the mean obliquity (22.2).
        $node = deg2rad(125.04452 - 1934.136261 * $t);
        $sun = deg2rad(280.4665 + 36000.7698 * $t);
        $moon = deg2rad(218.3165 + 481267.8813 * $t);
        $nutationLongitude = (-17.20 * sin($node) - 1.32 * sin(2 * $sun) - 0.23 * sin(2 * $moon) + 0.21 * sin(2 * $node)) / 3600;
        $nutationObliquity = (9.20 * cos($node) + 0.57 * cos(2 * $sun) + 0.10 * cos(2 * $moon) - 0.09 * cos(2 * $node)) / 3600;
        $meanObliquity = 23 + 26 / 60 + (21.448 - 46.8150 * $t - 0.00059 * $t ** 2 + 0.001813 * $t ** 3) / 3600;
        $obliquity = $meanObliquity + $nutationObliquity;

        // Apparent time adds the equation of the equinoxes.
        return [self::normalize($mean + $nutationLongitude * cos(deg2rad($obliquity)) + $longitude), $obliquity];
    }

    /** The ecliptic degree rising in the east. */
    private static function ascendant(float $armc, float $latitude, float $obliquity): float
    {
        [$theta, $epsilon] = [deg2rad($armc), deg2rad($obliquity)];

        return self::normalize(rad2deg(atan2(cos($theta), -(sin($theta) * cos($epsilon) + tan(deg2rad($latitude)) * sin($epsilon)))));
    }

    /** The ecliptic degree on the upper meridian. */
    private static function midheaven(float $armc, float $obliquity): float
    {
        [$theta, $epsilon] = [deg2rad($armc), deg2rad($obliquity)];

        return self::normalize(rad2deg(atan2(sin($theta), cos($theta) * cos($epsilon))));
    }

    /**
     * A Placidus cusp as its definition states it: the ecliptic point whose
     * right ascension is past the ARMC by a fixed share of its own semi-arcs —
     * a third (11) or two thirds (12) of the diurnal arc; the whole diurnal arc
     * and a third (2) or two thirds (3) of the nocturnal one. The point's
     * declination depends on the answer, so it is found by iteration.
     */
    private static function placidusCusp(int $cusp, float $armc, float $latitude, float $obliquity): float
    {
        $epsilon = deg2rad($obliquity);
        $longitude = self::normalize($armc + [11 => 30, 12 => 60, 2 => 120, 3 => 150][$cusp]);

        for ($i = 0; $i < 200; $i++) {
            $declination = asin(sin(deg2rad($longitude)) * sin($epsilon));
            $diurnal = rad2deg(acos(max(-1, min(1, -tan(deg2rad($latitude)) * tan($declination)))));
            $nocturnal = 180 - $diurnal;

            $offset = match ($cusp) {
                11 => $diurnal / 3,
                12 => 2 * $diurnal / 3,
                2 => $diurnal + $nocturnal / 3,
                3 => $diurnal + 2 * $nocturnal / 3,
            };

            $rightAscension = deg2rad($armc + $offset);
            $next = self::normalize(rad2deg(atan2(sin($rightAscension), cos($rightAscension) * cos($epsilon))));

            if (self::distance($next, $longitude) < 1e-10) {
                return $next;
            }

            $longitude = $next;
        }

        self::fail("Placidus cusp {$cusp} did not converge.");
    }

    /** Porphyry: each quadrant between the angles divided into three equal parts. */
    private function assertPorphyry(Houses $houses): void
    {
        $asc = $houses->ascendant;
        $mc = $houses->midheaven;
        $quadrants = [[$asc, $mc + 180, 0], [$mc + 180, $asc + 180, 3], [$asc + 180, $mc, 6], [$mc, $asc, 9]];

        foreach ($quadrants as [$from, $to, $first]) {
            $third = self::normalize($to - $from) / 3;

            foreach ([0, 1, 2] as $step) {
                $this->assertWithinTolerance($from + $step * $third, $houses->cusps[$first + $step], 'Porphyry cusp '.($first + $step + 1));
            }
        }
    }

    private function assertWithinTolerance(float $expected, float $actual, string $what): void
    {
        $difference = self::distance($expected, $actual);

        $this->assertLessThan(self::TOLERANCE, $difference, sprintf('%s is %.2f″ off (expected %.6f°, got %.6f°)', $what, $difference * 3600, self::normalize($expected), $actual));
    }

    /** The shorter way round between two longitudes, in degrees. */
    private static function distance(float $a, float $b): float
    {
        return abs(fmod($a - $b + 540, 360) - 180);
    }

    private static function normalize(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 360, 360);
    }
}
