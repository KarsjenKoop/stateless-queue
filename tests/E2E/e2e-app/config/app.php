<?php

return [
    'name' => env('APP_NAME', 'Stateless Queue E2E'),
    'env' => env('APP_ENV', 'local'),
    'key' => env('APP_KEY', 'base64:'.str_repeat('0', 44)),
    'debug' => (bool) env('APP_DEBUG', true),
    'url' => env('APP_URL', 'http://localhost'),
];
