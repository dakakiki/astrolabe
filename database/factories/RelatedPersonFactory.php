<?php

namespace Database\Factories;

use App\Enums\RelationshipType;
use App\Models\Client;
use App\Models\RelatedPerson;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelatedPerson>
 */
class RelatedPersonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
        ];
    }

    /** A person linked to the client, in the client's workspace. */
    public function relatedTo(Client $client, RelationshipType $type = RelationshipType::Partner): static
    {
        return $this->state(['workspace_id' => $client->workspace_id])
            ->afterCreating(function (RelatedPerson $person) use ($client, $type) {
                $relationship = $client->relationships()->make(['relationship_type' => $type]);
                $relationship->workspace_id = $client->workspace_id;
                $relationship->relatedPerson()->associate($person)->save();
            });
    }
}
