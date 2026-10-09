<?php

namespace App\Support\Portal;

use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Laravel's database session handler, writing the portal account's id into
 * `portal_sessions.user_id`. The stock handler asks the default guard — the
 * astrologers' — which is never signed in on the portal host.
 */
class PortalSessionHandler extends DatabaseSessionHandler
{
    protected function userId()
    {
        return Auth::guard('portal')->id();
    }
}
