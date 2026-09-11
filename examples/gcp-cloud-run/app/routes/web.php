<?php

use Illuminate\Support\Facades\Route;

/*
| The webhook route (POST /api/stateless/webhook) is registered by the package
| itself. The dispatch routes live in routes/api.php.
*/

Route::get('/', fn () => response()->json([
    'service' => 'stateless-queue GCP example',
    'webhook' => url('/api/stateless/webhook'),
    'dispatch' => [
        'POST /dispatch/default — 3 jobs on the default topic',
        'POST /dispatch/custom  — 1 job on the custom topic',
        'POST /dispatch/all     — all 4',
    ],
]));
