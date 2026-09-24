<?php

namespace Tests\Feature\Workspaces;

use App\Models\AstrologyMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AstrologyMethodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_built_in_methods_are_listed_for_every_workspace(): void
    {
        $user = User::factory()->withWorkspace()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/astrology-methods')->assertOk();

        $this->assertSame(
            ['western', 'vedic', 'chinese', 'hellenistic', 'psychological', 'evolutionary', 'horary', 'electional', 'other'],
            array_column($response->json('data'), 'slug'),
        );
        $response->assertJsonPath('data.1.suggested_zodiac_mode', 'sidereal')
            ->assertJsonPath('data.1.suggested_ayanamsa', 'lahiri')
            ->assertJsonPath('data.0.is_system', true)
            ->assertJsonPath('data.0.selected', false);
    }

    public function test_the_owner_can_add_rename_and_remove_their_own_method(): void
    {
        $owner = User::factory()->withWorkspace()->create();

        $id = $this->actingAs($owner)->postJson('/api/v1/astrology-methods', [
            'name' => 'Uranian Astrology',
            'suggested_zodiac_mode' => 'tropical',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'uranian-astrology')
            ->assertJsonPath('data.is_system', false)
            ->json('data.id');

        $this->actingAs($owner)->patchJson("/api/v1/astrology-methods/{$id}", ['name' => 'Hamburg School'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'hamburg-school');

        $this->actingAs($owner)->deleteJson("/api/v1/astrology-methods/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('astrology_methods', ['id' => $id]);
    }

    public function test_built_in_methods_cannot_be_changed(): void
    {
        $owner = User::factory()->withWorkspace()->create();
        $western = AstrologyMethod::withoutGlobalScopes()->where('slug', 'western')->firstOrFail();

        $this->actingAs($owner)->patchJson("/api/v1/astrology-methods/{$western->id}", ['name' => 'Mine now'])
            ->assertForbidden();
        $this->actingAs($owner)->deleteJson("/api/v1/astrology-methods/{$western->id}")->assertForbidden();
    }

    public function test_the_workspace_selection_can_be_saved_with_one_default(): void
    {
        $owner = User::factory()->withWorkspace()->create();
        $ids = AstrologyMethod::withoutGlobalScopes()->whereIn('slug', ['western', 'hellenistic'])->pluck('id', 'slug');

        $this->actingAs($owner)->putJson('/api/v1/workspace/astrology-methods', [
            'method_ids' => [$ids['western'], $ids['hellenistic']],
            'default_id' => $ids['hellenistic'],
        ])->assertNoContent();

        $methods = collect($this->actingAs($owner)->getJson('/api/v1/astrology-methods')->json('data'))->keyBy('slug');

        $this->assertTrue($methods['western']['selected']);
        $this->assertFalse($methods['western']['is_default']);
        $this->assertTrue($methods['hellenistic']['is_default']);
        $this->assertFalse($methods['vedic']['selected']);
    }

    public function test_the_default_must_be_one_of_the_selected_methods(): void
    {
        $owner = User::factory()->withWorkspace()->create();
        $ids = AstrologyMethod::withoutGlobalScopes()->whereIn('slug', ['western', 'vedic'])->pluck('id', 'slug');

        $this->actingAs($owner)->putJson('/api/v1/workspace/astrology-methods', [
            'method_ids' => [$ids['western']],
            'default_id' => $ids['vedic'],
        ])->assertUnprocessable()->assertJsonValidationErrors('default_id');
    }
}
