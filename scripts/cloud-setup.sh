#!/usr/bin/env bash
# =============================================================================
# Cursor Cloud Agent — Environment Setup Script
# Project: b2b.babypark.ua  (Laravel 13, Filament 5, Livewire 4, PHP 8.3, SQLite dev / MySQL 8 prod)
#
# USAGE:  Paste this script into Cursor Cloud → Environment Settings →
#         "Startup script" field at cursor.com/onboard, OR run manually.
#
# ROOT CAUSE OF ORIGINAL STARTUP FAILURE (mysql-server apt install)
# -----------------------------------------------------------------------
# Two compounding bugs prevented MySQL from installing/starting:
#
# BUG 1 — policy-rc.d blocks invoke-rc.d stop
#   /usr/sbin/policy-rc.d returns "exit 101" for ALL service operations,
#   including "stop".  MySQL's post-install script (mysql-server-8.0.postinst)
#   starts a temporary mysqld, then calls `invoke-rc.d stop mysql` to shut it
#   down → BLOCKED → script exits 1 → dpkg marks mysql-server-8.0 broken.
#
#   Evidence from /var/log/apt/term.log:
#     invoke-rc.d: policy-rc.d denied execution of stop.
#     mysqld is running as pid 17130
#     Error: Unable to shut down server with process id 17130
#     dpkg: error processing package mysql-server-8.0 (--configure):
#      installed mysql-server-8.0.postinst returned error exit status 1
#
# BUG 2 — InnoDB cannot use O_DIRECT on overlayfs (errno 22 EINVAL)
#   /var/lib/mysql is on the container's overlayfs root. MySQL 8 InnoDB
#   redo log creation uses O_DIRECT / fallocate.  overlayfs rejects these
#   with errno 22 (EINVAL), killing mysqld immediately on every start attempt.
#   Even with innodb_flush_method=fsync the close() syscall itself fails.
#   /dev/shm (tmpfs, 64 MB) is too small for a MySQL datadir (≥100 MB).
#
#   Confirmed: no flush-method setting (fsync, nosync, O_DSYNC) resolves this
#   in this container kernel.
#
# FIX STRATEGY
# -----------------------------------------------------------------------
# Use SQLite as the development database — it works perfectly on overlayfs,
# requires zero configuration, and supports the full Laravel migration set.
# For production, use Docker + MySQL 8 (see docker-compose.yml / DEPLOY.md).
#
# Additional steps:
#   - Install php8.3-sqlite3 (not in the base image)
#   - Patch policy-rc.d to allow stop/restart (needed for any future apt installs)
#   - Start Redis (installed but not auto-started)
# =============================================================================

set -euo pipefail

log() { echo "[cloud-setup] $*"; }

export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------------------
# 1. Patch policy-rc.d: allow stop/restart but still deny auto-start
#    This fixes the MySQL post-install bug and is safe for other packages.
# ---------------------------------------------------------------------------
log "Patching /usr/sbin/policy-rc.d..."
sudo tee /usr/sbin/policy-rc.d > /dev/null << 'POLICY'
#!/bin/sh
# Allow stop/restart (needed for post-install scripts) but deny auto-start.
case "$1" in
  stop|restart|force-reload) exit 0 ;;
  *)                         exit 101 ;;
esac
POLICY
sudo chmod +x /usr/sbin/policy-rc.d

# ---------------------------------------------------------------------------
# 2. Install PHP SQLite driver (not included in the base PHP install)
# ---------------------------------------------------------------------------
if ! php -m 2>/dev/null | grep -q pdo_sqlite; then
    log "Installing php8.3-sqlite3..."
    sudo apt-get install -y -qq php8.3-sqlite3
fi
log "SQLite driver: OK"

# ---------------------------------------------------------------------------
# 3. Start Redis (installed but disabled by policy-rc.d on boot)
# ---------------------------------------------------------------------------
if ! redis-cli ping > /dev/null 2>&1; then
    log "Starting Redis..."
    redis-server --daemonize yes --logfile /tmp/redis.log
    sleep 1
fi
redis-cli ping > /dev/null 2>&1 && log "Redis: OK" || log "WARNING: Redis did not start"

# ---------------------------------------------------------------------------
# 4. Bootstrap the Laravel application
# ---------------------------------------------------------------------------
cd /workspace

if [ ! -f ".env" ]; then
    log "Creating .env from .env.example..."
    cp .env.example .env
    # Force SQLite for this dev environment
    sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
    sed -i '/^DB_HOST=/d; /^DB_PORT=/d; /^DB_USERNAME=/d; /^DB_PASSWORD=/d; /^DB_SOCKET=/d' .env
fi

if [ ! -f "database/database.sqlite" ]; then
    log "Creating SQLite database file..."
    touch database/database.sqlite
fi

if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    log "Generating application key..."
    php artisan key:generate --no-interaction
fi

log "Clearing caches..."
php artisan optimize:clear --no-interaction > /dev/null 2>&1 || true

log "Running migrations..."
php artisan migrate --force --no-interaction

log "Publishing Filament assets..."
php artisan filament:assets --no-interaction > /dev/null 2>&1 || true

log "Creating storage symlink..."
php artisan storage:link --no-interaction > /dev/null 2>&1 || true

log "Seeding database..."
php artisan db:seed --force --no-interaction

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
log "=== Setup complete ==="
log "Database : SQLite  →  database/database.sqlite"
log "Redis    : 127.0.0.1:6379 (if started)"
log "Run      : php artisan serve --host=0.0.0.0 --port=8000  (use tmux)"
log "Admin    : http://localhost:8000/admin"
log "Cabinet  : http://localhost:8000/login"
log "Credentials: admin@babypark.ua / password  |  contractor: dytiachyi-svit / password"
