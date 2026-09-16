<?php

return [
    'frankfurter' => [
        'base_url' => env('FRANKFURTER_BASE_URL', 'https://api.frankfurter.dev/v2'),
        'cache_ttl' => (int) env('FRANKFURTER_CACHE_TTL', 3600),
    ],

    'exdz' => [
        'base_url' => env('EXDZ_BASE_URL', 'https://api.exchangedz.com/v1'),
        'api_key' => env('EXDZ_API_KEY'),
        'cache_ttl' => (int) env('EXDZ_CACHE_TTL', 900),
        'use_demo_fallback' => env('EXDZ_USE_DEMO_FALLBACK', true),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],
];
