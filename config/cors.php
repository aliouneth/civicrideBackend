<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | HandleCors is already in Laravel's global middleware stack, but it
    | no-ops when `cors.paths` is empty. This file was missing, so every
    | cross-origin preflight (e.g. https://civicride.com -> api.civicride.com)
    | returned without Access-Control-Allow-Origin headers.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Comma-separated list via FRONTEND_URL, e.g.
     * FRONTEND_URL="https://civicride.com,https://www.civicride.com"
     */
    'allowed_origins' => array_values(array_filter(array_map(
        fn ($origin) => trim((string) $origin),
        explode(',', (string) env('FRONTEND_URL', 'https://civicride.com,https://www.civicride.com,https://app.civicride.com'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer-token API (no cookies), so credentials stay false.
    // Must stay false while allowed_origins is specific and never '*'.
    'supports_credentials' => false,

];
