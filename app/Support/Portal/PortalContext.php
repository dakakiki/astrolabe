<?php

namespace App\Support\Portal;

use App\Models\Client;
use App\Models\PortalAccess;
use App\Models\Workspace;
use LogicException;

/**
 * The practice and client record a portal request acts in, set by
 * ResolvePortalAccess from the person's active link — never from the request.
 * Every portal query starts from here (docs/spec/12, "Izolacija").
 */
final class PortalContext
{
    private ?PortalAccess $access = null;

    public function set(?PortalAccess $access): void
    {
        $this->access = $access;
    }

    public function access(): PortalAccess
    {
        return $this->access ?? throw new LogicException('No portal access is set for this request.');
    }

    public function client(): Client
    {
        return $this->access()->client;
    }

    public function workspace(): Workspace
    {
        return $this->access()->workspace;
    }
}
