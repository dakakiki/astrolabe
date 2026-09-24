<?php

namespace Tests\Feature\Workspaces;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_update_practice_and_regional_settings(): void
    {
        $owner = User::factory()->withWorkspace()->create();

        $this->actingAs($owner)->patchJson('/api/v1/workspace', [
            'name' => 'Vega Astrology',
            'timezone' => 'Europe/Zurich',
            'default_currency' => 'CHF',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Vega Astrology')
            ->assertJsonPath('data.timezone', 'Europe/Zurich')
            ->assertJsonPath('data.default_currency', 'CHF')
            ->assertJsonPath('data.default_house_system', 'placidus');
    }

    public function test_sidereal_chart_defaults_need_an_ayanamsa(): void
    {
        $owner = User::factory()->withWorkspace()->create();

        $this->actingAs($owner)->patchJson('/api/v1/workspace', [
            'default_house_system' => 'whole_sign',
            'default_zodiac_mode' => 'sidereal',
        ])->assertUnprocessable()->assertJsonValidationErrors('default_ayanamsa');

        $this->actingAs($owner)->patchJson('/api/v1/workspace', [
            'default_house_system' => 'whole_sign',
            'default_zodiac_mode' => 'sidereal',
            'default_ayanamsa' => 'lahiri',
        ])->assertOk()->assertJsonPath('data.default_ayanamsa', 'lahiri');
    }

    public function test_switching_back_to_tropical_drops_the_ayanamsa(): void
    {
        $owner = User::factory()->withWorkspace()->create();
        $owner->currentWorkspace->update(['default_zodiac_mode' => 'sidereal', 'default_ayanamsa' => 'lahiri']);

        $this->actingAs($owner)->patchJson('/api/v1/workspace', [
            'default_house_system' => 'placidus',
            'default_zodiac_mode' => 'tropical',
            'default_ayanamsa' => 'lahiri',
        ])->assertOk()->assertJsonPath('data.default_ayanamsa', null);
    }

    public function test_unknown_values_are_rejected(): void
    {
        $owner = User::factory()->withWorkspace()->create();

        $this->actingAs($owner)->patchJson('/api/v1/workspace', [
            'default_currency' => 'XYZ',
            'timezone' => 'Somewhere/Else',
            'default_locale' => 'xx',
            'default_house_system' => 'made_up',
            'default_zodiac_mode' => 'tropical',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'default_currency', 'timezone', 'default_locale', 'default_house_system',
        ]);
    }

    public function test_a_member_who_is_not_the_owner_cannot_change_settings(): void
    {
        $owner = User::factory()->withWorkspace()->create();
        $member = User::factory()->create();
        $owner->currentWorkspace->users()->attach($member, [
            'role' => WorkspaceRole::Member->value,
            'status' => MembershipStatus::Active->value,
        ]);

        $this->actingAs($member)->getJson('/api/v1/workspace')
            ->assertOk()
            ->assertJsonPath('data.role', 'member');

        $this->actingAs($member)->patchJson('/api/v1/workspace', ['name' => 'Taken over'])->assertForbidden();
    }

    public function test_reference_data_lists_the_allowed_values(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $this->actingAs($user)->getJson('/api/v1/reference-data')
            ->assertOk()
            ->assertJsonPath('data.locales.0.code', 'en')
            ->assertJsonFragment(['house_systems' => [
                'placidus', 'koch', 'porphyry', 'regiomontanus', 'campanus',
                'equal', 'whole_sign', 'alcabitius', 'topocentric', 'morinus',
            ]]);
    }
}
