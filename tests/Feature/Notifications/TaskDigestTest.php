<?php

namespace Tests\Feature\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\TasksDueToday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\AddsWorkspaceMembers;
use Tests\TestCase;

/**
 * The morning email about tasks (docs/spec/02, "Zadaci i follow-up"; the
 * prototype's "Remind me — email on the morning it is due").
 */
class TaskDigestTest extends TestCase
{
    use AddsWorkspaceMembers, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Friday 2 October 2026, 07:00 in Belgrade.
        $this->travelTo('2026-10-02 05:00:00');
        $this->user = User::factory()->withWorkspace(['name' => 'Stellar Practice'])->create(['timezone' => 'Europe/Belgrade']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = [], ?User $for = null): Task
    {
        $for ??= $this->user;
        $date = $attributes['due_date'] ?? '2026-10-02';
        $time = $attributes['due_time'] ?? null;

        return Task::factory()->create($attributes + [
            'workspace_id' => $for->current_workspace_id,
            'assigned_user_id' => $for->id,
            'created_by' => $for->id,
            'due_date' => $date,
            'due_time' => $time,
            'timezone' => 'Europe/Belgrade',
            'due_at' => Task::deadline($date, $time, 'Europe/Belgrade'),
        ]);
    }

    private function sendDue(): void
    {
        $this->artisan('notifications:send-digests')->assertSuccessful();
    }

    public function test_the_next_morning_email_is_planned_on_the_persons_clock(): void
    {
        $this->assertSame('2026-10-02T06:00:00Z', $this->user->next_digest_at->toIso8601ZuluString());

        $this->user->forceFill(['notification_preferences' => ['digest_time' => '07:30', 'quiet_hours' => null]])->save();
        $this->assertSame('2026-10-02T05:30:00Z', $this->user->fresh()->next_digest_at->toIso8601ZuluString());

        // Already past today: tomorrow.
        $this->user->forceFill(['notification_preferences' => ['digest_time' => '06:30', 'quiet_hours' => null]])->save();
        $this->assertSame('2026-10-03T04:30:00Z', $this->user->fresh()->next_digest_at->toIso8601ZuluString());

        // 01:00 in New York: still this morning there.
        $this->user->forceFill(['timezone' => 'America/New_York'])->save();
        $this->assertSame('2026-10-02T10:30:00Z', $this->user->fresh()->next_digest_at->toIso8601ZuluString());

        $this->user->forceFill(['notification_preferences' => ['task_digest' => false]])->save();
        $this->assertNull($this->user->fresh()->next_digest_at);
    }

    public function test_it_counts_the_tasks_due_today_and_overdue_that_ask_for_a_reminder(): void
    {
        Notification::fake();

        $this->task(['title' => 'Send Ana the transit summary']);
        $this->task(['due_time' => '07:30']);
        $this->task(['due_date' => '2026-09-30']);
        $this->task(['remind' => false]);
        $this->task(['status' => 'done']);
        $this->task(['due_date' => '2026-10-03']);
        $this->task(['assigned_user_id' => null]);
        $this->task([], $this->memberOf($this->user));

        $this->sendDue();
        Notification::assertNothingSent();

        $this->travelTo('2026-10-02 06:00:20');
        $this->sendDue();
        $this->sendDue();

        Notification::assertSentToTimes($this->user, TasksDueToday::class, 1);
        Notification::assertSentTo($this->user, function (TasksDueToday $digest) {
            $mail = $digest->toMail($this->user);
            $text = $mail->subject.' '.implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines);

            $this->assertSame(3, $digest->today);
            $this->assertSame(1, $digest->overdue);
            $this->assertSame('3 tasks due today', $mail->subject);
            $this->assertStringContainsString('In Stellar Practice, 3 tasks are due today.', $text);
            $this->assertStringContainsString('1 more is overdue.', $text);
            $this->assertStringNotContainsString('Ana', $text);
            $this->assertSame(url('/tasks').'?show=today', $mail->actionUrl);

            return true;
        });

        $this->assertSame('2026-10-03T06:00:00Z', $this->user->fresh()->next_digest_at->toIso8601ZuluString());
    }

    public function test_no_email_when_nothing_is_due_today(): void
    {
        Notification::fake();
        $this->task(['due_date' => '2026-09-30']);
        $this->task(['due_date' => '2026-10-05']);

        $this->travelTo('2026-10-02 06:00:00');
        $this->sendDue();

        Notification::assertNothingSent();
        $this->assertSame('2026-10-03T06:00:00Z', $this->user->fresh()->next_digest_at->toIso8601ZuluString());
    }

    public function test_one_email_per_practice_with_tasks_today(): void
    {
        Notification::fake();
        $this->task();

        $second = Workspace::factory()->create(['name' => 'Evening Readings']);
        $second->users()->attach($this->user, ['role' => 'owner', 'status' => 'active']);
        Task::factory()->create([
            'workspace_id' => $second->id,
            'assigned_user_id' => $this->user->id,
            'due_date' => '2026-10-02',
            'timezone' => 'Europe/Belgrade',
            'due_at' => Task::deadline('2026-10-02', null, 'Europe/Belgrade'),
        ]);

        $this->travelTo('2026-10-02 06:00:00');
        $this->sendDue();

        Notification::assertSentToTimes($this->user, TasksDueToday::class, 2);
        Notification::assertSentTo($this->user, fn (TasksDueToday $digest) => $digest->practice === 'Evening Readings');
    }

    public function test_the_morning_stays_at_the_same_hour_across_the_clock_change(): void
    {
        Notification::fake();
        $this->user->forceFill(['next_digest_at' => '2026-10-24 06:00:00'])->saveQuietly();

        // Saturday 08:00 summer time, then Sunday 08:00 winter time.
        $this->travelTo('2026-10-24 06:00:00');
        $this->sendDue();

        $this->assertSame('2026-10-25T07:00:00Z', $this->user->fresh()->next_digest_at->toIso8601ZuluString());
    }

    public function test_remind_me_is_on_for_new_tasks_and_can_be_switched_off(): void
    {
        $id = $this->actingAs($this->user)->postJson('/api/v1/tasks', ['title' => 'Prepare the solar return'])
            ->assertCreated()
            ->assertJsonPath('data.remind', true)
            ->json('data.id');

        $this->actingAs($this->user)->patchJson("/api/v1/tasks/{$id}", ['remind' => false])
            ->assertOk()
            ->assertJsonPath('data.remind', false);

        $this->actingAs($this->user)->postJson('/api/v1/tasks', ['title' => 'Quiet task', 'remind' => false])
            ->assertCreated()
            ->assertJsonPath('data.remind', false);
    }
}
