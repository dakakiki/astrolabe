<?php

namespace App\Enums;

/**
 * Where an appointment stands (docs/spec/10). Cancelling keeps the record;
 * only a cancelled appointment leaves its time free.
 */
enum AppointmentStatus: string
{
    /**
     * Booked by the client in the portal while the practice confirms bookings by
     * hand (Phase 9b): it holds its time until the astrologer confirms or declines.
     */
    case Requested = 'requested';

    case Scheduled = 'scheduled';

    /** It took place; recording a consultation from it sets this. */
    case Completed = 'completed';

    /** Cancelled with a reason, via the cancel endpoint only (a declined request too). */
    case Cancelled = 'cancelled';

    /** The client did not come. */
    case NoShow = 'no_show';

    /** Whether the time counts as taken when looking for overlaps and free slots. */
    public function occupiesTime(): bool
    {
        return $this !== self::Cancelled;
    }

    /** Still ahead of both sides: booked, or waiting for the astrologer. */
    public function isOpen(): bool
    {
        return $this === self::Scheduled || $this === self::Requested;
    }

    /**
     * The values of the open statuses, for queries.
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Requested->value, self::Scheduled->value];
    }
}
