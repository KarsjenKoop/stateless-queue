# E2E application

A minimal Laravel application used only by the package's end-to-end suite. It consumes the parent
package through a Composer path repository (`../../..`) and exposes the webhook route so a real
push from an emulator can be delivered and executed.

This app is **not** part of the distributed package — `tests/` is `export-ignore`d.

## What it provides

- The webhook endpoint at `POST /api/stateless/webhook`, registered by the package itself
- `App\Providers\AppServiceProvider`, which registers the two E2E Artisan commands from
  `Karsjen\StatelessQueue\Tests\Support\Console`:
  - `stateless-queue:wait-for-emulators`
  - `stateless-queue:run-real-push-e2e`
- A `.env` pointing at the local emulators, with `STATELESS_QUEUE_ALLOW_LOCAL=true` so pushes from
  the emulators are not signature-checked

The credentials in `.env` are LocalStack placeholders (`test` / `test`) and are not real.

## First-time setup

From the package root:

```bash
cd tests/E2E/e2e-app
composer install
```

## Running

Do not run this app directly. From the **package root**, run:

```bash
./tests/E2E/e2e-test.sh
```

The script starts the emulator containers, boots this app with `php artisan serve` on port 8329,
runs the real-push round trip, then runs `phpunit --group e2e`.

Set `STATELESS_QUEUE_E2E_PORT` to use a different port; `.env` here has the matching default
webhook URL, and the script overrides it to stay in step.

See [`tests/README.md`](../../README.md) for the full test suite documentation.
