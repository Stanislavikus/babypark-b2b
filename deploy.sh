#!/bin/bash
set -euo pipefail

APP_DIR="/var/www/babypark-b2b"
cd "$APP_DIR"

MAINTENANCE_ACTIVE=0

on_deploy_error() {
    local exit_code=$?

    if [[ "$MAINTENANCE_ACTIVE" -eq 1 ]]; then
        echo "DEPLOYMENT FAILED after maintenance mode was enabled. Manual recovery is required." >&2
        echo "Application remains in maintenance mode." >&2
    else
        echo "DEPLOYMENT FAILED before maintenance mode. Manual recovery may be required." >&2
    fi

    echo "Current Git HEAD: $(git rev-parse HEAD 2>/dev/null || echo 'unknown')" >&2
    echo "Authorized DEPLOY_SHA: ${DEPLOY_SHA:-not set}" >&2
    exit "$exit_code"
}

if [[ -z "${DEPLOY_SHA:-}" ]]; then
    echo "DEPLOY_SHA is required." >&2
    exit 1
fi

DEPLOY_SHA="$(printf '%s' "$DEPLOY_SHA" | tr '[:upper:]' '[:lower:]')"

if ! [[ "$DEPLOY_SHA" =~ ^[0-9a-f]{40}$ ]]; then
    echo "DEPLOY_SHA must be a full Git commit SHA." >&2
    exit 1
fi

CURRENT_BRANCH="$(git branch --show-current)"
if [[ "$CURRENT_BRANCH" != "develop" ]]; then
    echo "Server must be on develop branch (current: ${CURRENT_BRANCH})." >&2
    exit 1
fi

if ! git diff --quiet HEAD --; then
    echo "Tracked working tree modifications detected." >&2
    exit 1
fi

if ! git diff --cached --quiet; then
    echo "Staged modifications detected." >&2
    exit 1
fi

git fetch origin develop

ORIGIN_DEVELOP="$(git rev-parse origin/develop)"

if [[ "$ORIGIN_DEVELOP" != "$DEPLOY_SHA" ]]; then
    echo "origin/develop (${ORIGIN_DEVELOP}) does not match authorized DEPLOY_SHA (${DEPLOY_SHA})." >&2
    exit 1
fi

if ! git merge-base --is-ancestor HEAD "$DEPLOY_SHA"; then
    echo "Current HEAD cannot fast-forward to authorized DEPLOY_SHA." >&2
    exit 1
fi

php artisan down
MAINTENANCE_ACTIVE=1
trap on_deploy_error ERR

git merge --ff-only "$DEPLOY_SHA"

composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart

echo "Authorized DEPLOY_SHA: ${DEPLOY_SHA}"
echo "Deployed Git HEAD: $(git rev-parse HEAD)"
echo "Branch: $(git branch --show-current)"
echo "Migrations: completed successfully"
echo "Queue restart: completed"
echo "Deployment completed."

php artisan up
