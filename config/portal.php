<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the client portal lives
    |--------------------------------------------------------------------------
    |
    | The portal is the same application on its own origin (docs/spec/12):
    | its routes are bound to this host, its session cookie is set for this
    | host only, and the astrologers' routes answer 404 here. `url` is what
    | links in emails start with. Locally the WAMP vhost
    | dev.lcl.portal.astrolabe.online; until it exists, `php artisan serve` on
    | localhost with PORTAL_DOMAIN=localhost.
    |
    */

    'domain' => env('PORTAL_DOMAIN', 'portal.astrolabe.online'),

    'url' => rtrim((string) env('PORTAL_URL', 'https://'.env('PORTAL_DOMAIN', 'portal.astrolabe.online')), '/'),

    /*
    |--------------------------------------------------------------------------
    | The portal's own session
    |--------------------------------------------------------------------------
    |
    | Never the astrologers' cookie or `sessions` table: those are read by
    | user id (Settings → Security, the admin's suspension), and a client's
    | session with the same number would mix with an astrologer's. Clients come
    | back rarely and sign in only through an emailed link or code, so the
    | session lasts 30 days without activity.
    |
    */

    'session' => [
        'driver' => env('PORTAL_SESSION_DRIVER', 'database'),
        'table' => 'portal_sessions',
        'cookie' => 'astrolabe_portal_session',
        'lifetime' => (int) env('PORTAL_SESSION_LIFETIME', 60 * 24 * 30),
        'secure' => env('SESSION_SECURE_COOKIE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invitations and signing in
    |--------------------------------------------------------------------------
    |
    | An invitation works once, for `invitation_days`; a new one replaces the
    | old. A sign-in link and its six-digit code work once, for
    | `sign_in_minutes`, and the code stops after `code_attempts` wrong tries.
    |
    */

    'invitation_days' => (int) env('PORTAL_INVITATION_DAYS', 7),

    'sign_in_minutes' => (int) env('PORTAL_SIGN_IN_MINUTES', 15),

    'code_attempts' => 5,

    /*
    |--------------------------------------------------------------------------
    | Request limits
    |--------------------------------------------------------------------------
    |
    | Sign-in requests per address and per IP, codes per IP, accepting
    | invitations per IP, every portal API call per signed-in person, and
    | booking, moving and cancelling appointments per portal account (Phase 9b).
    |
    */

    'limits' => [
        'sign_in_per_email' => [3, 15],
        'sign_in_per_ip' => [10, 60],
        'code_per_ip' => [10, 15],
        'invitation_per_ip' => [10, 60],
        'api_per_minute' => 120,
        'booking_per_account' => [10, 10],
        'push_per_account' => [10, 10],
    ],

    /*
    |--------------------------------------------------------------------------
    | Booking in the portal (Phase 9b)
    |--------------------------------------------------------------------------
    |
    | How many open appointments (scheduled, or requests waiting for the
    | practice) that have not ended yet one client may hold before the portal
    | stops taking new bookings from them — one account cannot fill the whole
    | booking window. Moving an appointment is not counted as a new one.
    |
    */

    'booking' => [
        'max_open_per_client' => (int) env('PORTAL_BOOKING_MAX_OPEN', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web push in the client PWA (Phase 9c)
    |--------------------------------------------------------------------------
    |
    | VAPID keys identify this server to the browsers' push services; make a
    | pair with `php artisan portal:push-keys` and keep the private one out of
    | the repository. Without both keys the portal offers no push at all. The
    | subject is a contact for the push services (mailto: or https:).
    |
    | Pushes go only to the push services of the browsers in `endpoint_hosts`
    | (a host, or ".suffix" for its subdomains), over HTTPS — the server never
    | posts to an address a client made up. A push the service cannot deliver
    | within `ttl` seconds is dropped.
    |
    */

    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // An empty VAPID_SUBJECT counts as unset: push services refuse an empty "sub".
        'subject' => env('VAPID_SUBJECT') ?: 'mailto:'.(env('OPERATOR_EMAIL') ?: 'operator@astrolabe.online'),
        'endpoint_hosts' => [
            'fcm.googleapis.com',                 // Chrome, Android, Opera, Samsung Internet, Brave
            'updates.push.services.mozilla.com',  // Firefox
            'web.push.apple.com',                 // Safari on macOS, iOS and iPadOS 16.4+
            '.notify.windows.com',                // Edge
        ],
        'ttl' => (int) env('PORTAL_PUSH_TTL', 60 * 60 * 12),
        // Seconds per push service request, and for one notice to all of an account's
        // devices together — well under the queue worker's 60 s, so a notice whose pushes
        // stall still reaches its email fallback instead of being killed and retried.
        'timeout' => 5,
        'connect_timeout' => 3,
        'deliver_seconds' => 40,
    ],

    /*
    |--------------------------------------------------------------------------
    | The practice's branding in the portal
    |--------------------------------------------------------------------------
    |
    | Display name, logo and one colour (docs/spec/09, "Branding" — the rest
    | comes later). The logo is PNG, WebP or SVG (cleaned) up to `logo_max_kb`,
    | square or wide; links to it are signed for `logo_link_days`.
    |
    */

    'logo_max_kb' => 1024,

    'logo_link_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | A portal account without any open link to a practice goes after
    | `account_days` (`data:prune`); used or expired sign-in tokens and
    | invitations after a day.
    |
    */

    'account_days' => (int) env('PORTAL_ACCOUNT_DAYS', 30),

];
