<?php

namespace App\Actions\Clients;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Tag;
use App\Support\Activity\ActivityLog;
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

    public function __construct(
        private readonly SaveBirthDetails $saveBirthDetails,
        private readonly ActivityLog $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated
     */
    public function handle(Client $client, array $input): Client
    {
        return DB::transaction(function () use ($client, $input) {
            $creating = ! $client->exists;
            $previousStatus = $client->status;
            $client->fill(Arr::only($input, self::PROFILE_FIELDS));

            if ($creating) {
                $client->status ??= ClientStatus::Active;
                $client->assigned_user_id ??= auth()->id();
            }

            // For the timeline: which fields changed, never their values.
            $changed = $creating ? [] : array_keys($client->getDirty());

            $client->last_activity_at = now();
            $client->save();

            if (array_key_exists('tags', $input)) {
                $synced = $client->tags()->sync($this->tagIds($input['tags'] ?? []));
                $changed = [...$changed, ...($this->syncChanged($synced) ? ['tags'] : [])];
            }

            if (array_key_exists('method_ids', $input)) {
                $defaultId = isset($input['default_method_id']) ? (int) $input['default_method_id'] : null;

                $synced = $client->astrologyMethods()->sync(
                    collect($input['method_ids'] ?? [])
                        ->mapWithKeys(fn ($id) => [(int) $id => ['is_default' => (int) $id === $defaultId]])
                        ->all()
                );
                $changed = [...$changed, ...($this->syncChanged($synced) ? ['methods'] : [])];
            }

            if (! $creating) {
                $this->activity->clientChanged($client, $changed, $previousStatus);
            }

            if (! empty($input['birth'])) {
                $this->saveBirthDetails->handle($client, $input['birth']);
            }

            return $client->load(['tags', 'astrologyMethods', 'birthDetails']);
        });
    }

    /**
     * @param  array{attached: array<int, mixed>, detached: array<int, mixed>, updated: array<int, mixed>}  $result
     */
    private function syncChanged(array $result): bool
    {
        return $result['attached'] !== [] || $result['detached'] !== [] || $result['updated'] !== [];
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
