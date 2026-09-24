<?php

namespace Database\Factories;

use App\Enums\HouseSystem;
use App\Enums\ZodiacMode;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates a bare workspace. Use UserFactory::withWorkspace() when a test needs
 * an owner with a membership, as registration would create.
 *
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'default_locale' => 'en',
            'timezone' => 'Europe/Belgrade',
            'default_currency' => 'EUR',
            'default_house_system' => HouseSystem::Placidus,
            'default_zodiac_mode' => ZodiacMode::Tropical,
            'default_ayanamsa' => null,
        ];
    }
}
