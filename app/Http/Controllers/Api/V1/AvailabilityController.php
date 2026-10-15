<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Booking\BookAppointment;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAvailabilityExceptionRequest;
use App\Http\Requests\SaveAvailabilityRequest;
use App\Http\Resources\AvailabilityExceptionResource;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in person's own availability for booking in the client portal
 * (Phase 9b, Settings → Availability; docs/spec/12, "Zakazivanje (9b)"): a
 * working week of wall-clock stretches in one zone, and exceptions on top of
 * it — time off and extra time. Everyone in the practice keeps their own and
 * nobody reads or changes another's; portal bookings go to the practice's
 * owner until teams arrive (BookingPolicy::astrologer()).
 *
 * Changes take the lock a booking takes before it checks its time again
 * (BookAppointment::lockAstrologer), so a booking never sees half a week.
 */
class AvailabilityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->availability($request)]);
    }

    /** Replaces the whole week. */
    public function update(SaveAvailabilityRequest $request, CurrentWorkspace $current): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $zone = $request->validated('timezone');

        DB::transaction(function () use ($request, $user, $current, $zone) {
            BookAppointment::lockAstrologer($current->id(), $user->getKey());

            AvailabilityRule::query()->where('user_id', $user->getKey())->delete();

            foreach ($request->week() as $rule) {
                AvailabilityRule::query()->create([...$rule, 'user_id' => $user->getKey(), 'timezone' => $zone]);
            }
        });

        return response()->json(['data' => $this->availability($request)]);
    }

    /** 201 with the new exception. */
    public function storeException(SaveAvailabilityExceptionRequest $request, CurrentWorkspace $current): AvailabilityExceptionResource
    {
        /** @var User $user */
        $user = $request->user();
        [$start, $end] = $request->period();

        $exception = DB::transaction(function () use ($request, $user, $current, $start, $end) {
            BookAppointment::lockAstrologer($current->id(), $user->getKey());

            return AvailabilityException::query()->create([
                'user_id' => $user->getKey(),
                'kind' => $request->validated('kind'),
                'starts_at' => $start,
                'ends_at' => $end,
                'timezone' => $request->validated('timezone'),
                'all_day' => $request->wholeDays(),
                'note' => $request->validated('note'),
            ]);
        });

        return AvailabilityExceptionResource::make($exception);
    }

    /** Only one's own: another member's exception is not found, like another practice's. */
    public function destroyException(Request $request, AvailabilityException $exception, CurrentWorkspace $current): Response
    {
        abort_unless($exception->user_id === $request->user()->getKey(), Response::HTTP_NOT_FOUND);

        DB::transaction(function () use ($exception, $current) {
            BookAppointment::lockAstrologer($current->id(), $exception->user_id);

            $exception->delete();
        });

        return response()->noContent();
    }

    /**
     * The week by weekday and start, in the zone it was entered in (the
     * person's own before they set one), and the exceptions that have not ended yet.
     *
     * @return array{timezone: string, rules: list<array<string, mixed>>, exceptions: list<array<string, mixed>>}
     */
    private function availability(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $rules = AvailabilityRule::query()
            ->where('user_id', $user->getKey())
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();

        $exceptions = AvailabilityException::query()
            ->where('user_id', $user->getKey())
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return [
            'timezone' => $rules->first()?->timezone ?? ($user->timezone ?: 'UTC'),
            'rules' => $rules->map(fn (AvailabilityRule $rule) => [
                'weekday' => $rule->weekday,
                'start_time' => $rule->start_time,
                'end_time' => $rule->end_time,
            ])->values()->all(),
            'exceptions' => AvailabilityExceptionResource::collection($exceptions)->resolve($request),
        ];
    }
}
