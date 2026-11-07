#!/usr/bin/env bash
# Runs ON THE VPS (as the deploy user), fed over SSH by .github/workflows/deploy-staging.yml.
# The workflow prepends one line that exports: IMAGE, TAG, GHCR_USER, GHCR_TOKEN.
# Never echo those variables.
set -euo pipefail

cd /srv/ris/staging
COMPOSE="docker compose"
BACKEND=ris_staging_backend

: "${IMAGE:?}" "${TAG:?}" "${GHCR_USER:?}" "${GHCR_TOKEN:?}"

# Remember what is running now, so we can roll back.
PREV_REPO="$(grep -E '^IMAGE_REPOSITORY=' .env | cut -d= -f2-)"
PREV_TAG="$(grep -E '^IMAGE_TAG=' .env | cut -d= -f2-)"
echo "previous: ${PREV_REPO}:${PREV_TAG}"
echo "deploying: ${IMAGE}:${TAG}"

set_image() { # $1=repo $2=tag
  sed -i "s|^IMAGE_REPOSITORY=.*|IMAGE_REPOSITORY=$1|; s|^IMAGE_TAG=.*|IMAGE_TAG=$2|" .env
}

wait_healthy() {
  for i in $(seq 1 30); do
    state="$(docker inspect -f '{{.State.Health.Status}}' "$BACKEND" 2>/dev/null || echo none)"
    if [ "$state" = "healthy" ]; then return 0; fi
    sleep 4
  done
  return 1
}

echo "── logging in to GHCR (temporary) ──"
echo "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_USER" --password-stdin >/dev/null
trap 'docker logout ghcr.io >/dev/null 2>&1 || true' EXIT

echo "── pulling image ──"
docker pull "${IMAGE}:${TAG}"

set_image "$IMAGE" "$TAG"

echo "── migrating (before starting new containers) ──"
if ! $COMPOSE run --rm --no-deps backend php artisan migrate --force --isolated; then
  echo "MIGRATION FAILED - restoring previous image setting, nothing restarted"
  set_image "$PREV_REPO" "$PREV_TAG"
  exit 1
fi

echo "── starting containers ──"
$COMPOSE up -d

echo "── waiting for backend health ──"
if ! wait_healthy; then
  echo "NEW VERSION NOT HEALTHY - rolling back to ${PREV_REPO}:${PREV_TAG}"
  $COMPOSE logs backend --tail 40 || true
  set_image "$PREV_REPO" "$PREV_TAG"
  $COMPOSE up -d
  wait_healthy || echo "WARNING: rollback is also not healthy, check the server"
  exit 1
fi

$COMPOSE ps
echo "── pruning dangling images ──"
docker image prune -f >/dev/null
echo "deploy ok: ${IMAGE}:${TAG}"
