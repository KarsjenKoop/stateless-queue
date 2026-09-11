#!/usr/bin/env bash
#
# Tear down everything deploy.sh created.
#
#   ./destroy.sh --project stateless-queue-test [--region europe-west1]
#
# Terraform needs two variables here that look meaningless and are not:
#
#   image           has no default, so an apply *or destroy* refuses to run
#                   without it. The value is never read while destroying.
#   caller_members  must match what was applied, or Terraform plans a change to
#                   those IAM bindings before destroying them. This script reads
#                   the applied value back out of the state so you do not have to
#                   remember it.
#
# The project must be named explicitly. gcloud carries an ambient default project
# and this script never reads it — deleting the wrong project's infrastructure is
# not something to leave to an inherited setting.

set -euo pipefail

GREEN='\033[0;32m'; BLUE='\033[0;34m'; RED='\033[0;31m'; YELLOW='\033[0;33m'; NC='\033[0m'

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TF_DIR="$HERE/terraform"

PROJECT_ID=""
REGION="europe-west1"
SERVICE_NAME="stateless-queue-example"
AUTO_APPROVE=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --project) PROJECT_ID="${2:-}"; shift 2 ;;
    --region)  REGION="${2:-}"; shift 2 ;;
    --service) SERVICE_NAME="${2:-}"; shift 2 ;;
    --yes|-y)  AUTO_APPROVE="-auto-approve"; shift ;;
    -h|--help)
      sed -n '2,19p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) echo -e "${RED}Unknown argument: $1${NC}"; exit 1 ;;
  esac
done

if [[ -z "$PROJECT_ID" ]]; then
  echo -e "${RED}Error: --project is required.${NC}"
  echo "This script never falls back to the gcloud default project."
  echo "  ./destroy.sh --project YOUR_PROJECT_ID"
  exit 1
fi

if [[ "$PROJECT_ID" == "atelo-505509" ]]; then
  echo -e "${RED}Refusing to operate on atelo-505509.${NC}"
  exit 1
fi

command -v terraform >/dev/null 2>&1 || { echo -e "${RED}terraform is not installed.${NC}"; exit 1; }

terraform -chdir="$TF_DIR" init -input=false >/dev/null 2>&1 || true

RESOURCES="$(terraform -chdir="$TF_DIR" state list 2>/dev/null || true)"

if [[ -z "$RESOURCES" ]]; then
  echo -e "${GREEN}Nothing to destroy — the Terraform state is empty.${NC}"
  echo
  echo "If resources still exist in the project, they were not created by this"
  echo "state file. List what is there with:"
  echo "  gcloud run services list --project=$PROJECT_ID"
  echo "  gcloud pubsub topics list --project=$PROJECT_ID"
  exit 0
fi

COUNT="$(printf '%s\n' "$RESOURCES" | grep -cv '^data\.' || true)"

echo -e "${GREEN}>>> Tearing down the stateless-queue Cloud Run example <<<${NC}"
echo "  project : $PROJECT_ID"
echo "  region  : $REGION"
echo "  service : $SERVICE_NAME"
echo
echo -e "${YELLOW}${COUNT} resources will be destroyed, including:${NC}"
printf '%s\n' "$RESOURCES" \
  | grep -E 'cloud_run_v2_service\.|pubsub_topic\.|pubsub_subscription\.|service_account\.|artifact_registry' \
  | grep -v '_iam_' \
  | sed 's/^/  - /'
echo
echo -e "${YELLOW}The image repository goes with it, and the images in it.${NC}"
echo

# caller_members has to match what was applied. The state knows: each binding is
# addressed as ...caller_impersonation["user:someone@example.com"].
CALLERS="$(printf '%s\n' "$RESOURCES" \
  | grep 'caller_impersonation\[' \
  | sed 's/.*\["//; s/"\]$//' || true)"

CALLER_VAR='caller_members=[]'
if [[ -n "$CALLERS" ]]; then
  JOINED=""
  while IFS= read -r c; do
    [[ -n "$c" ]] && JOINED+="\"${c}\","
  done <<< "$CALLERS"
  CALLER_VAR="caller_members=[${JOINED%,}]"
  echo -e "${BLUE}Recovered from state: ${CALLER_VAR}${NC}"
  echo
fi

# Without -auto-approve, Terraform prompts for confirmation itself.
terraform -chdir="$TF_DIR" destroy -input=false $AUTO_APPROVE \
  -var="project_id=$PROJECT_ID" \
  -var="region=$REGION" \
  -var="service_name=$SERVICE_NAME" \
  -var="image=unused" \
  -var="$CALLER_VAR"

echo
echo -e "${GREEN}>>> Destroyed <<<${NC}"
echo
echo "The five APIs stay enabled — disable_on_destroy is false, because turning"
echo "APIs off can break unrelated resources sharing the project. They cost"
echo "nothing while idle."
echo
echo "If the project existed only for this example, deleting it is the only way"
echo "to be certain nothing is left behind:"
echo "  gcloud projects delete $PROJECT_ID"
echo "(30-day recovery window, so it is reversible.)"
