<?php

namespace App\Astrology\Geocoding;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads GeoNames countryInfo.txt into `countries`: ISO codes, dialling code,
 * currency and languages. Names are shown by the browser in the viewer's
 * language; the English name here is only a fallback.
 *
 * Data: GeoNames (https://www.geonames.org), CC BY 4.0.
 */
class GeoNamesCountryImporter
{
    /** Codes GeoNames leaves empty although the country has one (ITU assignment). */
    private const PHONE_CODE_GAPS = [
        'XK' => '383',
    ];

    /**
     * @return int number of countries imported
     */
    public function import(string $file): int
    {
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot read {$file}.");
        }

        $rows = [];

        while (($line = fgets($handle)) !== false) {
            if ($line[0] === '#' || trim($line) === '') {
                continue;
            }

            $column = explode("\t", rtrim($line, "\r\n"));

            if (count($column) < 17 || ! preg_match('/^[A-Z]{2}$/', $column[0])) {
                continue;
            }

            $rows[] = [
                'code' => $column[0],
                'iso3' => $column[1] ?: null,
                'name' => $column[4],
                'capital' => $column[5] ?: null,
                'continent' => $column[8] ?: null,
                'currency_code' => $column[10] ?: null,
                'phone_code' => self::phoneCode($column[12]) ?? (self::PHONE_CODE_GAPS[$column[0]] ?? null),
                'languages' => $column[15] ?: null,
                'population' => (int) $column[7],
                'geoname_id' => $column[16] !== '' ? (int) $column[16] : null,
            ];
        }

        fclose($handle);

        DB::table('countries')->upsert($rows, ['code']);

        return count($rows);
    }

    /**
     * The first dialling code, without "+": "381", "1-684" (a North American
     * area code), or the first of "+1-809 and 1-829". Null when none is given.
     */
    public static function phoneCode(string $raw): ?string
    {
        $first = trim(explode(' and ', $raw)[0]);
        $code = ltrim($first, '+');

        return preg_match('/^\d[\d-]*$/', $code) ? $code : null;
    }
}
