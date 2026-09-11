#!/bin/bash
set -e

# Run Google Pub/Sub + AWS SNS E2E from the package repo.
#
# Requires: Docker, PHP, Composer. Run from the package root.
# Uses tests/E2E/e2e-app as the Laravel app (serve + artisan commands).
#
# Environment:
#   STATELESS_QUEUE_E2E_PORT         port for the Laravel test server (default 8329)
#   STATELESS_QUEUE_E2E_WEBHOOK_URL  full webhook URL the emulators push to
#   PUBSUB_EMULATOR_HOST             defaults to 127.0.0.1:8085
#   AWS_SNS_ENDPOINT                 defaults to http://127.0.0.1:4566

GREEN='\033[0;32m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

PKG_ROOT="$(cd "$(dirname "$0")" && cd ../.. && pwd)"
E2E_APP="$PKG_ROOT/tests/E2E/e2e-app"

echo -e "${GREEN}>>> Stateless Queue E2E (package repo) <<<${NC}"

if [[ ! -d "$E2E_APP" ]]; then
  echo -e "${RED}Error: tests/E2E/e2e-app/ not found.${NC}"
  exit 1
fi

cd "$PKG_ROOT/tests/E2E"

PROJECT_ID="${GOOGLE_CLOUD_PROJECT:-test-project}"
EMULATOR_HOST_DEFAULT="127.0.0.1:8085"

echo -e "${BLUE}--- Ensuring Pub/Sub emulator on $EMULATOR_HOST_DEFAULT ---${NC}"
if ! nc -z 127.0.0.1 8085 2>/dev/null; then
  docker compose up -d pubsub
fi
if ! nc -z 127.0.0.1 8085 2>/dev/null; then
  echo -e "${RED}Error: Pub/Sub emulator not reachable on 127.0.0.1:8085${NC}"
  exit 1
fi

export PUBSUB_EMULATOR_HOST="${PUBSUB_EMULATOR_HOST:-$EMULATOR_HOST_DEFAULT}"
export GOOGLE_CLOUD_PROJECT="$PROJECT_ID"
echo "PUBSUB_EMULATOR_HOST=$PUBSUB_EMULATOR_HOST"
echo "GOOGLE_CLOUD_PROJECT=$GOOGLE_CLOUD_PROJECT"

echo -e "${BLUE}--- Ensuring LocalStack SNS on 127.0.0.1:4566 ---${NC}"
if ! nc -z 127.0.0.1 4566 2>/dev/null; then
  docker compose up -d localstack
fi
if ! nc -z 127.0.0.1 4566 2>/dev/null; then
  echo -e "${RED}Error: LocalStack not reachable on 127.0.0.1:4566${NC}"
  exit 1
fi

export AWS_ACCESS_KEY_ID="${AWS_ACCESS_KEY_ID:-test}"
export AWS_SECRET_ACCESS_KEY="${AWS_SECRET_ACCESS_KEY:-test}"
export AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-us-east-1}"
export AWS_ACCOUNT_ID="${AWS_ACCOUNT_ID:-000000000000}"
export AWS_SNS_ENDPOINT="${AWS_SNS_ENDPOINT:-http://127.0.0.1:4566}"

echo -e "${BLUE}--- Waiting for emulators (up to ~40s) ---${NC}"
for i in $(seq 1 20); do
  if (cd "$E2E_APP" && php artisan stateless-queue:wait-for-emulators 2>/dev/null); then
    echo "Emulators ready."
    break
  fi
  if [[ "$i" -eq 20 ]]; then
    echo -e "${RED}Timeout: emulators did not become ready.${NC}"
    exit 1
  fi
  sleep 2
done

# Deliberately not 8000/8080: those collide with whatever else is running on a
# developer machine, and a foreign server answering on the port is very hard to
# tell apart from a broken test run. Override if 8329 is taken here.
E2E_PORT="${STATELESS_QUEUE_E2E_PORT:-8329}"
E2E_WEBHOOK_URL="${STATELESS_QUEUE_E2E_WEBHOOK_URL:-http://host.docker.internal:${E2E_PORT}/api/stateless/webhook}"
export STATELESS_QUEUE_E2E_WEBHOOK_URL="$E2E_WEBHOOK_URL"
export STATELESS_QUEUE_ALLOW_LOCAL=true
export APP_ENV=local

# Refuse to start if something already owns the port. Without this the run
# continues against a stranger's server: the readiness probe passes, the
# emulators push to it, and the failure surfaces much later as "marker not
# found" with no hint that the webhook never reached Laravel.
if lsof -nP -iTCP:"$E2E_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  echo -e "${RED}Error: port ${E2E_PORT} is already in use.${NC}"
  echo "Something else is listening there:"
  lsof -nP -iTCP:"$E2E_PORT" -sTCP:LISTEN | sed 's/^/  /'
  echo
  echo "Stop it, or pick another port:"
  echo "  STATELESS_QUEUE_E2E_PORT=8765 $0"
  exit 1
fi

echo -e "${BLUE}--- Starting Laravel server (e2e-app) on port ${E2E_PORT} ---${NC}"
cd "$E2E_APP"
php artisan serve --host=0.0.0.0 --port="$E2E_PORT" &
SERVER_PID=$!
trap "kill $SERVER_PID 2>/dev/null || true" EXIT

# Probe the webhook route rather than the port. An open socket only proves
# *something* is listening; this proves it is our Laravel app, by requiring the
# package's own response to a body no adapter recognises.
SERVER_READY=false
for i in $(seq 1 15); do
  PROBE="$(curl -s --max-time 2 -X POST \
    -H 'Content-Type: application/json' \
    -d '{"stateless_queue_probe":true}' \
    "http://127.0.0.1:${E2E_PORT}/api/stateless/webhook" 2>/dev/null || true)"

  if [[ "$PROBE" == *"Unknown payload source"* ]]; then
    echo "Laravel server is up (webhook route responding)."
    SERVER_READY=true
    break
  fi
  sleep 1
done

if [[ "$SERVER_READY" != true ]]; then
  echo -e "${RED}Error: the stateless-queue webhook did not respond on port ${E2E_PORT}.${NC}"
  if nc -z 127.0.0.1 "$E2E_PORT" 2>/dev/null; then
    echo "Something is listening on ${E2E_PORT}, but it is not this package's webhook."
    echo "Last response body was:"
    echo "  ${PROBE:-<empty>}"
  else
    echo "Nothing is listening on ${E2E_PORT} — the Laravel server failed to start."
    echo "Check that 'composer install' has been run in tests/E2E/e2e-app."
  fi
  exit 1
fi

echo -e "${BLUE}--- Real push E2E ---${NC}"
if ! php artisan stateless-queue:run-real-push-e2e --webhook-url="$E2E_WEBHOOK_URL"; then
  echo -e "${BLUE}Retrying once after 10s...${NC}"
  sleep 10
  if ! php artisan stateless-queue:run-real-push-e2e --webhook-url="$E2E_WEBHOOK_URL"; then
    echo -e "${RED}Real push E2E failed.${NC}"
    exit 1
  fi
fi
echo -e "${GREEN}>>> Real push E2E passed <<<${NC}"

echo -e "${BLUE}--- PHPUnit E2E (group: e2e) ---${NC}"
cd "$PKG_ROOT"
php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED" ./vendor/bin/phpunit --group e2e "$@"

echo -e "${GREEN}>>> E2E finished <<<${NC}"

echo -e "${BLUE}--- Stopping Docker ---${NC}"
cd "$PKG_ROOT/tests/E2E/"
docker compose down

echo -e "${GREEN}>>> Done <<<${NC}"
