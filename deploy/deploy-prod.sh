#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/superfpl}"
ENV_FILE="${ENV_FILE:-.env.production}"
DEPLOY_REF="${DEPLOY_REF:-main}"

cd "$APP_DIR"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $APP_DIR/$ENV_FILE"
  exit 1
fi

if command -v stat >/dev/null 2>&1; then
  perms="$(stat -c '%a' "$ENV_FILE" 2>/dev/null || true)"
  if [[ -n "$perms" ]] && [[ "$perms" -gt 640 ]]; then
    echo "Warning: $ENV_FILE permissions are $perms (recommended: 600 or 640)"
  fi
fi

echo "Fetching latest refs..."
git fetch --prune --tags origin

if git rev-parse -q --verify "refs/tags/$DEPLOY_REF" >/dev/null; then
  echo "Checking out tag $DEPLOY_REF..."
  git checkout --detach "refs/tags/$DEPLOY_REF"
elif git rev-parse -q --verify "refs/remotes/origin/$DEPLOY_REF" >/dev/null; then
  echo "Checking out branch $DEPLOY_REF..."
  git checkout "$DEPLOY_REF"
  git pull --ff-only origin "$DEPLOY_REF"
else
  echo "Error: DEPLOY_REF '$DEPLOY_REF' not found in origin branches or tags"
  exit 1
fi

echo "Installing node dependencies..."
export HUSKY=0
npm ci

echo "Building frontend..."
npm run build

echo "Validating compose configuration..."
docker compose -f docker-compose.prod.yml --env-file "$ENV_FILE" config >/dev/null

echo "Deploying containers..."
docker compose -f docker-compose.prod.yml --env-file "$ENV_FILE" up -d --build --remove-orphans

echo "Waiting for API health..."
for _ in $(seq 1 30); do
  if curl -fsS -H "Host: ${DOMAIN:-superfpl.com}" http://127.0.0.1/api/health >/dev/null; then
    echo "Healthy"
    exit 0
  fi
  sleep 2
done

echo "Health check failed"
docker compose -f docker-compose.prod.yml --env-file "$ENV_FILE" ps
exit 1
