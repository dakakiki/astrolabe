<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\ProjectsActivity;
use App\Support\Activity\ActivityProjection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A follow-up or to-do (docs/spec/02, "Zadaci i follow-up"). It may belong to
 * a client and, as a follow-up, to one of that client's consultations. The due
 * date is kept as entered — a day, optionally a time, in a zone — together
 * with `due_at`, the deadline in UTC (the time given, or the end of the day).
 *
 * On the client's timeline a task has an entry when it is created and, while
 * it is done, a second one when it was completed.
 *
 * @property TaskPriority $priority
 * @property TaskStatus $status
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $due_at UTC
 * @property CarbonImmutable|null $completed_at UTC
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToWorkspace, HasFactory, ProjectsActivity, SoftDeletes;

    /** Set by SaveTask, never mass-assigned from a request. */
    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'due_date' => 'immutable_date',
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The consultation this task follows up, if any.
     *
     * @return BelongsTo<Consultation, $this>
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The deadline in UTC for a due date as entered: the time given, or the
     * start of the next day when there is none.
     */
    public static function deadline(string $date, ?string $time, string $zone): CarbonImmutable
    {
        $local = CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone);

        if ($time === null) {
            return $local->addDay()->utc();
        }

        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $local->setTime($hours, $minutes)->utc();
    }

    /** "15:00", or null for a whole day. */
    public function dueTime(): ?string
    {
        return $this->due_time === null ? null : substr((string) $this->due_time, 0, 5);
    }

    /**
     * Where an open task stands on the viewer's calendar: overdue, due today,
     * later, or null (done, or no due date).
     */
    public function dueState(CarbonInterface $now, CarbonInterface $tomorrow): ?string
    {
        if ($this->status !== TaskStatus::Open || $this->due_at === null) {
            return null;
        }

        return match (true) {
            $this->due_at <= $now => 'overdue',
            $this->due_at <= $tomorrow => 'today',
            default => 'upcoming',
        };
    }

    /**
     * @param  Builder<Task>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', TaskStatus::Open->value);
    }

    /**
     * Past their deadline and still open.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeOverdue(Builder $query, CarbonInterface $now): void
    {
        $query->open()->where('due_at', '<=', $now);
    }

    /**
     * Still open and due before the viewer's day ends (`$tomorrow` is the start
     * of the next day in the viewer's zone), not yet overdue.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeDueToday(Builder $query, CarbonInterface $now, CarbonInterface $tomorrow): void
    {
        $query->open()->where('due_at', '>', $now)->where('due_at', '<=', $tomorrow);
    }

    /**
     * Open, due after the viewer's day, optionally only until a moment.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeDueLater(Builder $query, CarbonInterface $tomorrow, ?CarbonInterface $until = null): void
    {
        $query->open()
            ->where('due_at', '>', $tomorrow)
            ->when($until, fn (Builder $query) => $query->where('due_at', '<=', $until));
    }

    /**
     * Tasks the user is responsible for: assigned to them, or to nobody.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeFor(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('assigned_user_id', $user->getKey())
            ->orWhereNull('assigned_user_id'));
    }

    /**
     * Earliest deadline first, tasks without one last; the same deadline by priority.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeByDeadline(Builder $query): void
    {
        $query->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByRaw("case priority when 'high' then 0 when 'normal' then 1 else 2 end")
            ->orderBy('id');
    }

    public static function activityType(): ActivityType
    {
        return ActivityType::Task;
    }

    public static function projectedActivityTypes(): array
    {
        return [ActivityType::Task, ActivityType::TaskCompleted];
    }

    public function activityProjections(): array
    {
        return [
            ActivityType::Task->value => $this->activityProjection(),
            ActivityType::TaskCompleted->value => $this->completionProjection(),
        ];
    }

    /** The task on its client's timeline, when it was added. Tasks without a client have none. */
    public function activityProjection(): ?ActivityProjection
    {
        if ($this->client_id === null) {
            return null;
        }

        return new ActivityProjection(
            type: ActivityType::Task,
            clientId: $this->client_id,
            occurredAt: $this->created_at ?? now(),
            createdBy: $this->created_by,
            summary: $this->title,
            metadata: [
                'title' => $this->title,
                'status' => $this->status->value,
                'priority' => $this->priority->value,
                'due_date' => $this->due_date?->format('Y-m-d'),
                'due_time' => $this->dueTime(),
                'due_at' => $this->due_at?->toIso8601ZuluString(),
                'timezone' => $this->timezone,
                'consultation_id' => $this->consultation_id,
            ],
        );
    }

    /** "Task completed", at the time it was done; gone again when the task is reopened. */
    private function completionProjection(): ?ActivityProjection
    {
        if ($this->client_id === null || $this->status !== TaskStatus::Done || $this->completed_at === null) {
            return null;
        }

        return new ActivityProjection(
            type: ActivityType::TaskCompleted,
            clientId: $this->client_id,
            occurredAt: $this->completed_at,
            createdBy: $this->completed_by,
            summary: $this->title,
            metadata: [
                'title' => $this->title,
                'consultation_id' => $this->consultation_id,
            ],
        );
    }
}
