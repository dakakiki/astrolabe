<?php

namespace Database\Factories;

use App\Models\PortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortalUser>
 */
class PortalUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'email_verified_at' => now(),
        ];
    }
}
