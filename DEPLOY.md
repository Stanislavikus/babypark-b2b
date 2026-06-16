# Deployment Guide — DigitalOcean

This guide covers deploying **BabyPark B2B** on DigitalOcean using a single Droplet with Docker Compose.  
For managed databases and production hardening, see the sections below.

---

## Architecture Overview

```
Internet → Nginx (port 80/443) → PHP-FPM (Laravel)
                                  ├── MySQL 8  (Docker volume)
                                  ├── Redis 7  (Docker volume)
                                  ├── Queue worker (artisan queue:work)
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
