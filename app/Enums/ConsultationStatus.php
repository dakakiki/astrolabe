<?php

namespace App\Enums;

/**
 * Where a consultation stands (docs/spec/02, "Konsultacije").
 */
enum ConsultationStatus: string
{
    /** Being prepared; may have no date yet. */
    case Draft = 'draft';

    case Scheduled = 'scheduled';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    /** The client did not come. */
    case NoShow = 'no_show';

    /** Every status except a draft needs a date. */
    public function needsDate(): bool
    {
        return $this !== self::Draft;
    }
}
