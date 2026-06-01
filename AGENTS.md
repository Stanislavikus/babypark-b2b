You are building a B2B wholesale cabinet for babypark.ua.

Project: b2b.babypark.ua
Stack: Laravel 11, Filament 3, Livewire 3, MySQL 8, Redis

## GOAL
Build a B2B cabinet for wholesale buyers with:
- Personal login per contractor
- Individual prices per contractor (read from 1C via API)
- Stock availability display
- Cart and order placement
- Payment delay and credit limit display
- Simple reservation system
- Filament admin panel for managers

## DATABASE STRUCTURE

### contractors
- id, onec_guid (unique), name, short_name
- edrpou, ipn
- manager_name, manager_phone
- login (unique), is_active
- payment_delay_days, credit_limit, current_debt
- synced_at, timestamps

### users
- id, contractor_id (foreign), name, email
- password, role (enum: admin, manager, warehouse, товарознавець, director, programmer)
- is_active, timestamps

### categories
- id, onec_guid, name, parent_id
- stock_display_threshold (default 10)
- timestamps

### products
- id, onec_guid (unique), sku (unique)
- barcode_ean, barcode_box
- name, category_id (foreign)
- brand, unit
- min_order_quantity (default 1), order_step (default 1)
- package_quantity, package_type
- units_per_box, boxes_per_pallet
- lead_time_days
- weight_netto, weight_brutto, volume_m3
- depth_mm, width_mm, height_mm
- description (text), images (json)
- rozetka_category_id, meta_title, meta_description
- is_active, synced_at, timestamps

### product_variants
- id, product_id (foreign), onec_guid (unique)
- sku (unique), barcode_ean
- attributes (json) — e.g. {"Колір": "Червоний", "Розмір": "XL"}
- is_active, synced_at, timestamps

### stocks
- id, variant_id (foreign)
- warehouse_name, quantity, reserved
- expected_date (nullable), expected_quantity (nullable)
- updated_at

### prices
- id, contractor_id (foreign), variant_id (foreign)
- price, price_with_vat, vat_rate (default 20)
- recommended_retail_price
- min_quantity (default 1), currency (default UAH)
- updated_at

### orders
- id, contractor_id (foreign), user_id (foreign)
- onec_guid (nullable), onec_number (nullable)
- status (enum: new, pending, confirmed, in_progress, shipped, delivered, cancelled)
- total, total_with_vat, currency (default UAH)
- comment, manager_comment
- needs_call (boolean default false)
- transmitted_at (nullable), timestamps

### order_items
- id, order_id (foreign), variant_id (foreign)
- sku, name (snapshot), attributes (json snapshot)
- quantity, price, price_with_vat, total
- manager_price (nullable — if manager overrides)
- timestamps

### reservations
- id, contractor_id (foreign), variant_id (foreign)
- quantity, status (enum: active, confirmed, cancelled, expired)
- expires_at, timestamps

### sync_logs
- id, type (products/prices/stocks/contractors/statuses)
- status (success/error), records_processed
- error_message (nullable), started_at, finished_at

## FILAMENT ADMIN PANEL

Create Filament resources for:
1. OrderResource — list with filters by status/date/contractor, view details, edit manager_comment, manager_price per item
2. ContractorResource — list, view details, credit info, last orders
3. ProductResource — list, edit description/images/meta fields
4. StockResource — read-only list by warehouse
5. ReservationResource — list, confirm, cancel
6. SyncLogResource — read-only, show last sync status per type
7. UserResource — manage staff users with roles
8. CategoryResource — edit stock_display_threshold per category

## B2B CLIENT CABINET (Livewire + Blade)

Pages:
1. /login — contractor login page
2. /dashboard — welcome, credit limit widget, recent orders
3. /catalog — product list with search, filters by category/brand, stock status badges
4. /catalog/{product} — product detail with variants, prices, stock
5. /cart — cart with quantity validation (min_order_quantity, order_step)
6. /orders — order history with statuses
7. /orders/{order} — order detail

## STOCK DISPLAY LOGIC

Use category stock_display_threshold:
- quantity > threshold → show "В наявності"
- 0 < quantity ≤ threshold → show "Залишилось N шт"
- quantity = 0 AND expected_date → show "Очікується DD.MM"
- quantity = 0 → show "Немає в наявності"

## SYNC SERVICE

Create app/Services/SyncService.php with methods:
- syncProducts() — GET /api/v1/products
- syncStocks() — GET /api/v1/stocks  
- syncPrices() — GET /api/v1/prices
- syncContractors() — GET /api/v1/contractors
- syncOrderStatuses() — GET /api/v1/orders/statuses
- sendOrder(Order $order) — POST /api/v1/orders

Create app/Console/Commands/SyncFromOneC.php
Schedule: stocks every 15 min, others every 30 min

Use upsert() for all sync operations.
Log every sync to sync_logs table.

## ONEC SERVICE

Create app/Services/OneCService.php:
- HTTP client with Bearer token auth
- Base URL from config/onec.php
- Methods matching SyncService calls
- Handle errors gracefully — log and continue

## AUTH

- Separate auth guard for contractors (cabinet)
- Standard Laravel auth for staff (Filament)
- Contractor logs in with login + password fields
- Redirect to /dashboard after login

## IMPORTANT RULES

1. Laravel only READS from 1C — never writes except sending orders
2. All prices come from 1C — never editable in DB directly
3. Client sees ONLY products where their price exists
4. Show credit limit info but do NOT block orders automatically in MVP
5. Reservation is a signal to manager — does NOT deduct stock in MVP
6. Use Ukrainian language for all UI labels
7. Use UAH as default currency

## SEEDING

Create seeders with realistic test data:
- 3 contractors with different credit limits
- 50 products with variants
- Prices for each contractor
- Stock data for 2 warehouses
- 10 sample orders in various statuses

## FILE STRUCTURE

Follow standard Laravel structure.
Use Repository pattern for complex queries.
Create config/onec.php for 1C connection settings.

Start by creating all migrations, then models with relationships,
then Filament resources, then Livewire cabinet pages, then services.

## Cursor Cloud specific instructions

The VM ships **PHP 8.3**, **MySQL 8**, **Redis**, **Node 22**, and **Composer**. The environment is a container running on an **overlayfs** filesystem. This has two important implications documented below.

### Known Issue: MySQL startup script fails during VM provisioning

**Root cause:** The MySQL `mysql-server-8.0` Debian post-install script starts a temporary `mysqld` process to initialize the root user, then tries to shut it down. In this container (no systemd, `policy-rc.d` blocks `invoke-rc.d`), the shutdown fails — the process cannot be killed via the normal service mechanism. This causes `dpkg` to report exit code 1, which fails the `apt-get install mysql-server` step in the cloud agent startup script.

Additionally, the MySQL data directory (`/var/lib/mysql`) is on **overlayfs**. MySQL 8's InnoDB redo log uses `O_DIRECT` or `fallocate` calls that overlayfs rejects with errno 22 (EINVAL) or errno 122 (EDQUOT), making `sudo service mysql start` fail even after the packages are installed.

**Fixes required in the env setup script:**

1. **Add a policy-rc.d override before installing MySQL** so the post-install script does not try to start/stop MySQL via init:
   ```bash
   echo "exit 101" | sudo tee /usr/sbin/policy-rc.d && sudo chmod +x /usr/sbin/policy-rc.d
   sudo DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server redis-server
   sudo rm -f /usr/sbin/policy-rc.d
   ```

2. **Add InnoDB container-safe config** before first start:
   ```bash
   sudo bash -c 'cat > /etc/mysql/conf.d/container.cnf << EOF
   [mysqld]
   innodb_use_native_aio=0
   innodb_flush_method=fsync
   skip_log_bin=1
   EOF'
   ```

3. **Start MySQL with a fresh data directory on a non-overlayfs path** (e.g. tmpfs under `/dev/shm`), because the existing `/var/lib/mysql` may have dirty InnoDB redo logs from the failed post-install run:
   ```bash
   sudo mkdir -p /dev/shm/mysql-data && sudo chown mysql:mysql /dev/shm/mysql-data
   sudo -u mysql mysqld --initialize-insecure --datadir=/dev/shm/mysql-data \
     --innodb-use-native-aio=0 --innodb-flush-method=fsync 2>/dev/null
   # Start in background
   sudo -u mysql mysqld --datadir=/dev/shm/mysql-data \
     --socket=/tmp/mysql.sock --port=3306 \
     --innodb-use-native-aio=0 --innodb-flush-method=fsync \
     --skip-log-bin --daemonize
   sleep 5
   mysql -u root --socket=/tmp/mysql.sock -e \
     "CREATE DATABASE IF NOT EXISTS babypark_b2b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      CREATE USER IF NOT EXISTS 'babypark'@'localhost' IDENTIFIED BY 'secret';
      GRANT ALL PRIVILEGES ON babypark_b2b.* TO 'babypark'@'localhost';
      FLUSH PRIVILEGES;"
   ```

4. **Install php8.3-sqlite3** as a fallback in case MySQL still fails:
   ```bash
   sudo apt-get install -y php8.3-sqlite3
   ```

### Working MySQL start procedure (for existing agents)

If MySQL is not running (check with `mysql -u root --socket=/tmp/mysql.sock -e "SELECT 1"` or `pgrep mysqld`), run:

```bash
# One-time: initialize fresh datadir (only if /dev/shm/mysql-data doesn't exist)
sudo mkdir -p /dev/shm/mysql-data && sudo chown mysql:mysql /dev/shm/mysql-data
sudo -u mysql mysqld --initialize-insecure --datadir=/dev/shm/mysql-data \
  --innodb-use-native-aio=0 --innodb-flush-method=fsync 2>/dev/null

# Start MySQL
sudo -u mysql mysqld --datadir=/dev/shm/mysql-data \
  --socket=/tmp/mysql.sock --port=3306 \
  --innodb-use-native-aio=0 --innodb-flush-method=fsync \
  --skip-log-bin &
sleep 10

# Create DB and user
mysql -u root --socket=/tmp/mysql.sock -e "
  CREATE DATABASE IF NOT EXISTS babypark_b2b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'babypark'@'localhost' IDENTIFIED BY 'secret';
  GRANT ALL PRIVILEGES ON babypark_b2b.* TO 'babypark'@'localhost';
  FLUSH PRIVILEGES;"
```

`.env` must use `DB_SOCKET=/tmp/mysql.sock` (or `DB_HOST=127.0.0.1 DB_PORT=3306`) — see `.env.example`.

**DO NOT use** `sudo service mysql start` — it will fail in this container.

### Services

| Service | How to start | Notes |
|---------|--------------|-------|
| MySQL 8 | See "Working MySQL start procedure" above | Use `/dev/shm/mysql-data` as datadir |
| Redis | `redis-server --daemonize yes` | Installed, but may not be running |
| Laravel HTTP | `php artisan serve --host=0.0.0.0 --port=8000` | Use a **tmux** session |

### Commands (from repo root)

```bash
composer install                  # Install PHP dependencies
npm install && npm run build      # Build frontend assets
cp .env.example .env              # Copy env file
php artisan key:generate          # Generate app key
php artisan migrate               # Run migrations
php artisan db:seed               # Seed test data
php artisan serve                 # Start dev server (use tmux)
vendor/bin/pint                   # Code style check/fix
php artisan test                  # Run tests
```

Filament admin panel: `http://localhost:8000/admin`
Default credentials (after seeding): `admin@babypark.ua` / `password`
