# Stateless Queue

[![PHPUnit Tests](https://github.com/KarsjenKoop/stateless-queue/actions/workflows/phpunit.yml/badge.svg)](https://github.com/KarsjenKoop/stateless-queue/actions/workflows/phpunit.yml)
[![E2E Integration Test](https://github.com/KarsjenKoop/stateless-queue/actions/workflows/e2e.yml/badge.svg)](https://github.com/KarsjenKoop/stateless-queue/actions/workflows/e2e.yml)
[![Latest Version](https://img.shields.io/packagist/v/karsjen/stateless-queue.svg)](https://packagist.org/packages/karsjen/stateless-queue)
[![License](https://img.shields.io/packagist/l/karsjen/stateless-queue.svg)](LICENSE)

A Laravel package for **push-based** job queues. Jobs are serialised to JSON and published to a topic
(Google Cloud Pub/Sub or AWS SNS). The provider pushes each message to your application's webhook, and
the package decodes the payload and runs the job's `handle()` method inside that request.

No polling. No `queue:work`. No long-running workers.

## Table of contents

- [Why this package?](#why-this-package)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Creating jobs](#creating-jobs)
- [Pushing jobs](#pushing-jobs)
- [Receiving jobs (webhook)](#receiving-jobs-webhook)
- [Security](#security)
- [Extending the package](#extending-the-package)
- [Exceptions](#exceptions)
- [Wire format](#wire-format)
- [Testing](#testing)
- [Versioning and backward compatibility](#versioning-and-backward-compatibility)
- [Contributing](#contributing)
- [License](#license)

## Why this package?

Many cloud runtimes — Cloud Run, serverless containers, scale-to-zero platforms — **suspend CPU between
HTTP requests** to cut cost. In that model there is no long-lived process.

Laravel's default queue system assumes a **worker process** (`php artisan queue:work`) that **polls** the
queue in a loop. When the CPU is suspended between requests, that worker is suspended too, so jobs are
never processed — even if the queue backend itself is a managed cloud service.

This package turns job execution into **incoming HTTP requests**. You publish a job to a topic; the cloud
provider **pushes** the message to your webhook; the package decodes it and runs `handle()` inside that
request. Your application only needs to be alive while it is handling a request, which is exactly what
request-scoped runtimes guarantee.

Google Cloud Pub/Sub and AWS SNS are supported because both are **push-capable**: they deliver messages to
an HTTP endpoint. Pull-based queues such as SQS require a process to poll them and therefore cannot work
without a background worker.

## How it works

```
  ┌──────────────────────┐
  │  Your app (sender)   │
  │                      │
  │  MyJob::dispatch()   │
  │        ->push()      │
  └──────────┬───────────┘
             │  1. CanStatelessQueue builds an OutgoingJobMessage
             │     { uuid, job_class, topic, payload, timestamp, version }
             ▼
  ┌──────────────────────┐
  │   OutboundAdapter    │  GooglePubSubAdapter | AwsSnsAdapter | NullAdapter
  └──────────┬───────────┘
             │  2. Publish JSON to the resolved topic
             ▼
  ┌──────────────────────┐
  │  Pub/Sub  |  SNS     │
  └──────────┬───────────┘
             │  3. Provider pushes an HTTP POST to your webhook
             ▼
  ┌──────────────────────────────────────────────────────────┐
  │  POST /api/stateless/webhook                             │
  │                                                          │
  │  VerifyWebhookSignature  →  ProviderRegistry resolves    │
  │    the adapter, adapter verifies the signature/token     │
  │                            ↓                             │
  │  StatelessQueueController →  adapter->parseRequest()     │
  │                            ↓                             │
  │  JobRunner  →  allowlist check  →  new Job(...payload)   │
  │             →  $job->handle()                            │
  └──────────────────────────────────────────────────────────┘
```

The job is **never** PHP-serialised. Only a plain JSON payload of scalar constructor arguments crosses the
wire, so the sender and the receiver can be different deployments, different versions, or different
services entirely.

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, 12.x, or 13.x
- One of: **Google Cloud Pub/Sub**, **AWS SNS**, or the `null` adapter for local development

### Version compatibility

| Laravel | PHP                   |
| ------- | --------------------- |
| 10.x    | 8.1 / 8.2 / 8.3       |
| 11.x    | 8.2 / 8.3 / 8.4       |
| 12.x    | 8.2 / 8.3 / 8.4 / 8.5 |
| 13.x    | 8.3 / 8.4 / 8.5       |

The CI matrix in [`.github/workflows/phpunit.yml`](.github/workflows/phpunit.yml) is the authoritative
list of tested combinations.

## Installation

```bash
composer require karsjen/stateless-queue
```

The service provider is auto-discovered. Publish the config file if you want to edit it:

```bash
php artisan vendor:publish --tag=stateless-queue-config
```

This writes `config/stateless-queue.php` into your application. The package ships with working defaults,
so publishing is optional — except for `allowed_jobs`, which you **must** configure before the webhook
will execute anything (see [Security](#security)).

## Configuration

### Environment variables

#### Core

| Variable                          | Description                                                   | Default   |
| --------------------------------- | ------------------------------------------------------------- | --------- |
| `STATELESS_QUEUE_ADAPTER`         | Adapter to publish with: `google`, `aws`, or `null`           | `null`    |
| `STATELESS_QUEUE_TOPIC`           | Topic used when a job does not declare one                    | `default` |
| `STATELESS_QUEUE_REGISTER_ROUTES` | Register the built-in webhook route automatically             | `true`    |
| `STATELESS_QUEUE_WEBHOOK_SECRET`  | Optional shared secret accepted as `?secret=<value>`          | *(unset)* |
| `STATELESS_QUEUE_ALLOW_LOCAL`     | Skip signature verification in `local`/`testing` environments | `false`   |

#### Google Cloud Pub/Sub

| Variable                                          | Description                                                        |
| ------------------------------------------------- | ------------------------------------------------------------------ |
| `GOOGLE_CLOUD_PROJECT`                            | Google Cloud project ID                                            |
| `GOOGLE_APPLICATION_CREDENTIALS`                  | Path to a service account JSON key file                            |
| `STATELESS_QUEUE_GOOGLE_EXPECTED_AUDIENCE`        | Required `aud` claim on the incoming OIDC token (your webhook URL) |
| `STATELESS_QUEUE_GOOGLE_ALLOWED_ISSUERS`          | CSV of accepted `iss` claims                                       |
| `STATELESS_QUEUE_GOOGLE_ALLOWED_SERVICE_ACCOUNTS` | CSV of exact service account emails allowed to invoke the webhook  |
| `STATELESS_QUEUE_GOOGLE_ALLOWED_EMAIL_SUFFIXES`   | CSV of allowed email suffixes                                      |
| `STATELESS_QUEUE_GOOGLE_REQUIRE_EMAIL_VERIFIED`   | Require `email_verified` when the token carries an `email` claim   |

`STATELESS_QUEUE_GOOGLE_ALLOWED_ISSUERS` defaults to
`accounts.google.com,https://accounts.google.com`. The remaining Google auth variables are unset by
default, which means the corresponding check is skipped — see [Security](#security).

#### AWS SNS

| Variable                | Description                                                     |
| ----------------------- | --------------------------------------------------------------- |
| `AWS_ACCESS_KEY_ID`     | Access key. Omit to use the default AWS credential chain        |
| `AWS_SECRET_ACCESS_KEY` | Secret key. Omit to use the default AWS credential chain        |
| `AWS_DEFAULT_REGION`    | AWS region (default `us-east-1`)                                |
| `AWS_ACCOUNT_ID`        | Account ID used to expand a bare topic name into a full SNS ARN |

`AWS_ACCOUNT_ID` is required unless every job's topic is already a full ARN
(`arn:aws:sns:<region>:<account>:<topic>`).

#### Local development and E2E

| Variable               | Description                                               |
| ---------------------- | --------------------------------------------------------- |
| `PUBSUB_EMULATOR_HOST` | Pub/Sub emulator address, e.g. `127.0.0.1:8085`           |
| `AWS_SNS_ENDPOINT`     | LocalStack SNS endpoint, e.g. `http://127.0.0.1:4566`     |
| `APP_ENV`              | Must be `local` or `testing` for the local bypass to work |

### Config file reference

| Key                      | Purpose                                                                                          |
| ------------------------ | ------------------------------------------------------------------------------------------------ |
| `default`                | Adapter used for publishing: `google`, `aws`, or `null`. See the warning below.                  |
| `default_topic`          | Topic used when a job does not declare `$statelessTopic` / `$stateless_topic`.                   |
| `webhook_secret`         | Optional shared secret accepted as a `?secret=` query parameter.                                 |
| `connections`            | Per-adapter connection settings and, for Google, the inbound auth policy.                        |
| `allowed_jobs`           | **Required for receiving.** Class names or `Str::is()` patterns permitted from webhook payloads. |
| `allow_local_unverified` | Skip signature verification in `local`/`testing`. Never enable in production.                    |
| `register_routes`        | Register `POST /api/stateless/webhook` automatically.                                            |

An empty `allowed_jobs` list rejects **every** incoming job. This is deliberate: the package is
deny-by-default.

> **The `null` adapter is for development only — and it is the default.**
> It publishes nothing and writes each job, **payload included**, to the application log at `info`
> level. Job payloads carry whatever your application puts in them: email addresses, order contents,
> tokens, personal data. Leaving `STATELESS_QUEUE_ADAPTER` unset in production therefore does two
> things at once — every dispatched job is silently dropped, and every payload is recorded in
> plaintext in your logs and in any aggregator they are shipped to. Set `google` or `aws` in
> production.

### Example: Google Cloud Pub/Sub

On GCP-managed runtimes such as Cloud Run, the Google client library obtains credentials and the project
ID automatically through **Application Default Credentials** (the metadata server and the injected
service account), so `GOOGLE_APPLICATION_CREDENTIALS` and `GOOGLE_CLOUD_PROJECT` are often unnecessary.
The library resolves the project ID in this order: the `GOOGLE_CLOUD_PROJECT` environment variable, then
the service account key file, then the GCE metadata server.

> **Note:** Verifying an inbound OIDC token requires valid Google credentials to fetch Google's public
> keys. On Cloud Run this is handled by the metadata server. Outside GCP you must supply credentials
> explicitly, for example via `GOOGLE_APPLICATION_CREDENTIALS`.

```env
STATELESS_QUEUE_ADAPTER=google
GOOGLE_CLOUD_PROJECT=my-gcp-project

# Recommended: pin the webhook to a single caller identity.
STATELESS_QUEUE_GOOGLE_EXPECTED_AUDIENCE=https://api.my-app.com/api/stateless/webhook
STATELESS_QUEUE_GOOGLE_ALLOWED_SERVICE_ACCOUNTS=pubsub-invoker@my-gcp-project.iam.gserviceaccount.com
```

```php
// config/stateless-queue.php
return [
    'default'       => env('STATELESS_QUEUE_ADAPTER', 'google'),
    'default_topic' => env('STATELESS_QUEUE_TOPIC', 'default'),

    'connections' => [
        'google' => [
            'project_id' => env('GOOGLE_CLOUD_PROJECT'),
            'key_file'   => env('GOOGLE_APPLICATION_CREDENTIALS'),
            'auth' => [
                'expected_audience'        => env('STATELESS_QUEUE_GOOGLE_EXPECTED_AUDIENCE'),
                'allowed_issuers'          => ['https://accounts.google.com'],
                'allowed_service_accounts' => ['pubsub-invoker@my-gcp-project.iam.gserviceaccount.com'],
                'allowed_email_suffixes'   => ['@my-gcp-project.iam.gserviceaccount.com'],
                'require_email_verified'   => true,
            ],
        ],
    ],

    'allowed_jobs' => ['App\\Jobs\\*'],
];
```

### Example: AWS SNS

```php
// config/stateless-queue.php
return [
    'default'       => env('STATELESS_QUEUE_ADAPTER', 'aws'),
    'default_topic' => env('STATELESS_QUEUE_TOPIC', 'default'),

    'connections' => [
        'aws' => [
            'key'        => env('AWS_ACCESS_KEY_ID'),
            'secret'     => env('AWS_SECRET_ACCESS_KEY'),
            'region'     => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'account_id' => env('AWS_ACCOUNT_ID'),
            // Optional — point at LocalStack for local testing:
            // 'endpoint' => env('AWS_SNS_ENDPOINT'),
        ],
    ],

    'allowed_jobs' => ['App\\Jobs\\*'],
];
```

## Creating jobs

A stateless job:

1. Implements `Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue`.
2. Uses the `Karsjen\StatelessQueue\Traits\CanStatelessQueue` trait.
3. Implements `handle()`.

```php
<?php

namespace App\Jobs;

use Karsjen\StatelessQueue\Contracts\ShouldStatelessQueue;
use Karsjen\StatelessQueue\Traits\CanStatelessQueue;

class ProcessOrderJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public function __construct(
        public string $orderId,
    ) {}

    public function handle(): void
    {
        // Process the order.
    }
}
```

### Payload rules

`CanStatelessQueue::getPayload()` uses reflection to build the payload from your constructor signature.
For every constructor parameter that also exists as a property on the job:

- **Built-in types** (`string`, `int`, `float`, `bool`, `array`, and unions of built-ins) are included.
- **Class-typed parameters** are rejected with `InvalidStatelessJobPayloadException`.
- **Untyped parameters** are rejected with `InvalidStatelessJobPayloadException`.
- Constructor parameters that are *not* stored as properties — plain dependency-injection arguments —
  are skipped silently and re-resolved from the container on the receiving side.

Because promoted constructor properties *are* properties, a promoted service dependency would be
rejected. Resolve services inside `handle()` instead:

```php
public function __construct(
    public string $orderId,   // transported in the payload
) {}

public function handle(): void
{
    app(Mailer::class)->send(/* ... */);
}
```

Override `getPayload()` to take full control of what is transported:

```php
public function getPayload(): array
{
    return ['orderId' => $this->orderId];
}
```

### Topic resolution

The topic is resolved in this order:

1. Public property `$statelessTopic`
2. Public property `$stateless_topic` (snake_case)
3. `config('stateless-queue.default_topic')`

```php
class NotifySlackJob implements ShouldStatelessQueue
{
    use CanStatelessQueue;

    public string $statelessTopic = 'notifications-slack';

    public function __construct(
        public string $channel,
        public string $text,
    ) {}

    public function handle(): void
    {
        // Send to Slack.
    }
}
```

## Pushing jobs

```php
use App\Jobs\ProcessOrderJob;

// Instantiate and push:
(new ProcessOrderJob(orderId: '12345'))->push();

// Or via the trait's named constructor:
ProcessOrderJob::dispatch('12345')->push();

// Or in one call:
ProcessOrderJob::dispatchAndPush('12345');
```

`push()` builds an `OutgoingJobMessage`, resolves the configured `OutboundAdapter` from the container, and
publishes it. A provider failure is wrapped in `AdapterPublishException`.

> `dispatch()` here is the package's own named constructor and returns the job instance — it does **not**
> queue anything by itself. You must call `push()`. It is unrelated to Laravel's `Dispatchable::dispatch()`.

## Receiving jobs (webhook)

When `register_routes` is `true` (the default) the package registers:

- **Route:** `POST /api/stateless/webhook` (route name `stateless.webhook`)
- **Middleware:** `stateless.signature`

Point your provider at that URL:

- **Google Pub/Sub push subscription:** `https://your-app.com/api/stateless/webhook`
- **AWS SNS HTTP(S) subscription:** the same URL. SNS sends a `SubscriptionConfirmation` first; the
  package confirms it automatically.

### Registering the route yourself

Set `register_routes` to `false` and wire it up however you like:

```php
use Karsjen\StatelessQueue\Http\Controllers\StatelessQueueController;

Route::post('/hooks/queue', [StatelessQueueController::class, 'handle'])
    ->middleware(['stateless.signature']);
```

### Adapter resolution and response codes

| Step                     | Behaviour                                                                                                                                                              |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Order**                | Inbound adapters are probed in registration order: **AWS SNS**, then **Google Pub/Sub**. The `null` adapter is outbound-only and never participates.                    |
| **Match**                | The first adapter whose `supportsRequest()` returns `true` owns the request.                                                                                           |
| **Match, verify passes** | The adapter is stashed on the request so the controller does not have to resolve it again, and the request proceeds.                                                   |
| **Match, verify fails**  | **403** with `Invalid AWS Signature` / `Invalid Google Token`. The shared secret is deliberately **not** a fallback here — fix the provider credentials instead.        |
| **No match**             | The middleware falls back to the shared secret: `?secret=` must equal `stateless-queue.webhook_secret`. Otherwise **401** `Unauthorized: Missing or Invalid Signature`. |
| **Local bypass**         | When `APP_ENV` is `local` or `testing` **and** `allow_local_unverified` is `true`, verification is skipped entirely.                                                    |

Once past the middleware, the controller responds:

| Situation                                  | Status | Body                                     |
| ------------------------------------------ | ------ | ---------------------------------------- |
| Job parsed and executed successfully       | 200    | `{"status":"success"}`                   |
| Provider handshake (e.g. SNS subscription) | 200    | `{"status":"handled"}`                   |
| Recognised but unactionable message        | 200    | `{"status":"ignored"}`                   |
| No adapter matched the request             | 400    | `{"error":"Unknown payload source"}`     |
| Adapter reported a malformed payload       | 400    | `{"error":"..."}`                        |
| Adapter could not parse the payload        | 422    | `{"error":"Invalid payload structure"}`  |
| Job class is not in `allowed_jobs`         | 403    | `{"error":"..."}`                        |
| Adapter failed unexpectedly while parsing  | 500    | `{"error":"..."}`                        |
| Job execution failed                       | 500    | `{"error":"..."}`                        |

The 4xx/5xx split is deliberate. A **403** or **422** is a definitive rejection: the same payload will
never succeed, so the provider should stop redelivering it. A **500** means the failure may be
transient, so redelivery is worth attempting.

Returning a non-2xx status tells the provider that delivery failed, which triggers its own retry and
dead-letter policy. Configure retries and DLQs on the Pub/Sub subscription or SNS topic — this package
does not implement retries itself.

## Security

The webhook is a public HTTP endpoint that instantiates and executes classes named in its payload. Three
layers guard it, and **all three matter**.

### 1. Transport authentication

**AWS SNS** — the message signature is validated with the official
[`aws/aws-php-sns-message-validator`](https://github.com/aws/aws-php-sns-message-validator) library,
which checks the signature against the certificate SNS advertises.

**Google Pub/Sub** — the `Authorization: Bearer <jwt>` header is verified against Google's public keys,
and the decoded claims are then checked against the policy in `connections.google.auth`:

| Policy key                 | Effect when set                                            | Effect when unset       |
| -------------------------- | ---------------------------------------------------------- | ----------------------- |
| `expected_audience`        | `aud` must match exactly (string or array form)            | Audience is not checked |
| `allowed_issuers`          | `iss` must be in the list                                  | Issuer is not checked   |
| `allowed_service_accounts` | `email` must match exactly                                 | Not checked             |
| `allowed_email_suffixes`   | `email` must end with one of the suffixes                  | Not checked             |
| `require_email_verified`   | `email_verified` must be true when an `email` claim exists | Defaults to `true`      |

`allowed_service_accounts` and `allowed_email_suffixes` are evaluated together: an email matching
*either* list is accepted.

> **Recommended production baseline:** set `expected_audience` to your webhook URL **and** at least one of
> `allowed_service_accounts` / `allowed_email_suffixes`. Without them, any caller holding a valid
> Google-issued token — from any Google account — passes verification.

### 2. The `allowed_jobs` allowlist

Only classes matching `stateless-queue.allowed_jobs` may be instantiated from a webhook payload. Patterns
use Laravel's `Str::is()` syntax:

```php
'allowed_jobs' => [
    'App\\Jobs\\*',
    'App\\Domain\\Billing\\Jobs\\SendInvoice',
],
```

An empty list rejects everything. Keep the patterns as narrow as your job layout allows — a broad pattern
such as `*` would let any caller who reaches the webhook instantiate arbitrary classes.

The job is instantiated through the container with the payload as named arguments and must implement
`ShouldStatelessQueue`; the package never calls `unserialize()` on incoming data.

### 3. The shared secret (`webhook_secret`)

`?secret=<value>` is a convenience path for local testing and for providers that cannot sign requests. It
puts a credential in the URL, where it can land in access logs, proxy logs, and browser history. Prefer
provider signatures in production; if you must use it, treat the URL itself as a secret.

### Local bypass

`allow_local_unverified` disables all signature checking. It only applies when `APP_ENV` is `local` or
`testing`, but do not ship it enabled.

### Payload logging

The `null` adapter logs full job payloads at `info` level (see the warning under
[Configuration](#config-file-reference)). The real adapters do not — they log the job class, UUID, and
topic only. If you need an inert adapter in production, implement `OutboundAdapter` and log identifiers
rather than the payload.

### Reporting a vulnerability

See [SECURITY.md](SECURITY.md).

## Extending the package

### Adding a provider

Implement `QueueProviderAdapter` (which extends `InboundWebhookAdapter` and `OutboundAdapter`) — or just
one side of it if your provider is publish-only or receive-only:

```php
use Karsjen\StatelessQueue\Contracts\QueueProviderAdapter;

final class MyProviderAdapter implements QueueProviderAdapter
{
    public function name(): string { return 'my-provider'; }

    public function publish(OutgoingJobMessage $message): void { /* ... */ }

    public function supportsRequest(Request $request): bool { /* header sniffing */ }

    public function verifySignature(Request $request): bool { /* ... */ }

    public function parseRequest(Request $request): ParseResult
    {
        return ParseResult::job(IncomingJobMessage::fromJson($raw, $attributes, 'my-provider'));
    }
}
```

Then extend `StatelessQueueServiceProvider` and add your class to the `$adapters` map, or register your
own `ProviderRegistry` binding.

### Replacing the executor

`JobExecutor` is the execution boundary. The default `JobRunner` checks the allowlist and runs the job
synchronously. Bind your own implementation to change that — for example to hand the job off to Laravel's
regular queue, add retries, or attach tracing:

```php
$this->app->bind(
    \Karsjen\StatelessQueue\Contracts\JobExecutor::class,
    \App\Queue\TracingJobExecutor::class,
);
```

## Exceptions

All package exceptions extend `RuntimeException`, so existing `catch (RuntimeException)` blocks keep
working. Catch the specific type when you need to distinguish causes:

| Exception                             | Thrown by                              | Meaning                                                               |
| ------------------------------------- | -------------------------------------- | --------------------------------------------------------------------- |
| `AdapterPublishException`             | `AwsSnsAdapter`, `GooglePubSubAdapter` | Publishing to the provider failed. Original error is `getPrevious()`. |
| `InvalidStatelessJobPayloadException` | `CanStatelessQueue::getPayload()`      | A constructor parameter is untyped or not JSON-serialisable.          |
| `JobNotAllowedException`              | `JobRunner`                            | The incoming job class is not in `allowed_jobs`.                      |
| `WebhookParseException`               | `IncomingJobMessage`, adapters         | The incoming payload was malformed or not valid JSON.                 |

All live in `Karsjen\StatelessQueue\Exceptions`.

## Wire format

The JSON body published to the provider:

```json
{
  "uuid": "9b1f0f4e-1f2a-4a3b-9c8d-0e1f2a3b4c5d",
  "job_class": "App\\Jobs\\ProcessOrderJob",
  "topic": "orders",
  "payload": { "orderId": "12345" },
  "timestamp": 1767225600,
  "version": 1
}
```

`job_class`, `uuid`, and `topic` are additionally sent as provider message attributes, so you can filter
or route on them without decoding the body.

`version` is the schema version of this envelope. It is currently `1`; a change that would break older
receivers is treated as a major release.

## Testing

Run the fast suite (unit + feature, no external services):

```bash
composer install
vendor/bin/phpunit
```

The full end-to-end suite runs against a Google Pub/Sub emulator and LocalStack SNS in Docker. See
[`tests/README.md`](tests/README.md) for the test layout, conventions, and how to run E2E locally.

## Example: Cloud Run + Pub/Sub

[`examples/gcp-cloud-run`](examples/gcp-cloud-run) is a complete, deployable Terraform setup: a Laravel
app on Cloud Run scaling to zero, two Pub/Sub topics pushing to its webhook with OIDC auth, and four
jobs — three on the default topic, one on its own — plus a script that proves each of them executed.

## Versioning and backward compatibility

This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Public API** — covered by SemVer:

- The `ShouldStatelessQueue`, `QueueProviderAdapter`, `InboundWebhookAdapter`, `OutboundAdapter`, and
  `JobExecutor` contracts
- The `CanStatelessQueue` trait's public methods
- The `config/stateless-queue.php` schema
- The registered route, route name, and middleware alias
- The JSON wire format

**Internal** — not covered by SemVer, may change in a minor release:

- Anything under `Karsjen\StatelessQueue\Runtime`
- Anything marked `@internal`
- Private and protected members
- Everything under `tests/`

Wire-format changes that would break an older receiver are major releases. See
[CHANGELOG.md](CHANGELOG.md).

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for the development setup,
coding conventions, and test requirements, and note the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

Released under the [MIT License](LICENSE).
