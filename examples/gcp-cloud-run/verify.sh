#!/usr/bin/env bash
#
# Prove the round trip: publish to Pub/Sub, let it push to Cloud Run, and check
# every job actually executed.
#
#   ./verify.sh --project stateless-queue-test [--region europe-west1]
#
# Each job logs {"marker":"JOB_EXECUTED","job":"<class>",...} to stderr, which
# Cloud Run forwards to Cloud Logging. Those records are the assertion: a job
# can only write one from inside the webhook request, so finding all four proves
# publish -> push -> verify -> parse -> execute worked for both topics.

set -euo pipefail

GREEN='\033[0;32m'; BLUE='\033[0;34m'; RED='\033[0;31m'; YELLOW='\033[0;33m'; NC='\033[0m'

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TF_DIR="$HERE/terraform"

PROJECT_ID=""
REGION="europe-west1"
SERVICE_NAME="stateless-queue-example"
WAIT_SECONDS=90

while [[ $# -gt 0 ]]; do
  case "$1" in
    --project) PROJECT_ID="${2:-}"; shift 2 ;;
    --region)  REGION="${2:-}"; shift 2 ;;
    --service) SERVICE_NAME="${2:-}"; shift 2 ;;
    --wait)    WAIT_SECONDS="${2:-}"; shift 2 ;;
    -h|--help) sed -n '2,11p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo -e "${RED}Unknown argument: $1${NC}"; exit 1 ;;
  esac
done

if [[ -z "$PROJECT_ID" ]]; then
  echo -e "${RED}Error: --project is required.${NC}"
  exit 1
fi

if [[ "$PROJECT_ID" == "atelo-505509" ]]; then
  echo -e "${RED}Refusing to operate on atelo-505509.${NC}"
  exit 1
fi

SERVICE_URL="$(terraform -chdir="$TF_DIR" output -raw service_url 2>/dev/null || true)"
DISPATCH_TOKEN="$(terraform -chdir="$TF_DIR" output -raw dispatch_token 2>/dev/null || true)"

if [[ -z "$SERVICE_URL" || -z "$DISPATCH_TOKEN" ]]; then
  echo -e "${RED}Could not read Terraform outputs. Has ./deploy.sh been run?${NC}"
  exit 1
fi

echo -e "${GREEN}>>> Verifying stateless-queue on Cloud Run <<<${NC}"
echo "  project : $PROJECT_ID"
echo "  service : $SERVICE_URL"
echo

# Only count records written from here on, so a previous run's logs cannot make
# a failing run look like it passed.
START_TS="$(date -u -v-10S +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u -d '10 seconds ago' +%Y-%m-%dT%H:%M:%SZ)"

# The service requires authentication, so the dispatch call needs a Google
# identity token whose audience is the service URL — the dispatch token alone
# gets a 403 from Cloud Run before the request ever reaches PHP.
#
# A user account cannot mint an audience-scoped token for itself; gcloud rejects
# --audiences for anything but a service account. So we impersonate the caller
# service account, which exists for exactly this and holds run.invoker only.
CALLER_SA="$(terraform -chdir="$TF_DIR" output -raw caller_service_account 2>/dev/null || true)"

if [[ -z "$CALLER_SA" ]]; then
  echo -e "${RED}No caller service account in the Terraform outputs.${NC}"
  exit 1
fi

echo -e "${BLUE}--- Minting an identity token as ${CALLER_SA} ---${NC}"
ID_TOKEN="$(gcloud auth print-identity-token \
  --impersonate-service-account="$CALLER_SA" \
  --audiences="$SERVICE_URL" 2>/dev/null || true)"

if [[ -z "$ID_TOKEN" ]]; then
  echo -e "${RED}Could not mint an identity token as ${CALLER_SA}.${NC}"
  echo
  echo "You need Token Creator on that service account. Add yourself to"
  echo "caller_members and re-apply:"
  echo
  echo "  terraform -chdir=terraform apply \\"
  echo "    -var=\"project_id=${PROJECT_ID}\" \\"
  echo "    -var=\"image=<current image>\" \\"
  echo "    -var='caller_members=[\"user:$(gcloud config get-value account 2>/dev/null)\"]'"
  exit 1
fi

echo -e "${BLUE}--- Dispatching 4 jobs (3 default topic + 1 custom topic) ---${NC}"
RESPONSE="$(curl -sS -X POST \
  -H "Authorization: Bearer ${ID_TOKEN}" \
  -H "X-Dispatch-Token: ${DISPATCH_TOKEN}" \
  -H 'Content-Type: application/json' \
  --max-time 60 \
  "${SERVICE_URL}/dispatch/all")"

echo "  $RESPONSE"

if [[ "$RESPONSE" != *'"dispatched":4'* ]]; then
  echo -e "${RED}Dispatch did not report 4 jobs. Aborting.${NC}"
  exit 1
fi

echo
echo -e "${BLUE}--- Waiting for pushes to arrive (up to ${WAIT_SECONDS}s) ---${NC}"
echo "A cold start on a scale-to-zero service takes a few seconds."

EXPECTED_JOBS=(SendWelcomeEmailJob GenerateReportJob SyncInventoryJob NotifySlackJob)
DEADLINE=$(( $(date +%s) + WAIT_SECONDS ))
FOUND_LOG=""

while [[ $(date +%s) -lt $DEADLINE ]]; do
  FOUND_LOG="$(gcloud logging read \
    "resource.type=cloud_run_revision
     AND resource.labels.service_name=${SERVICE_NAME}
     AND timestamp>=\"${START_TS}\"
     AND jsonPayload.context.marker=\"JOB_EXECUTED\"" \
    --project="$PROJECT_ID" \
    --format='value(jsonPayload.context.job,jsonPayload.context.topic)' \
    --limit=50 2>/dev/null || true)"

  MISSING=0
  for job in "${EXPECTED_JOBS[@]}"; do
    grep -q "$job" <<<"$FOUND_LOG" || MISSING=1
  done

  if [[ "$MISSING" -eq 0 ]]; then
    break
  fi

  sleep 5
  printf '.'
done
echo
echo

echo -e "${BLUE}--- Results ---${NC}"
FAILED=0
for job in "${EXPECTED_JOBS[@]}"; do
  if grep -q "$job" <<<"$FOUND_LOG"; then
    TOPIC="$(grep "$job" <<<"$FOUND_LOG" | head -1 | awk '{print $2}')"
    printf "  ${GREEN}%-22s executed${NC}  topic=%s\n" "$job" "${TOPIC:-?}"
  else
    printf "  ${RED}%-22s NOT SEEN${NC}\n" "$job"
    FAILED=1
  fi
done

echo
if [[ "$FAILED" -eq 0 ]]; then
  echo -e "${GREEN}>>> All 4 jobs executed. Both topics routed correctly. <<<${NC}"
  echo
  echo "Full log records:"
  echo "  gcloud logging read 'resource.labels.service_name=${SERVICE_NAME} AND jsonPayload.context.marker=\"JOB_EXECUTED\"' --project=${PROJECT_ID} --limit=20"
  exit 0
fi

echo -e "${RED}>>> Some jobs did not execute. <<<${NC}"
echo
echo -e "${YELLOW}Where to look:${NC}"
echo "  Webhook rejections (403 means the audience or caller identity disagrees):"
echo "    gcloud logging read 'resource.labels.service_name=${SERVICE_NAME} AND severity>=WARNING' --project=${PROJECT_ID} --limit=20"
echo
echo "  Subscriptions and their dead-letter policy:"
echo "    gcloud pubsub subscriptions list --project=${PROJECT_ID}"
echo
echo "  Anything that failed permanently and was dead-lettered:"
echo "    gcloud pubsub subscriptions pull ${SERVICE_NAME}-dead-letter-sub --project=${PROJECT_ID} --limit=10"
exit 1
