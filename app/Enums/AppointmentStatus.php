<?php

namespace App\Enums;

/**
 * Where an appointment stands (docs/spec/10). Cancelling keeps the record;
 * only a cancelled appointment leaves its time free.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';

    /** It took place; recording a consultation from it sets this. */
    case Completed = 'completed';

    /** Cancelled with a reason, via the cancel endpoint only. */
    case Cancelled = 'cancelled';

    /** The client did not come. */
    case NoShow = 'no_show';

    /** Whether the time counts as taken when looking for overlaps. */
    public function occupiesTime(): bool
    {
        return $this !== self::Cancelled;
    }
}
