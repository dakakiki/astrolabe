<?php

namespace App\Enums;

/**
 * Where a client's link to the portal stands (docs/spec/12, `portal_access`).
 * At most one link per client is invited or active; a revoked one stays as
 * history, and inviting again starts a new link.
 */
enum PortalAccessStatus: string
{
    /** An invitation went out; nobody has accepted it yet. */
    case Invited = 'invited';

    /** Accepted: the portal account sees this client's appointments and what was shared. */
    case Active = 'active';

    /** Ended by the practice; the next portal request is refused. */
    case Revoked = 'revoked';

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Invited->value, self::Active->value];
    }
}
