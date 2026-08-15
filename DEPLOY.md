# Deployment Guide — DigitalOcean

This repository documents **two deployment families**. Read the section that matches
your environment — they are not interchangeable.

| Section | When to use |
|---------|-------------|
| **[Current Supervisor-based pilot deployment](#current-supervisor-based-pilot-deployment)** | **Active today** for the Babypark pilot: bare Ubuntu host, native PHP, MySQL, `database` queue driver, Supervisor-managed default worker (`babypark-queue`) and dedicated connector worker (`babypark-connector-queue`), repo-root `deploy.sh`. Connector Discovery is production-operational on the Babypark pilot (worker verified `RUNNING` and one successful manual UI Discovery completed 2026-08-15). |
| **[Docker Compose reference deployment](#docker-compose-reference-deployment)** | Local full-stack parity and a possible future containerized layout. **Not** the current pilot's actual production setup (user-confirmed: production host does not run `docker compose`). |

---

## Current Supervisor-based pilot deployment

The Babypark pilot runs on a bare host (not Docker Compose). Two separate
Supervisor queue workers are required and are both installed on the pilot host:
`babypark-queue` (default lane) and `babypark-connector-queue` (connector lane).
The default worker was verified `RUNNING` on 2026-07-31 (reconfirmed after
GAP-026B cutover 2026-08-14). The dedicated connector worker was installed and
verified `RUNNING` on 2026-08-15; Connector Discovery is production-operational
on the Babypark pilot. Deploys use the repo-root `deploy.sh` script run directly
on the server.

### Architecture overview (pilot)

```
Internet → Nginx → PHP-FPM (Laravel, /var/www/babypark-b2b)
                    ├── MySQL 8 (native or managed)
                    ├── Cache / locks (`database` store — verified on pilot host 2026-07-31)
                    ├── Supervisor: babypark-queue (default connection, short jobs)
                    └── Supervisor: babypark-connector-queue (database_connectors / connectors lane — verified RUNNING 2026-08-15)
```

### Application path

Typical checkout path (adjust if your host differs):

```text
/var/www/babypark-b2b
```

### Deploy (pilot)

On the server, from the application directory:

```bash
cd /var/www/babypark-b2b
./deploy.sh
```

`deploy.sh` runs `git pull`, `composer install --no-dev`, `npm ci`, `npm run build`,
`php artisan optimize:clear`, and **`php artisan queue:restart`**.

**`queue:restart` dependencies (must be true on the host):**

1. **Shared cache store** — restart signals are delivered through the cache layer.
   The pilot host uses the `database` cache/lock store (`cache` + `cache_locks`
   tables), verified on 2026-07-31 via `php artisan config:show cache`.
2. **Supervisor `autorestart=true`** — each worker must exit gracefully after
   `queue:restart` and be restarted by Supervisor (`babypark-queue` and
   `babypark-connector-queue`).

### Queue workers (pilot)

Two separate workers are required for connector discovery execution — they must
never be merged into one process. On the Babypark pilot host both workers are
installed and verified `RUNNING` (connector worker activation completed
2026-08-15).

| Supervisor program | Status | Queue connection | Queue name | Worker command |
|--------------------|--------|------------------|------------|----------------|
| `babypark-queue` | **RUNNING** (verified 2026-08-15) | `database` (default) | `default` | *(read from `/etc/supervisor/conf.d/babypark-queue.conf` — illustrative only: `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`)* |
| `babypark-connector-queue` | **RUNNING** (verified 2026-08-15) | `database_connectors` | `connectors` | `/usr/bin/php /var/www/babypark-b2b/artisan queue:work database_connectors --queue=connectors --sleep=3 --tries=3 --timeout=900 --max-time=3600` |

Connection-check jobs stay on the **default** lane (45s job timeout, 90s
`retry_after`). Discovery jobs use the **connector** lane (900s job
timeout, 1200s `retry_after`). Both share the same account-level
`WithoutOverlapping` lock key via the cache store.

### Livewire 4 endpoint prefix (GAP-024 PR4)

Livewire 4 serves update/upload endpoints under a hashed prefix derived from
`APP_KEY`, for example `/livewire-{hash}/update` rather than the fixed
`/livewire/update` path used in Livewire 3.

- The hash depends on `APP_KEY`; rotating the key changes the prefix.
- External WAF, CDN, or reverse-proxy allowlists must not assume the old fixed
  `/livewire/*` paths.
- The repository Nginx config uses generic front-controller routing and needs no
  functional change for this behavior; verify any **host-level** rules outside the
  repo if Livewire requests fail after upgrade.

Ensure the `pcntl` PHP extension is installed (`php -m | grep -i '^pcntl$'`).

### Verified current pilot state (post-GAP-024 PR4, 2026-08-09)

Read-only verification on the pilot host after the GAP-024 framework migration
programme (PR1–PR4). These facts are recorded here so repository tooling and CI
assumptions align with the live deployment baseline.

| Runtime | Verified value |
|---------|----------------|
| PHP CLI | 8.3.6 |
| PHP-FPM | 8.3.6 |
| Supervisor queue worker PHP binary | `/usr/bin/php8.3` |
| `pcntl` | present |
| Node.js | v22.22.2 |
| npm | 10.9.7 |

The main Supervisor queue worker (`babypark-queue`) was confirmed running.
`babypark-connector-queue` was verified absent on the pilot host on 2026-08-14
(historical) and installed/verified `RUNNING` on 2026-08-15 — see
[Connector-worker production activation](#connector-worker-production-activation-completed-2026-08-15).

A separate smoke checkout exists at `/var/www/babypark-b2b-smoke`, synchronized
to merged `develop` at `41dbb97094df13df93e72e3eaab3a4c46976fc34`, with PHPUnit
dev tooling and `pdo_sqlite` / `sqlite3` available for CLI smoke runs.

### Verified current pilot state (2026-07-31)

Host-prerequisite verification was completed on 2026-07-31 using read-only commands
on the pilot host.

**Active production cache store** (`php artisan config:show cache`):

- default: `database`
- `stores.database.connection`: `null` (→ default DB connection)
- `stores.database.lock_connection`: `null` (→ same DB connection, per
  `Illuminate\Cache\CacheManager::createDatabaseDriver` in installed
  `laravel/framework` v13.24.0: `$config['lock_connection'] ?? $config['connection'] ?? null`)
- `stores.database.lock_table`: `null` (→ falls back to `cache_locks`, per the same
  source: `$config['lock_table'] ?? 'cache_locks'`)

**Cache tables confirmed present:**

- `cache`: columns `key` (varchar 255), `value` (mediumtext), `expiration` (int);
  primary key on `key`
- `cache_locks`: columns `key` (varchar 255), `owner` (varchar 255),
  `expiration` (int); primary key on `key`

**Existing Supervisor worker:**

- `babypark-queue` registered as `babypark-queue:babypark-queue_00` (due to
  `process_name` templating), confirmed `RUNNING`
- Supervisor `user`: `root` (from existing `babypark-queue.conf`)

**PHP path:**

- `command -v php` on the host resolves to `/usr/bin/php`
- The live `babypark-queue.conf` currently contains
  `command=php /var/www/babypark-b2b/artisan queue:work ...` (bare `php`, resolved
  via `PATH` at runtime), **not** the absolute path
- The live `babypark-connector-queue.conf` uses the verified absolute path
  `/usr/bin/php` in its `command=` line (installed 2026-08-15)

**`pcntl`:** installed (`php -m | grep -i '^pcntl$'` confirms `pcntl`)

### Connector-worker production activation (completed 2026-08-15)

On **2026-08-15** the Babypark pilot completed permanent `babypark-connector-queue`
Supervisor activation and verified Connector Discovery end-to-end in production.

**Worker installation and lane verification**

- installed Supervisor program `babypark-connector-queue` and confirmed `RUNNING`;
- verified dedicated command:
  `/usr/bin/php /var/www/babypark-b2b/artisan queue:work database_connectors --queue=connectors --sleep=3 --tries=3 --timeout=900 --max-time=3600`;
- verified production config: `APP_ENV=production`, database `babypark_b2b`,
  connector queue `connectors`, `database_connectors.retry_after=1200`;
- verified `babypark-queue` remains separately `RUNNING` on the default lane;
- verified `php artisan queue:restart` caused both Supervisor workers to exit and
  restart successfully with new PIDs (`autorestart=true`).

**Manual Discovery enablement and production smoke**

- enabled `CONNECTOR_DISCOVERY_MANUAL_TRIGGER_ENABLED=true` only after worker
  verification;
- executed one real merchant UI manual Discovery (`Отримати поля`) against Adobe
  Commerce in production;
- persisted discovery evidence:
  - `RUN=a281b181-f478-4b0b-8c7d-295c26265020`
  - `TRIGGER=manual`
  - `STATUS=succeeded`
  - `ATTEMPTS=1`
  - `SNAPSHOT=a281b185-7618-4ce1-9c48-9ba5c8c87fca`
  - `ERROR=NULL`
  - `CONNECTOR_JOBS=0`
  - `FAILED_JOBS=0`
- dedicated worker log recorded:
  `ConnectorDiscoveryRunJob ... RUNNING` then `ConnectorDiscoveryRunJob ... 832.26ms DONE`.

**Historical:** on 2026-08-14 the dedicated worker was verified absent before
installation. Connector Discovery is now production-operational on the Babypark
pilot.

**Next repository task:** Task **4C-1c-2b** (Layer B mapping UI) — authorization
prerequisite satisfied (GAP-026B production cutover 2026-08-14).

### GAP-026B one-time Workspace RBAC cutover

> **Babypark pilot status:** one-time GAP-026B maintenance-window cutover **completed successfully on 2026-08-14** against commit `fb2c5a7a3f8a521a2bfca7583e57d1ae83e95bc9`.
> Post-cutover: 7 canonical permissions; 1 default workspace; 1 effective
> `manage_workspace_access` holder; 0 legacy Spatie assignments; maintenance mode OFF;
> `babypark-queue` RUNNING after restart. The procedure below remains the operational
> runbook for other environments.

**Ordinary recurring deployment** (`./deploy.sh`) is **not** sufficient for the one-time
GAP-026B workspace RBAC authority cutover and must **not** be used to expose GAP-026B-2
authority-switching code to merchant traffic before cutover completion.

Current `deploy.sh` only pulls, builds assets, clears cache, and restarts the queue.
It does **not** run migrations, RBAC catalogue seeding, legacy backfill, anti-lockout
validation, or cutover smoke checks. Do **not** silently turn every future deploy into
a backfill attempt.

**Repository merge ≠ production cutover.** Merging GAP-026B implementation into
`develop` does not by itself activate workspace-permission authority in production.
Activation requires the controlled one-time sequence documented in
`docs/03-DOMAIN_MODEL.md` → **Workspace RBAC authority cutover (Resolved —
GAP-026B-0, 2026-08-13)**.

**Slice ownership (frozen)**

- **CHECK-ONLY** — available from GAP-026B-1 (diagnostics only; no RBAC
  assignment/materialization).
- **EXECUTE** — ships only with GAP-026B-2 together with authority-switching runtime.
  Production legacy backfill is structurally unavailable in a B-1-only release.

**First B-2 production deployment = maintenance-window cutover**

The first production deployment containing GAP-026B-2 authority-switching code must be
this maintenance-window cutover deployment — not an ordinary `./deploy.sh` followed by
later EXECUTE. Merchant traffic remains blocked from B-2 deployment through successful
EXECUTE + anti-lockout validation + smoke verification. Pre-EXECUTE B-2 authority must
never fall back to legacy roles.

**One-time maintenance-window sequence (summary)**

1. verified DB backup / snapshot;
2. maintenance mode / block merchant writes;
3. deploy the **complete** approved GAP-026B-1 + GAP-026B-2 authority-changing cutover
   runtime while traffic is already blocked;
4. run pending migrations;
5. seed canonical `workspace_permissions` catalogue (`WorkspaceRbacPermissionSeeder`);
6. run guarded RBAC cutover **CHECK-ONLY** via `php artisan workspace-rbac:cutover-check` (diagnostic/read-only; no assignments);
7. if safe: **EXECUTE** deterministic legacy backfill (B-2 only);
8. fresh anti-lockout validation;
9. focused authorization/cutover smoke checks;
10. clear/reload application state; restart queue workers;
11. resume traffic.

Failure at any preflight/smoke step: remain blocked for merchant writes; no partial
authority fallback; no role-based Connector/Tax/Mapping fallback; reconcile while
traffic remains blocked — not via authority fallback.

**Cutover command contract**

Implementation must provide a guarded one-time command/service with:

- **CHECK-ONLY (B-1)** — `php artisan workspace-rbac:cutover-check`; diagnostic/read-only;
  no RBAC assignments/materialization; may run before maintenance; may ship in a
  B-1-only release. EXECUTE remains unavailable until GAP-026B-2.
- **EXECUTE (B-2)** — `php artisan workspace-rbac:cutover-execute`; requires Laravel
  maintenance mode; re-runs `WorkspaceRbacLegacyPreflight::assertSafe()`; invokes
  `WorkspaceRbacLegacyBackfill::execute()` with deterministic merchant-safe bootstrap
  display names; post-backfill `WorkspaceAccessEffectiveHolderQuery` anti-lockout
  validation; fails non-zero on unsafe state or zero effective holders.

Do **not** introduce persistent activation flags, marker tables, legacy/new authority
selectors, or dual-authority policy modes to enforce this boundary.

Implemented CHECK-ONLY command: `php artisan workspace-rbac:cutover-check`. This command
wraps `WorkspaceRbacLegacyPreflight::evaluate()` only; it never invokes
`WorkspaceRbacLegacyBackfill::execute()`.

Implemented EXECUTE command: `php artisan workspace-rbac:cutover-execute`. This command
refuses outside maintenance mode, runs preflight via `assertSafe()`, invokes backfill,
then validates effective `manage_workspace_access` holders. It is separate from CHECK
and exposes no `--apply` flag.

**Queue worker quiescence**

Because queue workers are long-running, verify no concurrent affected mutation during
cutover. Maintenance mode plus worker quiescence/restart must be verified against the
actual pilot Supervisor configuration (`babypark-queue` today). After cutover steps
complete, ensure `php artisan queue:restart` (or Supervisor restart) so workers reload
authorization state.

---

## Docker Compose reference deployment

> **Reference only** — illustrates a containerized layout for local development and
> possible future use. **Not** the Babypark pilot's current production mechanism.

This guide covers deploying **BabyPark B2B** on DigitalOcean using a single Droplet with Docker Compose.
For managed databases and production hardening, see the sections below.

### Architecture Overview

```
Internet → Nginx (port 80/443) → PHP-FPM (Laravel)
                                  ├── MySQL 8  (Docker volume)
                                  ├── Redis 7  (Docker volume)
                                  ├── Queue worker (artisan queue:work — default lane)
                                  ├── Connector queue worker (database_connectors / connectors lane)
                                  └── Scheduler (artisan schedule:run every 60s)
```

---

## 1. Provision the Droplet

### Recommended specs

| Tier | vCPU | RAM | Disk | Monthly cost |
|------|------|-----|------|-------------|
| Small (dev) | 2 | 4 GB | 80 GB SSD | ~$24 |
| Medium (prod) | 4 | 8 GB | 160 GB SSD | ~$48 |
| Large (scale) | 8 | 16 GB | 320 GB SSD | ~$96 |

### Create the Droplet

1. Log in to [cloud.digitalocean.com](https://cloud.digitalocean.com)
2. **Create** → **Droplets** → Choose:
   - **Image:** Ubuntu 24.04 LTS
   - **Datacenter:** Frankfurt (fra1) — closest to Ukraine
   - **Authentication:** SSH key (upload your public key)
   - **Hostname:** `b2b-babypark`
3. Optionally assign a **Floating IP** for stable DNS
4. Add the Droplet to a **VPC** if you plan to add managed DB later

---

## 2. Initial Server Setup

SSH into the server as root:

```bash
ssh root@YOUR_DROPLET_IP
```

### Create a deploy user

```bash
adduser deploy
usermod -aG sudo deploy
# Copy your SSH key
mkdir -p /home/deploy/.ssh
cp ~/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
```

### Install Docker and Docker Compose

```bash
apt-get update && apt-get upgrade -y

# Install Docker
curl -fsSL https://get.docker.com | sh
usermod -aG docker deploy

# Docker Compose v2 is bundled with Docker. Verify:
docker compose version
```

### Install other tools

```bash
apt-get install -y git nginx-extras certbot python3-certbot-nginx ufw fail2ban
```

### Configure firewall

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
ufw status
```

---

## 3. Set Up the Application

Switch to the deploy user:

```bash
su - deploy
```

### Clone the repository

```bash
cd /var/www
git clone git@github.com:YOUR_ORG/b2b.babypark.ua.git babypark
cd babypark
```

### Create production `.env`

```bash
cp .env.example .env
nano .env
```

Set these values for production:

```dotenv
APP_NAME="BabyPark B2B"
APP_ENV=production
APP_KEY=                         # will be generated below
APP_DEBUG=false
APP_URL=https://b2b.babypark.ua

DB_CONNECTION=mysql
DB_HOST=db                       # Docker service name
DB_PORT=3306
DB_DATABASE=babypark_b2b
DB_USERNAME=babypark
DB_PASSWORD=STRONG_PASSWORD_HERE
DB_ROOT_PASSWORD=STRONG_ROOT_PASSWORD_HERE

SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_HOST=redis                 # Docker service name
REDIS_PASSWORD=REDIS_PASSWORD_HERE
REDIS_PORT=6379

ONEC_BASE_URL=https://1c.babypark.ua/api/v1
ONEC_TOKEN=YOUR_1C_API_TOKEN_HERE
```

### Build and start services

```bash
# Build the PHP image
docker compose build app

# Start all services
docker compose up -d

# Generate app key (first time only)
docker compose exec app php artisan key:generate

# Run migrations
docker compose exec app php artisan migrate --force

# Publish Filament assets
docker compose exec app php artisan filament:assets

# Create storage symlink
docker compose exec app php artisan storage:link

# Optimize for production
docker compose exec app php artisan optimize

# (Optional) Seed initial admin user
docker compose exec app php artisan db:seed --class=B2BSeeder --force
```

---

## 4. Configure SSL with Let's Encrypt

Point your domain `b2b.babypark.ua` DNS A record to the Droplet IP, then:

```bash
# Stop Docker's nginx temporarily (or configure Certbot standalone)
docker compose stop nginx

# Get certificate
certbot certonly --standalone -d b2b.babypark.ua

docker compose start nginx
```

Then update `docker/nginx/default.conf` to add HTTPS:

```nginx
server {
    listen 80;
    server_name b2b.babypark.ua;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name b2b.babypark.ua;
    root /var/www/html/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/b2b.babypark.ua/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/b2b.babypark.ua/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    client_max_body_size 64M;
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Mount the Let's Encrypt directory in `docker-compose.yml`:

```yaml
nginx:
  volumes:
    - /etc/letsencrypt:/etc/letsencrypt:ro
    - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    - .:/var/www/html
```

Add Certbot auto-renewal to cron:

```bash
echo "0 3 * * * certbot renew --quiet && docker compose -f /var/www/babypark/docker-compose.yml exec nginx nginx -s reload" | crontab -
```

---

## 5. Zero-Downtime Deployment Script

Create `/home/deploy/deploy.sh`:

```bash
#!/bin/bash
set -e

APP_DIR=/var/www/babypark

echo "==> Pulling latest code..."
cd $APP_DIR
git pull origin main

echo "==> Installing dependencies..."
docker compose exec -T app composer install --no-dev --optimize-autoloader

echo "==> Building assets..."
docker compose exec -T app npm ci && docker compose exec -T app npm run build

echo "==> Running migrations..."
docker compose exec -T app php artisan migrate --force

echo "==> Clearing and re-caching config..."
docker compose exec -T app php artisan optimize

echo "==> Restarting queue workers..."
docker compose restart queue scheduler

echo "==> Done! Deployed at $(date)"
```

```bash
chmod +x /home/deploy/deploy.sh
```

---

## 6. DigitalOcean Managed Database (recommended for production)

Instead of running MySQL in Docker, use **DO Managed MySQL** for automatic backups, failover, and monitoring.

1. **Create** → **Databases** → MySQL 8 → Frankfurt region
2. Choose plan: **Basic / 1 GB RAM** (~$15/month) for start
3. Add your Droplet to the trusted sources
4. Create a database `babypark_b2b` and user `babypark`
5. Update `.env`:
   ```dotenv
   DB_HOST=your-db-cluster.db.ondigitalocean.com
   DB_PORT=25060
   DB_DATABASE=babypark_b2b
   DB_USERNAME=babypark
   DB_PASSWORD=managed_db_password
   MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt
   ```
6. Remove the `db` service from `docker-compose.yml` — the app connects to DO's managed cluster

---

## 7. DigitalOcean Spaces (Object Storage)

For storing product images in production (instead of local disk):

1. **Create** → **Spaces** → Frankfurt (fra1) → Name: `babypark-b2b`
2. Generate **Spaces access keys** in API settings
3. Update `.env`:
   ```dotenv
   FILESYSTEM_DISK=s3
   AWS_ACCESS_KEY_ID=your_spaces_key
   AWS_SECRET_ACCESS_KEY=your_spaces_secret
   AWS_DEFAULT_REGION=fra1
   AWS_BUCKET=babypark-b2b
   AWS_ENDPOINT=https://fra1.digitaloceanspaces.com
   AWS_USE_PATH_STYLE_ENDPOINT=false
   ```

---

## 8. Monitoring and Backups

### Database backups

```bash
# Add to cron (runs daily at 2am, keeps 7 days)
0 2 * * * docker compose -f /var/www/babypark/docker-compose.yml exec -T db \
  mysqldump -u root -p${DB_ROOT_PASSWORD} babypark_b2b | \
  gzip > /backups/db_$(date +\%Y\%m\%d).sql.gz && \
  find /backups/ -name "db_*.sql.gz" -mtime +7 -delete
```

### DigitalOcean monitoring

Enable **Droplet Metrics** in the DO console for CPU/memory/disk alerts.

### Log access

```bash
# Application logs
docker compose logs app --tail=100 -f

# Nginx access logs
docker compose logs nginx --tail=100 -f

# Laravel specific
docker compose exec app tail -f storage/logs/laravel.log
```

---

## 9. Sync Schedule

The 1C synchronization runs automatically via the scheduler container:

| Sync type | Frequency | Artisan command |
|-----------|-----------|-----------------|
| Stocks | Every 15 min | `sync:from-onec --type=stocks` |
| Products | Every 30 min | `sync:from-onec --type=products` |
| Prices | Every 30 min | `sync:from-onec --type=prices` |
| Contractors | Every 30 min | `sync:from-onec --type=contractors` |
| Order statuses | Every 30 min | `sync:from-onec --type=statuses` |

Manual sync trigger:

```bash
docker compose exec app php artisan sync:from-onec --type=stocks
```

---

## 10. Quick Reference

| Action | Command |
|--------|---------|
| Start all | `docker compose up -d` |
| Stop all | `docker compose down` |
| View logs | `docker compose logs -f` |
| Run Artisan | `docker compose exec app php artisan ...` |
| Open MySQL | `docker compose exec db mysql -u babypark -p babypark_b2b` |
| Open Redis CLI | `docker compose exec redis redis-cli` |
| Deploy update | `./deploy.sh` |
| Clear cache | `docker compose exec app php artisan optimize:clear` |
| Seed DB | `docker compose exec app php artisan db:seed --force` |

### Default credentials (after seeding)

- **Admin panel:** `https://b2b.babypark.ua/admin`
- **Admin user:** `admin@babypark.ua` / `password` *(change immediately!)*
- **Test contractor logins:** `dytiachyi-svit`, `malyuk-plus`, `ivanenko` (password: `password`)

---

## Checklist before go-live

- [ ] `APP_DEBUG=false` in `.env`
- [ ] Strong, unique passwords for DB, Redis, 1C token
- [ ] SSL certificate issued and auto-renewal configured
- [ ] Admin password changed from default
- [ ] Firewall configured (UFW: only 22, 80, 443)
- [ ] Fail2ban enabled for SSH brute-force protection
- [ ] Daily DB backup cron configured
- [ ] DO Droplet monitoring alerts set up
- [ ] `ONEC_TOKEN` configured and sync tested
- [ ] DNS A record pointing to Droplet IP
