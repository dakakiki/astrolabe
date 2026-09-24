<?php

namespace Tests\Feature\Places;

use App\Astrology\Geocoding\GeoNamesCountryImporter;
use App\Models\Country;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_countries_ship_with_the_schema(): void
    {
        $this->assertSame(252, Country::count());

        $serbia = Country::findOrFail('RS');
        $this->assertSame('381', $serbia->phone_code);
        $this->assertSame('RSD', $serbia->currency_code);
    }

    public function test_dialling_codes_are_normalised(): void
    {
        $this->assertSame('381', GeoNamesCountryImporter::phoneCode('381'));
        $this->assertSame('1-684', GeoNamesCountryImporter::phoneCode('+1-684'));
        $this->assertSame('1-809', GeoNamesCountryImporter::phoneCode('+1-809 and 1-829'));
        $this->assertNull(GeoNamesCountryImporter::phoneCode(''));

        // GeoNames leaves Kosovo empty; the ITU code is filled in.
        $this->assertSame('383', Country::findOrFail('XK')->phone_code);
    }

    public function test_reference_data_lists_countries_with_their_codes(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $countries = collect($this->actingAs($user)->getJson('/api/v1/reference-data')->json('data.countries'))
            ->keyBy('code');

        $this->assertSame(['code' => 'RS', 'phone_code' => '381', 'currency_code' => 'RSD', 'languages' => ['sr', 'hu', 'bs', 'rom']], $countries['RS']);
    }

    public function test_a_client_country_must_be_a_known_one(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $this->actingAs($user)->postJson('/api/v1/clients', ['first_name' => 'Ana', 'country_code' => 'RS'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/clients', ['first_name' => 'Ana', 'country_code' => 'ZZ'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country_code');
    }
}
