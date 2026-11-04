#!/bin/bash
set -euo pipefail

APP_DIR="/var/www/babypark-b2b"
cd "$APP_DIR"

MAINTENANCE_ACTIVE=0

on_deploy_error() {
    local exit_code=$?

    echo "ERROR: deployment did not complete." >&2
    echo "Maintenance state requires manual verification/recovery." >&2
    echo "No automatic rollback or recovery was attempted." >&2
    echo "Current HEAD: $(git rev-parse HEAD 2>/dev/null || echo 'unknown')" >&2
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

# Fail closed BEFORE maintenance if GitHub-hosted CI has not delivered the
# immutable frontend output from this exact authorized commit.
EXPECTED_ARCHIVE="/tmp/babypark-build-${DEPLOY_SHA}.tar.gz"
if [[ "${DEPLOY_BUILD_ARCHIVE:-}" != "$EXPECTED_ARCHIVE" || ! -f "$EXPECTED_ARCHIVE" ]]; then
    echo "A prebuilt Vite archive for authorized DEPLOY_SHA is required." >&2
    exit 1
fi
if ! [[ "${DEPLOY_BUILD_SHA256:-}" =~ ^[0-9a-f]{64}$ ]]; then
    echo "A valid expected SHA256 for the CI archive is required." >&2
    exit 1
fi
ACTUAL_ARCHIVE_SHA256="$(sha256sum "$EXPECTED_ARCHIVE" | cut -d ' ' -f 1)"
if [[ "$ACTUAL_ARCHIVE_SHA256" != "$DEPLOY_BUILD_SHA256" ]]; then
    echo "Prebuilt archive SHA256 does not match the CI-provided digest." >&2
    exit 1
fi

STAGED_BUILD_DIR="$(mktemp -d /tmp/babypark-build-verified.XXXXXXXX)"
tar -xzf "$EXPECTED_ARCHIVE" -C "$STAGED_BUILD_DIR"
if [[ ! -s "$STAGED_BUILD_DIR/build/manifest.json" \
   || ! -s "$STAGED_BUILD_DIR/build/.release-sha" \
   || "$(cat "$STAGED_BUILD_DIR/build/.release-sha")" != "$DEPLOY_SHA" ]]; then
    echo "Prebuilt Vite bundle is invalid or belongs to another commit." >&2
    exit 1
fi
if ! php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$STAGED_BUILD_DIR/build/manifest.json"; then
    echo "Prebuilt Vite manifest is not valid JSON." >&2
    exit 1
fi
if [[ ! -d public/build ]]; then
    echo "Existing public/build is missing; refusing an unreviewed deploy." >&2
    exit 1
fi

php artisan down
MAINTENANCE_ACTIVE=1
trap on_deploy_error ERR

git merge --ff-only "$DEPLOY_SHA"

composer install --no-dev --optimize-autoloader
# The compiled Vite bundle was created on the GitHub-hosted runner.
# On-host npm ci / vite build caused an outage on this 2 GiB pilot droplet.
mv public/build "$STAGED_BUILD_DIR/previous-build"
mv "$STAGED_BUILD_DIR/build" public/build
php artisan migrate --force
php artisan db:seed --class=WorkspaceRbacPermissionSeeder --force
php artisan db:seed --class=CanonicalActiveFieldSeeder --force
if [[ -e public/storage && ! -L public/storage ]]; then
    echo "public/storage exists but is not a symlink; refusing to replace it automatically." >&2
    exit 1
fi
php artisan storage:link --force
php artisan optimize:clear
php artisan queue:restart
php artisan up
MAINTENANCE_ACTIVE=0
trap - ERR
rm -rf "$STAGED_BUILD_DIR"
rm -f "$EXPECTED_ARCHIVE"

echo "Authorized DEPLOY_SHA: ${DEPLOY_SHA}"
echo "Deployed Git HEAD: $(git rev-parse HEAD)"
echo "Branch: $(git branch --show-current)"
echo "Migrations: completed successfully"
echo "Queue restart: completed"
echo "Deployment completed."
