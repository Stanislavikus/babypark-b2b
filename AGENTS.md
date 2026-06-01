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

The VM ships **PHP 8.3**, **MySQL 8**, **Redis**, **Node 22**, and **Composer**. It runs as a container on an **overlayfs** root filesystem.

### Environment setup

**The canonical setup script is `scripts/cloud-setup.sh`.**  
Paste its contents into the **Startup script** field at [cursor.com/onboard](https://cursor.com/onboard) → Cloud Agents → Environment Settings.  
It handles all the issues described below automatically.

---

### Why the original startup script failed (root cause)

The original startup script ran:
```bash
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mysql-server redis-server
```
This failed with `dpkg: error processing package mysql-server-8.0 (--configure)` for **two compounding reasons**:

#### Problem 1 — `policy-rc.d` blocks `invoke-rc.d stop`

The container ships with `/usr/sbin/policy-rc.d` returning `exit 101` for **all** operations, including `stop`. MySQL's Debian post-install script (`mysql-server-8.0.postinst`) works like this:

1. Starts a temporary `mysqld` directly to run initialization SQL — **succeeds**
2. Calls `invoke-rc.d stop mysql` to shut that mysqld down — **blocked by `policy-rc.d`**
3. Cannot kill the process → exits with error code 1
4. `dpkg` marks `mysql-server-8.0` as broken → entire install fails

Evidence from `/var/log/apt/term.log`:
```
invoke-rc.d: could not determine current runlevel
invoke-rc.d: policy-rc.d denied execution of stop.
mysqld will log errors to /var/log/mysql/error.log
mysqld is running as pid 17130
Error: Unable to shut down server with process id 17130
dpkg: error processing package mysql-server-8.0 (--configure):
 installed mysql-server-8.0 package post-installation script subprocess returned error exit status 1
```

**Fix:** Replace `policy-rc.d` with a version that allows `stop`/`restart` before installing MySQL:
```bash
sudo tee /usr/sbin/policy-rc.d > /dev/null << 'EOF'
#!/bin/sh
case "$1" in
  stop|restart|force-reload) exit 0 ;;
  *)                         exit 101 ;;
esac
EOF
sudo chmod +x /usr/sbin/policy-rc.d
sudo apt-get install -y mysql-server redis-server
# restore deny-all after install
echo '#!/bin/sh'$'\n''exit 101' | sudo tee /usr/sbin/policy-rc.d
```

#### Problem 2 — InnoDB cannot use `O_DIRECT` on overlayfs

Even after the packages install, `sudo service mysql start` always fails because `/var/lib/mysql` is on **overlayfs**. MySQL 8 InnoDB redo logs use `O_DIRECT` / `fallocate` operations that overlayfs rejects with errno 22 (EINVAL):

```
[InnoDB] Operating system error number 22 in a file operation.
[InnoDB] File (unknown): 'close' returned OS error 122. Cannot continue operation.
```

The dirty redo logs left by the killed post-install mysqld compound this — every restart attempt finds inconsistent log files and aborts.

**Fix:** Initialize and run MySQL from `/dev/shm` (a true `tmpfs` volume, always present in Cursor Cloud containers, not affected by overlayfs restrictions):
```bash
sudo mkdir -p /dev/shm/mysql-data && sudo chown mysql:mysql /dev/shm/mysql-data
sudo -u mysql mysqld --initialize-insecure --datadir=/dev/shm/mysql-data \
    --innodb-use-native-aio=0 --innodb-flush-method=fsync 2>/dev/null
```

---

### Recovering MySQL on an existing agent VM

If MySQL is not running, run these commands (or re-run `bash scripts/cloud-setup.sh`):

```bash
DATADIR=/dev/shm/mysql-data
SOCKET=/tmp/mysql.sock

# Initialize (only needed once per VM lifecycle)
sudo mkdir -p $DATADIR && sudo chown mysql:mysql $DATADIR
sudo -u mysql mysqld --initialize-insecure --datadir=$DATADIR \
    --innodb-use-native-aio=0 --innodb-flush-method=fsync 2>/dev/null

# Start (run in tmux so it stays alive)
tmux new-session -d -s mysql -- bash -c "
    sudo -u mysql mysqld \
        --datadir=$DATADIR --socket=$SOCKET --port=3306 \
        --innodb-use-native-aio=0 --innodb-flush-method=fsync \
        --skip-log-bin 2>&1 | tee /tmp/mysqld.log"
sleep 10

# Create DB and user
mysql --socket=$SOCKET -u root -e "
  CREATE DATABASE IF NOT EXISTS babypark_b2b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'babypark'@'localhost' IDENTIFIED BY 'secret';
  GRANT ALL PRIVILEGES ON babypark_b2b.* TO 'babypark'@'localhost';
  FLUSH PRIVILEGES;"
```

`.env` must include `DB_SOCKET=/tmp/mysql.sock` — see `.env.example`.  
**Never use** `sudo service mysql start` in this container — it always fails.

---

### Services

| Service | How to start | Status after `cloud-setup.sh` |
|---------|--------------|-------------------------------|
| MySQL 8 | `bash scripts/cloud-setup.sh` or recovery snippet above | ✅ Running on `/tmp/mysql.sock` |
| Redis | `redis-server --daemonize yes` | ✅ Running on `127.0.0.1:6379` |
| Laravel | `php artisan serve --host=0.0.0.0 --port=8000` (use tmux) | Manual |

### Commands (from repo root)

```bash
bash scripts/cloud-setup.sh   # Full one-shot setup (first time on a new VM)

composer install               # PHP dependencies
php artisan migrate            # Run migrations
php artisan db:seed            # Seed test data
php artisan serve              # Dev server (use tmux)
vendor/bin/pint                # Code style
php artisan test               # Tests
```

Admin panel: `http://localhost:8000/admin` — `admin@babypark.ua` / `password`
