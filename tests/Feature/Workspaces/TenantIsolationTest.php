<?php

namespace Tests\Feature\Workspaces;

use App\Enums\MembershipStatus;
use App\Models\AstrologyMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 exit criterion (docs/spec/04): two users cannot reach each other's data,
 * not even by changing an id in the request.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private AstrologyMethod $bobsMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->withWorkspace(['name' => 'Alice Astrology'])->create();
        $this->bob = User::factory()->withWorkspace(['name' => 'Bob Astrology'])->create();

        $this->bobsMethod = AstrologyMethod::factory()->create([
            'workspace_id' => $this->bob->current_workspace_id,
            'name' => "Bob's secret technique",
        ]);
    }

    public function test_another_workspaces_method_is_not_listed(): void
    {
        $this->actingAs($this->alice)->getJson('/api/v1/astrology-methods')
            ->assertOk()
            ->assertJsonMissing(['name' => "Bob's secret technique"]);
    }

    public function test_another_workspaces_method_cannot_be_changed_or_deleted_by_id(): void
    {
        $id = $this->bobsMethod->id;

        $this->actingAs($this->alice)->patchJson("/api/v1/astrology-methods/{$id}", ['name' => 'Stolen'])
            ->assertNotFound();
        $this->actingAs($this->alice)->deleteJson("/api/v1/astrology-methods/{$id}")->assertNotFound();

        $this->assertDatabaseHas('astrology_methods', ['id' => $id, 'name' => "Bob's secret technique"]);
    }

    public function test_another_workspaces_method_cannot_be_attached(): void
    {
        $this->actingAs($this->alice)->putJson('/api/v1/workspace/astrology-methods', [
            'method_ids' => [$this->bobsMethod->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('method_ids.0');

        $this->assertDatabaseMissing('workspace_astrology_method', ['astrology_method_id' => $this->bobsMethod->id]);
    }

    public function test_a_workspace_id_in_the_request_is_ignored(): void
    {
        $this->actingAs($this->alice)->patchJson('/api/v1/workspace', [
            'workspace_id' => $this->bob->current_workspace_id,
            'name' => 'Renamed',
        ])->assertOk()->assertJsonPath('data.id', $this->alice->current_workspace_id);

        $this->assertSame('Bob Astrology', $this->bob->currentWorkspace->fresh()->name);

        $created = $this->actingAs($this->alice)->postJson('/api/v1/astrology-methods', [
            'workspace_id' => $this->bob->current_workspace_id,
            'name' => 'Planted',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('astrology_methods', [
            'id' => $created,
            'workspace_id' => $this->alice->current_workspace_id,
        ]);
    }

    public function test_a_stale_current_workspace_pointing_elsewhere_is_not_trusted(): void
    {
        $this->alice->forceFill(['current_workspace_id' => $this->bob->current_workspace_id])->save();

        $this->actingAs($this->alice)->getJson('/api/v1/workspace')
            ->assertOk()
            ->assertJsonPath('data.name', 'Alice Astrology');

        $this->assertNotSame($this->bob->current_workspace_id, $this->alice->fresh()->current_workspace_id);
    }

    public function test_a_suspended_member_loses_access(): void
    {
        $this->alice->workspaces()->updateExistingPivot($this->alice->current_workspace_id, [
            'status' => MembershipStatus::Suspended->value,
        ]);

        $this->actingAs($this->alice)->getJson('/api/v1/workspace')->assertForbidden();
    }

    public function test_tenant_queries_fail_closed_without_a_current_workspace(): void
    {
        $current = app(CurrentWorkspace::class);
        $current->set(null);

        // Only built-in methods, never any workspace's own.
        $this->assertFalse(AstrologyMethod::query()->whereKey($this->bobsMethod->id)->exists());
        $this->assertSame(9, AstrologyMethod::query()->count());

        $current->run(Workspace::findOrFail($this->bob->current_workspace_id), function () {
            $this->assertTrue(AstrologyMethod::query()->whereKey($this->bobsMethod->id)->exists());
        });

        $current->run(Workspace::findOrFail($this->alice->current_workspace_id), function () {
            $this->assertFalse(AstrologyMethod::query()->whereKey($this->bobsMethod->id)->exists());
        });
    }
}
