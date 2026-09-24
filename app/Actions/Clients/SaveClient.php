<?php

namespace App\Actions\Clients;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Tag;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a client together with tags, methods and birth data, in
 * one transaction. Only the keys present in the input are changed.
 */
class SaveClient
{
    private const PROFILE_FIELDS = [
        'first_name', 'last_name', 'email', 'phone', 'country_code', 'timezone',
        'preferred_locale', 'status', 'internal_notes', 'assigned_user_id',
    ];

    public function __construct(private readonly SaveBirthDetails $saveBirthDetails) {}

    /**
     * @param  array<string, mixed>  $input  validated
     */
    public function handle(Client $client, array $input): Client
    {
        return DB::transaction(function () use ($client, $input) {
            $client->fill(Arr::only($input, self::PROFILE_FIELDS));

            if (! $client->exists) {
                $client->status ??= ClientStatus::Active;
                $client->assigned_user_id ??= auth()->id();
            }

            $client->last_activity_at = now();
            $client->save();

            if (array_key_exists('tags', $input)) {
                $client->tags()->sync($this->tagIds($input['tags'] ?? []));
            }

            if (array_key_exists('method_ids', $input)) {
                $defaultId = isset($input['default_method_id']) ? (int) $input['default_method_id'] : null;

                $client->astrologyMethods()->sync(
                    collect($input['method_ids'] ?? [])
                        ->mapWithKeys(fn ($id) => [(int) $id => ['is_default' => (int) $id === $defaultId]])
                        ->all()
                );
            }

            if (! empty($input['birth'])) {
                $this->saveBirthDetails->handle($client, $input['birth']);
            }

            return $client->load(['tags', 'astrologyMethods', 'birthDetails']);
        });
    }

    /**
     * Tags are chosen by name and created on first use (per workspace, case-insensitively).
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    private function tagIds(array $names): array
    {
        return collect($names)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->map(fn ($name) => Tag::firstOrCreate(['name' => $name])->id)
            ->values()
            ->all();
    }
}
