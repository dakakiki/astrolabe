<?php

namespace Database\Factories;

use App\Models\AstrologyMethod;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates a workspace's own (custom) method. Built-in methods come from the migration.
 *
 * @extends Factory<AstrologyMethod>
 */
class AstrologyMethodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->words(2, true).' astrology',
        ];
    }
}
