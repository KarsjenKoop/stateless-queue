<?php

use Monolog\Handler\StreamHandler;

return [

    /*
    | Cloud Run collects the container's stdout/stderr into Cloud Logging, so
    | writing to stderr is all that is needed — no agent, no log file. Logging to
    | storage/ instead would put the records on an ephemeral filesystem that is
    | discarded when the instance is torn down.
    */

    'default' => env('LOG_CHANNEL', 'stderr'),

    'channels' => [
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
            // Emit the context as JSON so Cloud Logging parses it into
            // structured fields that can be filtered on.
            'formatter' => Monolog\Formatter\JsonFormatter::class,
        ],
    ],
];
