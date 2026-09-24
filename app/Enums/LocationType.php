<?php

namespace App\Enums;

/**
 * Where a service is held (docs/spec/02, "Usluge"). Appointments (Phase 6b)
 * pick one concrete place when the service allows either.
 */
enum LocationType: string
{
    case Online = 'online';

    case InPerson = 'in_person';

    /** The client chooses. */
    case Either = 'either';
}
