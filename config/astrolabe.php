<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interface languages
    |--------------------------------------------------------------------------
    |
    | English is the default and the fallback (docs/spec/06). A locale is added
    | here only once both the frontend (resources/js/i18n/locales) and the
    | backend (lang/) translations exist. Names are shown in their own language.
    |
    */

    'locales' => [
        'en' => 'English',
    ],

    /*
    |--------------------------------------------------------------------------
    | Currencies
    |--------------------------------------------------------------------------
    |
    | ISO 4217 codes a workspace may choose as its default currency and a
    | service may be priced in. Display names come from the browser's Intl
    | API, so only the codes live here.
    |
    | Amounts are stored in the smallest unit of the currency. That unit is a
    | hundredth except for the currencies listed in `currency_decimals`
    | (ISO 4217 minor units), so 4900 is 49.00 EUR but 4900 JPY.
    |
    */

    'currencies' => [
        'EUR', 'USD', 'GBP', 'CHF', 'RSD', 'BAM', 'HRK', 'MKD', 'HUF', 'CZK',
        'PLN', 'RON', 'BGN', 'SEK', 'NOK', 'DKK', 'ISK', 'TRY', 'UAH', 'CAD',
        'AUD', 'NZD', 'JPY', 'CNY', 'HKD', 'SGD', 'INR', 'ZAR', 'BRL', 'MXN',
        'ARS', 'CLP', 'COP', 'ILS', 'AED',
    ],

    'currency_decimals' => [
        'ISK' => 0,
        'JPY' => 0,
        'CLP' => 0,
    ],

    'default_currency' => 'EUR',

    /*
    |--------------------------------------------------------------------------
    | Birth places
    |--------------------------------------------------------------------------
    |
    | The local GeoNames gazetteer (`php artisan places:import`). "all" holds
    | every populated place (~5.2 million, ~1 GB, ~13 minutes to import) and is
    | what production uses: "cities500" has only 492 places in Serbia, against
    | some 4,700 settlements. "cities500" (~14 MB) is a quick option for development.
    |
    */

    'places' => [
        'source' => env('PLACES_SOURCE', 'all'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ephemeris engine
    |--------------------------------------------------------------------------
    |
    | "swiss" runs the Swiss Ephemeris `swetest` program with the .se1 data
    | files in `path` (docs/spec/11); "fake" is the deterministic stand-in for
    | tests and CI. On Linux, `swetest` is built from the Swiss Ephemeris
    | sources; on Windows the published swetest64.exe is used.
    |
    */

    'ephemeris' => [
        'engine' => env('EPHEMERIS_ENGINE', 'swiss'),
        'swetest' => env('SWETEST_PATH', storage_path('app/private/swisseph/swetest64.exe')),
        'path' => env('EPHEMERIS_PATH', storage_path('app/private/swisseph/ephe')),
        'timeout' => (int) env('EPHEMERIS_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Where client files are kept and how large they may be. The disk must be
    | private: "attachments" (local, storage/app/private/attachments) or an
    | S3-compatible bucket, in which case downloads use short-lived signed URLs.
    | The effective limit is also capped by PHP's upload_max_filesize and
    | post_max_size; the smaller value is what the interface shows.
    |
    */

    'attachments' => [
        'disk' => env('ATTACHMENTS_DISK', 'attachments'),
        'max_size_mb' => (int) env('ATTACHMENTS_MAX_MB', 100),
        'signed_url_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | "invite" (the closed beta): an account is created only through a link
    | from `php artisan invitations:send`, for the address it was sent to.
    | "open": anyone may register. Invitation links work for `invitation_days`.
    |
    */

    'registration' => [
        'mode' => env('REGISTRATION_MODE', 'invite'),
        'invitation_days' => (int) env('INVITATION_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator
    |--------------------------------------------------------------------------
    |
    | Whoever runs the installation. Failing health checks and server errors
    | are emailed here (no external error service: nothing leaves the server
    | but the email, and the email names no client). Empty = no emails.
    |
    */

    'operator' => [
        'email' => env('OPERATOR_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator's admin
    |--------------------------------------------------------------------------
    |
    | The admin (Phase 8c) is a separate account made by `admin:create`. Its
    | session ends after `idle_minutes` without an admin request (astrologers
    | keep SESSION_LIFETIME), and account-help actions ask for the password
    | again when it was last confirmed more than `confirm_seconds` ago.
    | `support_email` is named in the emails astrologers get about their account.
    |
    */

    'admin' => [
        'idle_minutes' => (int) env('ADMIN_IDLE_MINUTES', 30),
        'confirm_seconds' => (int) env('ADMIN_CONFIRM_SECONDS', 900),
        'support_email' => env('SUPPORT_EMAIL', env('OPERATOR_EMAIL')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Requests per minute and person (or IP address when signed out). "engine"
    | covers the endpoints that may start the ephemeris engine: charts,
    | transits, synastry, the sky calendar and the dashboard.
    |
    */

    'rate_limits' => [
        'api' => (int) env('RATE_LIMIT_API', 300),
        'engine' => (int) env('RATE_LIMIT_ENGINE', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long data is kept (docs/spec/06, "pravila čuvanja i brisanja"),
    | applied every night by `php artisan data:prune`. Deleted consultations,
    | notes, files, tasks, payments and related people stay restorable for
    | `deleted_days`, then go for good — files from the disk too. A practice
    | scheduled for deletion can be cancelled for `practice_deletion_days`.
    | Expired or revoked invitations and failed queue jobs go after
    | `housekeeping_days`.
    |
    */

    'retention' => [
        'deleted_days' => (int) env('RETENTION_DELETED_DAYS', 30),
        'audit_log_months' => (int) env('RETENTION_AUDIT_LOG_MONTHS', 12),
        'practice_deletion_days' => (int) env('PRACTICE_DELETION_DAYS', 30),
        'housekeeping_days' => (int) env('RETENTION_HOUSEKEEPING_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Practice exports
    |--------------------------------------------------------------------------
    |
    | The owner's ZIP of everything in the practice, built by a queued job on
    | the private "exports" disk. The link in the "ready" email works for
    | `link_hours`; the file can be downloaded in Settings → Your data until it
    | is deleted after `keep_days`.
    |
    */

    'exports' => [
        'disk' => env('EXPORTS_DISK', 'exports'),
        'link_hours' => (int) env('EXPORT_LINK_HOURS', 24),
        'keep_days' => (int) env('EXPORT_KEEP_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legal documents
    |--------------------------------------------------------------------------
    |
    | The Terms of Service, the Data Processing Agreement and the Privacy
    | Policy (Phase 8c). Each version is its own Markdown file in
    | `path/<document>/<version>.md`; the newest file is the one in force. A
    | document with `acceptance` is accepted at registration and again, before
    | the practice opens, whenever a new version appears; the privacy policy
    | is acknowledged instead (a notice that blocks nothing).
    |
    */

    'legal' => [
        'path' => env('LEGAL_PATH', resource_path('legal')),
        'documents' => [
            'terms' => ['acceptance' => true],
            'dpa' => ['acceptance' => true],
            'privacy' => ['acceptance' => false],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | `php artisan backup:run` (every night once BACKUP_KEY is set) dumps the
    | database without the GeoNames rows (they come back with places:import)
    | and archives the client files, both compressed and encrypted with
    | BACKUP_KEY (libsodium; `php artisan backup:key` makes one). Without the
    | key a backup cannot be read: keep a copy of it away from the server. The
    | newest `keep` backups stay in `path`; older ones are removed.
    |
    */

    'backup' => [
        'key' => env('BACKUP_KEY'),
        'path' => env('BACKUP_PATH', storage_path('app/private/backups')),
        'keep' => (int) env('BACKUP_KEEP', 14),
        'dump_binary' => env('BACKUP_DUMP_BINARY', 'mariadb-dump'),
        'client_binary' => env('BACKUP_CLIENT_BINARY', 'mariadb'),
        // Dumped as structure only: the gazetteer (~1.8 GB) comes back with places:import;
        // sessions and cache refill by use — a restore signs nobody back in.
        'structure_only' => ['places', 'place_names', 'sessions', 'cache', 'cache_locks'],
        // With a key set, a newest backup older than this fails the health check.
        'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
        'timeout' => (int) env('BACKUP_TIMEOUT', 1800),
    ],

];
