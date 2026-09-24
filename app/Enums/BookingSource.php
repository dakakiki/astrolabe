<?php

namespace App\Enums;

/**
 * Where an appointment came from (docs/spec/05). Only manual entry exists so
 * far; the portal and a public booking page arrive in Phase 9.
 */
enum BookingSource: string
{
    case Manual = 'manual';
    case Portal = 'portal';
    case Public = 'public';
    case Import = 'import';
}
