<?php

namespace App\Enums;

/**
 * Which side changed an appointment (Phase 9b): the practice in the app or the
 * client in the portal. Kept on a cancelled appointment (`cancelled_by`) and
 * carried by the booking events, so each side hears about the other's changes.
 */
enum AppointmentActor: string
{
    case Practice = 'practice';

    case Client = 'client';
}
