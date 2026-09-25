<?php

namespace App\Http\Resources;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One task. The due date comes as entered (`due_date`, `due_time`, `timezone`)
 * and as the UTC deadline `due_at`; `due_state` places an open task on the
 * viewer's own calendar (overdue, today, upcoming).
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $zone = $request->user()?->timezone ?: 'UTC';
        $now = CarbonImmutable::now();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'full_name' => $this->client->fullName(),
                'status' => $this->client->status->value,
            ] : null),
            'consultation_id' => $this->consultation_id,
            'consultation' => $this->whenLoaded('consultation', fn () => $this->consultation ? [
                'id' => $this->consultation->id,
                'title' => $this->consultation->title ?? $this->consultation->service?->name,
                'starts_at' => $this->consultation->starts_at?->toIso8601ZuluString(),
                'status' => $this->consultation->status->value,
            ] : null),
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'due_time' => $this->dueTime(),
            'timezone' => $this->timezone,
            'due_at' => $this->due_at?->toIso8601ZuluString(),
            'due_state' => $this->dueState($now, $now->setTimezone($zone)->startOfDay()->addDay()),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
