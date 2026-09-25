<?php

namespace App\Enums;

/**
 * Money received from a client, or given back (docs/spec/02, "Plaćanja").
 * Amounts are always positive; a refund counts against what was received.
 */
enum PaymentKind: string
{
    case Payment = 'payment';
    case Refund = 'refund';

    /** +1 or −1: how the amount counts towards what the client has paid. */
    public function sign(): int
    {
        return $this === self::Refund ? -1 : 1;
    }
}
