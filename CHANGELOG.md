# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

See the [backward compatibility policy](README.md#versioning-and-backward-compatibility) for what is
covered by SemVer.

## [Unreleased]

### Added

- Support for **Laravel 13.x** and **PHP 8.5**.
- `version` field on the JSON wire envelope (`JobMessage::DEFAULT_VERSION`, currently `1`), so
  receivers can detect schema changes.
- `JobExecutor` contract — the execution boundary for incoming jobs. Bind your own implementation to
  replace `JobRunner` with custom retry, dispatch, or observability behaviour.
- Dedicated exception types, all extending `RuntimeException`:
  - `AdapterPublishException` — an outbound adapter failed to publish.
  - `InvalidStatelessJobPayloadException` — a constructor parameter is untyped or not serialisable.
  - `JobNotAllowedException` — the incoming job class is not in `allowed_jobs`.
  - `WebhookParseException` — the incoming payload is malformed or not valid JSON.
- `ParseResult` value object, giving adapters four explicit outcomes — `job`, `handshake`, `ignore`,
  `invalid` — each carrying its own HTTP status and body.
- `ProviderRegistry`, which resolves the inbound adapter for a request and caches resolved adapters
  for the request lifetime.
- Configurable Google inbound auth policy under `connections.google.auth`: `expected_audience`,
  `allowed_issuers`, `allowed_service_accounts`, `allowed_email_suffixes`, and
  `require_email_verified`.
- Automatic SNS `SubscriptionConfirmation` handling.
- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, GitHub issue and pull request templates.
- CI matrix covering PHP 8.1–8.5 against Laravel 10.x–13.x, plus a Dockerised E2E workflow running the
  Google Pub/Sub emulator and LocalStack SNS.

### Changed

- Documented that the `null` adapter — the package default — publishes nothing and writes full job
  payloads to the log at `info` level, making it unsuitable for production.
- **Breaking:** `CanStatelessQueue::getPayload()` no longer silently drops constructor parameters it
  cannot serialise. Untyped parameters and parameters typed as classes or intersection types now throw
  `InvalidStatelessJobPayloadException`. Parameters that are not stored as properties — plain DI
  arguments — are still skipped.
- **Breaking:** `QueueProviderAdapter` was split into `InboundWebhookAdapter` and `OutboundAdapter`.
  `QueueProviderAdapter` remains as a combined interface extending both, so adapters implementing it
  are unaffected; consumers type-hinting it for publish-only use should switch to `OutboundAdapter`.
- **Breaking:** the terminology throughout the package changed from *driver* to *adapter*, including
  class names, namespaces, and the `connections` config key.
- The webhook controller now returns `400` for an unrecognised payload source and `422` for a parse
  failure, instead of `500` for both.
- The signature middleware stashes the resolved adapter on the request, so the controller no longer
  resolves it a second time.
- Adapters resolve their SDK clients lazily, so constructing an adapter no longer opens a connection.
- E2E fixtures, the emulator compose file, and the driver script moved from the package root into
  `tests/E2E/`.

### Security

- **Breaking:** a job class not in `allowed_jobs` now answers **403** instead of 500. A 5xx tells
  Pub/Sub and SNS to redeliver, so a caller probing for executable class names had their payload
  replayed until the topic's dead-letter policy gave up.
- Webhook failure responses no longer contain exception text. Bodies are fixed strings and the
  detail goes to the log. The allowlist denial in particular no longer echoes the submitted class
  name back, which let a caller enumerate which classes are executable.
- The `webhook_secret` query parameter is compared with `hash_equals()`. The previous `===`
  short-circuited on the first differing byte, leaking the secret through response timing. A
  non-string value such as `?secret[]=x` is now rejected rather than raising a TypeError.
- The SNS `SubscribeURL` host is pinned to `sns.<region>.amazonaws.com` (and the `.amazonaws.com.cn`
  partitions) over HTTPS before it is fetched. With `allow_local_unverified` enabled, an
  unauthenticated caller could otherwise supply any URL and use the application as a proxy to reach
  cloud metadata endpoints and other internal addresses. The rule relaxes only when a custom
  `endpoint` is configured, which means the deployment is not talking to AWS.
- Google token verification logs the exception class instead of the library's message, which can
  quote the rejected bearer token back.
- Webhook parse failures are classified by exception type rather than by matching on the exception
  message, so an internal fault can no longer be misreported as a client error (or vice versa).
  Non-`WebhookParseException` failures during parsing now answer 500 rather than 422.

### Fixed

- Removed double JSON encoding of the outgoing message payload.
- Bearer tokens, SNS signature material, and request headers are no longer written to the log by the
  controller, the middleware, or either adapter.
- The SNS subscription-confirmation failure now chains the original throwable as `$previous`, which
  the previous `catch` discarded.
- Google Pub/Sub project ID now resolves automatically from Application Default Credentials when
  running on GCP.
- `composer.json` Laravel and Testbench constraints corrected for PHP 8.5.

### Removed

- Microsoft Azure Service Bus adapter.
- `composer.lock` is no longer tracked; this is a library and resolves against the consumer's
  constraints.

[Unreleased]: https://github.com/KarsjenKoop/stateless-queue/commits/main
