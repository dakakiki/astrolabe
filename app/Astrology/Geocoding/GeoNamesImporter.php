<?php

namespace App\Astrology\Geocoding;

use App\Support\SearchText;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Loads a GeoNames dump (the tab-separated `geoname` format, as in cities500.txt
 * or allCountries.txt) into `places` and `place_names`. Re-running it updates
 * places in place; ids are GeoNames ids, so they stay stable.
 *
 * Data: GeoNames (https://www.geonames.org), CC BY 4.0.
 */
class GeoNamesImporter
{
    private const CHUNK = 1000;

    /** @var array<string, string> "RS.VO" => "Vojvodina" */
    private array $admin1Names = [];

    /**
     * @param  list<string>|null  $countries  ISO codes to keep, or null for all
     * @param  (callable(int): void)|null  $progress  called with the running count
     * @return int number of places imported
     */
    public function import(
        string $placesFile,
        ?string $admin1File = null,
        bool $populatedPlacesOnly = true,
        ?array $countries = null,
        ?callable $progress = null,
    ): int {
        $this->admin1Names = $admin1File ? $this->readAdmin1Names($admin1File) : [];
        $countries = $countries ? array_flip(array_map('strtoupper', $countries)) : null;

        $handle = $this->open($placesFile);
        $chunk = [];
        $count = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $row = explode("\t", rtrim($line, "\r\n"));

                if (count($row) < 19
                    || ($populatedPlacesOnly && $row[6] !== 'P')
                    || ($countries !== null && ! isset($countries[$row[8]]))) {
                    continue;
                }

                $chunk[] = $row;

                if (count($chunk) === self::CHUNK) {
                    $count += $this->store($chunk);
                    $chunk = [];
                    $progress && $progress($count);
                }
            }

            if ($chunk) {
                $count += $this->store($chunk);
                $progress && $progress($count);
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function store(array $rows): int
    {
        $places = [];
        $names = [];

        foreach ($rows as $row) {
            $id = (int) $row[0];

            $places[] = [
                'id' => $id,
                'name' => mb_substr($row[1], 0, 200),
                'ascii_name' => mb_substr($row[2], 0, 200),
                'country_code' => $row[8] ?: null,
                'admin1_code' => $row[10] ?: null,
                'admin1_name' => $this->admin1Names[$row[8].'.'.$row[10]] ?? null,
                'latitude' => (float) $row[4],
                'longitude' => (float) $row[5],
                'timezone' => $row[17] ?: null,
                'population' => (int) $row[14],
                'feature_code' => $row[7] ?: null,
                'modified_on' => $row[18] ?: null,
            ];

            foreach ($this->searchNames($row) as $searchName) {
                $names[] = ['place_id' => $id, 'search_name' => $searchName];
            }
        }

        DB::transaction(function () use ($places, $names) {
            DB::table('places')->upsert($places, ['id']);
            DB::table('place_names')->whereIn('place_id', array_column($places, 'id'))->delete();

            foreach (array_chunk($names, 2000) as $batch) {
                DB::table('place_names')->insert($batch);
            }
        });

        return count($places);
    }

    /**
     * The name, its ASCII form and every alternate name (other languages and
     * scripts, historic names), normalised and de-duplicated.
     *
     * @param  list<string>  $row
     * @return list<string>
     */
    private function searchNames(array $row): array
    {
        $candidates = [$row[1], $row[2], ...explode(',', $row[3])];
        $names = [];

        foreach ($candidates as $candidate) {
            // Alternate names also carry links and codes; only real names are searchable.
            if ($candidate === '' || str_contains($candidate, '://')) {
                continue;
            }

            $normalized = mb_substr(SearchText::normalize($candidate), 0, 200);

            if (mb_strlen($normalized) >= 2 && ! ctype_digit(str_replace(' ', '', $normalized))) {
                $names[$normalized] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @return array<string, string>
     */
    private function readAdmin1Names(string $file): array
    {
        $names = [];
        $handle = $this->open($file);

        while (($line = fgets($handle)) !== false) {
            $row = explode("\t", rtrim($line, "\r\n"));

            if (count($row) >= 2) {
                $names[$row[0]] = $row[1];
            }
        }

        fclose($handle);

        return $names;
    }

    /**
     * A plain text file, or the first .txt entry inside a zip (GeoNames ships
     * each dump as a zip holding one text file).
     *
     * @return resource
     */
    private function open(string $path)
    {
        if (str_ends_with(strtolower($path), '.zip')) {
            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                throw new RuntimeException("Cannot open {$path}.");
            }

            $entry = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with($name, '.txt') && ! str_contains(strtolower($name), 'readme')) {
                    $entry = $name;
                    break;
                }
            }
            $zip->close();

            if ($entry === null) {
                throw new RuntimeException("No data file inside {$path}.");
            }

            $path = 'zip://'.$path.'#'.$entry;
        }

        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return $handle;
    }
}
