<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Adapter
    |--------------------------------------------------------------------------
    |
    | The adapter used to publish jobs. Inbound webhook handling is independent
    | of this setting — the package sniffs each incoming request and picks the
    | matching adapter regardless of which one you publish with.
    |
    | Supported: "google", "aws", "null"
    |
    | "null" is a development adapter and the default. It publishes nothing and
    | writes each job — payload included — to the application log at info level.
    | Leaving it set in production silently drops every job and records its
    | payload in plaintext in your logs.
    |
    */

    'default' => env('STATELESS_QUEUE_ADAPTER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Default Topic
    |--------------------------------------------------------------------------
    |
    | Used when a job declares neither $statelessTopic nor $stateless_topic.
    | The topic must already exist at the provider — the package does not
    | create topics.
    |
    */

    'default_topic' => env('STATELESS_QUEUE_TOPIC', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Job Classes
    |--------------------------------------------------------------------------
    |
    | The security boundary for the webhook. Only these classes may be
    | instantiated from an incoming payload. Use exact class names and/or
    | Laravel Str::is() patterns, e.g. "App\Jobs\*".
    |
    | Deny-by-default: an empty list rejects every incoming job. Keep the
    | patterns as narrow as your job layout allows — a pattern such as "*"
    | would let any caller who reaches the webhook instantiate arbitrary
    | classes.
    |
    */

    'allowed_jobs' => [
        // 'App\Jobs\*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Adapter Connections
    |--------------------------------------------------------------------------
    |
    | Per-adapter connection settings. Only the connection for the adapter you
    | actually use needs to be populated.
    |
    */

    'connections' => [

        'google' => [

            // Resolved automatically from Application Default Credentials when
            // running on GCP (Cloud Run, GCE, GKE); set explicitly elsewhere.
            'project_id' => env('GOOGLE_CLOUD_PROJECT'),
            'key_file'   => env('GOOGLE_APPLICATION_CREDENTIALS'),

            /*
            | Inbound auth policy applied to the Google-issued OIDC token after
            | its signature has been verified against Google's public keys.
            |
            | Each check is skipped when its setting is empty. Verifying the
            | signature alone only proves the token came from Google — it does
            | not prove it came from *your* Pub/Sub subscription. For production,
            | set expected_audience and at least one of the email allowlists.
            */
            'auth' => [

                // Require an exact `aud` claim. Set this to your webhook URL.
                'expected_audience' => env('STATELESS_QUEUE_GOOGLE_EXPECTED_AUDIENCE'),

                // Accepted `iss` claims. CSV in env, normalised to an array here.
                'allowed_issuers' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('STATELESS_QUEUE_GOOGLE_ALLOWED_ISSUERS', 'accounts.google.com,https://accounts.google.com'))
                ))),

                // Exact `email` claim allowlist, e.g. the invoker service account.
                'allowed_service_accounts' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('STATELESS_QUEUE_GOOGLE_ALLOWED_SERVICE_ACCOUNTS', ''))
                ))),

                // Suffix allowlist, e.g. "@my-project.iam.gserviceaccount.com".
                // Evaluated together with allowed_service_accounts: a match in
                // either list accepts the token.
                'allowed_email_suffixes' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('STATELESS_QUEUE_GOOGLE_ALLOWED_EMAIL_SUFFIXES', ''))
                ))),

                // When the token carries an `email` claim, require it verified.
                'require_email_verified' => (bool) env('STATELESS_QUEUE_GOOGLE_REQUIRE_EMAIL_VERIFIED', true),

            ],
        ],

        'aws' => [

            // Omit key/secret to fall back to the standard AWS credential
            // chain (instance profile, ECS task role, shared config file).
            'key'    => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

            // Used to expand a bare topic name into a full SNS ARN. Required
            // unless every job's topic is already a full arn:aws:sns:... value.
            'account_id' => env('AWS_ACCOUNT_ID'),

            // Optional — point at LocalStack or another SNS-compatible endpoint.
            // 'endpoint' => env('AWS_SNS_ENDPOINT'),

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Secret (optional)
    |--------------------------------------------------------------------------
    |
    | When set, a request that no adapter recognises is accepted if it carries
    | ?secret=<value>. Intended for testing and for providers that cannot sign
    | their requests.
    |
    | This is not a fallback for a failed signature check — an adapter that
    | matches the request but fails verification is rejected with 403 either
    | way.
    |
    | A secret in the query string can end up in access logs, proxy logs, and
    | browser history. Prefer provider signatures in production.
    |
    */

    'webhook_secret' => env('STATELESS_QUEUE_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Local Signature Bypass
    |--------------------------------------------------------------------------
    |
    | Skips signature and token verification entirely. Only takes effect when
    | APP_ENV is "local" or "testing", which makes it safe against an accidental
    | production deploy — but never ship it enabled.
    |
    | Intended for emulator-based development, where the Pub/Sub emulator and
    | LocalStack cannot produce real signatures.
    |
    */

    'allow_local_unverified' => env('STATELESS_QUEUE_ALLOW_LOCAL', false),

    /*
    |--------------------------------------------------------------------------
    | Auto-register Webhook Route
    |--------------------------------------------------------------------------
    |
    | When true, the package registers the webhook endpoint automatically at
    | POST /api/stateless/webhook with the stateless.signature middleware.
    |
    | Set to false to take full control of the URL, prefix, or middleware stack,
    | then register it yourself:
    |
    |   use Karsjen\StatelessQueue\Http\Controllers\StatelessQueueController;
    |
    |   Route::post('/your/path', [StatelessQueueController::class, 'handle'])
    |       ->middleware(['stateless.signature']);
    |
    */

    'register_routes' => env('STATELESS_QUEUE_REGISTER_ROUTES', true),

];
