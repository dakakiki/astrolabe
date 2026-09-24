<?php

namespace App\Astrology\Geocoding;

use App\Support\SearchText;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Loads a GeoNames dump (the tab-separated `geoname` format, as in cities500.txt
 * or allCountries.txt) into `places` and `place_names`.
 *
 * import() builds complete new tables next to the live ones, indexes them once
 * at the end and swaps them in with a single atomic RENAME, so searches never
 * see a half-loaded gazetteer and millions of rows load in minutes.
 *
 * Data: GeoNames (https://www.geonames.org), CC BY 4.0.
 */
class GeoNamesImporter
{
    /** Rows per INSERT, kept under the 65,535 placeholders a prepared statement allows. */
    private const PLACE_BATCH = 4000;

    private const NAME_BATCH = 20000;

    /** Places at least this large, or administrative seats, count as "major". */
    private const MAJOR_POPULATION = 1000;

    private const SEAT_CODES = ['PPLC', 'PPLG', 'PPLA', 'PPLA2', 'PPLA3', 'PPLA4'];

    /** @var array<string, string> "RS.VO" => "Vojvodina" */
    private array $admin1Names = [];

    /** @var array<string, string> "RS.SE.18" => "Raska" */
    private array $admin2Names = [];

    /**
     * Replace the gazetteer with the contents of $placesFile.
     *
     * @param  list<string>|null  $countries  ISO codes to keep, or null for all
     * @param  (callable(int): void)|null  $progress  called with the running count
     * @return int number of places imported
     */
    public function import(
        string $placesFile,
        ?string $admin1File = null,
        ?string $admin2File = null,
        ?array $countries = null,
        ?callable $progress = null,
    ): int {
        $this->createStagingTables();

        try {
            $count = $this->load($placesFile, $admin1File, $admin2File, $countries, 'places_import', 'place_names_import', $progress);

            // Index names match the migration, so they survive the rename unchanged.
            Schema::table('places_import', fn ($table) => $table->index(['latitude', 'longitude'], 'places_latitude_longitude_index'));
            Schema::table('place_names_import', function ($table) {
                $table->primary(['search_name', 'place_id']);
                $table->index('place_id', 'place_names_place_id_index');
                $table->index(['major', 'search_name'], 'place_names_major_search_name_index');
            });

            DB::statement('RENAME TABLE places TO places_old, places_import TO places,
                place_names TO place_names_old, place_names_import TO place_names');
        } finally {
            Schema::dropIfExists('places_import');
            Schema::dropIfExists('place_names_import');
            Schema::dropIfExists('places_old');
            Schema::dropIfExists('place_names_old');
        }

        return $count;
    }

    /**
     * Append places to the given tables without replacing anything. Used by
     * import() for the staging tables, and by tests for small fixtures.
     *
     * @param  list<string>|null  $countries
     * @param  (callable(int): void)|null  $progress
     */
    public function load(
        string $placesFile,
        ?string $admin1File = null,
        ?string $admin2File = null,
        ?array $countries = null,
        string $placesTable = 'places',
        string $namesTable = 'place_names',
        ?callable $progress = null,
    ): int {
        $this->admin1Names = $admin1File ? $this->readCodeNames($admin1File) : [];
        $this->admin2Names = $admin2File ? $this->readCodeNames($admin2File) : [];
        $countries = $countries ? array_flip(array_map('strtoupper', $countries)) : null;

        $places = [];
        $names = [];
        $count = 0;

        foreach ($this->rows($placesFile) as $row) {
            if ($countries !== null && ! isset($countries[$row[8]])) {
                continue;
            }

            $places[] = $this->place($row);
            $major = (int) $row[14] >= self::MAJOR_POPULATION || in_array($row[7], self::SEAT_CODES, true);

            foreach ($this->searchNames($row) as $searchName) {
                $names[] = ['place_id' => (int) $row[0], 'search_name' => $searchName, 'major' => $major];
            }

            if (count($places) === self::PLACE_BATCH) {
                $count += $this->flush($placesTable, $namesTable, $places, $names);
                $progress && $progress($count);
            }
        }

        $count += $this->flush($placesTable, $namesTable, $places, $names);
        $progress && $progress($count);

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $places
     * @param  list<array<string, mixed>>  $names
     */
    private function flush(string $placesTable, string $namesTable, array &$places, array &$names): int
    {
        if ($places === []) {
            return 0;
        }

        // One commit per batch rather than per statement.
        DB::transaction(function () use ($placesTable, $namesTable, $places, $names) {
            DB::table($placesTable)->insert($places);
            foreach (array_chunk($names, self::NAME_BATCH) as $batch) {
                DB::table($namesTable)->insert($batch);
            }
        });

        $count = count($places);
        $places = [];
        $names = [];

        return $count;
    }

    /**
     * Populated places (feature class P) from the dump, one tab-split row each.
     *
     * @return Generator<int, list<string>>
     */
    private function rows(string $file): Generator
    {
        $handle = $this->open($file);

        try {
            while (($line = fgets($handle)) !== false) {
                $row = explode("\t", rtrim($line, "\r\n"));

                if (count($row) >= 19 && $row[6] === 'P') {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string>  $row
     * @return array<string, mixed>
     */
    private function place(array $row): array
    {
        return [
            'id' => (int) $row[0],
            'name' => mb_substr($row[1], 0, 200),
            'ascii_name' => mb_substr($row[2], 0, 200),
            'country_code' => $row[8] ?: null,
            'admin1_code' => $row[10] ?: null,
            'admin1_name' => $this->admin1Names[$row[8].'.'.$row[10]] ?? null,
            'admin2_code' => $row[11] ?: null,
            'admin2_name' => $row[11] !== '' ? ($this->admin2Names[$row[8].'.'.$row[10].'.'.$row[11]] ?? null) : null,
            'latitude' => (float) $row[4],
            'longitude' => (float) $row[5],
            'timezone' => $row[17] ?: null,
            'population' => (int) $row[14],
            'feature_code' => $row[7] ?: null,
            'modified_on' => $row[18] ?: null,
        ];
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
     * Empty copies of the live tables. Secondary indexes and the names key are
     * added after loading, which is far faster than maintaining them per row.
     */
    private function createStagingTables(): void
    {
        Schema::dropIfExists('places_import');
        Schema::dropIfExists('place_names_import');

        DB::statement('CREATE TABLE places_import LIKE places');
        Schema::table('places_import', fn ($table) => $table->dropIndex('places_latitude_longitude_index'));

        DB::statement('CREATE TABLE place_names_import LIKE place_names');
        Schema::table('place_names_import', function ($table) {
            $table->dropPrimary();
            $table->dropIndex('place_names_place_id_index');
            $table->dropIndex('place_names_major_search_name_index');
        });
    }

    /**
     * @return array<string, string>
     */
    private function readCodeNames(string $file): array
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
