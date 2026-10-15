<?php

namespace Tests\Feature\Portal;

use App\Enums\AvailabilityKind;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\BookingPolicy;
use App\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * A practice that takes bookings in the portal (Phase 9b), set up the way
 * Settings → Booking and Settings → Availability save it: the owner's working
 * week in their zone, time off and extra time in UTC, and the booking policy.
 */
trait BookingFixtures
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function inPracticeOf(User $owner, callable $callback): mixed
    {
        return app(CurrentWorkspace::class)->run($owner->currentWorkspace()->firstOrFail(), $callback);
    }

    /** Switches booking on (or saves other rules) for the owner's practice; the defaults otherwise. */
    protected function bookingPolicy(User $owner, array $attributes = []): BookingPolicy
    {
        return $this->inPracticeOf($owner, function () use ($attributes) {
            $policy = BookingPolicy::current();
            $policy->fill(['enabled' => true, ...$attributes])->save();

            return $policy;
        });
    }

    /**
     * The astrologer's week: ISO weekday => [["09:00", "17:00"], …], wall-clock times in `$zone`.
     *
     * @param  array<int, list<array{0: string, 1: string}>>  $week
     */
    protected function workingWeek(User $astrologer, array $week, string $zone = 'Europe/Belgrade', ?User $owner = null): void
    {
        $this->inPracticeOf($owner ?? $astrologer, function () use ($astrologer, $week, $zone) {
            foreach ($week as $weekday => $stretches) {
                foreach ($stretches as [$start, $end]) {
                    AvailabilityRule::query()->create([
                        'user_id' => $astrologer->id,
                        'weekday' => $weekday,
                        'start_time' => $start,
                        'end_time' => $end,
                        'timezone' => $zone,
                    ]);
                }
            }
        });
    }

    /** Time off or extra time from one UTC moment to another ("2026-10-20 09:30"). */
    protected function availabilityException(User $astrologer, AvailabilityKind $kind, string $from, string $to): AvailabilityException
    {
        return $this->inPracticeOf($astrologer, fn () => AvailabilityException::query()->create([
            'user_id' => $astrologer->id,
            'kind' => $kind,
            'starts_at' => CarbonImmutable::parse($from, 'UTC'),
            'ends_at' => CarbonImmutable::parse($to, 'UTC'),
            'timezone' => 'Europe/Belgrade',
            'all_day' => false,
        ]));
    }

    /**
     * The free starts in a slots (or 409 slot_taken) response.
     *
     * @return list<string>
     */
    protected function starts(TestResponse $response, string $path = 'data.slots'): array
    {
        return array_column($response->json($path) ?? [], 'starts_at');
    }

    /** A request with an Idempotency-Key, which the portal helper does not send by itself. */
    protected function portalWithKey(string $key, string $method, string $path, array $data = []): TestResponse
    {
        $this->withHeader('Idempotency-Key', $key);

        try {
            return $this->portal($method, $path, $data);
        } finally {
            $this->flushHeaders();
        }
    }
}
