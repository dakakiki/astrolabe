<?php

namespace App\Http\Middleware\Portal;

use App\Support\Portal\PortalSessionManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * The same CSRF protection as the application's, but the XSRF-TOKEN cookie is
 * set like the portal's session cookie — for the portal host only. The stock
 * middleware would give it the astrologers' cookie domain, which the browser
 * refuses on the portal host.
 */
class PreventPortalRequestForgery extends PreventRequestForgery
{
    protected function newCookie($request, $config)
    {
        return parent::newCookie($request, app(PortalSessionManager::class)->getSessionConfig());
    }
}
