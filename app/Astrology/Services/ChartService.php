<?php

namespace App\Astrology\Services;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Exceptions\IncompleteBirthData;
use App\Astrology\Support\JulianDay;
use App\Astrology\ValueObjects\Aspect;
use App\Astrology\ValueObjects\ChartRequest;
use App\Astrology\ValueObjects\PlanetPosition;
use App\Enums\CelestialBody;
use App\Enums\HouseSystem;
use App\Models\ChartCalculation;
use App\Models\Client;
use App\Models\ClientBirthDetails;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turns a client's birth details into a stored natal chart (docs/spec/11).
 *
 * The chart is cached under a hash of everything that shapes it — the moment,
 * place, chart settings, aspect orbs, time accuracy, the engine with its data
 * files and the payload format — so the same input is calculated once, and any
 * change yields a new row while the old one stays for comparison.
 */
class ChartService
{
    public const NATAL = 'natal';

    /**
     * The shape of `payload`. 1: positions only (Phase 3). 2: angles, houses
     * and aspects. Part of the hash, so a chart stored in an older shape is
     * never served where a newer one is expected; old rows stay as they are.
     */
    public const PAYLOAD_VERSION = 2;

    public function __construct(
        private readonly EphemerisEngine $engine,
        private readonly AspectCalculator $aspects,
    ) {}

    /**
     * The client's natal chart in the workspace's house system, or in the one
     * chosen on the chart screen. Each system is its own cached calculation.
     *
     * @throws IncompleteBirthData
     */
    public function natal(Client $client, ?HouseSystem $houseSystem = null): ChartCalculation
    {
        $birth = $client->birthDetails;
        $missing = $birth?->missingForChart() ?? ['birth_date', 'birth_time', 'location', 'timezone'];

        if ($missing !== []) {
            throw new IncompleteBirthData($missing);
        }

        $workspace = $client->workspace;
        $timeKnown = $birth->time_accuracy->hasTime();
        $aspectSettings = $workspace->aspectSettings();

        // An unknown time is calculated for 12:00 UT on the birth date, without
        // angles or houses (docs/spec/02, docs/spec/11).
        $moment = $timeKnown
            ? $birth->localMoment()->utc()
            : CarbonImmutable::parse($birth->birth_date->format('Y-m-d').' 12:00:00', 'UTC');

        $request = new ChartRequest(
            julianDayUt: JulianDay::fromMoment($moment),
            latitude: $birth->latitude,
            longitude: $birth->longitude,
            houseSystem: $timeKnown ? ($houseSystem ?? $workspace->default_house_system) : null,
            zodiacMode: $workspace->default_zodiac_mode,
            ayanamsa: $workspace->default_ayanamsa,
            bodies: CelestialBody::natal(),
            includeHouses: $timeKnown,
        );

        $hash = hash('sha256', json_encode([
            'chart_type' => self::NATAL,
            'payload_version' => self::PAYLOAD_VERSION,
            'time_accuracy' => $birth->time_accuracy->value,
            'engine' => $this->engine->fingerprint(),
            'request' => $request->normalized(),
            'aspects' => $aspectSettings->toArray(),
        ]));

        $existing = $this->find($client, $hash);

        if ($existing) {
            return $existing;
        }

        $result = $this->engine->calculate($request);
        $houses = $result->houses;
        $houseData = $houses?->toArray();

        $payload = [
            'version' => self::PAYLOAD_VERSION,
            'location' => ['latitude' => $birth->latitude, 'longitude' => $birth->longitude],
            'positions' => array_map(fn (PlanetPosition $position) => $position->toArray() + [
                'house' => $houses?->houseOf($position->longitude),
            ], $result->positions),
            'houses' => $houseData['houses'] ?? null,
            'angles' => $houseData['angles'] ?? null,
            'aspects' => array_map(
                fn (Aspect $aspect) => $aspect->toArray(),
                $this->aspects->between(AspectCalculator::natalPoints($result, $timeKnown), $aspectSettings),
            ),
            'aspect_settings' => $aspectSettings->toArray(),
        ];

        if (! $timeKnown) {
            $payload['moon_range'] = $this->moonRange($birth, $request);
        }

        $chart = new ChartCalculation([
            'subject_type' => $client->getMorphClass(),
            'subject_id' => $client->getKey(),
            'chart_type' => self::NATAL,
            'input_hash' => $hash,
            'julian_day_ut' => $request->julianDayUt,
            'house_system' => $request->houseSystem,
            'zodiac_mode' => $request->zodiacMode,
            'ayanamsa' => $request->normalized()['ayanamsa'],
            'time_accuracy' => $birth->time_accuracy,
            'payload' => $payload,
            'engine_name' => $result->engineName,
            'engine_version' => $result->engineVersion,
            'ephemeris_version' => $result->ephemerisVersion,
            'tzdata_version' => timezone_version_get(),
            'calculated_at' => now(),
        ]);
        $chart->workspace_id = $client->workspace_id;

        try {
            $chart->save();
        } catch (UniqueConstraintViolationException) {
            // A parallel request stored the same chart first.
            return $this->find($client, $hash);
        }

        return $chart;
    }

    private function find(Client $client, string $hash): ?ChartCalculation
    {
        return ChartCalculation::query()
            ->where('subject_type', $client->getMorphClass())
            ->where('subject_id', $client->getKey())
            ->where('chart_type', self::NATAL)
            ->where('input_hash', $hash)
            ->first();
    }

    /**
     * Where the Moon was at the start and at the end of the local birth date. It
     * moves about 13° a day, so with an unknown time only the span is known.
     *
     * @return array{from: float, to: float}
     */
    private function moonRange(ClientBirthDetails $birth, ChartRequest $noon): array
    {
        $date = $birth->birth_date->format('Y-m-d');
        $start = CarbonImmutable::parse($date.' 00:00:00', $birth->birth_timezone);
        // The next local midnight, not start + 24 h: clock-change days last 23 or 25 hours.
        $end = CarbonImmutable::parse($birth->birth_date->addDay()->format('Y-m-d').' 00:00:00', $birth->birth_timezone);

        $moonAt = function (CarbonImmutable $moment) use ($noon): float {
            $request = new ChartRequest(
                julianDayUt: JulianDay::fromMoment($moment->utc()),
                latitude: $noon->latitude,
                longitude: $noon->longitude,
                houseSystem: null,
                zodiacMode: $noon->zodiacMode,
                ayanamsa: $noon->ayanamsa,
                bodies: [CelestialBody::Moon],
            );

            return round($this->engine->calculate($request)->positions[0]->longitude, 7);
        };

        return ['from' => $moonAt($start), 'to' => $moonAt($end)];
    }
}
