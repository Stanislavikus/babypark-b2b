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
- net_weight, gross_weight, volume_m3
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

The VM ships **PHP 8.3**, **Redis**, **Node 22**, and **Composer**. MySQL client/server binaries may be installed, but the supported Cloud development database is **SQLite**. The container runs on an **overlayfs** root filesystem.

### Environment setup

**The canonical setup script is `scripts/cloud-setup.sh`.**  
Paste its contents into the **Startup script** field at [cursor.com/onboard](https://cursor.com/onboard) → Cloud Agents → Environment Settings.

The script:

- patches `policy-rc.d` so future apt post-install scripts can stop services;
- installs `php8.3-sqlite3` if needed;
- starts Redis;
- configures `.env` with `DB_CONNECTION=sqlite` and `database/database.sqlite`;
- runs migrations and seeders.

`bash scripts/cloud-setup.sh` configures **SQLite** for Cloud development. It does **not** start MySQL and must not be presented as MySQL verification.

### Why native MySQL is not the Cloud dev default

Two issues block reliable native MySQL on typical Cursor Cloud agents:

1. **`policy-rc.d`** — the container denies service auto-start/stop unless patched (the setup script handles this for apt installs).
2. **overlayfs** — `/var/lib/mysql` lives on overlayfs; MySQL 8 InnoDB redo-log operations (`O_DIRECT` / `fallocate`) fail with errno 22 (EINVAL). `sudo service mysql start` is inappropriate on overlayfs-based Cloud agents.

An older workaround ran `mysqld` from `/dev/shm` (tmpfs). That recipe is **not** a guaranteed working contract: current VMs often expose only **64 MB** on `/dev/shm`, which is insufficient for an InnoDB datadir (typically ≥100 MB). Always measure before attempting native MySQL.

### Native MySQL on Cloud (non-canonical — probe first)

Before attempting native MySQL in a Cloud agent VM:

1. Measure `/dev/shm` capacity: `df -h /dev/shm` and `df -B1 --output=size,avail /dev/shm`.
2. Confirm `mysqld` is installed: `mysqld --version`.
3. Confirm passwordless `sudo` if initialization is required: `sudo -n true`.
4. Check for an existing server: `pgrep -a mysqld`, `mysqladmin ping`.
5. Use a **new isolated disposable datadir** only (for example `/dev/shm/babypark-mysql-test` when capacity allows). Never delete or overwrite `/var/lib/mysql` or an unknown existing datadir.
6. **Never** use `sudo service mysql start` on overlayfs-based Cloud agents.

If any prerequisite fails, skip native MySQL and rely on Docker Compose locally or the GitHub Actions MySQL workflow (see below).

### Production-driver verification

MySQL 8 migration correctness is verified by:

- **Docker Compose** locally (`docker compose up -d db` — see `docker-compose.yml`);
- **GitHub Actions** workflow `.github/workflows/mysql-tests.yml` on pull requests to `develop`.

### Services

| Service | How to start | Status after `cloud-setup.sh` |
|---------|--------------|-------------------------------|
| SQLite (Cloud dev) | automatic via `scripts/cloud-setup.sh` | ✅ `database/database.sqlite` |
| Redis | `redis-server --daemonize yes` | ✅ Running on `127.0.0.1:6379` |
| Laravel | `php artisan serve --host=0.0.0.0 --port=8000` (use tmux) | Manual |
| MySQL 8 (production driver) | Docker Compose or CI workflow | Not started by `cloud-setup.sh` |

### Commands (from repo root)

```bash
bash scripts/cloud-setup.sh   # Full one-shot setup (first time on a new VM)

composer install               # PHP dependencies
php artisan migrate            # Run migrations
php artisan db:seed            # Seed test data
php artisan serve              # Dev server (use tmux)
vendor/bin/pint                # Code style
php artisan test               # Tests (SQLite in Cloud; MySQL via CI)
```

Admin panel: `http://localhost:8000/admin` — `admin@babypark.ua` / `password`
