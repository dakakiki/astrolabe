<?php

namespace App\Support\Activity;

use App\Enums\ActivityType;
use App\Enums\ClientStatus;
use App\Models\ActivityEvent;
use App\Models\Client;
use Illuminate\Database\Eloquent\Model;

/**
 * Records that something about a client changed — a profile edit, new birth
 * data, archiving, a moved or cancelled appointment. Unlike projected entries
 * these exist only here. Profile changes carry which fields changed, never
 * their values (docs/spec/06, logging); scheduling entries carry the times
 * (and a cancellation its reason), since that is what the entry is about.
 */
class ActivityLog
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  Model|null  $subject  what the entry is about, when not the client itself (e.g. a moved appointment)
     */
    public function record(Client $client, ActivityType $type, array $metadata = [], ?Model $subject = null): ActivityEvent
    {
        $subject ??= $client;

        $event = new ActivityEvent;
        $event->forceFill([
            'workspace_id' => $client->workspace_id,
            'client_id' => $client->getKey(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event_type' => $type,
            'occurred_at' => now(),
            'created_by' => auth()->id(),
            'metadata' => $metadata ?: null,
        ])->save();

        return $event;
    }

    /**
     * A profile edit. Moving into or out of the archive is its own entry; any
     * other changed fields are listed by name.
     *
     * @param  list<string>  $fields
     */
    public function clientChanged(Client $client, array $fields, ?ClientStatus $previousStatus): void
    {
        $fields = array_values(array_unique($fields));

        if (in_array('status', $fields, true) && $previousStatus !== $client->status) {
            $archiving = match (true) {
                $client->status === ClientStatus::Archived => ActivityType::ClientArchived,
                $previousStatus === ClientStatus::Archived => ActivityType::ClientRestored,
                default => null,
            };

            if ($archiving !== null) {
                $this->record($client, $archiving);
                $fields = array_values(array_diff($fields, ['status']));
            }
        }

        if ($fields !== []) {
            $this->record($client, ActivityType::ClientUpdated, ['fields' => $fields]);
        }
    }
}
