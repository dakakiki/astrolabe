<?php

namespace Tests\Feature\Astrology;

use App\Astrology\Engines\SwissEphemerisEngine;
use App\Astrology\Services\SkyCalendar;
use App\Astrology\Support\JulianDay;
use App\Astrology\ValueObjects\ChartRequest;
use App\Enums\AspectType;
use App\Enums\CelestialBody;
use App\Enums\ZodiacMode;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The sky calendar against the real engine (Phase 7d): at the minute given
 * for each event, a separate calculation must show the aspect, the station,
 * the ingress or the lunation within an arc-minute. Checks the search, not the
 * ephemeris (that is SwissEphemerisReferenceTest). Skipped without swetest.
 */
class SkyCalendarAccuracyTest extends TestCase
{
    private const ARC_MINUTE = 1 / 60;

    public function test_every_kind_of_event_is_exact_at_its_minute(): void
    {
        $binary = config('astrolabe.ephemeris.swetest');
        $path = config('astrolabe.ephemeris.path');

        if (! is_file($binary) || ! glob($path.'/*.se1')) {
            $this->markTestSkipped('Swiss Ephemeris is not installed here.');
        }

        $engine = new SwissEphemerisEngine($binary, $path);
        $workspace = new Workspace;
        $workspace->default_zodiac_mode = ZodiacMode::Tropical;
        $workspace->default_ayanamsa = null;

        // Mercury and Venus retrograde, the Sun into Scorpio, a full moon.
        $start = CarbonImmutable::parse('2026-10-18 00:00', 'UTC');
        $calendar = (new SkyCalendar($engine))->calendar($workspace, $start, $start->addDays(30), $start);
        $position = function (string $at, string $body) use ($engine): array {
            $julianDay = JulianDay::fromMoment(CarbonImmutable::parse($at));
            $result = $engine->calculate(new ChartRequest($julianDay, null, null, null, ZodiacMode::Tropical, null, [CelestialBody::from($body)]));

            return [$result->positions[0]->longitude, $result->positions[0]->speed];
        };
        // The shorter way round between two longitudes, 0–180°.
        $gap = fn (float $degrees) => abs(fmod(fmod($degrees, 360) + 540, 360) - 180);
        $first = fn (string $type) => collect($calendar['events'])->where('type', $type)->values();

        foreach ($first('aspect')->take(4) as $event) {
            [$a, $b] = array_column($event['bodies'], 'body');
            $angle = AspectType::from($event['aspect'])->angle();
            $separation = $gap($position($event['at'], $a)[0] - $position($event['at'], $b)[0]);

            $this->assertEqualsWithDelta($angle, $separation, self::ARC_MINUTE, "{$a} {$event['aspect']} {$b} at {$event['at']}");
        }

        $station = $first('station')->firstWhere('body', 'mercury');
        [, $speed] = $position($station['at'], 'mercury');
        $this->assertSame('retrograde', $station['direction']);
        $this->assertLessThan(0.001, abs($speed), 'Mercury barely moves at its station');

        $ingress = $first('ingress')->firstWhere('body', 'sun');
        $this->assertSame('scorpio', $ingress['sign']);
        $this->assertEqualsWithDelta(0, $gap($position($ingress['at'], 'sun')[0] - 210), self::ARC_MINUTE);

        $lunation = $first('lunation')->first();
        $elongation = $gap($position($lunation['at'], 'moon')[0] - $position($lunation['at'], 'sun')[0]);
        $this->assertEqualsWithDelta($lunation['phase'] === 'full' ? 180 : 0, $elongation, self::ARC_MINUTE);
    }
}
