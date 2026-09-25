<?php

namespace App\Enums;

/**
 * Where a consultation's fee stands, derived from the fee and the payments
 * against it — never stored (docs/spec/02, "Plaćanja").
 */
enum BillingStatus: string
{
    /** The fee is 0. */
    case NoCharge = 'no_charge';

    case Unpaid = 'unpaid';

    case PartiallyPaid = 'partially_paid';

    /** Everything received, or more. */
    case Paid = 'paid';

    /** Money came in and all of it went back. */
    case Refunded = 'refunded';

    /**
     * From the fee (null: none set) and the net received — payments less
     * refunds — with whether anything was refunded. Null when there is
     * neither a fee nor any money.
     */
    public static function derive(?int $fee, int $paid, bool $refunded): ?self
    {
        return match (true) {
            $fee === 0 => self::NoCharge,
            $paid <= 0 && $refunded => self::Refunded,
            $fee === null => $paid > 0 ? self::Paid : null,
            $paid <= 0 => self::Unpaid,
            $paid < $fee => self::PartiallyPaid,
            default => self::Paid,
        };
    }
}
