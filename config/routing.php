<?php

return [

    // Driving distance lookup. Mapbox Directions is used when a token is
    // configured; otherwise the service falls back to a straight-line estimate.
    'provider' => env('DIRECTIONS_PROVIDER', 'mapbox'),

    'mapbox' => [
        'access_token' => env('MAPBOX_ACCESS_TOKEN'),
        'endpoint' => env('MAPBOX_DIRECTIONS_ENDPOINT', 'https://api.mapbox.com/directions/v5/mapbox/driving'),

        // Mapbox Service Terms allow Directions results to be cached for up
        // to 30 days. This is separate from permanent geocoding rights.
        'cache_ttl' => env('MAPBOX_DIRECTIONS_CACHE_TTL', 86400 * 30),
    ],

];
