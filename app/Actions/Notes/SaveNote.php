<?php

namespace App\Actions\Notes;

use App\Enums\Visibility;
use App\Models\Note;
use App\Support\RichText;
use Illuminate\Support\Arr;

/**
 * Creates or updates a note. New notes are private unless marked otherwise
 * (docs/spec/02, "Beleške").
 */
class SaveNote
{
    /**
     * @param  array<string, mixed>  $input  validated
     */
    public function handle(Note $note, array $input): Note
    {
        $note->fill(Arr::only($input, ['title', 'visibility', 'consultation_id']));

        if (array_key_exists('content', $input)) {
            $note->content = RichText::sanitize($input['content']);
        }

        if (! $note->exists) {
            $note->client_id = (int) $input['client_id'];
            $note->created_by = auth()->id();
            $note->visibility ??= Visibility::Private;
        }

        $note->save();
        $note->client->touchActivity();

        return $note->load(['author', 'consultation']);
    }
}
