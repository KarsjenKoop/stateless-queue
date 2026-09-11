# Cloud Run + Pub/Sub example

A working deployment of `karsjen/stateless-queue` on Google Cloud: a Laravel app on Cloud Run that
scales to zero, two Pub/Sub topics pushing to its webhook, and four jobs that prove the round trip.

Everything is Terraform. Nothing is clicked.

## What it demonstrates

Four jobs, chosen to cover the routing and payload cases that actually differ:

| Job                   | Topic                     | Covers                                                      |
| --------------------- | ------------------------- | ----------------------------------------------------------- |
| `SendWelcomeEmailJob` | default                   | Two strings, one with a default value                       |
| `GenerateReportJob`   | default                   | `string` + `int` + `array` — mixed built-in types           |
| `SyncInventoryJob`    | default                   | A `bool`, and a service resolved in `handle()` not injected |
| `NotifySlackJob`      | `stateless-notifications` | `$statelessTopic`, its own subscription, same webhook       |

The first three declare no topic, so they resolve to `stateless-queue.default_topic`. The fourth
declares `$statelessTopic` and travels through a second subscription. **Both subscriptions push to the
same endpoint** — routing is a property of the topic, not the URL, and that is the thing worth seeing.

The service runs with `min_instance_count = 0` and `cpu_idle = true`: no CPU between requests, no
process alive to poll anything. That is precisely the environment a `queue:work` worker cannot survive,
and the reason this package exists.

## The container

`php:8.4-cli` with Swoole compiled from a pinned tag, running **Laravel Octane** bound straight to
`$PORT`. No web server in front of it — Cloud Run terminates TLS and routes to the container port, so
an Apache or nginx layer would only add a hop.

Swoole is built from source rather than `pecl install`, which prompts for a dozen build options;
answering those non-interactively is how you end up enabling c-ares against a library that is not in
the image. `--enable-openssl` matters because the app makes outbound HTTPS calls — publishing to
Pub/Sub, and fetching Google's public keys to verify the inbound OIDC token.

`SESSION_DRIVER`, `CACHE_STORE`, and `QUEUE_CONNECTION` are pinned to in-memory equivalents. This
example has no database, and Laravel's defaults reach for one — the session driver alone is enough to
make every route 500 on a missing SQLite file.

The `/dispatch/*` routes are registered in the **api** group, not `web`. They are called by curl and by
`verify.sh`, never by a browser, so a session and a CSRF token are both meaningless; in the web group
the CSRF check answers 419 to every one of them.

## Architecture

```
  POST /dispatch/all              ┌──────────────────────────────┐
  (guarded by DISPATCH_TOKEN) ───▶│  Cloud Run                   │
                                  │  stateless-queue-example     │
                                  │                              │
                                  │  runtime SA: …-app           │──┐
                                  └──────────────────────────────┘  │ publish
                                                 ▲                  ▼
                                                 │      ┌───────────────────────┐
                                    push + OIDC  │      │ topic: stateless-     │
                                                 │      │        default        │
                                  ┌──────────────┴───┐  │ topic: stateless-     │
                                  │ Pub/Sub          │◀─│        notifications  │
                                  │ push SA: …-push  │  └───────────────────────┘
                                  └──────────────────┘              │
                                                                    │ 5 failures
                                                                    ▼
                                                        ┌───────────────────────┐
                                                        │ dead-letter topic     │
                                                        └───────────────────────┘
```

Two service accounts, because they are two different trust decisions:

- **`…-app`** — the Cloud Run runtime identity. Publishes to the topics. Nothing else.
- **`…-push`** — the identity Pub/Sub presents when calling the webhook. It is the *only* principal
  with `roles/run.invoker`, and the only address in `allowed_service_accounts`.

Keeping them separate is what makes the allowlist meaningful. The webhook accepts exactly one caller,
and it is not the application itself.

### How the webhook is secured

Three independent checks, all enforced:

1. **Cloud Run IAM** — the service requires authentication. Only `…-push` holds `run.invoker`, so an
   anonymous request never reaches PHP at all.
2. **OIDC claims** — the package verifies the bearer token against Google's public keys, then requires
   `aud` to equal the configured audience and `email` to equal the push service account. A valid
   Google token minted for something else fails here.
3. **`allowed_jobs`** — scoped to `App\Jobs\*`. Even a caller past the first two can only instantiate
   classes in that namespace.

### The audience is a constant, not the URL

`webhook_audience` defaults to the string `stateless-queue-example`, and the same value appears in
three places: the audience Pub/Sub mints into the token, `custom_audiences` on the Cloud Run service,
and `STATELESS_QUEUE_GOOGLE_EXPECTED_AUDIENCE` in the app's environment.

It is not the service URL, and that is deliberate. The URL only exists once the service does, but the
value has to be present in that same service's environment — a cycle Terraform cannot resolve in one
apply. Cloud Run's `custom_audiences` breaks it: the service accepts a token minted for an arbitrary
identifier instead of its own URL. The audience is an identifier, never resolved as an address.

If the three ever disagree, the webhook answers **403**.

## Prerequisites

- `gcloud`, `terraform` (≥ 1.5), and `docker`
- A GCP project **with billing enabled**, and a project ID you pass explicitly
- `gcloud auth login` and `gcloud auth application-default login`

## Deploy

```bash
cd examples/gcp-cloud-run
./deploy.sh --project YOUR_PROJECT_ID --region europe-west1 --caller user:you@example.com
```

`--caller` is what lets you drive the `/dispatch` routes yourself. The service requires
authentication, so a plain curl gets a 403 from Cloud Run before it reaches PHP — and a *user*
account cannot mint an audience-scoped identity token for itself, because gcloud rejects
`--audiences` for anything but a service account. So the principals you pass get Token Creator on a
dedicated caller service account that holds `run.invoker` and nothing else, and `verify.sh`
impersonates it.

Omit it for a deployment nobody needs to poke by hand; Pub/Sub has its own invoker binding either
way. Note that IAM bindings on a service account can take **up to two minutes** to propagate — if
token minting fails immediately after an apply, wait and retry before assuming it is misconfigured.

The script runs in four stages, because the image cannot be pushed to a registry that does not exist
yet and Terraform cannot build images:

1. Targeted apply — enable the APIs, create Artifact Registry
2. `docker build --platform linux/amd64` (Cloud Run will not run an arm64 image, and an Apple Silicon
   machine builds one by default)
3. `docker push`
4. Full apply — service accounts, topics, Cloud Run, IAM, subscriptions

> **The project is never inferred.** `--project` is required and `variable "project_id"` has no
> default. gcloud's ambient default project is deliberately never read: an apply landing in the wrong
> project creates real, billable resources.

## Verify

```bash
./verify.sh --project YOUR_PROJECT_ID
```

It dispatches all four jobs, then polls Cloud Logging until each has logged its `JOB_EXECUTED` marker:

```
--- Results ---
  SendWelcomeEmailJob    executed  topic=default
  GenerateReportJob      executed  topic=default
  SyncInventoryJob       executed  topic=default
  NotifySlackJob         executed  topic=stateless-notifications

>>> All 4 jobs executed. Both topics routed correctly. <<<
```

The marker is the assertion, not a convenience: a job can only write one from inside the webhook
request, so finding all four proves publish → push → verify → parse → execute worked on both topics.
Results are bounded to the current run, so an earlier run's logs cannot make a failing one look green.

### Dispatching by hand

```bash
TOKEN=$(terraform -chdir=terraform output -raw dispatch_token)
URL=$(terraform -chdir=terraform output -raw service_url)

curl -X POST -H "X-Dispatch-Token: $TOKEN" "$URL/dispatch/default"  # 3 jobs
curl -X POST -H "X-Dispatch-Token: $TOKEN" "$URL/dispatch/custom"   # 1 job
```

The `/dispatch/*` routes exist only to trigger a test. They publish to real topics, so they sit behind
a shared token compared with `hash_equals()`. They are not a pattern to copy into an application.

## What to look at afterwards

```bash
# Jobs that ran
gcloud logging read 'jsonPayload.context.marker="JOB_EXECUTED"' --project=PROJECT --limit=20

# Rejections — 403 means audience or caller identity disagree
gcloud logging read 'resource.labels.service_name="stateless-queue-example" AND severity>=WARNING' \
  --project=PROJECT --limit=20

# Topics, including the dead-letter one
gcloud pubsub topics list --project=PROJECT
```

A **403** in the logs is almost always one of: the audience constant differing between the three
places it appears, or `allowed_service_accounts` not matching the push service account.

If instead you see `Google token verification failed` with `"exception":"RuntimeException"`, check
that `phpseclib/phpseclib` v3 is installed. `google/auth` only *suggests* it, but
`AccessToken::verify()` cannot verify a token without it — the package requires it explicitly for
this reason.

## Retries and dead-lettering

The package returns a status that tells Pub/Sub what to do, and the Terraform configures Pub/Sub to
honour it:

| Response | Meaning                          | Pub/Sub does            |
| -------- | -------------------------------- | ----------------------- |
| 200      | Job executed                     | Acknowledge             |
| 403      | Class not in `allowed_jobs`      | Retry, then dead-letter |
| 422      | Payload could not be parsed      | Retry, then dead-letter |
| 500      | Job threw, or the adapter failed | Retry with backoff      |

`max_delivery_attempts = 5`, then the message moves to the dead-letter topic. Without a dead-letter
topic a permanently-failing message would be retried to the subscription's limit and then dropped
silently. Retries are Pub/Sub's job; the package does not implement its own.

## Cost

Scale-to-zero, three instances maximum, and Pub/Sub charges per message. Idle cost is effectively the
Artifact Registry storage for the image. Deploying, verifying, and tearing down the same day should
stay inside the free tier — but it is a real project with billing on, so check your own account.

## Tear down

```bash
cd examples/gcp-cloud-run
./destroy.sh --project YOUR_PROJECT_ID
```

It lists what will go, then hands over to Terraform's own confirmation prompt (`--yes` skips it).

The script exists because a raw `terraform destroy` here needs two arguments that look like noise and
are not:

- **`image`** has no default, so Terraform refuses to run without it even when destroying. The value
  is never read.
- **`caller_members`** has to match what was applied, or Terraform plans a *change* to those IAM
  bindings before destroying them. `destroy.sh` recovers the applied value from the state file, so
  you do not have to remember what you deployed with.

Everything goes: the service, both topics and the dead-letter topic, both subscriptions, all three
service accounts, every IAM binding, and the image repository along with the images in it.

The APIs stay enabled (`disable_on_destroy = false`) — turning APIs off can break unrelated resources
sharing the project, and they cost nothing idle. If the project existed only for this example,
deleting it is the only way to be sure nothing is left:

```bash
gcloud projects delete YOUR_PROJECT_ID   # 30-day recovery window
```

## Adapting it

- **Your own jobs** — put them under `App\Jobs`, which `allowed_jobs` already covers. Declare
  `$statelessTopic` to route one elsewhere, and add a matching topic and subscription in `main.tf`.
- **More topics** — copy the `custom` topic/subscription pair. The webhook needs no change.
- **A published package** — once `karsjen/stateless-queue` is on Packagist, drop the path repository
  from `app/composer.json`, replace it with a version constraint, and the Dockerfile no longer needs
  the repository root as its build context.
