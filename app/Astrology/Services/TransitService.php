<?php

namespace App\Astrology\Services;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\Exceptions\IncompleteBirthData;
use App\Astrology\Support\JulianDay;
use App\Astrology\ValueObjects\Aspect;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\Houses;
use App\Astrology\ValueObjects\PlanetPosition;
use App\Enums\AspectType;
use App\Enums\CelestialBody;
use App\Models\ChartCalculation;
use App\Models\Client;
use App\Models\RelatedPerson;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Transits: where the planets stand at a moment, measured against a natal
 * chart (docs/spec/11, Phase 7a). Calculated on request and never stored —
 * only the natal chart underneath is cached.
 *
 * One engine run covers the moment and the days around it: the moment is the
 * middle of a daily series, and the other days tell when each slow transit
 * is exact. Starting the engine costs far more than calculating, and the sky
 * is the same for every client, so a request reuses its series.
 */
class TransitService
{
    /** Days looked at on either side of the moment for the dates a slow transit is exact. */
    public const SEARCH_DAYS = 365;

    /** @var array<string, list<list<PlanetPosition>>> daily series by request, for this instance's lifetime */
    private array $series = [];

    public function __construct(
        private readonly EphemerisEngine $engine,
        private readonly ChartService $charts,
        private readonly AspectCalculator $aspects,
    ) {}

    /**
     * Transits to the natal chart of a client or a related person.
     *
     * @return array{
     *     moment: string,
     *     julian_day_ut: float,
     *     zodiac_mode: string,
     *     ayanamsa: string|null,
     *     positions: list<array{body: string, longitude: float, speed: float, retrograde: bool, house: int|null}>,
     *     contacts: list<array{transit: string, natal: string, type: string, orb: float, applying: bool|null, exact: list<string>|null}>,
     *     orbs: array<string, mixed>,
     *     search_days: int,
     *     engine: array{name: string, version: string},
     *     natal: ChartCalculation,
     * }
     *
     * @throws IncompleteBirthData
     * @throws EphemerisException
     */
    public function transits(Client|RelatedPerson $subject, CarbonImmutable $moment): array
    {
        $natal = $this->charts->natal($subject);
        $workspace = $subject->workspace;
        $settings = $workspace->transitSettings();
        $days = $this->days($workspace, $moment);
        $sky = $days[self::SEARCH_DAYS];
        $cusps = $natal->payload['houses']['cusps'] ?? null;

        $contacts = $this->aspects->across(self::points($sky), self::natalPoints($natal), $settings);
        usort($contacts, fn (Aspect $a, Aspect $b) => $a->orb <=> $b->orb);

        return [
            'moment' => $moment->utc()->toIso8601ZuluString(),
            'julian_day_ut' => round(JulianDay::fromMoment($moment), 8),
            'zodiac_mode' => $workspace->default_zodiac_mode->value,
            'ayanamsa' => $workspace->default_ayanamsa?->value,
            'positions' => array_map(fn (PlanetPosition $position) => $position->toArray() + [
                'house' => $cusps ? Houses::numberFor($cusps, $position->longitude) : null,
            ], $sky),
            'contacts' => array_map(fn (Aspect $aspect) => $this->contact($aspect, $natal, $days, $moment), $contacts),
            'orbs' => $settings->toArray(),
            'search_days' => self::SEARCH_DAYS,
            'engine' => ['name' => $this->engine->name(), 'version' => $this->engine->version()],
            'natal' => $natal,
        ];
    }

    /**
     * The daily positions from SEARCH_DAYS before the moment to as many after,
     * in the workspace's zodiac; the middle one is the moment itself.
     *
     * @return list<list<PlanetPosition>>
     */
    public function days(Workspace $workspace, CarbonImmutable $moment): array
    {
        $request = new ChartRequest(
            julianDayUt: JulianDay::fromMoment($moment) - self::SEARCH_DAYS,
            latitude: null,
            longitude: null,
            houseSystem: null,
            zodiacMode: $workspace->default_zodiac_mode,
            ayanamsa: $workspace->default_ayanamsa,
            bodies: CelestialBody::natal(),
        );

        $key = json_encode($request->normalized());

        return $this->series[$key] ??= $this->engine->series($request, 2 * self::SEARCH_DAYS + 1);
    }

    /**
     * The contact as the API shows it; a slow transit also says when it is
     * exact within the searched days (a retrograde loop can give three dates).
     *
     * @param  list<list<PlanetPosition>>  $days
     * @return array{transit: string, natal: string, type: string, orb: float, applying: bool|null, exact: list<string>|null}
     */
    private function contact(Aspect $aspect, ChartCalculation $natal, array $days, CarbonImmutable $moment): array
    {
        $body = CelestialBody::from($aspect->first);
        $exact = null;

        if (in_array($body, CelestialBody::slow(), true)) {
            $index = array_search($body, CelestialBody::natal(), true);
            $target = self::natalLongitude($natal, $aspect->second);
            $start = JulianDay::fromMoment($moment) - self::SEARCH_DAYS;

            $exact = array_map(
                fn (float $julianDay) => JulianDay::toMoment($julianDay)->toIso8601ZuluString(),
                self::exactDays(array_map(fn (array $positions) => $positions[$index]->longitude, $days), $target, $aspect->type, $start),
            );
        }

        return [
            'transit' => $aspect->first,
            'natal' => $aspect->second,
            'type' => $aspect->type->value,
            'orb' => round($aspect->orb, 4),
            'applying' => $aspect->applying,
            'exact' => $exact,
        ];
    }

    /**
     * When a moving point makes an aspect to a fixed one: where its signed
     * distance crosses the aspect's angle (either side, for all but the
     * conjunction and opposition), interpolated between daily samples. The
     * signed distance matters — the absolute one only touches zero at a
     * conjunction, so no change of sign would ever show it.
     *
     * @param  list<float>  $longitudes  one per day, from $startJulianDay on
     * @return list<float> Julian days, earliest first
     */
    public static function exactDays(array $longitudes, float $target, AspectType $type, float $startJulianDay): array
    {
        $angle = $type->angle();
        $sides = $angle === 0.0 || $angle === 180.0 ? [$angle] : [$angle, -$angle];
        $found = [];

        foreach ($sides as $side) {
            $before = null;

            foreach ($longitudes as $day => $longitude) {
                $gap = self::wrap($longitude - $target - $side);

                // Opposite signs a little apart is a crossing; far apart is the ±180° seam.
                if ($before !== null && ($before < 0) !== ($gap < 0) && abs($before - $gap) < 90) {
                    $found[] = $startJulianDay + $day - 1 + $before / ($before - $gap);
                }

                $before = $gap;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * The transiting points: every body that takes aspects.
     *
     * @param  list<PlanetPosition>  $positions
     * @return list<array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}>
     */
    public static function points(array $positions): array
    {
        return array_values(array_map(fn (PlanetPosition $position) => [
            'key' => $position->body->value,
            'longitude' => $position->longitude,
            'speed' => $position->speed,
            'luminary' => $position->body->isLuminary(),
            'angle' => false,
        ], array_filter($positions, fn (PlanetPosition $position) => $position->body->takesAspects())));
    }

    /**
     * The natal points transits are measured against, as the natal chart's own
     * aspects use them: the bodies without the mean node — and without the Moon
     * when the birth time is unknown — then the Ascendant and Midheaven.
     *
     * @return list<array{key: string, longitude: float, speed: float|null, luminary: bool, angle: bool}>
     */
    public static function natalPoints(ChartCalculation $natal): array
    {
        $timeKnown = $natal->time_accuracy->hasTime();
        $points = [];

        foreach ($natal->payload['positions'] as $position) {
            $body = CelestialBody::tryFrom($position['body']);

            if ($body === null || ! $body->takesAspects() || (! $timeKnown && $body === CelestialBody::Moon)) {
                continue;
            }

            $points[] = [
                'key' => $body->value,
                'longitude' => (float) $position['longitude'],
                'speed' => (float) $position['speed'],
                'luminary' => $body->isLuminary(),
                'angle' => false,
            ];
        }

        foreach (['asc', 'mc'] as $angle) {
            if (isset($natal->payload['angles'][$angle])) {
                $points[] = ['key' => $angle, 'longitude' => (float) $natal->payload['angles'][$angle], 'speed' => null, 'luminary' => false, 'angle' => true];
            }
        }

        return $points;
    }

    private static function natalLongitude(ChartCalculation $natal, string $key): float
    {
        foreach (self::natalPoints($natal) as $point) {
            if ($point['key'] === $key) {
                return $point['longitude'];
            }
        }

        throw new LogicException("No natal point {$key}.");
    }

    /** Into (-180°, 180°]. */
    private static function wrap(float $degrees): float
    {
        $degrees = fmod($degrees, 360);

        if ($degrees > 180) {
            return $degrees - 360;
        }

        return $degrees <= -180 ? $degrees + 360 : $degrees;
    }
}
