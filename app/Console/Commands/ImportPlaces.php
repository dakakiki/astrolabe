<?php

namespace App\Console\Commands;

use App\Astrology\Geocoding\GeoNamesImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Downloads a GeoNames dump and loads it into the local gazetteer used for
 * birth places. Safe to re-run: places are updated in place.
 */
class ImportPlaces extends Command
{
    protected $signature = 'places:import
        {--source= : cities500, cities1000, cities5000, cities15000 or "all" (every populated place); default from config}
        {--file= : Import this local .zip or .txt instead of downloading}
        {--countries= : Only these countries, comma-separated ISO codes (e.g. RS,ME,BA)}
        {--fresh : Download again even if the file is already here}';

    protected $description = 'Import populated places from GeoNames into the local gazetteer';

    private const BASE_URL = 'https://download.geonames.org/export/dump/';

    public function handle(GeoNamesImporter $importer): int
    {
        $source = $this->option('source') ?: config('astrolabe.places.source');
        $archive = $source === 'all' ? 'allCountries.zip' : $source.'.zip';

        if (! in_array($source, ['cities500', 'cities1000', 'cities5000', 'cities15000', 'all'], true)) {
            $this->error("Unknown source \"{$source}\".");

            return self::INVALID;
        }

        $placesFile = $this->option('file') ?: $this->download($archive);
        $admin1File = $this->download('admin1CodesASCII.txt');
        $admin2File = $this->download('admin2Codes.txt');
        $countries = $this->option('countries')
            ? array_filter(array_map('trim', explode(',', $this->option('countries'))))
            : null;

        $this->info("Importing {$placesFile}".($countries ? ' ('.implode(', ', $countries).')' : '').' …');
        $started = microtime(true);

        $count = $importer->import(
            $placesFile,
            $admin1File,
            $admin2File,
            countries: $countries,
            progress: function (int $count) {
                if ($count % 200000 === 0) {
                    $this->line(number_format($count).' places');
                }
            },
        );

        $this->info(sprintf('Imported %s places in %.1f s.', number_format($count), microtime(true) - $started));

        return self::SUCCESS;
    }

    private function download(string $file): string
    {
        $directory = storage_path('app/private/geonames');
        $target = $directory.DIRECTORY_SEPARATOR.$file;

        if (is_file($target) && ! $this->option('fresh')) {
            return $target;
        }

        File::ensureDirectoryExists($directory);
        $this->line("Downloading {$file} …");

        Http::timeout(1800)->sink($target)->get(self::BASE_URL.$file)->throw();

        return $target;
    }
}
