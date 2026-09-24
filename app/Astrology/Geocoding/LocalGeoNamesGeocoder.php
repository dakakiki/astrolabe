<?php

namespace App\Astrology\Geocoding;

use App\Astrology\Contracts\Geocoder;
use App\Astrology\ValueObjects\PlaceResult;
use App\Enums\GeocodeSource;
use App\Models\Place;
use App\Support\SearchText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Searches the local copy of GeoNames (the `places` tables, filled by
 * `places:import`). Nothing the user types leaves the server.
 */
class LocalGeoNamesGeocoder implements Geocoder
{
    public function search(string $query, int $limit = 10, ?string $preferCountry = null): Collection
    {
        // "Novi Sad, Serbia" → search on the place part only.
        $term = SearchText::normalize(strtok($query, ',') ?: '');

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $matches = DB::table('place_names')
            ->select('place_id')
            ->selectRaw('max(search_name = ?) as exact_match', [$term])
            ->where('search_name', 'like', $term.'%')
            ->groupBy('place_id');

        // Whole-name matches first, then the practice's own country, then real towns
        // before city districts (PPLX), then the larger place.
        return Place::query()
            ->joinSub($matches, 'matches', 'matches.place_id', '=', 'places.id')
            ->orderByDesc('matches.exact_match')
            ->when($preferCountry, fn ($query) => $query->orderByRaw('places.country_code = ? desc', [strtoupper($preferCountry)]))
            ->orderByRaw("places.feature_code = 'PPLX'")
            ->orderByDesc('places.population')
            ->limit($limit)
            ->get(['places.*'])
            ->map(fn (Place $place) => $this->toResult($place));
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
