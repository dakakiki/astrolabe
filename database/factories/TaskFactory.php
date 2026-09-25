<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Open,
        ];
    }

    /** A task of the given astrologer, in their current workspace, without a client. */
    public function by(User $user): static
    {
        return $this->state([
            'workspace_id' => $user->current_workspace_id,
            'created_by' => $user->id,
            'assigned_user_id' => $user->id,
        ]);
    }

    /** A task about the given client, created by the given astrologer. */
    public function forClient(Client $client, User $user): static
    {
        return $this->by($user)->state([
            'workspace_id' => $client->workspace_id,
            'client_id' => $client->id,
        ]);
    }

    /** Due on a day ("2026-10-05"), optionally at a time, in a zone. */
    public function due(string $date, ?string $time = null, string $zone = 'Europe/Belgrade'): static
    {
        return $this->state([
            'due_date' => $date,
            'due_time' => $time,
            'timezone' => $zone,
            'due_at' => Task::deadline($date, $time, $zone),
        ]);
    }

    public function done(?User $by = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Done,
            'completed_at' => now(),
            'completed_by' => $by?->id ?? $attributes['created_by'] ?? null,
        ]);
    }
}
