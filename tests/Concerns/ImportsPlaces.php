<?php

namespace Tests\Concerns;

use App\Astrology\Geocoding\GeoNamesImporter;

/**
 * Loads a handful of real GeoNames rows (tests/fixtures/geonames) so place
 * lookups can be tested without downloading the gazetteer.
 */
trait ImportsPlaces
{
    protected function importPlaces(): void
    {
        app(GeoNamesImporter::class)->import(
            base_path('tests/fixtures/geonames/places.txt'),
            base_path('tests/fixtures/geonames/admin1.txt'),
        );
    }
}
