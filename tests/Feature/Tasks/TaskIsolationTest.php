<?php

namespace Tests\Feature\Tasks;

use App\Models\Client;
use App\Models\Consultation;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tasks stay inside their workspace (docs/spec/06, "Multi-tenant izolacija"),
 * not even reachable by changing an id.
 */
class TaskIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private Client $bobsClient;

    private Task $bobsTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->withWorkspace()->create();
        $this->bob = User::factory()->withWorkspace()->create();

        $this->bobsClient = Client::factory()->inWorkspace($this->bob->current_workspace_id)->create();
        $this->bobsTask = Task::factory()->forClient($this->bobsClient, $this->bob)->due('2026-10-05')->create(['title' => 'Bob only']);
    }

    public function test_another_workspaces_task_is_not_listed_or_found(): void
    {
        $this->actingAs($this->alice)->getJson('/api/v1/tasks?status=all')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('counts', ['open' => 0, 'overdue' => 0, 'today' => 0, 'done' => 0]);

        $this->actingAs($this->alice)->getJson("/api/v1/tasks?client_id={$this->bobsTask->client_id}")->assertJsonCount(0, 'data');
        $this->actingAs($this->alice)->getJson("/api/v1/tasks/{$this->bobsTask->id}")->assertNotFound();
    }

    public function test_another_workspaces_task_cannot_be_changed_or_deleted(): void
    {
        $id = $this->bobsTask->id;

        $this->actingAs($this->alice)->patchJson("/api/v1/tasks/{$id}", ['status' => 'done'])->assertNotFound();
        $this->actingAs($this->alice)->deleteJson("/api/v1/tasks/{$id}")->assertNotFound();

        $this->assertDatabaseHas('tasks', ['id' => $id, 'title' => 'Bob only', 'status' => 'open', 'deleted_at' => null]);
    }

    public function test_another_workspaces_client_consultation_or_member_cannot_be_used(): void
    {
        $consultation = Consultation::factory()->forClient($this->bobsClient)->create();

        $this->actingAs($this->alice)->postJson('/api/v1/tasks', [
            'title' => 'Snoop',
            'client_id' => $this->bobsTask->client_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->actingAs($this->alice)->postJson('/api/v1/tasks', [
            'title' => 'Snoop',
            'consultation_id' => $consultation->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('consultation_id');

        $this->actingAs($this->alice)->postJson('/api/v1/tasks', [
            'title' => 'Snoop',
            'assigned_user_id' => $this->bob->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assigned_user_id');
    }

    public function test_the_dashboard_shows_nothing_from_another_workspace(): void
    {
        $this->actingAs($this->alice)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.tasks.overdue', [])
            ->assertJsonPath('data.tasks.today', [])
            ->assertJsonPath('data.recent_clients', [])
            ->assertJsonPath('data.counts.tasks_open', 0)
            ->assertJsonPath('data.counts.clients_total', 0);
    }
}
