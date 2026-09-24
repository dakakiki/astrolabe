<?php

namespace App\Astrology\Contracts;

use App\Astrology\ValueObjects\PlaceResult;
use Illuminate\Support\Collection;

/**
 * Resolves birth places (docs/spec/02, "Geokodiranje je obavezno"). Called only
 * while data is being entered; stored birth details are never resolved again.
 */
interface Geocoder
{
    /**
     * Places matching what the user has typed so far, most likely first.
     * Places in $preferCountry (ISO code) rank above equally good matches elsewhere.
     *
     * @return Collection<int, PlaceResult>
     */
    public function search(string $query, int $limit = 10, ?string $preferCountry = null): Collection;

    /** A place previously offered by search(), by its id. */
    public function find(string $id): ?PlaceResult;

    /** The closest known place, used to suggest a time zone for hand-entered coordinates. */
    public function nearest(float $latitude, float $longitude): ?PlaceResult;
}
