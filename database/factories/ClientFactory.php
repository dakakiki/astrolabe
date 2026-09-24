<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->safeEmail(),
            'status' => ClientStatus::Active,
            'last_activity_at' => now(),
        ];
    }

    public function inWorkspace(int $workspaceId): static
    {
        return $this->state(['workspace_id' => $workspaceId]);
    }
}
