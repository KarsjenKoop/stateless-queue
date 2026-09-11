<?php

return [
    'name' => env('APP_NAME', 'Stateless Queue GCP Example'),
    'env' => env('APP_ENV', 'production'),
    'key' => env('APP_KEY'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),

    // Shared token guarding the example's /dispatch/* routes.
    'dispatch_token' => env('DISPATCH_TOKEN'),
];
