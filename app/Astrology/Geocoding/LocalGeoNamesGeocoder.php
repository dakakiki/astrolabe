<?php

namespace App\Astrology\Geocoding;

use App\Astrology\Contracts\Geocoder;
use App\Astrology\ValueObjects\PlaceResult;
use App\Enums\GeocodeSource;
use App\Models\Place;
use App\Support\SearchText;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Searches the local copy of GeoNames (the `places` tables, filled by
 * `places:import`). Nothing the user types leaves the server.
 */
class LocalGeoNamesGeocoder implements Geocoder
{
    /** From this many letters on, prefixes also match minor places (villages, hamlets). */
    private const FULL_PREFIX_LENGTH = 5;

    /** Most village candidates considered for one prefix. */
    private const MINOR_CANDIDATES = 2000;

    public function search(string $query, int $limit = 10, ?string $preferCountry = null): Collection
    {
        // "Novi Sad, Serbia" → search on the place part only.
        $term = SearchText::normalize(strtok($query, ',') ?: '');

        if (mb_strlen($term) < 2) {
            return collect();
        }

        // Candidates, in tiers, since the full gazetteer holds ~9 million names:
        // - a whole name always matches, in every place however small ("Ub", "Ig");
        // - a prefix of 3+ letters matches major places (towns, administrative seats);
        // - a prefix of 5+ letters also matches villages and hamlets, capped so a
        //   broad prefix ("santa") stays fast — typing on narrows it anyway.
        $length = mb_strlen($term);

        $candidates = DB::table('place_names')
            ->select('place_id')
            ->selectRaw('1 as exact_match')
            ->where('search_name', $term);

        if ($length >= 3) {
            $candidates->unionAll($this->prefixMatches($term)->where('major', true));
        }

        if ($length >= self::FULL_PREFIX_LENGTH) {
            $candidates->unionAll($this->prefixMatches($term)->limit(self::MINOR_CANDIDATES));
        }

        $matches = DB::query()
            ->fromSub($candidates, 'candidates')
            ->select('place_id')
            ->selectRaw('max(exact_match) as exact_match')
            ->groupBy('place_id');

        // One score, so no single signal wins outright (each point ≈ tenfold population):
        //   the place's own name typed in full    +3
        //   an alternate name typed in full       +2
        //   the start of the place's own name     +2
        //   the start of an alternate name         0  (keeps "Santa Fe de Bogotá" from outranking Santiago)
        //   in the practice's own country         +3  (a local village beats a foreign town)
        //   a city district (PPLX)                -1
        //   plus log10 of the population.
        $ownName = "replace(lower(places.ascii_name), '-', ' ')";
        $countryBoost = $preferCountry ? '(places.country_code = ?) * 3' : '0';

        return Place::query()
            ->joinSub($matches, 'matches', 'matches.place_id', '=', 'places.id')
            ->orderByRaw(
                "(case when {$ownName} = ? then 3 when matches.exact_match = 1 then 2 when {$ownName} like ? then 2 else 0 end)
                    + {$countryBoost} + log10(places.population + 1) - (places.feature_code = 'PPLX') desc",
                array_filter([$term, $term.'%', $preferCountry ? strtoupper($preferCountry) : null]),
            )
            ->orderBy('places.id')
            ->limit($limit)
            ->get(['places.*'])
            ->map(fn (Place $place) => $this->toResult($place));
    }

    private function prefixMatches(string $term): Builder
    {
        return DB::table('place_names')
            ->select('place_id')
            ->selectRaw('0 as exact_match')
            ->where('search_name', 'like', $term.'%');
    }

    public function find(string $id): ?PlaceResult
    {
        $place = ctype_digit($id) ? Place::find((int) $id) : null;

        return $place ? $this->toResult($place) : null;
    }

    public function nearest(float $latitude, float $longitude): ?PlaceResult
    {
        // Widen the box until something is found; compare squared distances with
        // longitude scaled by latitude, which is plenty for picking a neighbour.
        $scale = max(cos(deg2rad($latitude)), 0.01);

        foreach ([0.5, 2.0, 8.0] as $radius) {
            $place = Place::query()
                ->whereBetween('latitude', [$latitude - $radius, $latitude + $radius])
                ->whereBetween('longitude', [$longitude - $radius / $scale, $longitude + $radius / $scale])
                ->orderByRaw(
                    'pow(latitude - ?, 2) + pow((longitude - ?) * ?, 2)',
                    [$latitude, $longitude, $scale],
                )
                ->first();

            if ($place) {
                return $this->toResult($place);
            }
        }

        return null;
    }

    private function toResult(Place $place): PlaceResult
    {
        return new PlaceResult(
            id: (string) $place->id,
            name: $place->name,
            label: $place->label(),
            countryCode: $place->country_code,
            latitude: $place->latitude,
            longitude: $place->longitude,
            timezone: $place->timezone,
            population: $place->population,
            source: GeocodeSource::GeoNames,
        );
    }
}
