<?php

namespace App\Enums;

/**
 * How a client hears about their appointments (Phase 9c, Portal → Profile →
 * Notifications): email, push to the devices that switched it on, or both.
 * Push needs at least one such device; without one the client gets email
 * whatever they chose, so nothing about a booking is ever lost.
 */
enum PortalNotificationChannel: string
{
    case Email = 'email';
    case Push = 'push';
    case Both = 'both';

    public function wantsEmail(): bool
    {
        return $this !== self::Push;
    }

    public function wantsPush(): bool
    {
        return $this !== self::Email;
    }
}
