<?php

namespace Tests\Unit;

use App\Astrology\Services\AspectCalculator;
use App\Astrology\ValueObjects\Aspect;
use App\Astrology\ValueObjects\AspectSettings;
use App\Astrology\ValueObjects\ChartResult;
use App\Astrology\ValueObjects\Houses;
use App\Astrology\ValueObjects\PlanetPosition;
use App\Enums\AspectType;
use App\Enums\CelestialBody;
use App\Enums\HouseSystem;
use PHPUnit\Framework\TestCase;

/**
 * Aspects from positions (docs/spec/11): default and custom orbs, the wider
 * orb for the Sun and Moon, applying and separating from the daily motion.
 */
class AspectCalculatorTest extends TestCase
{
    /**
     * @return array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}
     */
    private static function point(string $key, float $longitude, ?float $speed = 0.0, bool $angle = false): array
    {
        return [
            'key' => $key,
            'longitude' => $longitude,
            'speed' => $speed,
            'luminary' => in_array($key, ['sun', 'moon'], true),
            'angle' => $angle,
        ];
    }

    /**
     * @param  list<array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}>  $points
     */
    private static function only(array $points, ?AspectSettings $settings = null): ?Aspect
    {
        $aspects = (new AspectCalculator)->between($points, $settings ?? AspectSettings::defaults());

        return $aspects[0] ?? null;
    }

    public function test_the_major_aspects_are_found_within_their_default_orbs(): void
    {
        $cases = [
            [AspectType::Conjunction, 7.9], [AspectType::Sextile, 63.9], [AspectType::Square, 84.1],
            [AspectType::Trine, 125.9], [AspectType::Opposition, 173.1],
        ];

        foreach ($cases as [$type, $distance]) {
            $aspect = self::only([self::point('mars', 10), self::point('jupiter', 10 + $distance)]);

            $this->assertSame($type, $aspect?->type, "{$distance}°");
            $this->assertEqualsWithDelta(abs($distance - $type->angle()), $aspect->orb, 1e-9);
        }

        // Just outside: 8.1° is no conjunction, 64.1° no sextile.
        $this->assertNull(self::only([self::point('mars', 10), self::point('jupiter', 18.1)]));
        $this->assertNull(self::only([self::point('mars', 10), self::point('jupiter', 74.1)]));
    }

    public function test_minor_aspects_wait_until_they_are_switched_on(): void
    {
        $points = [self::point('mars', 0), self::point('saturn', 150.5)];

        $this->assertNull(self::only($points));

        $settings = AspectSettings::fromArray(['aspects' => ['quincunx' => ['enabled' => true]]]);
        $this->assertSame(AspectType::Quincunx, self::only($points, $settings)?->type);
    }

    public function test_the_sun_and_moon_get_a_wider_orb(): void
    {
        // A square 7° from exact: beyond the 6° orb, within 6° + 1.5°.
        $this->assertNull(self::only([self::point('mars', 0), self::point('jupiter', 97)]));
        $this->assertSame(AspectType::Square, self::only([self::point('sun', 0), self::point('jupiter', 97)])?->type);
        $this->assertSame(AspectType::Square, self::only([self::point('mars', 0), self::point('moon', 97)])?->type);

        // The bonus counts once, even for Sun and Moon together.
        $this->assertNull(self::only([self::point('sun', 0), self::point('moon', 98)]));
    }

    public function test_custom_orbs_and_switched_off_aspects_are_respected(): void
    {
        $settings = AspectSettings::fromArray([
            'aspects' => ['conjunction' => ['enabled' => true, 'orb' => 3], 'trine' => ['enabled' => false, 'orb' => 6]],
            'luminary_bonus' => 0,
        ]);

        $this->assertNull(self::only([self::point('sun', 10), self::point('venus', 14)], $settings));
        $this->assertNull(self::only([self::point('mars', 10), self::point('venus', 130)], $settings));
        $this->assertSame(AspectType::Conjunction, self::only([self::point('mars', 10), self::point('venus', 12.5)], $settings)?->type);
    }

    public function test_the_closest_aspect_wins_where_orbs_overlap(): void
    {
        $settings = AspectSettings::fromArray(['aspects' => [
            'sextile' => ['enabled' => true, 'orb' => 10],
            'semisquare' => ['enabled' => true, 'orb' => 10],
        ]]);

        // 50°: 5° from a semi-square, 10° from a sextile.
        $aspect = self::only([self::point('mars', 0), self::point('venus', 50)], $settings);

        $this->assertSame(AspectType::Semisquare, $aspect?->type);
        $this->assertEqualsWithDelta(5.0, $aspect->orb, 1e-9);
    }

    public function test_aspects_are_measured_across_zero_aries(): void
    {
        $aspect = self::only([self::point('mars', 358), self::point('venus', 2)]);
        $this->assertSame(AspectType::Conjunction, $aspect?->type);
        $this->assertEqualsWithDelta(4.0, $aspect->orb, 1e-9);

        $aspect = self::only([self::point('mars', 355), self::point('venus', 176)]);
        $this->assertSame(AspectType::Opposition, $aspect?->type);
        $this->assertEqualsWithDelta(1.0, $aspect->orb, 1e-9);
    }

    public function test_applying_and_separating_follow_the_daily_motion(): void
    {
        // The faster Moon behind Saturn closes the conjunction; ahead of it, it moves away.
        $this->assertTrue(self::only([self::point('moon', 95, 13.2), self::point('saturn', 100, 0.1)])->applying);
        $this->assertFalse(self::only([self::point('moon', 105, 13.2), self::point('saturn', 100, 0.1)])->applying);

        // A retrograde Mercury moving back towards Venus.
        $this->assertTrue(self::only([self::point('mercury', 104, -0.8), self::point('venus', 100, 1.2)])->applying);

        // A square 2° short of exact that is widening, then one that is closing.
        $this->assertFalse(self::only([self::point('mars', 0, 0.7), self::point('jupiter', 88, 0.1)])->applying);
        $this->assertTrue(self::only([self::point('mars', 0, 0.1), self::point('jupiter', 88, 0.7)])->applying);

        // Oppositions from either side of 180°, and across 0° Aries.
        $this->assertTrue(self::only([self::point('mars', 0, 0.1), self::point('jupiter', 178, 0.7)])->applying);
        $this->assertFalse(self::only([self::point('mars', 0, 0.1), self::point('jupiter', 182, 0.7)])->applying);
        $this->assertTrue(self::only([self::point('mars', 2, 0.1), self::point('jupiter', 180, 0.7)])->applying);
        $this->assertTrue(self::only([self::point('venus', 358, 1.2), self::point('mars', 1, 0.5)])->applying);
    }

    public function test_aspects_to_the_angles_have_no_direction_and_the_angles_none_between_them(): void
    {
        $aspects = (new AspectCalculator)->between([
            self::point('sun', 100, 1.0),
            self::point('asc', 190, null, angle: true),
            self::point('mc', 100, null, angle: true),
        ], AspectSettings::defaults());

        $this->assertCount(2, $aspects);
        $this->assertSame(['sun', 'asc', 'square'], [$aspects[0]->first, $aspects[0]->second, $aspects[0]->type->value]);
        $this->assertNull($aspects[0]->applying);
        $this->assertSame(['sun', 'mc', 'conjunction'], [$aspects[1]->first, $aspects[1]->second, $aspects[1]->type->value]);
    }

    public function test_natal_points_leave_out_the_mean_node_and_without_a_time_the_moon(): void
    {
        $result = new ChartResult(
            positions: [
                new PlanetPosition(CelestialBody::Sun, 10, 1),
                new PlanetPosition(CelestialBody::Moon, 20, 13),
                new PlanetPosition(CelestialBody::TrueNode, 30, -0.05),
                new PlanetPosition(CelestialBody::MeanNode, 31, -0.05),
            ],
            engineName: 'Test',
            engineVersion: '1',
            ephemerisVersion: null,
            houses: new Houses(HouseSystem::Equal, HouseSystem::Equal, array_map(fn ($i) => 30.0 * $i, range(0, 11)), 0, 270, 268, 180),
        );

        $keys = fn (array $points) => array_column($points, 'key');

        $this->assertSame(['sun', 'moon', 'true_node', 'asc', 'mc'], $keys(AspectCalculator::natalPoints($result, timeKnown: true)));
        $this->assertSame(['sun', 'true_node', 'asc', 'mc'], $keys(AspectCalculator::natalPoints($result, timeKnown: false)));
    }

    public function test_stored_settings_are_laid_over_the_defaults(): void
    {
        $settings = AspectSettings::fromArray(['aspects' => ['square' => ['orb' => '5.5']], 'luminary_bonus' => 2]);

        $this->assertSame(['enabled' => true, 'orb' => 5.5], $settings->aspects['square']);
        $this->assertSame(['enabled' => true, 'orb' => 8.0], $settings->aspects['conjunction']);
        $this->assertSame(['enabled' => false, 'orb' => 3.0], $settings->aspects['quincunx']);
        $this->assertSame(2.0, $settings->luminaryBonus);
        $this->assertSame(
            [AspectType::Conjunction, AspectType::Sextile, AspectType::Square, AspectType::Trine, AspectType::Opposition],
            AspectSettings::defaults()->enabledTypes(),
        );
    }

    public function test_transits_are_measured_against_a_natal_chart_that_stands_still(): void
    {
        $settings = AspectSettings::transitDefaults();
        $natal = [self::point('sun', 10, 0.98), self::point('asc', 200, null, angle: true)];

        // Saturn 90.5° ahead of the natal Sun and moving on: a square, separating.
        $separating = (new AspectCalculator)->across([self::point('saturn', 100.5, 0.05)], $natal, $settings);
        $this->assertCount(1, $separating);
        $this->assertSame(['saturn', 'sun', AspectType::Square], [$separating[0]->first, $separating[0]->second, $separating[0]->type]);
        $this->assertEqualsWithDelta(0.5, $separating[0]->orb, 1e-9);
        $this->assertFalse($separating[0]->applying, 'the natal Sun’s own motion must not count');

        // Retrograde, the same square closes in; an angle takes transits too.
        $applying = (new AspectCalculator)->across([self::point('saturn', 100.5, -0.05), self::point('jupiter', 201.8, 0.1)], $natal, $settings);
        $this->assertTrue($applying[0]->applying);
        $this->assertSame(['jupiter', 'asc', AspectType::Conjunction, false], [$applying[1]->first, $applying[1]->second, $applying[1]->type, $applying[1]->applying]);

        // Transit orbs are tight: 2.5° from exact is nothing, and there is no wider orb for the Sun.
        $this->assertSame([], (new AspectCalculator)->across([self::point('mars', 102.5, 0.6)], $natal, $settings));
        $this->assertSame(0.0, $settings->luminaryBonus);
        $this->assertSame(1.5, $settings->aspects['sextile']['orb']);
    }
}
