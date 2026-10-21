<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant base domain
    |--------------------------------------------------------------------------
    |
    | Public domain the agency (tenant) subdomains hang off, e.g.
    | "civicride.com" -> "acme.civicride.com". Only hosts under this domain
    | are read as tenant hosts; every other hostname (bare domains, other
    | domains, infrastructure hosts) carries no tenant.
    |
    */

    'base_domain' => env('TENANT_BASE_DOMAIN', 'civicride.com'),

    /*
    |--------------------------------------------------------------------------
    | Reserved subdomains
    |--------------------------------------------------------------------------
    |
    | Infrastructure labels that must never be read as a tenant slug. Without
    | this list a host such as "api.civicride.com" or "staging.civicride.com"
    | resolves to a bogus tenant and every request without an explicit
    | X-Tenant header fails with "Unknown or inactive tenant".
    |
    */

    'reserved_subdomains' => [
        'api',
        'www',
        'app',
        'admin',
        'platform',
        'cdn',
        'assets',
        'static',
        'media',
        'dev',
        'staging',
        'test',
        'local',
        'localhost',
    ],

];
