# Contributing

Thanks for taking the time to contribute. This document covers how to get the project running locally,
what the code and test conventions are, and what a reviewable pull request looks like.

## Code of Conduct

This project is governed by the [Contributor Covenant](CODE_OF_CONDUCT.md). By participating you agree to
uphold it.

## Getting started

```bash
git clone https://github.com/KarsjenKoop/stateless-queue.git
cd stateless-queue
composer install
vendor/bin/phpunit
```

`composer.lock` is intentionally not committed — this is a library, so dependencies resolve against the
consuming application's constraints.

## Reporting bugs

Open an issue using the **Bug report** template and include:

- The package, PHP, and Laravel versions
- Which adapter is in use (`google`, `aws`, `null`)
- A minimal reproduction — ideally a failing test
- The full exception message and stack trace, with credentials redacted

**Do not open a public issue for a security vulnerability.** Follow [SECURITY.md](SECURITY.md) instead.

## Proposing changes

For anything larger than a bug fix or a documentation correction, please open an issue first so the
approach can be agreed before you invest time in it. This is especially true for:

- New provider adapters
- Changes to the JSON wire format
- Changes to any contract in `src/Contracts`
- Changes to the `config/stateless-queue.php` schema

## Development workflow

1. Fork the repository and branch from `main`.
2. Make your change, with tests.
3. Run the fast suite: `vendor/bin/phpunit`.
4. Run the E2E suite if you touched an adapter (see below).
5. Update `CHANGELOG.md` under `## [Unreleased]`.
6. Open a pull request against `main`.

## Coding conventions

- **PSR-12** for code style, **PSR-4** for autoloading.
- **`declare(strict_types=1)` is not currently used** — do not add it to individual files in isolation;
  it would be a project-wide change.
- Type every parameter, property, and return value. Untyped code is what
  `InvalidStatelessJobPayloadException` exists to reject, so the package should hold itself to the same
  standard.
- Prefer `final` on concrete classes. Extension points are the contracts in `src/Contracts`.
- Exceptions live in `src/Exceptions` and use static named constructors
  (`WebhookParseException::invalidJson(...)`) rather than being thrown with an inline message string.
- **Never log credentials.** Bearer tokens, SNS signature material, `Authorization` headers, and query
  secrets must never reach a log call — not even inside an exception message that gets logged.
  `tests/Feature/Webhook/WebhookSecurityLoggingTest.php` guards this; extend it when you add logging.

### Documentation conventions

Every class, interface, and trait carries a class-level PHPDoc block with:

- A one-line summary in the form `<Kind>: <ClassName>` (`Adapter:`, `Message:`, `Exception:`, …)
- A short paragraph on what it does and where it sits in the flow
- `### How It Works` for anything with non-obvious control flow
- `@see` references to the neighbouring classes in the flow

Public methods carry a docblock when the behaviour is not fully described by the signature — in
particular an accurate `@throws` for every exception that can escape. Keep `@throws` tags in sync with
the code; a wrong `@throws` is worse than none.

## Test conventions

The suite is organised **by concern**, not by mirroring `src/`. See [`tests/README.md`](tests/README.md)
for the full layout and the class docblock template.

- Unit tests: `tests/Unit` — traits, DTOs, adapters with stubbed SDK clients
- Feature tests: `tests/Feature` — HTTP, routing, middleware, controller, via Testbench
- E2E tests: `tests/E2E` — require the Pub/Sub emulator or LocalStack, tagged `#[Group('e2e')]`
- Fixtures: `tests/Support` — job fixtures, payload builders, helpers

Every test class needs a class-level docblock stating what it **validates** and what it explicitly
**does not validate**. Use `// Given` / `// When` / `// Then` comments inside test bodies.

New behaviour needs a test. Bug fixes need a regression test that fails before the fix.

## Running the E2E suite

The E2E suite needs Docker (Google Pub/Sub emulator on 8085, LocalStack SNS on 4566).

```bash
cd tests/E2E/e2e-app && composer install && cd -   # first time only
./tests/E2E/e2e-test.sh
```

The script starts the containers, waits for them, boots the minimal Laravel app in
`tests/E2E/e2e-app`, runs the real-push round trip, then runs `phpunit --group e2e`.

To run only the PHPUnit E2E group against already-running emulators:

```bash
vendor/bin/phpunit --group e2e
```

## Pull request checklist

- [ ] `vendor/bin/phpunit` passes
- [ ] New behaviour is covered by a test; bug fixes have a regression test
- [ ] `CHANGELOG.md` updated under `## [Unreleased]`
- [ ] README / docblocks updated if behaviour or configuration changed
- [ ] No credentials, tokens, or signature material added to any log call
- [ ] Breaking changes are called out explicitly in the PR description

## Backward compatibility

See the [Versioning and backward compatibility](README.md#versioning-and-backward-compatibility) section
of the README for what is and is not covered by SemVer. If your change breaks the public API or the wire
format, say so in the PR — it needs to land in a major release.

## Licence

By contributing you agree that your contributions are licensed under the
[MIT License](LICENSE) that covers this project.
