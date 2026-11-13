<?php

/*
| Which websites may call the API from a browser. The frontend normally lives on the same address as the API (the web server forwards
| /api), and then nothing here is needed. When the frontend is on another address, list it in CORS_ALLOWED_ORIGINS (comma separated,
| e.g. https://app.example.com). In production an empty list means no other website is allowed; outside production the default is any
| website, so local development works without setup. Sign-in uses a token header, not cookies, so credentials are never allowed.
*/
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('APP_ENV') === 'production' ? '' : '*'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Accept-Language', 'X-School', 'X-Requested-With'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 600,

    'supports_credentials' => false,
];
