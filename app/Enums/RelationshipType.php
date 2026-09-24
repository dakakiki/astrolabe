<?php

namespace App\Enums;

/**
 * What the other party is to the client (docs/spec/02, "Povezane osobe"):
 * "child" on Ana's profile means the person is Ana's child.
 */
enum RelationshipType: string
{
    /** Also a spouse. */
    case Partner = 'partner';

    case Child = 'child';

    case Parent = 'parent';

    case Sibling = 'sibling';

    case Friend = 'friend';

    case BusinessPartner = 'business_partner';

    case Other = 'other';

    /**
     * The same link seen from the other side: if Marko is Ana's child, Ana is
     * Marko's parent. Applying it twice gives the original type back.
     */
    public function inverse(): self
    {
        return match ($this) {
            self::Child => self::Parent,
            self::Parent => self::Child,
            default => $this,
        };
    }
}
