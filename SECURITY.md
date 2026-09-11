# Security Policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 1.x     | ✅        |
| < 1.0   | ❌        |

Security fixes are applied to the latest minor release of the current major version.

## Reporting a vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Report privately through either channel:

- GitHub's [private vulnerability reporting](https://github.com/KarsjenKoop/stateless-queue/security/advisories/new)
- Email: **karsjen@kas-ai.com**

Please include:

- A description of the vulnerability and its impact
- Steps to reproduce, ideally a minimal proof of concept
- The package, PHP, and Laravel versions
- Which adapter is affected (`google`, `aws`, or both)

You can expect an acknowledgement within **72 hours** and an assessment within **7 days**. If the report
is confirmed, we will agree a disclosure timeline with you and credit you in the advisory unless you
prefer to stay anonymous.

## Scope

This package exposes a public HTTP endpoint that instantiates and executes classes named in an incoming
payload. The following are in scope and treated as high severity:

- Bypassing signature or token verification in `VerifyWebhookSignature` or either adapter
- Bypassing the `allowed_jobs` allowlist in `JobRunner`
- Executing a class that does not implement `ShouldStatelessQueue`
- Leaking bearer tokens, SNS signature material, or the webhook secret into logs or HTTP responses
- Any path that leads to arbitrary code execution from an unauthenticated request

Out of scope:

- Misconfiguration by the consuming application, in particular an over-broad `allowed_jobs` pattern such
  as `*`, or `allow_local_unverified` enabled in production
- Vulnerabilities in upstream dependencies — report those to the dependency's maintainers
- Denial of service through request volume; rate limiting is the application's responsibility

## Hardening checklist for operators

Before exposing the webhook publicly:

- [ ] `allowed_jobs` lists the narrowest patterns that cover your jobs — never `*`
- [ ] `allow_local_unverified` is `false` (and `APP_ENV` is not `local`/`testing`)
- [ ] **Google:** `expected_audience` is set to your webhook URL
- [ ] **Google:** `allowed_service_accounts` or `allowed_email_suffixes` restricts the caller identity —
      without one of these, any valid Google-issued token is accepted
- [ ] `webhook_secret` is unset in production, or the URL is treated as a credential
- [ ] The endpoint is served over HTTPS
- [ ] `STATELESS_QUEUE_ADAPTER` is `google` or `aws` — the default `null` adapter publishes nothing
      and writes every job payload to the log at `info` level
- [ ] Job `handle()` methods treat the payload as untrusted input and validate it
