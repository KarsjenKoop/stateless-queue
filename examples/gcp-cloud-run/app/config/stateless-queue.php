<?php

return [

    'default' => env('STATELESS_QUEUE_ADAPTER', 'google'),

    'default_topic' => env('STATELESS_QUEUE_TOPIC', 'stateless-default'),

    /*
    | Only these classes may be instantiated from an incoming webhook payload.
    | Scoped to this app's Jobs namespace — never widen this to '*'.
    */
    'allowed_jobs' => [
        'App\Jobs\*',
    ],

    'connections' => [
        'google' => [
            /*
            | Both are resolved from the metadata server on Cloud Run via
            | Application Default Credentials, so neither is set in the
            | Terraform. project_id is read here only to make the value visible
            | in config dumps.
            */
            'project_id' => env('GOOGLE_CLOUD_PROJECT'),
            'key_file' => env('GOOGLE_APPLICATION_CREDENTIALS'),

            'auth' => [
                /*
                | The exact `aud` claim required on the inbound OIDC token. It
                | matches the audience the push subscription is told to mint and
                | the custom audience configured on the Cloud Run service, so all
                | three agree on one constant.
                */
                'expected_audience' => env('STATELESS_QUEUE_GOOGLE_EXPECTED_AUDIENCE'),

                'allowed_issuers' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('STATELESS_QUEUE_GOOGLE_ALLOWED_ISSUERS', 'https://accounts.google.com,accounts.google.com'))
                ))),

                /*
                | The single service account Pub/Sub uses to call this webhook.
                | Without this, any caller holding a valid Google-issued token
                | for the right audience would pass.
                */
                'allowed_service_accounts' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('STATELESS_QUEUE_GOOGLE_ALLOWED_SERVICE_ACCOUNTS', ''))
                ))),

                'allowed_email_suffixes' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('STATELESS_QUEUE_GOOGLE_ALLOWED_EMAIL_SUFFIXES', ''))
                ))),

                'require_email_verified' => (bool) env('STATELESS_QUEUE_GOOGLE_REQUIRE_EMAIL_VERIFIED', true),
            ],
        ],
    ],

    // Never true in a deployed environment. The bypass only applies when
    // APP_ENV is local or testing, and this service runs as production.
    'allow_local_unverified' => false,

    'register_routes' => true,

];
