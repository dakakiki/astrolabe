<?php

namespace App\Astrology\Services;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\Support\JulianDay;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\PlanetPosition;
use App\Enums\AspectType;
use App\Enums\CelestialBody;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * The sky itself, nobody's chart (docs/spec/02, "Nebo"; Phase 7d): over a
 * period, the minute each aspect between two planets is exact, the stations,
 * the ingresses and the new and full moons, with retrograde passes of the same
 * aspect gathered into one arc.
 *
 * Two engine runs. Positions every hour through the period give the exact
 * minutes: the days are scanned for a change of sign, and the hour it happened
 * in is interpolated linearly (the same result as a search minute by minute).
 * Daily positions from a year before the period to a year after it show when
 * a retrograde brings an aspect back. Calculated on request and never stored,
 * like transits.
 *
 * Both bodies move, so an aspect is a moment to find, not a state to test: the
 * signed separation is watched as it crosses the aspect's angle. The absolute
 * separation only touches zero at a conjunction and bounces back.
 */
class SkyCalendar
{
    /** Periods offered, in days. */
    public const PERIODS = [7, 30, 90, 365];

    public const DEFAULT_PERIOD = 30;

    /** Days searched before and after the period for the other passes of an aspect. */
    public const ARC_DAYS = 365;

    /** How far either planet may move between the passes of one arc. */
    private const ARC_DEGREES = 30.0;

    public const SIGNS = [
        'aries', 'taurus', 'gemini', 'cancer', 'leo', 'virgo',
        'libra', 'scorpio', 'sagittarius', 'capricorn', 'aquarius', 'pisces',
    ];

    private const HOUR = 1 / 24;

    public function __construct(private readonly EphemerisEngine $engine) {}

    /**
     * Bodies whose aspects, stations and ingresses are listed. The Moon takes
     * part only in new and full moons: its aspects alone would be some 130 a month.
     *
     * @return list<CelestialBody>
     */
    public static function movers(): array
    {
        return [
            CelestialBody::Sun, CelestialBody::Mercury, CelestialBody::Venus, CelestialBody::Mars,
            CelestialBody::Jupiter, CelestialBody::Saturn, CelestialBody::Uranus, CelestialBody::Neptune,
            CelestialBody::Pluto, CelestialBody::Chiron,
        ];
    }

    /**
     * @return list<AspectType>
     */
    public static function aspects(): array
    {
        return [
            AspectType::Conjunction, AspectType::Sextile, AspectType::Square,
            AspectType::Trine, AspectType::Quincunx, AspectType::Opposition,
        ];
    }

    /**
     * The period from `$start` to `$end` (the start of the day after it) in the
     * workspace's zodiac. Positions are those at `$now` when it falls inside
     * the period, otherwise at its start.
     *
     * @return array{
     *     start: string,
     *     end: string,
     *     zodiac_mode: string,
     *     ayanamsa: string|null,
     *     events: list<array<string, mixed>>,
     *     arcs: list<array<string, mixed>>,
     *     positions_at: string,
     *     positions: list<array{body: string, longitude: float, speed: float, retrograde: bool}>,
     *     arc_days: int,
     *     engine: array{name: string, version: string},
     * }
     *
     * @throws EphemerisException
     */
    public function calendar(Workspace $workspace, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now): array
    {
        $first = JulianDay::fromMoment($start);
        $last = JulianDay::fromMoment($end);
        $hours = (int) ceil(($last - $first) * 24);
        $days = (int) ceil($last - $first);

        $bodies = [CelestialBody::Moon, ...self::movers()];
        $hourly = self::compact($this->engine->series($this->request($workspace, $first, $bodies), $hours + 1, self::HOUR));
        $daily = self::compact($this->engine->series(
            $this->request($workspace, $first - self::ARC_DAYS, self::movers()),
            $days + 2 * self::ARC_DAYS + 1,
        ));

        $events = self::events($hourly, $first, $last);
        $arcs = self::arcs($daily, $first - self::ARC_DAYS, $events, $first, $last);

        $nowDay = JulianDay::fromMoment($now);
        $at = $nowDay >= $first && $nowDay < $last ? ($nowDay - $first) * 24 : 0.0;

        return [
            'start' => $start->utc()->toIso8601ZuluString(),
            'end' => $end->utc()->toIso8601ZuluString(),
            'zodiac_mode' => $workspace->default_zodiac_mode->value,
            'ayanamsa' => $workspace->default_ayanamsa?->value,
            'events' => array_map(self::present(...), $events),
            'arcs' => array_map(fn (array $arc) => [
                ...$arc,
                'passes' => array_map(fn (array $pass) => [
                    'at' => self::moment($pass['jd']),
                    'in_period' => $pass['in_period'],
                ], $arc['passes']),
            ], $arcs),
            'positions_at' => self::moment($first + $at * self::HOUR),
            'positions' => array_map(function (CelestialBody $body) use ($hourly, $at) {
                [$longitude, $speed] = self::at($hourly, $at, $body->value);

                return [
                    'body' => $body->value,
                    'longitude' => round($longitude, 7),
                    'speed' => round($speed, 7),
                    'retrograde' => $speed < 0,
                ];
            }, [CelestialBody::Sun, CelestialBody::Moon, ...array_slice(self::movers(), 1)]),
            'arc_days' => self::ARC_DAYS,
            'engine' => ['name' => $this->engine->name(), 'version' => $this->engine->version()],
        ];
    }

    /**
     * Everything that happens between `$first` and `$last` (Julian days, UT),
     * found in positions `1/24` day apart starting at `$first`, earliest first.
     *
     * @param  array<string, array{0: list<float>, 1: list<float>}>  $hourly  body => [longitudes, speeds]
     * @return list<array<string, mixed>>
     */
    public static function events(array $hourly, float $first, float $last): array
    {
        $events = [
            ...self::aspectEvents($hourly, $first),
            ...self::stations($hourly, $first),
            ...self::ingresses($hourly, $first),
            ...self::lunations($hourly, $first),
        ];

        $events = array_values(array_filter($events, fn (array $event) => $event['jd'] >= $first && $event['jd'] < $last));
        usort($events, fn (array $a, array $b) => $a['jd'] <=> $b['jd']);

        return $events;
    }

    /**
     * @param  array<string, array{0: list<float>, 1: list<float>}>  $hourly
     * @return list<array<string, mixed>>
     */
    private static function aspectEvents(array $hourly, float $first): array
    {
        $events = [];
        $movers = array_map(fn (CelestialBody $body) => $body->value, self::movers());
        $coarse = self::coarse(self::length($hourly), 24);

        foreach ($movers as $i => $a) {
            foreach (array_slice($movers, $i + 1) as $b) {
                $separation = fn (int $k) => self::wrap($hourly[$a][0][$k] - $hourly[$b][0][$k]);
                $coarseSeparation = array_map($separation, $coarse);

                foreach (self::aspects() as $type) {
                    foreach (self::targets($type) as $target) {
                        $crossings = self::crossings(
                            $coarse,
                            array_map(fn (float $value) => self::wrap($value - $target), $coarseSeparation),
                            fn (int $k) => self::wrap($separation($k) - $target),
                        );

                        foreach ($crossings as $index) {
                            $events[] = self::aspectEvent($hourly, $first, $index, $a, $b, $type, $target);
                        }
                    }
                }
            }
        }

        return $events;
    }

    /**
     * @param  array<string, array{0: list<float>, 1: list<float>}>  $hourly
     * @return array<string, mixed>
     */
    private static function aspectEvent(array $hourly, float $first, float $index, string $a, string $b, AspectType $type, float $target): array
    {
        [$longitudeA, $speedA] = self::at($hourly, $index, $a);
        [$longitudeB, $speedB] = self::at($hourly, $index, $b);

        // The faster body is said to aspect the slower one ("Mars squares Saturn").
        $bodies = [
            ['body' => $a, 'longitude' => $longitudeA, 'retrograde' => $speedA < 0, 'pace' => abs($speedA)],
            ['body' => $b, 'longitude' => $longitudeB, 'retrograde' => $speedB < 0, 'pace' => abs($speedB)],
        ];
        usort($bodies, fn (array $x, array $y) => $y['pace'] <=> $x['pace']);

        return [
            'type' => 'aspect',
            'jd' => $first + $index * self::HOUR,
            'aspect' => $type->value,
            'pair' => [$a, $b],
            'target' => $target,
            'bodies' => array_map(fn (array $body) => array_diff_key($body, ['pace' => true]), $bodies),
        ];
    }

    /**
     * A planet standing still: its speed changes sign. Retrograde when it
     * turns backwards, direct when it turns forward again.
     *
     * @param  array<string, array{0: list<float>, 1: list<float>}>  $hourly
     * @return list<array<string, mixed>>
     */
    private static function stations(array $hourly, float $first): array
    {
        $events = [];
        $coarse = self::coarse(self::length($hourly), 24);

        foreach (self::movers() as $body) {
            if ($body === CelestialBody::Sun) {
                continue;
            }

            $speed = fn (int $k) => $hourly[$body->value][1][$k];

            foreach (self::crossings($coarse, array_map($speed, $coarse), $speed) as $index) {
                // Half an hour before: still moving the old way.
                $before = self::at($hourly, max(0.0, $index - 0.5), $body->value)[1];

                $events[] = [
                    'type' => 'station',
                    'jd' => $first + $index * self::HOUR,
                    'body' => $body->value,
                    'direction' => $before > 0 ? 'retrograde' : 'direct',
                    'longitude' => self::at($hourly, $index, $body->value)[0],
                ];
            }
        }

        return $events;
    }

    /**
     * A planet entering a sign — forwards, or back into the previous one while
     * retrograde. Signs follow the workspace's zodiac.
     *
     * @param  array<string, array{0: list<float>, 1: list<float>}>  $hourly
     * @return list<array<string, mixed>>
     */
    private static function ingresses(array $hourly, float $first): array
    {
        $events = [];
        $coarse = self::coarse(self::length($hourly), 24);

        foreach (self::movers() as $body) {
            $longitude = fn (int $k) => $hourly[$body->value][0][$k];
            $coarseLongitude = array_map($longitude, $coarse);

            foreach (range(0, 11) as $sign) {
                $cusp = $sign * 30.0;
                $crossings = self::crossings(
                    $coarse,
                    array_map(fn (float $value) => self::wrap($value - $cusp), $coarseLongitude),
                    fn (int $k) => self::wrap($longitude($k) - $cusp),
                );

                foreach ($crossings as $index) {
                    // Half an hour before it was behind the cusp when moving forwards.
                    $forward = self::wrap(self::at($hourly, max(0.0, $index - 0.5), $body->value)[0] - $cusp) < 0;

                    $events[] = [
                        'type' => 'ingress',
                        'jd' => $first + $index * self::HOUR,
                        'body' => $body->value,
                        'sign' => self::SIGNS[$forward ? $sign : ($sign + 11) % 12],
                        'retrograde' => ! $forward,
                    ];
                }
            }
        }

        return $events;
    }

    /**
     * New moons (the Moon joins the Sun) and full moons (it stands opposite).
     *
     * @param  array<string, array{0: list<float>, 1: list<float>}>  $hourly
     * @return list<array<string, mixed>>
     */
    private static function lunations(array $hourly, float $first): array
    {
        $events = [];
        $coarse = self::coarse(self::length($hourly), 24);
        $moon = CelestialBody::Moon->value;
        $sun = CelestialBody::Sun->value;

        foreach (['new' => 0.0, 'full' => 180.0] as $phase => $angle) {
            $elongation = fn (int $k) => self::wrap($hourly[$moon][0][$k] - $hourly[$sun][0][$k] - $angle);

            foreach (self::crossings($coarse, array_map($elongation, $coarse), $elongation) as $index) {
                $events[] = [
                    'type' => 'lunation',
                    'jd' => $first + $index * self::HOUR,
                    'phase' => $phase,
                    'longitude' => self::at($hourly, $index, $moon)[0],
                ];
            }
        }

        return $events;
    }

    /**
     * Aspects that perfect more than once because a planet turns round: direct,
     * retrograde, direct. Passes belong to one arc when both planets are still
     * within ARC_DEGREES of where they stood at its first pass (a retrograde
     * loop is at most some 20°) and the separation came back to the aspect
     * instead of going all the way round. An ordinary return of the aspect —
     * or the Sun meeting Mercury again, which never goes round at all —
     * happens somewhere else in the zodiac. Followed in daily positions a year
     * before and after the period; passes inside it keep their exact minute.
     *
     * @param  array<string, array{0: list<float>, 1: list<float>}>  $daily
     * @param  list<array<string, mixed>>  $events
     * @return list<array{bodies: list<string>, aspect: string, passes: list<array{jd: float, in_period: bool}>}>
     */
    public static function arcs(array $daily, float $dailyFirst, array $events, float $first, float $last): array
    {
        $arcs = [];
        $searched = [];

        foreach ($events as $event) {
            if ($event['type'] !== 'aspect') {
                continue;
            }

            [$a, $b] = $event['pair'];
            $key = "{$a}|{$b}|{$event['target']}";

            if (isset($searched[$key])) {
                continue;
            }
            $searched[$key] = true;

            foreach (self::runs(self::dailyPasses($daily, $dailyFirst, $a, $b, $event['target'])) as $run) {
                $passes = array_map(fn (array $pass) => self::pass($pass['jd'], $events, $key, $first, $last), $run);

                if (count($passes) < 2 || array_filter($passes, fn (array $pass) => $pass['in_period']) === []) {
                    continue;
                }

                $arcs[] = ['bodies' => [$a, $b], 'aspect' => $event['aspect'], 'passes' => $passes];
            }
        }

        usort($arcs, fn (array $x, array $y) => $x['passes'][0]['jd'] <=> $y['passes'][0]['jd']);

        return $arcs;
    }

    /**
     * Every pass of one aspect between two planets in the daily positions,
     * with where both stood and how many times the separation had gone round.
     *
     * @param  array<string, array{0: list<float>, 1: list<float>}>  $daily
     * @return list<array{jd: float, a: float, b: float, lap: int}>
     */
    private static function dailyPasses(array $daily, float $dailyFirst, string $a, string $b, float $target): array
    {
        $passes = [];
        $previous = null;
        $unwrapped = 0.0;

        for ($day = 0, $days = self::length($daily); $day < $days; $day++) {
            $value = self::wrap($daily[$a][0][$day] - $daily[$b][0][$day] - $target);

            if ($previous === null) {
                $unwrapped = $value;
            } else {
                $unwrapped += self::wrap($value - $previous);

                if (self::crosses($previous, $value)) {
                    $index = $day - 1 + $previous / ($previous - $value);
                    $passes[] = [
                        'jd' => $dailyFirst + $index,
                        'a' => self::at($daily, $index, $a)[0],
                        'b' => self::at($daily, $index, $b)[0],
                        'lap' => (int) round($unwrapped / 360),
                    ];
                }
            }

            $previous = $value;
        }

        return $passes;
    }

    /**
     * Passes grouped into arcs, each measured against the arc's first pass
     * (chaining pass to pass would let slow planets drift on indefinitely).
     *
     * @param  list<array{jd: float, a: float, b: float, lap: int}>  $passes
     * @return list<list<array{jd: float, a: float, b: float, lap: int}>>
     */
    private static function runs(array $passes): array
    {
        $runs = [];
        $run = [];

        foreach ($passes as $pass) {
            $first = $run[0] ?? null;

            if ($first !== null
                && $pass['lap'] === $first['lap']
                && abs(self::wrap($pass['a'] - $first['a'])) <= self::ARC_DEGREES
                && abs(self::wrap($pass['b'] - $first['b'])) <= self::ARC_DEGREES) {
                $run[] = $pass;

                continue;
            }

            if ($run !== []) {
                $runs[] = $run;
            }
            $run = [$pass];
        }

        if ($run !== []) {
            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * A pass of an arc: the exact event when it is in the period, otherwise
     * the day found in the daily positions.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array{jd: float, in_period: bool}
     */
    private static function pass(float $jd, array $events, string $key, float $first, float $last): array
    {
        foreach ($events as $event) {
            if ($event['type'] === 'aspect'
                && "{$event['pair'][0]}|{$event['pair'][1]}|{$event['target']}" === $key
                && abs($event['jd'] - $jd) < 1.5) {
                return ['jd' => $event['jd'], 'in_period' => true];
            }
        }

        return ['jd' => $jd, 'in_period' => $jd >= $first && $jd < $last];
    }

    /**
     * Fractional indices where a value crosses zero. The values at `$coarse`
     * (every day) are scanned first; a day with a change of sign is walked
     * hour by hour with `$fine`, and the hour interpolated.
     *
     * @param  list<int>  $coarse
     * @param  list<float>  $coarseValues
     * @param  callable(int): float  $fine
     * @return list<float>
     */
    private static function crossings(array $coarse, array $coarseValues, callable $fine): array
    {
        $found = [];

        for ($i = 1, $n = count($coarse); $i < $n; $i++) {
            if (! self::crosses($coarseValues[$i - 1], $coarseValues[$i])) {
                continue;
            }

            $previous = $coarseValues[$i - 1];

            for ($k = $coarse[$i - 1] + 1; $k <= $coarse[$i]; $k++) {
                $value = $k === $coarse[$i] ? $coarseValues[$i] : $fine($k);

                if (self::crosses($previous, $value)) {
                    $found[] = $k - 1 + $previous / ($previous - $value);
                    break;
                }

                $previous = $value;
            }
        }

        return $found;
    }

    /** A change of sign between neighbours; a wrap from +180° to −180° is not one. */
    private static function crosses(float $before, float $after): bool
    {
        return (($before < 0 && $after >= 0) || ($before > 0 && $after <= 0)) && abs($after - $before) < 90;
    }

    /**
     * 0, 24, 48 … and the last index.
     *
     * @return list<int>
     */
    private static function coarse(int $count, int $stride): array
    {
        $indices = range(0, $count - 1, $stride);

        if (end($indices) !== $count - 1) {
            $indices[] = $count - 1;
        }

        return $indices;
    }

    /**
     * Longitude and speed of a body at a fractional index.
     *
     * @param  array<string, array{0: list<float>, 1: list<float>}>  $series
     * @return array{0: float, 1: float}
     */
    private static function at(array $series, float $index, string $body): array
    {
        [$longitudes, $speeds] = $series[$body];
        $low = (int) floor($index);
        $high = min($low + 1, count($longitudes) - 1);
        $fraction = $index - $low;
        [$longitude, $speed, $nextLongitude, $nextSpeed] = [$longitudes[$low], $speeds[$low], $longitudes[$high], $speeds[$high]];

        return [
            fmod(fmod($longitude + self::wrap($nextLongitude - $longitude) * $fraction, 360) + 360, 360),
            $speed + ($nextSpeed - $speed) * $fraction,
        ];
    }

    /**
     * Conjunction and opposition have one target; the others one on each side.
     *
     * @return list<float>
     */
    private static function targets(AspectType $type): array
    {
        $angle = $type->angle();

        return $angle === 0.0 || $angle === 180.0 ? [$angle] : [$angle, -$angle];
    }

    /**
     * @param  list<CelestialBody>  $bodies
     */
    private function request(Workspace $workspace, float $julianDay, array $bodies): ChartRequest
    {
        return new ChartRequest(
            julianDayUt: $julianDay,
            latitude: null,
            longitude: null,
            houseSystem: null,
            zodiacMode: $workspace->default_zodiac_mode,
            ayanamsa: $workspace->default_ayanamsa,
            bodies: $bodies,
        );
    }

    /**
     * Longitudes and speeds as lists of numbers per body: a year of hours is
     * some 100,000 positions, and plain lists keep that to a few megabytes.
     *
     * @param  list<list<PlanetPosition>>  $series
     * @return array<string, array{0: list<float>, 1: list<float>}>
     */
    private static function compact(array $series): array
    {
        $columns = [];

        foreach ($series as $positions) {
            foreach ($positions as $position) {
                $columns[$position->body->value][0][] = $position->longitude;
                $columns[$position->body->value][1][] = $position->speed;
            }
        }

        return $columns;
    }

    /**
     * How many moments a series holds.
     *
     * @param  array<string, array{0: list<float>, 1: list<float>}>  $series
     */
    private static function length(array $series): int
    {
        return count(reset($series)[0] ?? []);
    }

    /**
     * An event as the API shows it: the moment to the minute, no working values.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private static function present(array $event): array
    {
        $shown = ['at' => self::moment($event['jd'])] + array_diff_key($event, array_flip(['jd', 'pair', 'target']));

        if (isset($shown['longitude'])) {
            $shown['longitude'] = round($shown['longitude'], 7);
        }

        if (isset($shown['bodies'])) {
            $shown['bodies'] = array_map(fn (array $body) => [...$body, 'longitude' => round($body['longitude'], 7)], $shown['bodies']);
        }

        return $shown;
    }

    private static function moment(float $julianDay): string
    {
        return JulianDay::toMoment($julianDay)->utc()->addSeconds(30)->startOfMinute()->toIso8601ZuluString();
    }

    private static function wrap(float $degrees): float
    {
        $degrees = fmod($degrees, 360.0);

        if ($degrees > 180) {
            return $degrees - 360;
        }

        return $degrees <= -180 ? $degrees + 360 : $degrees;
    }
}
