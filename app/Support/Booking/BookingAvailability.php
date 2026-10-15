<?php

namespace App\Support\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\AvailabilityKind;
use App\Models\Appointment;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\BookingPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The free times one astrologer can offer in the portal (docs/spec/12): their
 * week and exceptions, their appointments in the current practice (every one
 * that holds its time, requests included) and the practice's booking policy,
 * read from the database and handed to the pure SlotSearch.
 *
 * Runs inside the tenant scope of the practice. Booking calls `isFree()` again
 * inside its transaction, after locking the astrologer's membership row — the
 * same lock the calendar takes — so two requests for one slot cannot both win.
 */
final class BookingAvailability
{
    public function __construct(
        private readonly BookingPolicy $policy,
        private readonly User $astrologer,
    ) {}

    /** For the practice's booking astrologer; null when there is none. */
    public static function for(BookingPolicy $policy): ?self
    {
        $astrologer = $policy->astrologer();

        return $astrologer === null ? null : new self($policy, $astrologer);
    }

    public function astrologer(): User
    {
        return $this->astrologer;
    }

    /**
     * Free start times for something that lasts `$minutes`, within the policy's
     * window from `$now` (optionally narrowed to [from, to]). `$except` leaves
     * an appointment out of the busy times — the one being moved.
     *
     * @return list<CarbonImmutable> UTC
     */
    public function slots(
        int $minutes,
        CarbonInterface $now,
        ?Appointment $except = null,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array {
        $earliest = $this->policy->earliestStart($now);
        $latest = $this->policy->latestStart($now);

        if ($from !== null && $from > $earliest) {
            $earliest = CarbonImmutable::instance($from)->utc();
        }

        if ($to !== null && $to < $latest) {
            $latest = CarbonImmutable::instance($to)->utc();
        }

        if ($latest < $earliest || $minutes <= 0) {
            return [];
        }

        $gap = ($this->policy->buffer_before_minutes + $this->policy->buffer_after_minutes) * 60;
        // Everything that can touch a slot in the window: a day either side covers whole
        // stretches of the week; the buffers reach the appointments next to a slot.
        $windowStart = $earliest->subDay()->subSeconds($gap);
        $windowEnd = $latest->addMinutes($minutes)->addDay()->addSeconds($gap);

        [$week, $extra, $timeOff] = $this->workingTime($windowStart, $windowEnd);

        $starts = SlotSearch::find(
            week: $week,
            extra: $extra,
            timeOff: $timeOff,
            busy: $this->busy($windowStart, $windowEnd, $except),
            duration: $minutes * 60,
            step: $this->policy->slot_step_minutes * 60,
            bufferBefore: $this->policy->buffer_before_minutes * 60,
            bufferAfter: $this->policy->buffer_after_minutes * 60,
            earliest: $earliest->getTimestamp(),
            latest: $latest->getTimestamp(),
        );

        return array_map(fn (int $start) => CarbonImmutable::createFromTimestampUTC($start), $starts);
    }

    /**
     * Where an appointment can move to: free times of its own length, its own
     * time counting as free for itself — but not its current start, which would
     * be no move at all (the portal would say "moved" while nothing changed).
     *
     * @return list<CarbonImmutable> UTC
     */
    public function slotsToMove(Appointment $appointment, CarbonInterface $now): array
    {
        return array_values(array_filter(
            $this->slots($appointment->durationMinutes(), $now, $appointment),
            fn (CarbonImmutable $start) => ! $start->equalTo($appointment->starts_at),
        ));
    }

    /** Whether exactly this start is on offer now — checked again just before writing. */
    public function isFree(CarbonInterface $start, int $minutes, CarbonInterface $now, ?Appointment $except = null): bool
    {
        $start = CarbonImmutable::instance($start)->utc();

        foreach ($this->slots($minutes, $now, $except, $start, $start) as $slot) {
            if ($slot->equalTo($start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The week's stretches, extra time and time off touching the window — kept
     * apart, because time off cuts the week but not extra time (SlotSearch).
     *
     * @return array{0: list<array{0: int, 1: int}>, 1: list<array{0: int, 1: int}>, 2: list<array{0: int, 1: int}>}
     */
    private function workingTime(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rules = AvailabilityRule::query()
            ->where('user_id', $this->astrologer->getKey())
            ->get()
            ->map(fn (AvailabilityRule $rule) => [
                'weekday' => $rule->weekday,
                'start' => $rule->startMinute(),
                'end' => $rule->endMinute(),
                'timezone' => $rule->timezone,
            ]);

        $week = WeeklyHours::between($rules, $from, $to);
        $extra = [];
        $timeOff = [];

        $exceptions = AvailabilityException::query()
            ->where('user_id', $this->astrologer->getKey())
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['kind', 'starts_at', 'ends_at']);

        foreach ($exceptions as $exception) {
            $interval = [$exception->starts_at->getTimestamp(), $exception->ends_at->getTimestamp()];

            if ($exception->kind === AvailabilityKind::Available) {
                $extra[] = $interval;
            } else {
                $timeOff[] = $interval;
            }
        }

        return [$week, $extra, $timeOff];
    }

    /**
     * The astrologer's appointments in this practice that hold their time.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function busy(CarbonImmutable $from, CarbonImmutable $to, ?Appointment $except): array
    {
        return Appointment::query()
            ->where('assigned_user_id', $this->astrologer->getKey())
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Appointment $appointment) => [$appointment->starts_at->getTimestamp(), $appointment->ends_at->getTimestamp()])
            ->all();
    }
}
