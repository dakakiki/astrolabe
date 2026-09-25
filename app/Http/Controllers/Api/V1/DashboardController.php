<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\ClientStatus;
use App\Enums\TimeAccuracy;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\TaskResource;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The start screen (docs/spec/02, "Dashboard") in one request, on the viewer's
 * own calendar: their appointments today and in the next seven days, their
 * overdue, today's and upcoming tasks, the recently active clients, new files,
 * and clients whose chart cannot be drawn yet. Payments, revenue and transits
 * join with their phases (7).
 */
class DashboardController extends Controller
{
    /** Today and the seven days after it. */
    public const DAYS_AHEAD = 7;

    private const LIMIT = 8;

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Client::class);

        $user = $request->user();
        $zone = $user->timezone ?: 'UTC';
        $now = CarbonImmutable::now();
        $today = $now->setTimezone($zone)->startOfDay();
        $tomorrow = $today->addDay();
        $horizon = $today->addDays(self::DAYS_AHEAD + 1);

        $appointments = fn (): Builder => Appointment::query()
            ->with(['client.birthDetails', 'service', 'consultation'])
            ->where('assigned_user_id', $user->getKey());

        $todays = $appointments()
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->where('starts_at', '<', $tomorrow->utc())
            ->where('ends_at', '>', $today->utc())
            ->orderBy('starts_at')
            ->get();

        $upcoming = $appointments()
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('starts_at', '>=', $tomorrow->utc())
            ->where('starts_at', '<', $horizon->utc())
            ->orderBy('starts_at');

        $tasks = fn (): Builder => Task::query()
            ->for($user)
            ->with(['client', 'consultation.service']);

        $incomplete = $this->incompleteBirthData();

        return response()->json(['data' => [
            'today' => $today->format('Y-m-d'),
            'timezone' => $zone,
            'appointments' => [
                'today' => AppointmentResource::collection($todays)->resolve($request),
                'upcoming' => AppointmentResource::collection((clone $upcoming)->limit(self::LIMIT)->get())->resolve($request),
            ],
            'tasks' => [
                'overdue' => $this->tasks($tasks()->overdue($now), $request),
                'today' => $this->tasks($tasks()->dueToday($now, $tomorrow), $request),
                'upcoming' => $this->tasks($tasks()->dueLater($tomorrow, $horizon), $request),
            ],
            'recent_clients' => ClientResource::collection(
                Client::query()
                    ->where('status', '!=', ClientStatus::Archived->value)
                    ->whereNotNull('last_activity_at')
                    ->orderByDesc('last_activity_at')
                    ->orderByDesc('id')
                    ->limit(6)
                    ->get(),
            )->resolve($request),
            'recent_files' => AttachmentResource::collection(
                Attachment::query()
                    ->visibleTo($user)
                    ->with(['client', 'uploader'])
                    ->latest()
                    ->orderByDesc('id')
                    ->limit(6)
                    ->get(),
            )->resolve($request),
            'incomplete_birth_data' => ClientResource::collection(
                (clone $incomplete)->with('birthDetails')->orderByDesc('last_activity_at')->orderByDesc('id')->limit(5)->get(),
            )->resolve($request),
            'counts' => [
                'appointments_today' => $todays->count(),
                'appointments_upcoming' => $upcoming->count(),
                'tasks_open' => $tasks()->open()->count(),
                'tasks_overdue' => $tasks()->overdue($now)->count(),
                'tasks_today' => $tasks()->dueToday($now, $tomorrow)->count(),
                'clients_active' => Client::query()->where('status', '!=', ClientStatus::Archived->value)->count(),
                'clients_new_this_month' => Client::query()->where('created_at', '>=', $today->startOfMonth()->utc())->count(),
                'clients_total' => Client::query()->count(),
                'incomplete_birth_data' => $incomplete->count(),
            ],
        ]]);
    }

    /**
     * @param  Builder<Task>  $query
     * @return array<int, array<string, mixed>>
     */
    private function tasks(Builder $query, Request $request): array
    {
        return TaskResource::collection($query->byDeadline()->limit(self::LIMIT)->get())->resolve($request);
    }

    /**
     * Clients in the practice whose natal chart cannot be calculated yet: no
     * birth data, or no date, place, zone or — when it is known — time
     * (the same rules as `BirthDetails::missingForChart`).
     *
     * @return Builder<Client>
     */
    private function incompleteBirthData(): Builder
    {
        return Client::query()
            ->where('status', '!=', ClientStatus::Archived->value)
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('birthDetails')
                ->orWhereHas('birthDetails', fn (Builder $birth) => $birth->where(fn (Builder $birth) => $birth
                    ->whereNull('birth_date')
                    ->orWhereNull('latitude')
                    ->orWhereNull('longitude')
                    ->orWhereNull('birth_timezone')
                    ->orWhere(fn (Builder $birth) => $birth
                        ->where('time_accuracy', '!=', TimeAccuracy::Unknown->value)
                        ->whereNull('birth_time')))));
    }
}
