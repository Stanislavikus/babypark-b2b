#!/usr/bin/env bash
# =============================================================================
# Cursor Cloud Agent — Environment Setup Script
# Project: b2b.babypark.ua  (Laravel 11, MySQL 8, Redis 7, PHP 8.3)
#
# USAGE:  Copy this script into the "Startup script" field in
#         Cursor Cloud → Environment Settings, or run it manually once
#         after provisioning a fresh agent VM.
#
# ROOT CAUSE OF ORIGINAL FAILURE
# -----------------------------------------------------------------------
# The container ships with /usr/sbin/policy-rc.d set to "exit 101", which
# blocks ALL service control operations (start, stop, reload) issued via
# invoke-rc.d.  MySQL's post-install script (mysql-server-8.0.postinst):
#   1. Starts a temporary mysqld directly (succeeds)
#   2. Calls `invoke-rc.d stop mysql` to shut it down  ← BLOCKED by policy-rc.d
#   3. Cannot kill the process through normal channels
#   4. Returns exit 1 → dpkg marks the package as broken
#
# Additionally, /var/lib/mysql lives on an overlayfs mount.  MySQL 8's
# InnoDB redo log uses O_DIRECT/fallocate calls that overlayfs rejects
# with errno 22 (EINVAL), so even a clean package install can't start
# mysqld against that directory.
#
# FIX STRATEGY
# -----------------------------------------------------------------------
# 1. Temporarily swap policy-rc.d to allow "stop" before installing MySQL
# 2. Add innodb_use_native_aio=0 + innodb_flush_method=fsync to MySQL cfg
# 3. Initialize a fresh MySQL datadir on /dev/shm (true tmpfs — not overlayfs)
# 4. Start MySQL from /dev/shm/mysql-data; it avoids the O_DIRECT issue
# 5. Restore policy-rc.d and install PHP sqlite3 fallback driver
# =============================================================================

set -euo pipefail

log() { echo "[cloud-setup] $*"; }

# ---------------------------------------------------------------------------
# 0. Preconditions
# ---------------------------------------------------------------------------
log "Running as: $(id)"
export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------------------
# 1. Fix policy-rc.d so MySQL post-install can call invoke-rc.d stop/restart
#    without being blocked.  We allow stop/restart but still deny start so
#    services don't auto-launch during apt upgrades.
# ---------------------------------------------------------------------------
log "Patching policy-rc.d to allow stop/restart..."
sudo tee /usr/sbin/policy-rc.d > /dev/null << 'POLICY'
#!/bin/sh
# Container policy: allow stop and restart, deny start.
# This lets dpkg post-install scripts shut down temporary processes while
# still preventing services from auto-starting during package installs.
case "$1" in
  stop|restart|force-reload) exit 0 ;;
  *)                         exit 101 ;;
esac
POLICY
sudo chmod +x /usr/sbin/policy-rc.d

# ---------------------------------------------------------------------------
# 2. Install MySQL 8 and Redis (now the post-install can stop mysqld cleanly)
# ---------------------------------------------------------------------------
log "Installing MySQL 8 and Redis..."
sudo apt-get update -qq
sudo apt-get install -y -qq mysql-server redis-server php8.3-sqlite3

log "Restoring policy-rc.d (deny all starts)..."
sudo tee /usr/sbin/policy-rc.d > /dev/null << 'POLICY'
#!/bin/sh
exit 101
POLICY
sudo chmod +x /usr/sbin/policy-rc.d

# ---------------------------------------------------------------------------
# 3. Write MySQL config that disables O_DIRECT (needed for overlayfs)
# ---------------------------------------------------------------------------
log "Writing MySQL container-safe config..."
sudo tee /etc/mysql/conf.d/container.cnf > /dev/null << 'CNF'
[mysqld]
innodb_use_native_aio     = 0
innodb_flush_method       = fsync
skip_log_bin              = 1
character-set-server      = utf8mb4
collation-server          = utf8mb4_unicode_ci
CNF

# ---------------------------------------------------------------------------
# 4. Initialize a fresh MySQL datadir on /dev/shm (real tmpfs, not overlayfs)
#    /dev/shm is always available in Cursor Cloud containers and is NOT on the
#    overlay filesystem, so InnoDB O_DIRECT restrictions don't apply.
# ---------------------------------------------------------------------------
DATADIR=/dev/shm/mysql-data
SOCKET=/tmp/mysql.sock
PORT=3306

if [ ! -d "$DATADIR/mysql" ]; then
    log "Initializing MySQL datadir at $DATADIR..."
    sudo mkdir -p "$DATADIR"
    sudo chown mysql:mysql "$DATADIR"
    sudo -u mysql mysqld \
        --initialize-insecure \
        --datadir="$DATADIR" \
        --innodb-use-native-aio=0 \
        --innodb-flush-method=fsync \
        2>/tmp/mysql-init.log
    log "Initialization done."
else
    log "MySQL datadir $DATADIR already exists, skipping --initialize-insecure."
fi

# ---------------------------------------------------------------------------
# 5. Start MySQL
# ---------------------------------------------------------------------------
if ! mysqladmin --socket="$SOCKET" ping --silent 2>/dev/null; then
    log "Starting MySQL on socket $SOCKET, port $PORT..."
    SESSION="mysql-server"
    tmux new-session -d -s "$SESSION" -- bash -c "
        sudo -u mysql mysqld \
            --datadir=$DATADIR \
            --socket=$SOCKET \
            --port=$PORT \
            --innodb-use-native-aio=0 \
            --innodb-flush-method=fsync \
            --skip-log-bin \
            2>&1 | tee /tmp/mysqld.log
    " 2>/dev/null || true

    # Wait up to 30 s for MySQL to become ready
    for i in $(seq 1 30); do
        if mysqladmin --socket="$SOCKET" ping --silent 2>/dev/null; then
            log "MySQL is ready (waited ${i}s)."
            break
        fi
        sleep 1
    done
fi

if ! mysqladmin --socket="$SOCKET" ping --silent 2>/dev/null; then
    log "ERROR: MySQL did not start. Check /tmp/mysqld.log"
    exit 1
fi

# ---------------------------------------------------------------------------
# 6. Create application database and user
# ---------------------------------------------------------------------------
log "Creating database and user..."
mysql --socket="$SOCKET" -u root << 'SQL'
CREATE DATABASE IF NOT EXISTS babypark_b2b
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'babypark'@'localhost' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON babypark_b2b.* TO 'babypark'@'localhost';

CREATE USER IF NOT EXISTS 'babypark'@'127.0.0.1' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON babypark_b2b.* TO 'babypark'@'127.0.0.1';

FLUSH PRIVILEGES;
SQL
log "Database ready."

# ---------------------------------------------------------------------------
# 7. Start Redis
# ---------------------------------------------------------------------------
if ! redis-cli ping > /dev/null 2>&1; then
    log "Starting Redis..."
    redis-server --daemonize yes --logfile /tmp/redis.log
    sleep 1
    if redis-cli ping > /dev/null 2>&1; then
        log "Redis is ready."
    else
        log "WARNING: Redis did not start. Check /tmp/redis.log"
    fi
else
    log "Redis already running."
fi

# ---------------------------------------------------------------------------
# 8. Laravel application bootstrap
# ---------------------------------------------------------------------------
cd /workspace

if [ ! -f ".env" ]; then
    log "Copying .env.example → .env..."
    cp .env.example .env
fi

log "Installing Composer dependencies..."
composer install --no-interaction --quiet 2>&1 | tail -3

log "Generating APP_KEY..."
php artisan key:generate --no-interaction

# Point Laravel at the socket-based MySQL connection
php artisan config:clear

log "Running migrations..."
php artisan migrate --force --no-interaction

log "Publishing Filament assets..."
php artisan filament:assets --no-interaction 2>/dev/null || true

log "Seeding database..."
php artisan db:seed --force --no-interaction

log "Creating storage symlink..."
php artisan storage:link --no-interaction 2>/dev/null || true

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
log "=== Setup complete ==="
log "MySQL  : socket $SOCKET  |  database babypark_b2b  |  user babypark / secret"
log "Redis  : 127.0.0.1:6379"
log "Admin  : php artisan serve  →  http://localhost:8000/admin"
log "         login: admin@babypark.ua / password"
