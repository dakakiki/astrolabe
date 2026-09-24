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
        // load() appends to the live tables; import() would swap tables with DDL,
        // which cannot run inside the test transaction.
        app(GeoNamesImporter::class)->load(
            base_path('tests/fixtures/geonames/places.txt'),
            base_path('tests/fixtures/geonames/admin1.txt'),
            base_path('tests/fixtures/geonames/admin2.txt'),
        );
    }
}
