<?php

namespace App\Support\Activity;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\Concerns\ProjectsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Keeps each consultation, note, file, chart, client, appointment and task in
 * step with its timeline entries: written when the row is saved, removed when
 * it is deleted. Works across workspaces on purpose — an entry is found by its
 * subject and takes the subject's workspace, never the request's.
 */
class ActivityProjector
{
    /**
     * @param  Model&ProjectsActivity  $subject
     */
    public function sync(Model $subject): void
    {
        $gone = ! $subject->exists || (method_exists($subject, 'trashed') && $subject->trashed());
        $projections = $gone ? [] : $subject->activityProjections();
        $types = $subject::projectedActivityTypes();

        $existing = ActivityEvent::withoutGlobalScopes()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->whereIn('event_type', array_map(fn (ActivityType $type) => $type->value, $types))
            ->get()
            ->keyBy(fn (ActivityEvent $event) => $event->event_type->value);

        foreach ($types as $type) {
            $this->write($subject, $existing->get($type->value), $projections[$type->value] ?? null);
        }
    }

    private function write(Model $subject, ?ActivityEvent $existing, ?ActivityProjection $projection): void
    {
        if ($projection === null) {
            $existing?->delete();

            return;
        }

        $event = $existing ?? new ActivityEvent;
        $event->forceFill([
            'workspace_id' => $subject->getAttribute('workspace_id'),
            'client_id' => $projection->clientId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event_type' => $projection->type,
            'occurred_at' => $projection->occurredAt,
            'created_by' => $projection->createdBy,
            'visibility' => $projection->visibility,
            'summary' => $projection->summary === null ? null : Str::limit($projection->summary, 250),
            'metadata' => $projection->metadata ?: null,
        ]);

        if ($event->isDirty()) {
            $event->save();
        }
    }
}
