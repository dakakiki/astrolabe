<?php

namespace App\Console\Commands;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Services\SkyCalendar;
use App\Astrology\Services\TransitService;
use App\Astrology\Support\JulianDay;
use App\Astrology\ValueObjects\ChartRequest;
use App\Enums\CelestialBody;
use App\Enums\HouseSystem;
use App\Enums\ZodiacMode;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * How long the engine takes on this machine (Phase 8c): one natal chart with
 * houses, the transit series (731 days in one run) and the sky calendar for a
 * month and a year. Starting `swetest` is most of the cost on Windows; this
 * shows what it is on the server — CI prints it on Linux, and it belongs in
 * the checks after deploying a new engine (docs/deployment.md).
 */
class BenchmarkEphemeris extends Command
{
    protected $signature = 'ephemeris:benchmark {--runs=5 : Runs per measurement (the median is shown)}';

    protected $description = 'Time a chart, the transit series and the sky calendar with the configured engine';

    public function handle(EphemerisEngine $engine, SkyCalendar $sky): int
    {
        if (! $engine->available()) {
            $this->error('The ephemeris engine is not available here (SWETEST_PATH, EPHEMERIS_PATH).');

            return self::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));
        $now = CarbonImmutable::now()->startOfHour();
        $workspace = new Workspace(['default_zodiac_mode' => ZodiacMode::Tropical, 'default_ayanamsa' => null]);

        $natal = new ChartRequest(
            julianDayUt: JulianDay::fromMoment(CarbonImmutable::parse('1990-06-15 12:30', 'UTC')),
            latitude: 44.787197,
            longitude: 20.457273,
            houseSystem: HouseSystem::Placidus,
            zodiacMode: ZodiacMode::Tropical,
            ayanamsa: null,
            bodies: CelestialBody::natal(),
            includeHouses: true,
        );
        $series = new ChartRequest(
            julianDayUt: JulianDay::fromMoment($now) - TransitService::SEARCH_DAYS,
            latitude: null,
            longitude: null,
            houseSystem: null,
            zodiacMode: ZodiacMode::Tropical,
            ayanamsa: null,
            bodies: CelestialBody::natal(),
        );
        $days = 2 * TransitService::SEARCH_DAYS + 1;

        $rows = [
            ['Natal chart with houses', $this->median($runs, fn () => $engine->calculate($natal))],
            ["Transit series, {$days} days", $this->median($runs, fn () => $engine->series($series, $days))],
            ['Sky calendar, 30 days', $this->median($runs, fn () => $sky->calendar($workspace, $now, $now->addDays(30), $now))],
            ['Sky calendar, 365 days', $this->median(max(1, intdiv($runs, 2)), fn () => $sky->calendar($workspace, $now, $now->addDays(365), $now))],
        ];

        $this->table(['Measurement', 'Median ms'], array_map(fn (array $row) => [$row[0], sprintf('%.0f', $row[1])], $rows));
        $this->line(sprintf('%s %s on %s, PHP %s%s.', $engine->name(), $engine->version(), PHP_OS_FAMILY, PHP_VERSION, extension_loaded('xdebug') ? ' with Xdebug' : ''));

        return self::SUCCESS;
    }

    private function median(int $runs, callable $work): float
    {
        $times = [];
        for ($i = 0; $i < $runs; $i++) {
            $started = hrtime(true);
            $work();
            $times[] = (hrtime(true) - $started) / 1e6;
        }
        sort($times);

        return $times[intdiv(count($times), 2)];
    }
}
