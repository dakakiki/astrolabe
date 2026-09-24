<?php

namespace App\Console\Commands;

use App\Astrology\Geocoding\GeoNamesCountryImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class ImportCountries extends Command
{
    protected $signature = 'countries:import {--file= : Import this local countryInfo.txt instead of downloading}';

    protected $description = 'Refresh countries (dialling codes, currencies, languages) from GeoNames';

    public function handle(GeoNamesCountryImporter $importer): int
    {
        $file = $this->option('file') ?: $this->download();

        $this->info(sprintf('Imported %d countries.', $importer->import($file)));

        return self::SUCCESS;
    }

    private function download(): string
    {
        $directory = storage_path('app/private/geonames');
        File::ensureDirectoryExists($directory);
        $target = $directory.DIRECTORY_SEPARATOR.'countryInfo.txt';

        Http::timeout(120)->sink($target)->get('https://download.geonames.org/export/dump/countryInfo.txt')->throw();

        return $target;
    }
}
