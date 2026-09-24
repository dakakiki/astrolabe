<?php

namespace Database\Factories;

use App\Enums\ConsultationStatus;
use App\Models\Client;
use App\Models\Consultation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consultation>
 */
class ConsultationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Natal reading',
            'status' => ConsultationStatus::Completed,
            'starts_at' => now()->subDays(3)->startOfHour(),
            'timezone' => 'Europe/Belgrade',
            'duration_minutes' => 60,
            'topics' => fake()->sentence(),
            'internal_notes' => '<p>'.fake()->sentence().'</p>',
            'client_summary' => '<p>'.fake()->sentence().'</p>',
        ];
    }

    /** A consultation with the given client, in that client's workspace. */
    public function forClient(Client $client): static
    {
        return $this->state(['workspace_id' => $client->workspace_id, 'client_id' => $client->id]);
    }
}
