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
        $this->assertDatabaseCount('places', 7);
        $this->assertDatabaseMissing('places', ['name' => 'Belgrade Fortress']);
        $this->assertDatabaseHas('places', ['id' => 3194360, 'admin1_name' => 'Vojvodina', 'timezone' => 'Europe/Belgrade']);
    }

    public function test_links_and_codes_are_not_searchable_names(): void
    {
        $this->assertDatabaseMissing('place_names', ['search_name' => 'https en wikipedia org wiki belgrade']);
        $this->assertDatabaseHas('place_names', ['place_id' => 792680, 'search_name' => 'beograd']);
    }

    public function test_importing_again_updates_instead_of_duplicating(): void
    {
        $names = DB::table('place_names')->count();

        $this->importPlaces();

        $this->assertDatabaseCount('places', 7);
        $this->assertSame($names, DB::table('place_names')->count());
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

    public function test_a_city_ranks_above_its_districts(): void
    {
        $this->assertSame(['Zürich', 'Zürich (Kreis 3)'], app(Geocoder::class)->search('zurich')->pluck('name')->all());
    }

    public function test_the_practice_country_wins_between_equal_matches(): void
    {
        $geocoder = app(Geocoder::class);

        $this->assertSame('Nice', $geocoder->search('nis')->first()->name);
        $this->assertSame('Niš', $geocoder->search('nis', preferCountry: 'RS')->first()->name);
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
            ->assertJsonPath('data.0.label', 'Niš, Central Serbia')
            ->assertJsonPath('data.0.country_code', 'RS')
            ->assertJsonPath('data.0.timezone', 'Europe/Belgrade');

        $this->actingAs($user)->getJson('/api/v1/places/nearest?latitude=42.4&longitude=19.3')
            ->assertOk()
            ->assertJsonPath('data.name', 'Podgorica');

        $this->actingAs($user)->getJson('/api/v1/places?q=x')->assertUnprocessable();
    }
}
