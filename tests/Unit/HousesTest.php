<?php

namespace Tests\Unit;

use App\Astrology\ValueObjects\Houses;
use App\Enums\HouseSystem;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HousesTest extends TestCase
{
    private static function houses(array $cusps, HouseSystem $system = HouseSystem::Placidus, ?HouseSystem $requested = null): Houses
    {
        return new Houses($system, $requested ?? $system, $cusps, $cusps[0], $cusps[9], 0, 0);
    }

    public function test_a_longitude_belongs_to_the_house_whose_cusp_it_has_passed(): void
    {
        // Cusps of a real Placidus chart (Novi Sad, 15 July 1985, 14:30).
        $houses = self::houses([218.18, 246.90, 281.22, 318.20, 351.10, 17.38, 38.18, 66.90, 101.22, 138.20, 171.10, 197.38]);

        $this->assertSame(1, $houses->houseOf(218.18));
        $this->assertSame(1, $houses->houseOf(246.89));
        $this->assertSame(2, $houses->houseOf(246.90));
        $this->assertSame(9, $houses->houseOf(112.95));
        $this->assertSame(12, $houses->houseOf(210));

        // The fifth house spans 0° Aries.
        $this->assertSame(5, $houses->houseOf(355));
        $this->assertSame(5, $houses->houseOf(0));
        $this->assertSame(5, $houses->houseOf(17.37));
        $this->assertSame(6, $houses->houseOf(17.38));
        $this->assertSame(6, $houses->houseOf(377.5));
    }

    public function test_the_angles_include_the_descendant_and_the_imum_coeli(): void
    {
        $angles = self::houses([218.18, 246.90, 281.22, 318.20, 351.10, 17.38, 38.18, 66.90, 101.22, 138.20, 171.10, 197.38])
            ->toArray()['angles'];

        $this->assertEqualsWithDelta(38.18, $angles['dsc'], 1e-9);
        $this->assertEqualsWithDelta(318.20, $angles['ic'], 1e-9);
    }

    public function test_a_fallback_keeps_both_systems(): void
    {
        $houses = self::houses(array_map(fn ($i) => 30.0 * $i, range(0, 11)), HouseSystem::Porphyry, HouseSystem::Placidus);

        $this->assertTrue($houses->fellBack());
        $this->assertSame(
            ['system' => 'porphyry', 'requested_system' => 'placidus'],
            array_intersect_key($houses->toArray()['houses'], array_flip(['system', 'requested_system'])),
        );
    }

    public function test_a_chart_has_twelve_cusps(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Houses(HouseSystem::Equal, HouseSystem::Equal, [0.0, 30.0, 60.0], 0, 270, 268, 180);
    }
}
