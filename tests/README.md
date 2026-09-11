# Test suite

Tests are grouped **by concern** — webhook authentication, parsing, allowlist, execution; adapter
behaviour; payload encoding; topic resolution — rather than mirroring the `src/` tree. E2E is reserved
for tests that need external emulators.

## Layout

```
tests/
├── TestCase.php                 Base Testbench case for all non-E2E tests
├── dummy-credentials.json       Placeholder Google credentials for the unit/feature suite
│
├── Unit/                        Pure PHP; no HTTP, no external services
│   ├── Adapters/                Adapter behaviour with stubbed SDK clients
│   ├── Exceptions/              Named-constructor behaviour of package exceptions
│   ├── Messages/                JobMessage / Incoming / Outgoing DTOs
│   ├── Payload/                 getPayload() inference and JSON encoding
│   ├── Runtime/                 JobRunner, ProviderRegistry, JobExecutor binding
│   ├── Topic/                   Topic resolution precedence
│   └── StatelessQueueManagerTest.php   Default-adapter resolution from config
│
├── Feature/                     Laravel HTTP layer via Testbench; no external services
│   ├── Adapters/                NullAdapter logging behaviour
│   ├── Dispatch/                dispatch() / push() end of the flow
│   ├── ServiceProvider/         Route registration toggles
│   └── Webhook/                 Auth, allowlist, parsing, dispatch validation, execution,
│                                handshake, middleware, security logging
│
├── E2E/                         Requires Docker (Pub/Sub emulator + LocalStack SNS)
│   ├── docker-compose.yml       Emulator containers
│   ├── e2e-test.sh              Full E2E driver script
│   ├── e2e-app/                 Minimal Laravel app that consumes the package via path repo
│   ├── GooglePubSubE2ETestCase.php
│   ├── AwsSnsE2ETestCase.php
│   ├── Google/                  Adapter, HTTP round trip, topic routing
│   └── Aws/                     SNS topic routing
│
└── Support/                     Fixtures — never assertions
    ├── Jobs/                    Job fixtures, one per scenario
    ├── Jobs/E2E/                Job fixtures used by the real-push E2E run
    ├── Console/                 Artisan commands used by e2e-test.sh
    ├── WebhookPayloadBuilder.php   Builds provider-shaped webhook envelopes
    └── GooglePubSubTestHelper.php  Emulator connectivity and topic/subscription helpers
```

Everything under `tests/` is `export-ignore`d in `.gitattributes`, so it is not shipped in the
Composer distribution.

## Base test cases

| Class                          | Purpose                                                                                                                        |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------ |
| `Tests\TestCase`               | Extends Orchestra Testbench, registers `StatelessQueueServiceProvider`, sets `default` to `null`, `default_topic` to `test-topic`, and allows `Karsjen\StatelessQueue\Tests\*` job classes. |
| `Tests\E2E\GooglePubSubE2ETestCase` | Extends `TestCase`, forces the `google` adapter, points at the Pub/Sub emulator, enables `allow_local_unverified`. Skips gracefully when the emulator is unreachable. |
| `Tests\E2E\AwsSnsE2ETestCase`  | Extends `TestCase`, forces the `aws` adapter, points at LocalStack. Skips gracefully when LocalStack is unreachable.           |

Use `TestCase` for fast unit and feature tests, and the E2E base cases only for tests that genuinely
need an emulator.

## Conventions

### Class docblock

Every test class carries a class-level PHPDoc stating what it asserts and — just as importantly — what
it deliberately does not, so scope does not creep between neighbouring files:

```php
/**
 * <One-sentence purpose>.
 *
 * Validates:
 * - <bullet>
 *
 * Does not validate:
 * - <bullet, naming the test class that does cover it>
 *
 * Inputs/Outputs:
 * - <input> -> <output>
 */
```

### Test body

Use `// Given` / `// When` / `// Then` comments. Keep them short and high-signal:

```php
public function test_unknown_job_class_is_rejected(): void
{
    // Given
    config()->set('stateless-queue.allowed_jobs', ['App\\Jobs\\*']);

    // When
    $response = $this->postJson(route('stateless.webhook'), $payload);

    // Then
    $response->assertStatus(500);
}
```

### Fixtures

Job fixtures live in `tests/Support/Jobs` — one class per scenario, named for the scenario
(`JobWithUntypedParam`, `JobWithOnlyDependency`, `NonEncodablePayloadJob`). Fixtures never assert;
they exist to be constructed, pushed, or executed.

Build webhook envelopes with `WebhookPayloadBuilder::googlePush()` / `::awsSnsNotification()` rather
than hand-rolling provider JSON in each test.

## Running the tests

From the package root:

```bash
# Fast suite — unit + feature, no external services
vendor/bin/phpunit --exclude-group e2e

# Everything, including E2E (emulators must already be running)
vendor/bin/phpunit

# E2E only
vendor/bin/phpunit --group e2e
```

`phpunit.xml.dist` sets `executionOrder="random"`, `failOnWarning`, `failOnRisky`, and
`failOnEmptyTestSuite`, so order-dependent or silently-skipped tests fail the build.

## Running the E2E suite

The E2E suite needs Docker: the Google Pub/Sub emulator on `8085` and LocalStack SNS on `4566`.

**First-time setup:**

```bash
cd tests/E2E/e2e-app && composer install && cd -
```

**Full run, from the package root:**

```bash
./tests/E2E/e2e-test.sh
```

The script starts the containers, waits for both emulators, boots `tests/E2E/e2e-app` on port
**8329**, runs the real-push round trip through `stateless-queue:run-real-push-e2e`, then runs
`phpunit --group e2e`, then stops the containers.

Port 8329 rather than 8000 or 8080, which collide with whatever else a developer happens to be
running. If it is taken, override it:

```bash
STATELESS_QUEUE_E2E_PORT=8765 ./tests/E2E/e2e-test.sh
```

The script refuses to start when the port is already occupied, and its readiness probe checks that
the **webhook route** answers rather than that the socket is open — an unrelated server on the port
cannot satisfy it. Both guards exist because the failure they prevent is badly disguised: pushes go
to the other server, jobs never run, and the only symptom is `marker not found`.

**Emulators only:**

```bash
cd tests/E2E && docker compose up -d
```

Two Artisan commands support the script and are registered by the E2E app's `AppServiceProvider`, not
by the package itself:

- `stateless-queue:wait-for-emulators` — readiness probe for both emulators
- `stateless-queue:run-real-push-e2e` — publishes real jobs and asserts the webhook executed them

## Adding tests

- **Unit** for DTOs, the trait's payload inference, exceptions, and adapter error handling with
  stubbed SDK clients.
- **Feature** for controller, middleware, and routing behaviour that does not need a real provider.
- **E2E** only when a real emulator round trip is the point. Tag with `#[Group('e2e')]` so it can be
  excluded, and make it skip cleanly when the emulator is absent.

If you add logging anywhere in `src/`, extend
`tests/Feature/Webhook/WebhookSecurityLoggingTest.php` to prove the new log call cannot emit bearer
tokens, signature material, or the webhook secret.
