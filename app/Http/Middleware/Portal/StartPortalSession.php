<?php

namespace App\Http\Middleware\Portal;

use App\Support\Portal\PortalSessionManager;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Session\Middleware\StartSession;

/**
 * Laravel's StartSession over the portal's own session manager: the portal's
 * cookie and the `portal_sessions` table, never the astrologers' session.
 */
class StartPortalSession extends StartSession
{
    public function __construct(PortalSessionManager $manager)
    {
        parent::__construct($manager, fn () => app(CacheFactory::class));
    }
}
