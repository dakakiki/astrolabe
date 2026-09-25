<?php

namespace Tests\Feature\Tasks;

use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * Tasks and follow-ups through the API (docs/spec/02, "Zadaci i follow-up").
 */
class TasksTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 5 October 2026, 12:00 in Belgrade.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));

        $this->user = User::factory()->withWorkspace()->create(['timezone' => 'Europe/Belgrade']);
        $this->client = Client::factory()->inWorkspace($this->user->current_workspace_id)->create([
            'first_name' => 'Ana',
            'last_name' => 'Marković',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function add(array $attributes = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->postJson('/api/v1/tasks', $attributes + ['title' => 'Send the transit summary']);
    }

    /**
     * @return list<string>
     */
    private function timeline(?Client $client = null): array
    {
        return ActivityEvent::withoutGlobalScopes()
            ->where('client_id', ($client ?? $this->client)->id)
            ->where('event_type', 'like', 'task%')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->pluck('event_type')
            ->map(fn ($type) => $type->value)
            ->all();
    }

    public function test_a_new_task_is_open_normal_and_assigned_to_whoever_adds_it(): void
    {
        $this->add(['description' => "Include Saturn.\nAnd Jupiter."])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Send the transit summary')
            ->assertJsonPath('data.description', "Include Saturn.\nAnd Jupiter.")
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.priority', 'normal')
            ->assertJsonPath('data.client', null)
            ->assertJsonPath('data.consultation', null)
            ->assertJsonPath('data.assigned_user.id', $this->user->id)
            ->assertJsonPath('data.created_by.id', $this->user->id)
            ->assertJsonPath('data.due_date', null)
            ->assertJsonPath('data.due_at', null)
            ->assertJsonPath('data.due_state', null)
            ->assertJsonPath('data.completed_at', null);

        // Without a client there is no timeline to appear on.
        $this->assertSame(0, ActivityEvent::withoutGlobalScopes()->where('event_type', 'task')->count());
    }

    public function test_the_due_date_is_a_day_or_a_time_in_the_users_zone_across_the_clock_change(): void
    {
        // A time: that moment.
        $this->add(['due_date' => '2026-10-07', 'due_time' => '15:00'])
            ->assertJsonPath('data.due_date', '2026-10-07')
            ->assertJsonPath('data.due_time', '15:00')
            ->assertJsonPath('data.timezone', 'Europe/Belgrade')
            ->assertJsonPath('data.due_at', '2026-10-07T13:00:00Z');

        // A day: until the day is over. Belgrade leaves summer time on 25 October.
        $this->add(['due_date' => '2026-10-24'])->assertJsonPath('data.due_time', null)
            ->assertJsonPath('data.due_at', '2026-10-24T22:00:00Z');
        $id = $this->add(['due_date' => '2026-10-25'])->assertJsonPath('data.due_at', '2026-10-25T23:00:00Z')->json('data.id');

        // Another zone, when the deadline is someone else's.
        $this->add(['due_date' => '2026-10-07', 'due_time' => '09:00', 'timezone' => 'America/New_York'])
            ->assertJsonPath('data.due_at', '2026-10-07T13:00:00Z');

        // A time added later keeps the day; a new day alone keeps the time.
        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['due_time' => '10:30'])
            ->assertOk()->assertJsonPath('data.due_at', '2026-10-25T09:30:00Z');
        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['due_date' => '2026-10-26'])
            ->assertJsonPath('data.due_time', '10:30')
            ->assertJsonPath('data.due_at', '2026-10-26T09:30:00Z');

        // Removing the day removes the time and the deadline with it.
        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['due_date' => null])
            ->assertJsonPath('data.due_date', null)
            ->assertJsonPath('data.due_time', null)
            ->assertJsonPath('data.timezone', null)
            ->assertJsonPath('data.due_at', null);
    }

    public function test_invalid_tasks_are_rejected(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/tasks', [
            'title' => '',
            'priority' => 'urgent',
            'status' => 'done',
            'due_date' => '5 October',
            'due_time' => '3pm',
            'timezone' => 'Mars/Olympus',
            'client_id' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'title', 'priority', 'status', 'due_date', 'due_time', 'timezone', 'client_id',
        ]);

        $this->add(['due_time' => '15:00'])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->add(['title' => str_repeat('x', 201)])->assertUnprocessable()->assertJsonValidationErrors('title');
    }

    public function test_a_follow_up_takes_its_client_from_the_consultation(): void
    {
        $consultation = Consultation::factory()->forClient($this->client)->create(['title' => 'Natal reading']);

        $this->add(['title' => 'Send the recording', 'consultation_id' => $consultation->id, 'due_date' => '2026-10-12'])
            ->assertCreated()
            ->assertJsonPath('data.client.full_name', 'Ana Marković')
            ->assertJsonPath('data.consultation.id', $consultation->id)
            ->assertJsonPath('data.consultation.title', 'Natal reading');

        $other = Client::factory()->inWorkspace($this->user->current_workspace_id)->create();

        $this->add(['client_id' => $other->id, 'consultation_id' => $consultation->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consultation_id' => __('tasks.consultation_other_client')]);

        $this->assertSame(['task'], $this->timeline());
    }

    public function test_a_task_can_move_to_another_client_and_its_entries_follow(): void
    {
        $consultation = Consultation::factory()->forClient($this->client)->create();
        $id = $this->add(['client_id' => $this->client->id, 'consultation_id' => $consultation->id])->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['status' => 'done'])->assertOk();

        $other = Client::factory()->inWorkspace($this->user->current_workspace_id)->create();

        // The consultation is the first client's, so it cannot stay.
        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['client_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('consultation_id');

        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['client_id' => $other->id, 'consultation_id' => null])
            ->assertOk()->assertJsonPath('data.client.id', $other->id);

        $this->assertSame([], $this->timeline());
        $this->assertSame(['task', 'task_completed'], $this->timeline($other));

        // Without a client, a task leaves the timeline altogether.
        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['client_id' => null])->assertOk();
        $this->assertSame([], $this->timeline($other));
    }

    public function test_done_records_when_and_by_whom_and_reopening_takes_it_back(): void
    {
        $member = $this->memberOf($this->user);
        $id = $this->add(['client_id' => $this->client->id, 'priority' => 'high'])->json('data.id');

        $this->travel(2)->hours();
        $this->actingAs($member)->patchJson("/api/v1/tasks/{$id}", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.completed_at', '2026-10-05T12:00:00Z');

        $this->assertSame(['task', 'task_completed'], $this->timeline());
        $completed = ActivityEvent::withoutGlobalScopes()->where('event_type', 'task_completed')->sole();
        $this->assertSame($member->id, $completed->created_by);
        $this->assertSame('2026-10-05 12:00:00', $completed->occurred_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Send the transit summary', $completed->summary);

        $entry = ActivityEvent::withoutGlobalScopes()->where('event_type', 'task')->sole();
        $this->assertSame('done', $entry->metadata['status']);
        $this->assertSame('high', $entry->metadata['priority']);
        $this->assertSame('2026-10-05 10:00:00', $entry->occurred_at->utc()->format('Y-m-d H:i:s'));

        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('data.completed_at', null);

        $this->assertSame(['task'], $this->timeline());
        $this->assertDatabaseHas('tasks', ['id' => $id, 'completed_by' => null]);
    }

    public function test_the_timeline_shows_tasks_under_their_own_filter(): void
    {
        $id = $this->add(['client_id' => $this->client->id, 'due_date' => '2026-10-09'])->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['status' => 'done']);

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$this->client->id}/timeline?type=tasks")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.category', 'tasks')
            ->assertJsonPath('data.1.type', 'task')
            ->assertJsonPath('data.1.metadata.due_date', '2026-10-09')
            ->assertJsonPath('data.1.subject', ['type' => 'task', 'id' => $id]);
    }

    public function test_a_deleted_task_leaves_the_lists_and_the_timeline(): void
    {
        $id = $this->add(['client_id' => $this->client->id])->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['status' => 'done']);

        $this->actingAs($this->user)->deleteJson("/api/v1/tasks/{$id}")->assertNoContent();

        $this->assertSoftDeleted('tasks', ['id' => $id]);
        $this->assertSame([], $this->timeline());
        $this->actingAs($this->user)->getJson('/api/v1/tasks?status=all')->assertJsonCount(0, 'data');
        $this->actingAs($this->user)->getJson("/api/v1/tasks/{$id}")->assertNotFound();
    }

    public function test_rebuilding_the_timeline_brings_back_both_entries(): void
    {
        $id = $this->add(['client_id' => $this->client->id])->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['status' => 'done']);
        ActivityEvent::withoutGlobalScopes()->where('event_type', 'like', 'task%')->delete();

        $this->artisan('activity:rebuild')->assertSuccessful();

        $this->assertSame(['task', 'task_completed'], $this->timeline());
    }

    public function test_lists_follow_the_viewers_calendar_with_counts_for_the_tabs(): void
    {
        $make = fn (string $title, array $state = []) => Task::factory()
            ->forClient($this->client, $this->user)
            ->state(['title' => $title] + $state);

        $make('Late by a day')->due('2026-10-04')->create();
        $make('Late this morning', ['priority' => 'low'])->due('2026-10-05', '09:00')->create();
        $make('This afternoon')->due('2026-10-05', '16:00')->create();
        $make('By tonight, pressing', ['priority' => 'high'])->due('2026-10-05')->create();
        $make('By tonight')->due('2026-10-05')->create();
        $make('Wednesday')->due('2026-10-07')->create();
        $make('Some day')->create();
        $make('Done last week')->done()->create(['completed_at' => now()->subWeek()]);
        $make('Done yesterday')->done()->create(['completed_at' => now()->subDay()]);

        $titles = fn (string $query) => collect($this->actingAs($this->user)->getJson("/api/v1/tasks{$query}")
            ->assertOk()->json('data'))->pluck('title')->all();

        $this->assertSame(
            ['Late by a day', 'Late this morning', 'This afternoon', 'By tonight, pressing', 'By tonight', 'Wednesday', 'Some day'],
            $titles(''),
        );
        $this->assertSame(['Late by a day', 'Late this morning'], $titles('?due=overdue'));
        $this->assertSame(['This afternoon', 'By tonight, pressing', 'By tonight'], $titles('?due=today'));
        $this->assertSame(['Wednesday'], $titles('?due=upcoming'));
        $this->assertSame(['Some day'], $titles('?due=none'));
        $this->assertSame(['Done yesterday', 'Done last week'], $titles('?status=done'));
        $this->assertSame(['Wednesday'], $titles('?search=wedn'));
        $this->assertCount(9, $titles('?status=all'));

        $response = $this->actingAs($this->user)->getJson('/api/v1/tasks?due=overdue')
            ->assertJsonPath('counts', ['open' => 7, 'overdue' => 2, 'today' => 3, 'done' => 2])
            ->assertJsonPath('data.0.due_state', 'overdue');

        $this->assertSame(1, $response->json('meta.current_page'));
    }

    public function test_today_is_the_viewers_today(): void
    {
        // 01:00 on Tuesday in Belgrade is still Monday evening in New York.
        Task::factory()->forClient($this->client, $this->user)->due('2026-10-06', '01:00')->create();
        $traveller = $this->memberOf($this->user);
        $traveller->update(['timezone' => 'America/New_York']);

        $this->actingAs($this->user)->getJson('/api/v1/tasks')->assertJsonPath('data.0.due_state', 'upcoming');
        $this->actingAs($traveller)->getJson('/api/v1/tasks')
            ->assertJsonPath('data.0.due_state', 'today')
            ->assertJsonPath('counts.today', 1);
    }

    public function test_a_clients_tasks_and_a_consultations_follow_ups_are_listed_apart(): void
    {
        $consultation = Consultation::factory()->forClient($this->client)->create();
        $this->add(['title' => 'Follow-up', 'consultation_id' => $consultation->id]);
        $this->add(['title' => 'About Ana', 'client_id' => $this->client->id]);
        $this->add(['title' => 'Own to-do']);

        $this->actingAs($this->user)->getJson("/api/v1/tasks?client_id={$this->client->id}")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('counts.open', 2);
        $this->actingAs($this->user)->getJson("/api/v1/tasks?consultation_id={$consultation->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Follow-up');

        $this->actingAs($this->user)->getJson("/api/v1/clients/{$this->client->id}")
            ->assertJsonPath('data.stats.open_tasks', 2);
    }

    public function test_every_member_works_with_the_practices_tasks(): void
    {
        $member = $this->memberOf($this->user);
        $id = $this->add(['client_id' => $this->client->id])->json('data.id');

        $this->actingAs($member)->getJson('/api/v1/tasks')->assertJsonCount(1, 'data');
        $this->actingAs($member)->patchJson("/api/v1/tasks/{$id}", ['title' => 'Send it today', 'assigned_user_id' => $member->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_user.id', $member->id);

        $this->actingAs($this->user)->getJson("/api/v1/tasks?assigned_user_id={$member->id}")->assertJsonCount(1, 'data');
    }

    public function test_a_repeated_request_adds_the_task_once(): void
    {
        $headers = ['Idempotency-Key' => 'task-0123456789abcdef'];

        $first = $this->actingAs($this->user)->postJson('/api/v1/tasks', ['title' => 'Once'], $headers)->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/tasks', ['title' => 'Once'], $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Task::query()->withoutGlobalScopes()->count());
    }
}
