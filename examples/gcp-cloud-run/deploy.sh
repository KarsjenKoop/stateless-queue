#!/usr/bin/env bash
#
# Build the example image, push it to Artifact Registry, and apply the Terraform.
#
#   ./deploy.sh --project stateless-queue-test [--region europe-west1]
#
# Pass --caller to be able to drive the /dispatch routes yourself. The service
# requires authentication, so verify.sh needs an identity it can impersonate:
#
#   ./deploy.sh --project P --caller user:you@example.com
#
# Repeat --caller for more principals. Omitting it is fine for a deployment
# nobody pokes by hand — Pub/Sub has its own invoker binding either way.
#
# The project must be named explicitly. gcloud carries an ambient default
# project and this script deliberately never reads it: an apply that lands in
# the wrong project creates real, billable resources.

set -euo pipefail

GREEN='\033[0;32m'; BLUE='\033[0;34m'; RED='\033[0;31m'; YELLOW='\033[0;33m'; NC='\033[0m'

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$HERE/../.." && pwd)"
TF_DIR="$HERE/terraform"

PROJECT_ID=""
REGION="europe-west1"
SERVICE_NAME="stateless-queue-example"
TAG="$(date +%Y%m%d-%H%M%S)"
AUTO_APPROVE=""
CALLERS=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --project) PROJECT_ID="${2:-}"; shift 2 ;;
    --region)  REGION="${2:-}"; shift 2 ;;
    --service) SERVICE_NAME="${2:-}"; shift 2 ;;
    --tag)     TAG="${2:-}"; shift 2 ;;
    --caller)  CALLERS+=("${2:-}"); shift 2 ;;
    --yes|-y)  AUTO_APPROVE="-auto-approve"; shift ;;
    -h|--help)
      sed -n '2,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) echo -e "${RED}Unknown argument: $1${NC}"; exit 1 ;;
  esac
done

if [[ -z "$PROJECT_ID" ]]; then
  echo -e "${RED}Error: --project is required.${NC}"
  echo "This script never falls back to the gcloud default project."
  echo "  ./deploy.sh --project YOUR_PROJECT_ID"
  exit 1
fi

# Hard stop on a project that must never receive these resources, in case it is
# ever passed by habit or inherited from a wrapper script.
if [[ "$PROJECT_ID" == "atelo-505509" ]]; then
  echo -e "${RED}Refusing to deploy into atelo-505509.${NC}"
  exit 1
fi

REPO_HOST="${REGION}-docker.pkg.dev"
IMAGE_REPO="${REPO_HOST}/${PROJECT_ID}/${SERVICE_NAME}"
IMAGE="${IMAGE_REPO}/app:${TAG}"

echo -e "${GREEN}>>> stateless-queue Cloud Run example <<<${NC}"
echo "  project : $PROJECT_ID"
echo "  region  : $REGION"
echo "  service : $SERVICE_NAME"
echo "  image   : $IMAGE"
echo

# ── Preflight ────────────────────────────────────────────────────────────────
for tool in gcloud terraform docker; do
  command -v "$tool" >/dev/null 2>&1 || { echo -e "${RED}Missing required tool: $tool${NC}"; exit 1; }
done

if ! gcloud projects describe "$PROJECT_ID" --format='value(projectId)' >/dev/null 2>&1; then
  echo -e "${RED}Cannot read project '$PROJECT_ID'.${NC}"
  echo "Check the ID, and that you are authenticated:  gcloud auth login"
  exit 1
fi

# Terraform list literal, e.g. ["user:a@b.com","group:c@d.com"].
CALLER_VAR='caller_members=[]'
if [[ ${#CALLERS[@]} -gt 0 ]]; then
  JOINED=""
  for c in "${CALLERS[@]}"; do
    JOINED+="\"${c}\","
  done
  CALLER_VAR="caller_members=[${JOINED%,}]"
fi

echo -e "${BLUE}--- Stage 1/4: APIs and image repository ---${NC}"
# Targeted apply first. The image cannot be pushed until Artifact Registry
# exists, and Terraform cannot build images — so the repository has to be
# created before the build rather than in a single pass.
terraform -chdir="$TF_DIR" init -input=false >/dev/null
terraform -chdir="$TF_DIR" apply -input=false $AUTO_APPROVE \
  -var="project_id=$PROJECT_ID" \
  -var="region=$REGION" \
  -var="service_name=$SERVICE_NAME" \
  -var="image=placeholder" \
  -var="$CALLER_VAR" \
  -target=google_project_service.required \
  -target=google_artifact_registry_repository.images

echo -e "${BLUE}--- Stage 2/4: build image ---${NC}"
gcloud auth configure-docker "$REPO_HOST" --quiet >/dev/null 2>&1

# linux/amd64 explicitly: Cloud Run will not run an arm64 image, and building on
# an Apple Silicon machine produces one by default.
docker build \
  --platform linux/amd64 \
  -f "$HERE/app/Dockerfile" \
  -t "$IMAGE" \
  "$REPO_ROOT"

echo -e "${BLUE}--- Stage 3/4: push image ---${NC}"
docker push "$IMAGE"

echo -e "${BLUE}--- Stage 4/4: apply infrastructure ---${NC}"
terraform -chdir="$TF_DIR" apply -input=false $AUTO_APPROVE \
  -var="project_id=$PROJECT_ID" \
  -var="region=$REGION" \
  -var="service_name=$SERVICE_NAME" \
  -var="image=$IMAGE" \
  -var="$CALLER_VAR"

echo
echo -e "${GREEN}>>> Deployed <<<${NC}"
terraform -chdir="$TF_DIR" output
echo
echo -e "${YELLOW}Verify it end to end:${NC}"
echo "  ./verify.sh --project $PROJECT_ID --region $REGION --service $SERVICE_NAME"
echo
echo -e "${YELLOW}Tear it down when you are finished:${NC}"
echo "  ./destroy.sh --project $PROJECT_ID --region $REGION --service $SERVICE_NAME"
