<?php

namespace Tests\Unit;

use App\Astrology\Support\JulianDay;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class JulianDayTest extends TestCase
{
    public function test_standard_epochs(): void
    {
        $this->assertSame(2451545.0, JulianDay::fromMoment(CarbonImmutable::parse('2000-01-01 12:00:00', 'UTC')));
        $this->assertSame(2440587.5, JulianDay::fromMoment(CarbonImmutable::parse('1970-01-01 00:00:00', 'UTC')));
        $this->assertSame(2415020.5, JulianDay::fromMoment(CarbonImmutable::parse('1900-01-01 00:00:00', 'UTC')));
    }

    public function test_the_local_zone_is_taken_into_account(): void
    {
        // 14:30 summer time in Belgrade is 12:30 UT.
        $local = CarbonImmutable::parse('1985-07-15 14:30:00', 'Europe/Belgrade');

        $this->assertEqualsWithDelta(2446262.0208333, JulianDay::fromMoment($local), 1e-7);
    }
}
