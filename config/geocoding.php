<?php

return [

    // Mapbox is preferred when a token is configured. Without a token the
    // service keeps using the free Nominatim fallback.
    'provider' => env('GEOCODING_PROVIDER', 'mapbox'),

    'retry_delay_ms' => env('GEOCODING_RETRY_DELAY_MS', 1200),

    'mapbox' => [
        'access_token' => env('MAPBOX_ACCESS_TOKEN'),
        'endpoint' => env('MAPBOX_GEOCODING_ENDPOINT', 'https://api.mapbox.com/search/geocode/v6/forward'),
        'country' => env('MAPBOX_COUNTRY', 'us'),
        'language' => env('MAPBOX_LANGUAGE', 'en'),
        'types' => env('MAPBOX_TYPES', 'address,place,street'),
        'proximity' => env('MAPBOX_PROXIMITY'),

        // Temporary results must not be cached. Set this to true only after a
        // valid card is on file with Mapbox; then successful lookups can be
        // cached for 30 days like the other providers.
        'permanent' => env('MAPBOX_PERMANENT_GEOCODING', false),
    ],

];
