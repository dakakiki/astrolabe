<?php

namespace Database\Factories;

use App\Enums\LocationType;
use App\Enums\ServiceColor;
use App\Models\Service;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->words(2, true),
            'duration_minutes' => 60,
            'price_amount' => 9000,
            'currency' => 'EUR',
            'location_type' => LocationType::Online,
            'color' => ServiceColor::Indigo,
            'requires_deposit' => false,
            'is_active' => true,
        ];
    }

    public function inWorkspace(int $workspaceId): static
    {
        return $this->state(['workspace_id' => $workspaceId]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
