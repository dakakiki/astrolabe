<?php

namespace Tests\Feature\Dashboard;

use App\Enums\AppointmentStatus;
use App\Enums\ClientStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * The start screen (docs/spec/02, "Dashboard"): the viewer's day and week.
 */
class DashboardTest extends TestCase
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

    private function appointment(string $utcStart, array $state = [], ?User $astrologer = null): Appointment
    {
        return Appointment::factory()->forClient($this->client, $astrologer ?? $this->user)->at($utcStart)->create($state);
    }

    public function test_the_viewers_appointments_today_and_in_the_week_ahead(): void
    {
        $morning = $this->appointment('2026-10-05 07:00', ['status' => AppointmentStatus::Completed]);
        $afternoon = $this->appointment('2026-10-05 13:00');
        $this->appointment('2026-10-05 15:00', ['status' => AppointmentStatus::Cancelled, 'cancellation_reason' => 'Ill']);
        $this->appointment('2026-10-05 16:00', [], $this->memberOf($this->user));
        $tomorrow = $this->appointment('2026-10-06 08:00');
        $nextMonday = $this->appointment('2026-10-12 08:00');
        $this->appointment('2026-10-13 08:00');
        $this->appointment('2026-10-07 08:00', ['status' => AppointmentStatus::Cancelled, 'cancellation_reason' => 'Moved']);

        $response = $this->actingAs($this->user)->getJson('/api/v1/dashboard')->assertOk();

        $response->assertJsonPath('data.today', '2026-10-05')
            ->assertJsonPath('data.timezone', 'Europe/Belgrade')
            ->assertJsonPath('data.counts.appointments_today', 2)
            ->assertJsonPath('data.counts.appointments_upcoming', 2);

        $this->assertSame([$morning->id, $afternoon->id], array_column($response->json('data.appointments.today'), 'id'));
        $this->assertSame([$tomorrow->id, $nextMonday->id], array_column($response->json('data.appointments.upcoming'), 'id'));
        $response->assertJsonPath('data.appointments.today.1.client.full_name', 'Ana Marković');
        $response->assertJsonMissingPath('data.appointments.today.0.notes');
    }

    public function test_the_viewers_overdue_todays_and_upcoming_tasks(): void
    {
        $task = fn (string $title, ?User $owner = null) => Task::factory()
            ->forClient($this->client, $owner ?? $this->user)
            ->state(['title' => $title]);

        $task('Late')->due('2026-10-02')->create();
        $task('Today')->due('2026-10-05', '18:00')->create();
        $task('Sunday')->due('2026-10-11')->create();
        $task('Next month')->due('2026-11-02')->create();
        $task('Whenever')->create();
        $task('Done')->due('2026-10-01')->done()->create();
        $task('Unassigned')->due('2026-10-05')->create(['assigned_user_id' => null]);
        $task('A colleague\'s', $this->memberOf($this->user))->due('2026-10-01')->create();

        $response = $this->actingAs($this->user)->getJson('/api/v1/dashboard')->assertOk();

        $titles = fn (string $list) => array_column($response->json("data.tasks.{$list}"), 'title');
        $this->assertSame(['Late'], $titles('overdue'));
        $this->assertSame(['Today', 'Unassigned'], $titles('today'));
        $this->assertSame(['Sunday'], $titles('upcoming'));

        $response->assertJsonPath('data.counts.tasks_open', 6)
            ->assertJsonPath('data.counts.tasks_overdue', 1)
            ->assertJsonPath('data.counts.tasks_today', 2)
            ->assertJsonPath('data.tasks.overdue.0.client.full_name', 'Ana Marković')
            ->assertJsonPath('data.tasks.overdue.0.due_state', 'overdue');
    }

    public function test_recently_active_clients_and_new_files(): void
    {
        $member = $this->memberOf($this->user);

        $this->actingAs($this->user)->postJson('/api/v1/attachments', [
            'client_id' => $this->client->id, 'kind' => 'link', 'url' => 'https://example.com/recording', 'visibility' => 'team',
        ])->assertCreated();
        $this->actingAs($member)->postJson('/api/v1/attachments', [
            'client_id' => $this->client->id, 'kind' => 'link', 'url' => 'https://example.com/private', 'visibility' => 'private',
        ])->assertCreated();

        $this->client->forceFill(['last_activity_at' => now()->subDays(3)])->save();
        $recent = Client::factory()->inWorkspace($this->user->current_workspace_id)->create(['last_activity_at' => now()->subHour()]);
        Client::factory()->inWorkspace($this->user->current_workspace_id)->create([
            'status' => ClientStatus::Archived,
            'last_activity_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/dashboard')->assertOk();

        $this->assertSame([$recent->id, $this->client->id], array_column($response->json('data.recent_clients'), 'id'));
        $response->assertJsonCount(1, 'data.recent_files')
            ->assertJsonPath('data.recent_files.0.url', 'https://example.com/recording')
            ->assertJsonPath('data.recent_files.0.client.full_name', 'Ana Marković')
            ->assertJsonPath('data.counts.clients_active', 2)
            ->assertJsonPath('data.counts.clients_total', 3)
            ->assertJsonPath('data.counts.clients_new_this_month', 3);
    }

    public function test_clients_whose_chart_cannot_be_drawn_yet(): void
    {
        $birth = [
            'birth_date' => '1985-07-15',
            'birth_time' => '14:30',
            'time_accuracy' => 'exact',
            'birth_place' => 'Novi Sad',
            'birth_country_code' => 'RS',
            'latitude' => 45.25167,
            'longitude' => 19.83694,
            'birth_timezone' => 'Europe/Belgrade',
        ];
        $add = fn (string $name, ?array $birth) => $this->actingAs($this->user)->postJson('/api/v1/clients', array_filter([
            'first_name' => $name,
            'birth' => $birth,
        ]))->assertCreated()->json('data.id');

        $add('Complete', $birth);
        $add('Time unknown', ['time_accuracy' => 'unknown', 'birth_time' => null] + $birth);
        $noPlace = $add('No place', ['time_accuracy' => 'approximate', 'latitude' => null, 'longitude' => null, 'birth_timezone' => null, 'birth_place' => 'Somewhere'] + $birth);

        // Ana (from setUp) has no birth data at all; an archived client is left out.
        Client::factory()->inWorkspace($this->user->current_workspace_id)->create(['status' => ClientStatus::Archived]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/dashboard')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->client->id, $noPlace],
            array_column($response->json('data.incomplete_birth_data'), 'id'),
        );
        $response->assertJsonPath('data.counts.incomplete_birth_data', 2);
    }

    public function test_a_new_practice_gets_an_empty_dashboard(): void
    {
        $fresh = User::factory()->withWorkspace()->create();

        $this->actingAs($fresh)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.appointments.today', [])
            ->assertJsonPath('data.recent_files', [])
            ->assertJsonPath('data.incomplete_birth_data', [])
            ->assertJsonPath('data.counts.clients_total', 0);
    }
}
