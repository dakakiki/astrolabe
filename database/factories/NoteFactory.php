<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\Client;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'content' => '<p>'.fake()->paragraph().'</p>',
            'visibility' => Visibility::Private,
        ];
    }

    /** A note on the given client, written by the given member. */
    public function forClient(Client $client, User $author): static
    {
        return $this->state([
            'workspace_id' => $client->workspace_id,
            'client_id' => $client->id,
            'created_by' => $author->id,
        ]);
    }
}
