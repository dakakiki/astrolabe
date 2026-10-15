<?php

namespace App\Enums;

/**
 * An exception to an astrologer's working week (Phase 9b, Settings → Availability).
 */
enum AvailabilityKind: string
{
    /** Time off: a holiday, a free afternoon. Nothing is offered then. */
    case Unavailable = 'unavailable';

    /** Extra time outside the usual week, offered like working hours. */
    case Available = 'available';
}
