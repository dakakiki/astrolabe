<?php

namespace App\Actions\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a task (docs/spec/02, "Zadaci i follow-up"). Only the
 * keys present in the input are changed.
 *
 * - A new task is open, normal priority unless said otherwise, and assigned
 *   to the person who adds it. A follow-up takes its client from the consultation.
 * - The due date is kept as entered (day, optional time, zone) and as a UTC
 *   deadline. A new date alone keeps the time; removing the date removes it all.
 * - Marking it done records when and by whom; reopening clears that.
 */
class SaveTask
{
    private const FIELDS = ['title', 'description', 'priority', 'assigned_user_id', 'client_id', 'consultation_id'];

    /**
     * @param  array<string, mixed>  $input  validated
     */
    public function handle(Task $task, array $input): Task
    {
        return DB::transaction(function () use ($task, $input) {
            $creating = ! $task->exists;

            $task->fill(Arr::only($input, self::FIELDS));

            if ($creating) {
                $task->created_by = auth()->id();
                $task->assigned_user_id ??= auth()->id();
                $task->priority ??= TaskPriority::Normal;
                $task->status = TaskStatus::Open;

                if ($task->consultation_id !== null && $task->client_id === null) {
                    $task->client_id = Consultation::query()->whereKey($task->consultation_id)->value('client_id');
                }
            }

            if (array_key_exists('status', $input)) {
                $this->applyStatus($task, TaskStatus::from($input['status']));
            }

            if (array_intersect_key($input, array_flip(['due_date', 'due_time', 'timezone'])) !== []) {
                $this->applyDue($task, $input);
            }

            $touched = $creating || $task->isDirty(['status', 'client_id']);
            $task->save();

            if ($touched && $task->client_id !== null) {
                Client::query()->find($task->client_id)?->touchActivity();
            }

            return $task->load(['client', 'consultation.service', 'assignedUser', 'creator']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyDue(Task $task, array $input): void
    {
        $date = array_key_exists('due_date', $input) ? $input['due_date'] : $task->due_date?->format('Y-m-d');

        if ($date === null) {
            $task->forceFill(['due_date' => null, 'due_time' => null, 'timezone' => null, 'due_at' => null]);

            return;
        }

        $time = array_key_exists('due_time', $input) ? $input['due_time'] : $task->dueTime();
        $zone = $input['timezone'] ?? $task->timezone ?? auth()->user()?->timezone ?? 'UTC';

        $task->forceFill([
            'due_date' => $date,
            'due_time' => $time,
            'timezone' => $zone,
            'due_at' => Task::deadline($date, $time, $zone),
        ]);
    }

    private function applyStatus(Task $task, TaskStatus $status): void
    {
        if ($task->status === $status) {
            return;
        }

        $task->status = $status;
        $done = $status === TaskStatus::Done;
        $task->completed_at = $done ? now() : null;
        $task->completed_by = $done ? auth()->id() : null;
    }
}
