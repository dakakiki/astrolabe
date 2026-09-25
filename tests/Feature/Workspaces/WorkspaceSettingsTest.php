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

    public function test_aspect_orbs_start_from_the_defaults_and_can_be_changed(): void
    {
        $owner = User::factory()->withWorkspace()->create();

        $this->actingAs($owner)->getJson('/api/v1/workspace')
            ->assertJsonPath('data.aspect_orbs.luminary_bonus', 1.5)
            ->assertJsonPath('data.aspect_orbs.aspects.conjunction', ['enabled' => true, 'orb' => 8])
            ->assertJsonPath('data.aspect_orbs.aspects.quincunx', ['enabled' => false, 'orb' => 3]);

        $this->actingAs($owner)->patchJson('/api/v1/workspace', [
            'aspect_orbs' => [
                'aspects' => ['square' => ['enabled' => true, 'orb' => 5.25], 'quincunx' => ['enabled' => true, 'orb' => 2]],
                'luminary_bonus' => 2,
            ],
        ])->assertOk()
            ->assertJsonPath('data.aspect_orbs.aspects.square', ['enabled' => true, 'orb' => 5.25])
            ->assertJsonPath('data.aspect_orbs.aspects.quincunx', ['enabled' => true, 'orb' => 2])
            ->assertJsonPath('data.aspect_orbs.aspects.trine', ['enabled' => true, 'orb' => 6])
            ->assertJsonPath('data.aspect_orbs.luminary_bonus', 2);

        // Stored complete, so later changes to the defaults do not move existing settings.
        $this->assertCount(9, $owner->currentWorkspace->fresh()->aspect_orbs['aspects']);
    }

    public function test_aspect_orbs_are_validated(): void
    {
        $owner = User::factory()->withWorkspace()->create();

        $this->actingAs($owner)->patchJson('/api/v1/workspace', [
            'aspect_orbs' => [
                'aspects' => [
                    'square' => ['enabled' => true, 'orb' => 0],
                    'trine' => ['enabled' => 'sometimes', 'orb' => 16],
                ],
                'luminary_bonus' => 6,
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'aspect_orbs.aspects.square.orb',
            'aspect_orbs.aspects.trine.enabled',
            'aspect_orbs.aspects.trine.orb',
            'aspect_orbs.luminary_bonus',
        ]);

        $this->actingAs($owner)->patchJson('/api/v1/workspace', [
            'aspect_orbs' => ['aspects' => ['parallel' => ['enabled' => true, 'orb' => 1]], 'luminary_bonus' => 1],
        ])->assertUnprocessable()->assertJsonValidationErrors('aspect_orbs.aspects');
    }

    public function test_transit_orbs_are_a_tighter_set_of_their_own_that_the_owner_can_change(): void
    {
        $owner = User::factory()->withWorkspace()->create();

        $this->actingAs($owner)->getJson('/api/v1/workspace')
            ->assertJsonPath('data.transit_orbs.luminary_bonus', 0)
            ->assertJsonPath('data.transit_orbs.aspects.conjunction', ['enabled' => true, 'orb' => 2])
            ->assertJsonPath('data.transit_orbs.aspects.sextile', ['enabled' => true, 'orb' => 1.5])
            ->assertJsonPath('data.transit_orbs.aspects.quincunx', ['enabled' => false, 'orb' => 1]);

        $this->actingAs($owner)->getJson('/api/v1/reference-data')
            ->assertJsonPath('data.aspects.transit_defaults.aspects.square', ['enabled' => true, 'orb' => 2]);

        $this->actingAs($owner)->patchJson('/api/v1/workspace', [
            'transit_orbs' => [
                'aspects' => ['square' => ['enabled' => true, 'orb' => 1.25], 'sextile' => ['enabled' => false, 'orb' => 1]],
                'luminary_bonus' => 0.5,
            ],
        ])->assertOk()
            ->assertJsonPath('data.transit_orbs.aspects.square', ['enabled' => true, 'orb' => 1.25])
            ->assertJsonPath('data.transit_orbs.aspects.sextile', ['enabled' => false, 'orb' => 1])
            ->assertJsonPath('data.transit_orbs.aspects.trine', ['enabled' => true, 'orb' => 2])
            ->assertJsonPath('data.transit_orbs.luminary_bonus', 0.5)
            // The natal orbs are a separate set and stay as they were.
            ->assertJsonPath('data.aspect_orbs.aspects.square', ['enabled' => true, 'orb' => 6]);

        $this->assertCount(9, $owner->currentWorkspace->fresh()->transit_orbs['aspects']);

        $this->actingAs($owner)->patchJson('/api/v1/workspace', [
            'transit_orbs' => ['aspects' => ['trine' => ['enabled' => true, 'orb' => 0]], 'luminary_bonus' => 9],
        ])->assertUnprocessable()->assertJsonValidationErrors(['transit_orbs.aspects.trine.orb', 'transit_orbs.luminary_bonus']);
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
        $this->actingAs($member)->patchJson('/api/v1/workspace', [
            'transit_orbs' => ['aspects' => ['square' => ['enabled' => true, 'orb' => 5]], 'luminary_bonus' => 0],
        ])->assertForbidden();
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
