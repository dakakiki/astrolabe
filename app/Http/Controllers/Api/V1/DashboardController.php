<?php

namespace App\Http\Controllers\Api\V1;

use App\Astrology\Exceptions\EphemerisException;
use App\Astrology\Exceptions\IncompleteBirthData;
use App\Astrology\Services\TransitService;
use App\Enums\AppointmentStatus;
use App\Enums\AspectType;
use App\Enums\CelestialBody;
use App\Enums\ClientStatus;
use App\Enums\TimeAccuracy;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\ConsultationResource;
use App\Http\Resources\TaskResource;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Task;
use App\Models\User;
use App\Support\Billing\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The start screen (docs/spec/02, "Dashboard") in one request, on the viewer's
 * own calendar: their appointments today and in the next seven days, their
 * overdue, today's and upcoming tasks, the recently active clients, new files,
 * clients whose chart cannot be drawn yet, the slow transits on the charts of
 * the clients they see this week, and the practice's money: received this
 * month, outstanding, and the consultations waiting on payment.
 */
class DashboardController extends Controller
{
    /** Today and the seven days after it. */
    public const DAYS_AHEAD = 7;

    private const LIMIT = 8;

    /** "Before your next consultations": how many clients, and how many transits each. */
    private const TRANSIT_CLIENTS = 6;

    private const TRANSITS_EACH = 3;

    /** Only transits this close to exact make the dashboard (degrees). */
    public const TRANSIT_ORB = 1.0;

    /** "Waiting on payment": how many consultations, the longest waiting first. */
    private const WAITING = 6;

    public function __invoke(Request $request, TransitService $transits, Ledger $ledger): JsonResponse
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
            'transits' => $this->transits($user, $now, $horizon, $transits),
            'payments' => [
                'received_this_month' => $ledger->receivedBetween($today->startOfMonth()->format('Y-m-d'), $today->format('Y-m-d')),
                'outstanding' => $ledger->outstanding(),
                'waiting' => ConsultationResource::collection(
                    Consultation::query()
                        ->owed()
                        ->withBilling()
                        ->with(['client', 'service'])
                        ->orderBy('starts_at')
                        ->orderBy('id')
                        ->limit(self::WAITING)
                        ->get(),
                )->resolve($request),
            ],
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
     * "Before your next consultations" (Phase 7a): the clients the viewer sees
     * in the coming week and the slow transits closest to exact on their
     * charts now — Jupiter to Pluto in a conjunction, square, trine or
     * opposition to a personal planet or an angle, within a degree. The sky
     * is the same for all of them, so it is one engine run. A client without
     * complete birth data is left out; an engine failure leaves the rest of
     * the dashboard as it is (`null`).
     *
     * @return array{moment: string, clients: list<array<string, mixed>>}|null
     */
    private function transits(User $user, CarbonImmutable $now, CarbonImmutable $horizon, TransitService $transits): ?array
    {
        $moment = $now->utc()->startOfHour();
        $appointments = Appointment::query()
            ->with(['client.birthDetails', 'client.workspace'])
            ->where('assigned_user_id', $user->getKey())
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('starts_at', '>=', $now->utc())
            ->where('starts_at', '<', $horizon->utc())
            ->orderBy('starts_at')
            ->get()
            ->unique('client_id')
            ->take(self::TRANSIT_CLIENTS);

        $clients = [];

        try {
            foreach ($appointments as $appointment) {
                try {
                    $report = $transits->transits($appointment->client, $moment);
                } catch (IncompleteBirthData) {
                    continue;
                }

                $contacts = array_values(array_filter($report['contacts'], self::significant(...)));

                if ($contacts !== []) {
                    $clients[] = [
                        'client' => ['id' => $appointment->client->id, 'full_name' => $appointment->client->fullName()],
                        'appointment' => ['id' => $appointment->id, 'starts_at' => $appointment->starts_at->toIso8601ZuluString()],
                        'contacts' => array_slice($contacts, 0, self::TRANSITS_EACH),
                    ];
                }
            }
        } catch (EphemerisException $failure) {
            Log::error('Dashboard transits failed', ['error' => $failure->getMessage()]);

            return null;
        }

        return ['moment' => $moment->toIso8601ZuluString(), 'clients' => $clients];
    }

    /**
     * @param  array{transit: string, natal: string, type: string, orb: float}  $contact
     */
    private static function significant(array $contact): bool
    {
        $slow = [CelestialBody::Jupiter, CelestialBody::Saturn, CelestialBody::Uranus, CelestialBody::Neptune, CelestialBody::Pluto];
        $types = [AspectType::Conjunction, AspectType::Square, AspectType::Trine, AspectType::Opposition];
        $personal = ['sun', 'moon', 'mercury', 'venus', 'mars', 'asc', 'mc'];

        return in_array($contact['transit'], array_column($slow, 'value'), true)
            && in_array($contact['type'], array_column($types, 'value'), true)
            && in_array($contact['natal'], $personal, true)
            && $contact['orb'] <= self::TRANSIT_ORB;
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
