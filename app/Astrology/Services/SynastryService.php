<?php

namespace App\Astrology\Services;

use App\Astrology\ValueObjects\Aspect;
use App\Astrology\ValueObjects\AspectSettings;
use App\Astrology\ValueObjects\Houses;
use App\Enums\CelestialBody;
use App\Models\ChartCalculation;

/**
 * Two natal charts compared (docs/spec/11, Phase 7e): the contacts between
 * them, where each person's points fall in the other's houses, and the
 * composite chart. Worked out on request from the two cached natal charts —
 * no engine run of its own, nothing stored.
 *
 * The client whose profile it is stands inside; the other person is the one
 * compared with, and their point comes first in a contact (the prototype's
 * order: "Marko's Venus trine Ana's Mars").
 */
class SynastryService
{
    public function __construct(private readonly AspectCalculator $aspects) {}

    /**
     * @return array{
     *     contacts: list<array{a: string, b: string, type: string, orb: float}>,
     *     overlays: array{other_in_client: array<string, int>|null, client_in_other: array<string, int>|null},
     *     composite: array<string, mixed>,
     * }
     */
    public function compare(ChartCalculation $client, ChartCalculation $other, AspectSettings $settings): array
    {
        $contacts = $this->aspects->betweenCharts(
            AspectCalculator::storedPoints($other),
            AspectCalculator::storedPoints($client),
            $settings,
        );
        usort($contacts, fn (Aspect $a, Aspect $b) => $a->orb <=> $b->orb);

        $composite = CompositeChart::of($client, $other);
        $composite['aspects'] = array_map(
            fn (Aspect $aspect) => $aspect->toArray(),
            $this->aspects->between(CompositeChart::points($composite), $settings),
        );

        return [
            'contacts' => array_map(fn (Aspect $aspect) => [
                'a' => $aspect->first,
                'b' => $aspect->second,
                'type' => $aspect->type->value,
                'orb' => round($aspect->orb, 4),
            ], $contacts),
            'overlays' => [
                'other_in_client' => self::overlay($other, $client),
                'client_in_other' => self::overlay($client, $other),
            ],
            'composite' => $composite,
        ];
    }

    /**
     * The house of the host chart each point of the guest chart falls in, or
     * null when the host has no houses (unknown birth time). Without a birth
     * time the guest's Moon has no single house and there are no angles.
     *
     * @return array<string, int>|null point => house
     */
    public static function overlay(ChartCalculation $guest, ChartCalculation $host): ?array
    {
        $cusps = $host->payload['houses']['cusps'] ?? null;

        if ($cusps === null) {
            return null;
        }

        $timeKnown = $guest->time_accuracy->hasTime();
        $houses = [];

        foreach ($guest->payload['positions'] as $position) {
            if (! $timeKnown && $position['body'] === CelestialBody::Moon->value) {
                continue;
            }

            $houses[$position['body']] = Houses::numberFor($cusps, (float) $position['longitude']);
        }

        foreach (['asc', 'mc'] as $angle) {
            if (isset($guest->payload['angles'][$angle])) {
                $houses[$angle] = Houses::numberFor($cusps, (float) $guest->payload['angles'][$angle]);
            }
        }

        return $houses;
    }
}
