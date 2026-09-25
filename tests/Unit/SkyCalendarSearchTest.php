<?php

namespace Tests\Unit;

use App\Astrology\Services\SkyCalendar;
use PHPUnit\Framework\TestCase;

/**
 * The sky calendar's search (docs/spec/11, Phase 7d) on made-up skies: hourly
 * positions in, the minute each thing happens out.
 */
class SkyCalendarSearchTest extends TestCase
{
    private const START = 2461000.5;

    private const MINUTE = 1 / 1440;

    /** Where the bodies nobody is watching stand still, well apart. */
    private const STILL = [
        'sun' => 200.3, 'moon' => 213.7, 'mercury' => 233.1, 'venus' => 256.3, 'mars' => 97.5,
        'jupiter' => 271.3, 'saturn' => 10.0, 'uranus' => 283.7, 'neptune' => 301.1,
        'pluto' => 322.9, 'chiron' => 345.1,
    ];

    /**
     * Positions every hour for `$days` days; the bodies in `$moving` follow their
     * function of time in days (longitude, speed), the rest stand still.
     *
     * @param  array<string, callable(float): array{0: float, 1: float}>  $moving
     * @return array<string, array{0: list<float>, 1: list<float>}>
     */
    private static function hourly(int $days, array $moving): array
    {
        $series = [];

        foreach (self::STILL as $body => $longitude) {
            foreach (range(0, $days * 24) as $hour) {
                [$at, $speed] = isset($moving[$body]) ? $moving[$body]($hour / 24) : [$longitude, 0.0];
                $series[$body][0][] = fmod(fmod($at, 360) + 360, 360);
                $series[$body][1][] = $speed;
            }
        }

        return $series;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private static function only(array $events, string $type, ?array $pair = null): array
    {
        return array_values(array_filter($events, fn (array $event) => $event['type'] === $type
            && ($pair === null || $event['pair'] === $pair)));
    }

    public function test_an_aspect_between_two_moving_planets_is_found_to_the_minute(): void
    {
        // Mars forwards at 0.5° a day, Saturn back at 0.05°: 87.5° apart, square after 2.5 / 0.55 days.
        $sky = self::hourly(10, [
            'mars' => fn (float $t) => [97.5 + 0.5 * $t, 0.5],
            'saturn' => fn (float $t) => [10.0 - 0.05 * $t, -0.05],
        ]);

        $squares = self::only(SkyCalendar::events($sky, self::START, self::START + 10), 'aspect', ['mars', 'saturn']);

        $this->assertCount(1, $squares);
        $this->assertSame('square', $squares[0]['aspect']);
        $this->assertEqualsWithDelta(self::START + 2.5 / 0.55, $squares[0]['jd'], self::MINUTE);
        $this->assertSame(['mars', 'saturn'], array_column($squares[0]['bodies'], 'body'), 'the faster one first');
        $this->assertSame([false, true], array_column($squares[0]['bodies'], 'retrograde'));
        $this->assertEqualsWithDelta(97.5 + 0.5 * 2.5 / 0.55, $squares[0]['bodies'][0]['longitude'], 1e-6);
    }

    public function test_a_conjunction_is_caught_by_the_signed_separation(): void
    {
        // Venus overtakes Jupiter 5 days in; the absolute separation would only touch zero.
        $sky = self::hourly(10, [
            'venus' => fn (float $t) => [100.0 + 1.2 * $t, 1.2],
            'jupiter' => fn (float $t) => [105.0 + 0.2 * $t, 0.2],
        ]);

        $conjunctions = array_values(array_filter(
            self::only(SkyCalendar::events($sky, self::START, self::START + 10), 'aspect', ['venus', 'jupiter']),
            fn (array $event) => $event['aspect'] === 'conjunction',
        ));

        $this->assertCount(1, $conjunctions);
        $this->assertEqualsWithDelta(self::START + 5, $conjunctions[0]['jd'], self::MINUTE);
    }

    public function test_stations_are_found_where_the_speed_turns(): void
    {
        // Mercury slows by 0.1° a day from 0.5°: retrograde after 5 days. Mars turns direct after 3.
        $sky = self::hourly(10, [
            'mercury' => fn (float $t) => [200.0 + 0.5 * $t - 0.05 * $t * $t, 0.5 - 0.1 * $t],
            'mars' => fn (float $t) => [150.0 - 0.3 * $t + 0.05 * $t * $t, -0.3 + 0.1 * $t],
        ]);

        $stations = self::only(SkyCalendar::events($sky, self::START, self::START + 10), 'station');

        $this->assertCount(2, $stations);
        [$mars, $mercury] = $stations;
        $this->assertSame(['mars', 'direct'], [$mars['body'], $mars['direction']]);
        $this->assertEqualsWithDelta(self::START + 3, $mars['jd'], self::MINUTE);
        $this->assertEqualsWithDelta(150.0 - 0.9 + 0.45, $mars['longitude'], 1e-3);
        $this->assertSame(['mercury', 'retrograde'], [$mercury['body'], $mercury['direction']]);
        $this->assertEqualsWithDelta(self::START + 5, $mercury['jd'], self::MINUTE);
    }

    public function test_ingresses_forwards_and_back_across_the_first_point_of_aries(): void
    {
        $sky = self::hourly(10, [
            // Into Taurus after 4 days.
            'mars' => fn (float $t) => [28.0 + 0.5 * $t, 0.5],
            // Retrograde from 0.4° Aries back into Pisces after 1⅓ days.
            'mercury' => fn (float $t) => [0.4 - 0.3 * $t, -0.3],
        ]);

        $ingresses = self::only(SkyCalendar::events($sky, self::START, self::START + 10), 'ingress');

        $this->assertCount(2, $ingresses);
        $this->assertSame(['mercury', 'pisces', true], [$ingresses[0]['body'], $ingresses[0]['sign'], $ingresses[0]['retrograde']]);
        $this->assertEqualsWithDelta(self::START + 4 / 3, $ingresses[0]['jd'], self::MINUTE);
        $this->assertSame(['mars', 'taurus', false], [$ingresses[1]['body'], $ingresses[1]['sign'], $ingresses[1]['retrograde']]);
        $this->assertEqualsWithDelta(self::START + 4, $ingresses[1]['jd'], self::MINUTE);
    }

    public function test_new_and_full_moons(): void
    {
        // The Moon gains 12° a day on the Sun from 5° behind it.
        $sky = self::hourly(20, [
            'sun' => fn (float $t) => [5.0 + $t, 1.0],
            'moon' => fn (float $t) => [13.0 * $t, 13.0],
        ]);

        $lunations = self::only(SkyCalendar::events($sky, self::START, self::START + 20), 'lunation');

        $this->assertSame(['new', 'full'], array_column($lunations, 'phase'));
        $this->assertEqualsWithDelta(self::START + 5 / 12, $lunations[0]['jd'], self::MINUTE);
        $this->assertEqualsWithDelta(self::START + 185 / 12, $lunations[1]['jd'], self::MINUTE);
        $this->assertEqualsWithDelta(13.0 * 185 / 12, $lunations[1]['longitude'], 1e-6);
    }

    public function test_only_the_period_is_listed(): void
    {
        $sky = self::hourly(10, ['mars' => fn (float $t) => [28.0 + 0.5 * $t, 0.5]]);

        $this->assertCount(1, self::only(SkyCalendar::events($sky, self::START, self::START + 10), 'ingress'));
        $this->assertSame([], self::only(SkyCalendar::events($sky, self::START, self::START + 3.9), 'ingress'));
    }

    public function test_a_retrograde_loop_is_one_arc_and_an_ordinary_return_is_not(): void
    {
        // Mars forwards to 80°, back to 60° and on again, over Saturn at 70°: days 80, 140 and 200.
        $mars = fn (int $day) => match (true) {
            $day <= 100 => 30.0 + 0.5 * $day,
            $day <= 180 => 80.0 - 0.25 * ($day - 100),
            default => 60.0 + 0.5 * ($day - 180),
        };
        // Mercury swinging 25° either side of the Sun, which moves on a degree a day.
        $mercury = fn (int $day) => $day + 25 * sin(2 * M_PI * $day / 116);

        $daily = [];
        foreach (range(0, 399) as $day) {
            foreach (['mars' => $mars($day), 'saturn' => 70.0, 'sun' => (float) $day, 'mercury' => $mercury($day)] as $body => $longitude) {
                $daily[$body][0][] = fmod($longitude + 360, 360);
                $daily[$body][1][] = 0.0;
            }
        }

        $events = [
            ['type' => 'aspect', 'pair' => ['mars', 'saturn'], 'target' => 0.0, 'aspect' => 'conjunction', 'jd' => self::START + 80.01],
            ['type' => 'aspect', 'pair' => ['sun', 'mercury'], 'target' => 0.0, 'aspect' => 'conjunction', 'jd' => self::START + 58],
        ];

        $arcs = SkyCalendar::arcs($daily, self::START, $events, self::START + 50, self::START + 100);

        $this->assertCount(1, $arcs, 'the Sun and Mercury meet again elsewhere, not in a loop');
        $this->assertSame(['mars', 'saturn'], $arcs[0]['bodies']);
        $this->assertSame([true, false, false], array_column($arcs[0]['passes'], 'in_period'));
        $this->assertEqualsWithDelta(self::START + 80.01, $arcs[0]['passes'][0]['jd'], 1e-9, 'the exact minute from the period');
        $this->assertEqualsWithDelta(self::START + 140, $arcs[0]['passes'][1]['jd'], 1e-6);
        $this->assertEqualsWithDelta(self::START + 200, $arcs[0]['passes'][2]['jd'], 1e-6);
    }
}
