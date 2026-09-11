<?php

return [
    // STATELESS_QUEUE_ADAPTER, not _PROVIDER: the package renamed drivers to
    // adapters and this file kept the old key, so the value was never read.
    'default' => env('STATELESS_QUEUE_ADAPTER', 'null'),
    'default_topic' => env('STATELESS_QUEUE_TOPIC', 'default'),
    'connections' => [
        'google' => [
            'project_id' => env('GOOGLE_CLOUD_PROJECT'),
            'key_file' => env('GOOGLE_APPLICATION_CREDENTIALS'),
        ],
        'aws' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'account_id' => env('AWS_ACCOUNT_ID'),

            // Required for the inbound side, not just for publishing. The webhook
            // is handled by the `artisan serve` process, which builds the adapter
            // from this file — the E2E command's runtime config() calls apply only
            // to its own process. Without this, the server has no way to know it is
            // talking to LocalStack and refuses the SubscriptionConfirmation, whose
            // SubscribeURL is on localhost.localstack.cloud rather than an AWS host.
            'endpoint' => env('AWS_SNS_ENDPOINT'),
        ],
    ],
    'allow_local_unverified' => env('STATELESS_QUEUE_ALLOW_LOCAL', true),
    'allowed_jobs' => [
        'Karsjen\StatelessQueue\Tests\Support\Jobs\E2E\*',
        'Karsjen\StatelessQueue\Tests\*',
    ],
];
