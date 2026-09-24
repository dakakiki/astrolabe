<?php

namespace Tests\Feature\Places;

use App\Astrology\Contracts\Geocoder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ImportsPlaces;
use Tests\TestCase;

class PlacesTest extends TestCase
{
    use ImportsPlaces, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importPlaces();
    }

    public function test_only_populated_places_are_imported_with_region_names(): void
    {
        $this->assertDatabaseCount('places', 9);
        $this->assertDatabaseMissing('places', ['name' => 'Belgrade Fortress']);
        $this->assertDatabaseHas('places', ['id' => 3194360, 'admin1_name' => 'Vojvodina', 'timezone' => 'Europe/Belgrade']);
    }

    public function test_links_and_codes_are_not_searchable_names(): void
    {
        $this->assertDatabaseMissing('place_names', ['search_name' => 'https en wikipedia org wiki belgrade']);
        $this->assertDatabaseHas('place_names', ['place_id' => 792680, 'search_name' => 'beograd']);
    }

    public function test_each_spelling_is_stored_once_per_place(): void
    {
        $duplicates = DB::table('place_names')
            ->select('place_id', 'search_name')
            ->groupBy('place_id', 'search_name')
            ->havingRaw('count(*) > 1')
            ->get();

        $this->assertCount(0, $duplicates);

        // Zürich, Zurich, Zuerich and Zurigo: the first two meet on one key.
        $this->assertSame(
            ['zuerich', 'zurich', 'zurigo'],
            DB::table('place_names')->where('place_id', 2657896)->orderBy('search_name')->pluck('search_name')->all(),
        );
    }

    public function test_places_are_found_by_any_script_spelling_or_former_name(): void
    {
        $geocoder = app(Geocoder::class);

        $this->assertSame('Belgrade', $geocoder->search('Београд')->first()?->name);
        $this->assertSame('Belgrade', $geocoder->search('beog')->first()?->name);
        $this->assertSame('Novi Sad', $geocoder->search('Újvidék')->first()?->name);
        $this->assertSame('Podgorica', $geocoder->search('Titograd')->first()?->name);
        $this->assertSame('Novi Sad', $geocoder->search('Novi Sad, Serbia')->first()?->name);
    }

    public function test_two_letters_match_whole_names_only(): void
    {
        $geocoder = app(Geocoder::class);

        $this->assertSame([], $geocoder->search('be')->pluck('name')->all());
        $this->assertSame(['Ub'], $geocoder->search('Уб')->pluck('name')->all());
    }

    public function test_short_prefixes_search_major_places_and_longer_ones_everything(): void
    {
        $geocoder = app(Geocoder::class);

        // "novi" (4 letters): the town, not the hamlet of 0 inhabitants.
        $this->assertSame(['Novi Sad'], $geocoder->search('novi')->pluck('name')->all());
        // "novi b" (5+ letters): every populated place.
        $this->assertSame(['Novi Banovci'], $geocoder->search('novi b')->pluck('name')->all());
        // A whole name is always found, however small the place.
        $this->assertSame(['Novi Banovci'], $geocoder->search('Нови Бановци')->pluck('name')->all());
    }

    public function test_the_district_tells_same_named_places_apart(): void
    {
        $this->assertSame('Novi Sad, South Backa, Vojvodina', app(Geocoder::class)->search('novi sad')->first()->label);
    }

    public function test_a_city_ranks_above_its_districts(): void
    {
        $this->assertSame(['Zürich', 'Zürich (Kreis 3)'], app(Geocoder::class)->search('zurich')->pluck('name')->all());
    }

    public function test_a_place_named_so_outranks_one_merely_also_called_so(): void
    {
        // Niš is called "Nis"; Nice only has "Nis" among its alternate names.
        $this->assertSame('Niš', app(Geocoder::class)->search('nis')->first()->name);
    }

    public function test_the_practice_country_can_outweigh_that(): void
    {
        $this->assertSame('Nice', app(Geocoder::class)->search('nis', preferCountry: 'FR')->first()->name);
    }

    public function test_the_nearest_place_suggests_a_time_zone(): void
    {
        $place = app(Geocoder::class)->nearest(45.2, 19.9);

        $this->assertSame('Novi Sad', $place->name);
        $this->assertSame('Europe/Belgrade', $place->timezone);
    }

    public function test_the_place_api_prefers_the_workspace_country(): void
    {
        $user = User::factory()->withWorkspace(['timezone' => 'Europe/Belgrade'])->create();
        $user->currentWorkspace->update(['timezone' => 'Europe/Belgrade']);

        $this->actingAs($user)->getJson('/api/v1/places?q=nis')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Niš')
            ->assertJsonPath('data.0.label', 'Niš, Nisava, Central Serbia')
            ->assertJsonPath('data.0.country_code', 'RS')
            ->assertJsonPath('data.0.timezone', 'Europe/Belgrade');

        $this->actingAs($user)->getJson('/api/v1/places/nearest?latitude=42.4&longitude=19.3')
            ->assertOk()
            ->assertJsonPath('data.name', 'Podgorica');

        $this->actingAs($user)->getJson('/api/v1/places?q=x')->assertUnprocessable();
    }
}
