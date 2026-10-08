<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | None (Phase 8a). The SPA is served from the same host as the API
    | (app.astrolabe.online), so no other origin needs to read responses; the
    | framework's default would answer "Access-Control-Allow-Origin: *" on
    | every API route. A portal or booking widget on another host (Phase 9)
    | gets its own explicit list here, never "*".
    |
    */

    'paths' => [],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
