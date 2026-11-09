# GAP-024 PR1 — Filament 3 visual baseline manifest

This manifest is the **authoritative pre-migration visual baseline** for GAP-024 §16.
All captures represent Filament 3 / Livewire 3 UI at baseline commit
`b45e01385778a9fd69b7051389452f447ad9a85d` (merged PR #108), captured **before**
PR1 runtime/toolchain changes merge and **before** any PR3/PR4 UI migration work.

PR3/PR4 may add *after* captures for comparison; they must **not** substitute for this
pre-upgrade baseline.

## Baseline commit

`b45e01385778a9fd69b7051389452f447ad9a85d`

Captured from a detached worktree at this commit (`/tmp/gap024-baseline`) with
`php artisan migrate:fresh --seed`, `npm run build`, and
`php artisan serve --host=127.0.0.1 --port=8765`. Connector account fixture data
for surfaces #10–12 was inserted via one-off `artisan tinker` (not committed to
application code).

## Durable evidence location (repository)

`docs/audits/visual-baselines/gap-024-filament3/`

144 optimized WebP screenshots plus this manifest. Cloud Agent paths under
`/opt/cursor/artifacts/` are auxiliary/ephemeral only and must not be treated as
the canonical copy.

## Capture matrix (GAP-024 §16 authoritative)

Each of the 20 §16 surfaces must be captured in **light**, **dark**, and **system**
appearance and at **desktop** (1280×900) plus **mobile** (390×844) viewports.

| Matrix dimension | Values |
|---|---|
| Surfaces (§16 rows) | 20 |
| Appearance / theme | light, dark, system |
| Viewport | desktop (1280×900), mobile (390×844) |
| **Required core states** | **20 × 3 × 2 = 120** |

### Extended sub-states (multi-page rows)

Some §16 rows name multiple pages or forms. Additional captures document each named
sub-surface without reducing the core 120-state matrix:

| Row | Extra sub-states | Additional files |
|---|---|---|
| 7 — Admin forms | price list item edit, delivery setting edit | +12 (2 forms × 6 theme/viewport) |
| 9 — Field Matrix + Governance | Governance page | +6 (1 page × 6) |
| 18 — Mobile / responsive both panels | Cabinet Livewire catalog (admin captured in core row) | +6 (1 panel × 6) |
| **Extended sub-state total** | | **+24** |
| **Grand total files** | | **144** |

### Surface definitions (§16)

| # | Surface | Route / context | Interaction / state |
|---|---|---|---|
| 1 | Admin login | `/admin/login` | Unauthenticated login form |
| 2 | Cabinet login | `/cabinet/login` | Custom Filament customer-guard login |
| 3 | Navigation / sidebar / topbar | `/admin` | Authenticated admin dashboard navigation |
| 4 | Admin product table | `/admin/products` | Default table view |
| 5 | Shared data-list toolbar | `/admin/field-matrix` | Toolbar at md contract (desktop 1024px; mobile 767px) |
| 6 | Product context drawer / modal | `/admin/products` | Neutral row click → ViewAction slideOver |
| 7 | Admin forms | `/admin/products/1/edit`, `/admin/price-lists/{id}/edit`, `/admin/delivery-settings/1/edit` | Product edit; price list edit; delivery setting edit |
| 8 | Price Inspector | `/admin/price-inspector` | Default inspector page |
| 9 | Field Matrix + Governance | `/admin/field-matrix`, `/admin/governance` | Custom Blade pages |
| 10 | Connector account list | `/admin/connector-accounts` | Polling table (5s) with status badges |
| 11 | Connector account detail | `/admin/connector-accounts/{id}` | Infolist + runtime-state view |
| 12 | Connector connection-check history | `/admin/connector-accounts/{id}` | Connection checks relation manager |
| 13 | B2B catalogue table view | `/cabinet/products` | Filament ListProducts + cart toolbar hook |
| 14 | B2B catalogue card/grid view | `/catalog?viewMode=cards` | Livewire catalog card grid |
| 15 | Quantity selector + cart drawer + checkout | `/cabinet/products` | Quantity input; cart dropdown open (see partial-capture note) |
| 16 | Availability + pricing display | `/catalog?viewMode=table` | Availability colour tokens and role-based pricing |
| 17 | Product photo lightbox | `/catalog` | `bpOpenLightbox` overlay open |
| 18 | Mobile / responsive both panels | `/admin/products`, `/catalog` | Admin and cabinet at mobile viewport |
| 19 | Dark / light / system | `/admin` | Theme/appearance context on admin dashboard |
| 20 | Toasts / notifications | `/cabinet/products` | Filament success notification after add-to-cart |

## Capture summary

| Metric | Count |
|---|---|
| §16 required core row/states (20 × 3 themes × 2 viewports) | **120** |
| Extended sub-state captures (rows 7, 9, 18) | **24** |
| **Total captured** | **144** |
| Impossible / not reachable | **0 full rows**; see partial notes below |
| Total binary size (WebP) | **~4.3 MB** |

## Partial-capture and appearance notes

### Row 15 — checkout confirmation UI not implemented

The §16 row names "checkout", but at `b45e013` the cabinet cart UI exposes a
dropdown summary only — there is no order-confirmation or checkout-submit step in the
rendered UI. Captures show quantity selector active and cart drawer open with line
items and total.

Evidence: `resources/views/livewire/cabinet/cart-toolbar.blade.php` lists items and
total but has no checkout/confirm action; `OrderCreator` is service-level only
(no cabinet checkout page).

### Livewire cabinet layout — dark/system appearance

Surfaces on Livewire routes (`/catalog`, `/login`, `/dashboard`) do not implement
Filament dark-mode theming. `resources/views/layouts/cabinet.blade.php` uses a fixed
light palette with no `dark:` variants, so dark/system captures for rows 14, 16, 17,
and 18 (cabinet) are visually identical to light. Files are still captured per §16
matrix requirements; PR3/PR4 comparison should note this baseline limitation.

### Data sanitization

- No session cookies, credentials, or secrets stored in images.
- Login captures show empty forms (no password typed).
- Seeded fictional contractor/product names only (B2BSeeder test data).
- Connector credentials were never rendered (redacted test fixture).

## Complete file inventory

| §16 | File | Route / context | Theme | Viewport | Interaction |
|---:|---|---|---|---|---|
| 1 | `s01-admin-login-dark-desktop.webp` | /admin/login | dark | desktop | Login form (unauthenticated) |
| 1 | `s01-admin-login-dark-mobile.webp` | /admin/login | dark | mobile | Login form (unauthenticated) |
| 1 | `s01-admin-login-light-desktop.webp` | /admin/login | light | desktop | Login form (unauthenticated) |
| 1 | `s01-admin-login-light-mobile.webp` | /admin/login | light | mobile | Login form (unauthenticated) |
| 1 | `s01-admin-login-system-desktop.webp` | /admin/login | system | desktop | Login form (unauthenticated) |
| 1 | `s01-admin-login-system-mobile.webp` | /admin/login | system | mobile | Login form (unauthenticated) |
| 2 | `s02-cabinet-login-dark-desktop.webp` | /cabinet/login | dark | desktop | Custom Filament customer-guard login form |
| 2 | `s02-cabinet-login-dark-mobile.webp` | /cabinet/login | dark | mobile | Custom Filament customer-guard login form |
| 2 | `s02-cabinet-login-light-desktop.webp` | /cabinet/login | light | desktop | Custom Filament customer-guard login form |
| 2 | `s02-cabinet-login-light-mobile.webp` | /cabinet/login | light | mobile | Custom Filament customer-guard login form |
| 2 | `s02-cabinet-login-system-desktop.webp` | /cabinet/login | system | desktop | Custom Filament customer-guard login form |
| 2 | `s02-cabinet-login-system-mobile.webp` | /cabinet/login | system | mobile | Custom Filament customer-guard login form |
| 3 | `s03-admin-navigation-dark-desktop.webp` | /admin | dark | desktop | Authenticated admin dashboard with sidebar |
| 3 | `s03-admin-navigation-dark-mobile.webp` | /admin | dark | mobile | Authenticated admin dashboard with sidebar |
| 3 | `s03-admin-navigation-light-desktop.webp` | /admin | light | desktop | Authenticated admin dashboard with sidebar |
| 3 | `s03-admin-navigation-light-mobile.webp` | /admin | light | mobile | Authenticated admin dashboard with sidebar |
| 3 | `s03-admin-navigation-system-desktop.webp` | /admin | system | desktop | Authenticated admin dashboard with sidebar |
| 3 | `s03-admin-navigation-system-mobile.webp` | /admin | system | mobile | Authenticated admin dashboard with sidebar |
| 4 | `s04-admin-products-table-dark-desktop.webp` | /admin/products | dark | desktop | Default product table view |
| 4 | `s04-admin-products-table-dark-mobile.webp` | /admin/products | dark | mobile | Default product table view |
| 4 | `s04-admin-products-table-light-desktop.webp` | /admin/products | light | desktop | Default product table view |
| 4 | `s04-admin-products-table-light-mobile.webp` | /admin/products | light | mobile | Default product table view |
| 4 | `s04-admin-products-table-system-desktop.webp` | /admin/products | system | desktop | Default product table view |
| 4 | `s04-admin-products-table-system-mobile.webp` | /admin/products | system | mobile | Default product table view |
| 5 | `s05-data-list-toolbar-dark-desktop.webp` | /admin/field-matrix | dark | desktop | data-list-toolbar (desktop 1024px; mobile 767px) |
| 5 | `s05-data-list-toolbar-dark-mobile.webp` | /admin/field-matrix | dark | mobile | data-list-toolbar (desktop 1024px; mobile 767px) |
| 5 | `s05-data-list-toolbar-light-desktop.webp` | /admin/field-matrix | light | desktop | data-list-toolbar (desktop 1024px; mobile 767px) |
| 5 | `s05-data-list-toolbar-light-mobile.webp` | /admin/field-matrix | light | mobile | data-list-toolbar (desktop 1024px; mobile 767px) |
| 5 | `s05-data-list-toolbar-system-desktop.webp` | /admin/field-matrix | system | desktop | data-list-toolbar (desktop 1024px; mobile 767px) |
| 5 | `s05-data-list-toolbar-system-mobile.webp` | /admin/field-matrix | system | mobile | data-list-toolbar (desktop 1024px; mobile 767px) |
| 6 | `s06-product-context-drawer-dark-desktop.webp` | /admin/products | dark | desktop | Neutral row click opens ViewAction slideOver |
| 6 | `s06-product-context-drawer-dark-mobile.webp` | /admin/products | dark | mobile | Neutral row click opens ViewAction slideOver |
| 6 | `s06-product-context-drawer-light-desktop.webp` | /admin/products | light | desktop | Neutral row click opens ViewAction slideOver |
| 6 | `s06-product-context-drawer-light-mobile.webp` | /admin/products | light | mobile | Neutral row click opens ViewAction slideOver |
| 6 | `s06-product-context-drawer-system-desktop.webp` | /admin/products | system | desktop | Neutral row click opens ViewAction slideOver |
| 6 | `s06-product-context-drawer-system-mobile.webp` | /admin/products | system | mobile | Neutral row click opens ViewAction slideOver |
| 7 | `s07-admin-forms-dark-desktop.webp` | /admin/products/1/edit | dark | desktop | Product edit form |
| 7 | `s07-admin-forms-dark-mobile.webp` | /admin/products/1/edit | dark | mobile | Product edit form |
| 7 | `s07-admin-forms-delivery-setting-dark-desktop-delivery-setting.webp` | /admin/delivery-settings/1/edit | dark | desktop | Delivery setting edit form |
| 7 | `s07-admin-forms-delivery-setting-dark-mobile-delivery-setting.webp` | /admin/delivery-settings/1/edit | dark | mobile | Delivery setting edit form |
| 7 | `s07-admin-forms-delivery-setting-light-desktop-delivery-setting.webp` | /admin/delivery-settings/1/edit | light | desktop | Delivery setting edit form |
| 7 | `s07-admin-forms-delivery-setting-light-mobile-delivery-setting.webp` | /admin/delivery-settings/1/edit | light | mobile | Delivery setting edit form |
| 7 | `s07-admin-forms-delivery-setting-system-desktop-delivery-setting.webp` | /admin/delivery-settings/1/edit | system | desktop | Delivery setting edit form |
| 7 | `s07-admin-forms-delivery-setting-system-mobile-delivery-setting.webp` | /admin/delivery-settings/1/edit | system | mobile | Delivery setting edit form |
| 7 | `s07-admin-forms-light-desktop.webp` | /admin/products/1/edit | light | desktop | Product edit form |
| 7 | `s07-admin-forms-light-mobile.webp` | /admin/products/1/edit | light | mobile | Product edit form |
| 7 | `s07-admin-forms-price-list-item-dark-desktop-price-list-item.webp` | /admin/price-lists/{id}/edit | dark | desktop | Price list edit form |
| 7 | `s07-admin-forms-price-list-item-dark-mobile-price-list-item.webp` | /admin/price-lists/{id}/edit | dark | mobile | Price list edit form |
| 7 | `s07-admin-forms-price-list-item-light-desktop-price-list-item.webp` | /admin/price-lists/{id}/edit | light | desktop | Price list edit form |
| 7 | `s07-admin-forms-price-list-item-light-mobile-price-list-item.webp` | /admin/price-lists/{id}/edit | light | mobile | Price list edit form |
| 7 | `s07-admin-forms-price-list-item-system-desktop-price-list-item.webp` | /admin/price-lists/{id}/edit | system | desktop | Price list edit form |
| 7 | `s07-admin-forms-price-list-item-system-mobile-price-list-item.webp` | /admin/price-lists/{id}/edit | system | mobile | Price list edit form |
| 7 | `s07-admin-forms-system-desktop.webp` | /admin/products/1/edit | system | desktop | Product edit form |
| 7 | `s07-admin-forms-system-mobile.webp` | /admin/products/1/edit | system | mobile | Product edit form |
| 8 | `s08-price-inspector-dark-desktop.webp` | /admin/price-inspector | dark | desktop | Default inspector page |
| 8 | `s08-price-inspector-dark-mobile.webp` | /admin/price-inspector | dark | mobile | Default inspector page |
| 8 | `s08-price-inspector-light-desktop.webp` | /admin/price-inspector | light | desktop | Default inspector page |
| 8 | `s08-price-inspector-light-mobile.webp` | /admin/price-inspector | light | mobile | Default inspector page |
| 8 | `s08-price-inspector-system-desktop.webp` | /admin/price-inspector | system | desktop | Default inspector page |
| 8 | `s08-price-inspector-system-mobile.webp` | /admin/price-inspector | system | mobile | Default inspector page |
| 9 | `s09-field-matrix-dark-desktop.webp` | /admin/field-matrix | dark | desktop | Custom Field Matrix page |
| 9 | `s09-field-matrix-dark-mobile.webp` | /admin/field-matrix | dark | mobile | Custom Field Matrix page |
| 9 | `s09-field-matrix-light-desktop.webp` | /admin/field-matrix | light | desktop | Custom Field Matrix page |
| 9 | `s09-field-matrix-light-mobile.webp` | /admin/field-matrix | light | mobile | Custom Field Matrix page |
| 9 | `s09-field-matrix-system-desktop.webp` | /admin/field-matrix | system | desktop | Custom Field Matrix page |
| 9 | `s09-field-matrix-system-mobile.webp` | /admin/field-matrix | system | mobile | Custom Field Matrix page |
| 9 | `s09-governance-dark-desktop-governance.webp` | /admin/governance | dark | desktop | Custom Governance page |
| 9 | `s09-governance-dark-mobile-governance.webp` | /admin/governance | dark | mobile | Custom Governance page |
| 9 | `s09-governance-light-desktop-governance.webp` | /admin/governance | light | desktop | Custom Governance page |
| 9 | `s09-governance-light-mobile-governance.webp` | /admin/governance | light | mobile | Custom Governance page |
| 9 | `s09-governance-system-desktop-governance.webp` | /admin/governance | system | desktop | Custom Governance page |
| 9 | `s09-governance-system-mobile-governance.webp` | /admin/governance | system | mobile | Custom Governance page |
| 10 | `s10-connector-account-list-dark-desktop.webp` | /admin/connector-accounts | dark | desktop | Polling table with status badges |
| 10 | `s10-connector-account-list-dark-mobile.webp` | /admin/connector-accounts | dark | mobile | Polling table with status badges |
| 10 | `s10-connector-account-list-light-desktop.webp` | /admin/connector-accounts | light | desktop | Polling table with status badges |
| 10 | `s10-connector-account-list-light-mobile.webp` | /admin/connector-accounts | light | mobile | Polling table with status badges |
| 10 | `s10-connector-account-list-system-desktop.webp` | /admin/connector-accounts | system | desktop | Polling table with status badges |
| 10 | `s10-connector-account-list-system-mobile.webp` | /admin/connector-accounts | system | mobile | Polling table with status badges |
| 11 | `s11-connector-account-detail-dark-desktop.webp` | /admin/connector-accounts/{id} | dark | desktop | Infolist with runtime-state view |
| 11 | `s11-connector-account-detail-dark-mobile.webp` | /admin/connector-accounts/{id} | dark | mobile | Infolist with runtime-state view |
| 11 | `s11-connector-account-detail-light-desktop.webp` | /admin/connector-accounts/{id} | light | desktop | Infolist with runtime-state view |
| 11 | `s11-connector-account-detail-light-mobile.webp` | /admin/connector-accounts/{id} | light | mobile | Infolist with runtime-state view |
| 11 | `s11-connector-account-detail-system-desktop.webp` | /admin/connector-accounts/{id} | system | desktop | Infolist with runtime-state view |
| 11 | `s11-connector-account-detail-system-mobile.webp` | /admin/connector-accounts/{id} | system | mobile | Infolist with runtime-state view |
| 12 | `s12-connector-connection-history-dark-desktop.webp` | /admin/connector-accounts/{id} | dark | desktop | Connection checks relation manager |
| 12 | `s12-connector-connection-history-dark-mobile.webp` | /admin/connector-accounts/{id} | dark | mobile | Connection checks relation manager |
| 12 | `s12-connector-connection-history-light-desktop.webp` | /admin/connector-accounts/{id} | light | desktop | Connection checks relation manager |
| 12 | `s12-connector-connection-history-light-mobile.webp` | /admin/connector-accounts/{id} | light | mobile | Connection checks relation manager |
| 12 | `s12-connector-connection-history-system-desktop.webp` | /admin/connector-accounts/{id} | system | desktop | Connection checks relation manager |
| 12 | `s12-connector-connection-history-system-mobile.webp` | /admin/connector-accounts/{id} | system | mobile | Connection checks relation manager |
| 13 | `s13-b2b-catalogue-table-dark-desktop.webp` | /cabinet/products | dark | desktop | Filament ListProducts table |
| 13 | `s13-b2b-catalogue-table-dark-mobile.webp` | /cabinet/products | dark | mobile | Filament ListProducts table |
| 13 | `s13-b2b-catalogue-table-light-desktop.webp` | /cabinet/products | light | desktop | Filament ListProducts table |
| 13 | `s13-b2b-catalogue-table-light-mobile.webp` | /cabinet/products | light | mobile | Filament ListProducts table |
| 13 | `s13-b2b-catalogue-table-system-desktop.webp` | /cabinet/products | system | desktop | Filament ListProducts table |
| 13 | `s13-b2b-catalogue-table-system-mobile.webp` | /cabinet/products | system | mobile | Filament ListProducts table |
| 14 | `s14-b2b-catalogue-cards-dark-desktop.webp` | /catalog?viewMode=cards | dark | desktop | Livewire catalog card grid |
| 14 | `s14-b2b-catalogue-cards-dark-mobile.webp` | /catalog?viewMode=cards | dark | mobile | Livewire catalog card grid |
| 14 | `s14-b2b-catalogue-cards-light-desktop.webp` | /catalog?viewMode=cards | light | desktop | Livewire catalog card grid |
| 14 | `s14-b2b-catalogue-cards-light-mobile.webp` | /catalog?viewMode=cards | light | mobile | Livewire catalog card grid |
| 14 | `s14-b2b-catalogue-cards-system-desktop.webp` | /catalog?viewMode=cards | system | desktop | Livewire catalog card grid |
| 14 | `s14-b2b-catalogue-cards-system-mobile.webp` | /catalog?viewMode=cards | system | mobile | Livewire catalog card grid |
| 15 | `s15-qty-cart-checkout-dark-desktop.webp` | /cabinet/products | dark | desktop | Quantity set; cart dropdown open |
| 15 | `s15-qty-cart-checkout-dark-mobile.webp` | /cabinet/products | dark | mobile | Quantity set; cart dropdown open |
| 15 | `s15-qty-cart-checkout-light-desktop.webp` | /cabinet/products | light | desktop | Quantity set; cart dropdown open |
| 15 | `s15-qty-cart-checkout-light-mobile.webp` | /cabinet/products | light | mobile | Quantity set; cart dropdown open |
| 15 | `s15-qty-cart-checkout-system-desktop.webp` | /cabinet/products | system | desktop | Quantity set; cart dropdown open |
| 15 | `s15-qty-cart-checkout-system-mobile.webp` | /cabinet/products | system | mobile | Quantity set; cart dropdown open |
| 16 | `s16-availability-pricing-dark-desktop.webp` | /catalog?viewMode=table | dark | desktop | Availability colour tokens and pricing |
| 16 | `s16-availability-pricing-dark-mobile.webp` | /catalog?viewMode=table | dark | mobile | Availability colour tokens and pricing |
| 16 | `s16-availability-pricing-light-desktop.webp` | /catalog?viewMode=table | light | desktop | Availability colour tokens and pricing |
| 16 | `s16-availability-pricing-light-mobile.webp` | /catalog?viewMode=table | light | mobile | Availability colour tokens and pricing |
| 16 | `s16-availability-pricing-system-desktop.webp` | /catalog?viewMode=table | system | desktop | Availability colour tokens and pricing |
| 16 | `s16-availability-pricing-system-mobile.webp` | /catalog?viewMode=table | system | mobile | Availability colour tokens and pricing |
| 17 | `s17-product-photo-lightbox-dark-desktop.webp` | /catalog | dark | desktop | bpOpenLightbox overlay open |
| 17 | `s17-product-photo-lightbox-dark-mobile.webp` | /catalog | dark | mobile | bpOpenLightbox overlay open |
| 17 | `s17-product-photo-lightbox-light-desktop.webp` | /catalog | light | desktop | bpOpenLightbox overlay open |
| 17 | `s17-product-photo-lightbox-light-mobile.webp` | /catalog | light | mobile | bpOpenLightbox overlay open |
| 17 | `s17-product-photo-lightbox-system-desktop.webp` | /catalog | system | desktop | bpOpenLightbox overlay open |
| 17 | `s17-product-photo-lightbox-system-mobile.webp` | /catalog | system | mobile | bpOpenLightbox overlay open |
| 18 | `s18-mobile-responsive-admin-dark-desktop.webp` | /admin/products | dark | desktop | Admin panel at mobile viewport |
| 18 | `s18-mobile-responsive-admin-dark-mobile.webp` | /admin/products | dark | mobile | Admin panel at mobile viewport |
| 18 | `s18-mobile-responsive-admin-light-desktop.webp` | /admin/products | light | desktop | Admin panel at mobile viewport |
| 18 | `s18-mobile-responsive-admin-light-mobile.webp` | /admin/products | light | mobile | Admin panel at mobile viewport |
| 18 | `s18-mobile-responsive-admin-system-desktop.webp` | /admin/products | system | desktop | Admin panel at mobile viewport |
| 18 | `s18-mobile-responsive-admin-system-mobile.webp` | /admin/products | system | mobile | Admin panel at mobile viewport |
| 18 | `s18-mobile-responsive-cabinet-dark-desktop-cabinet.webp` | /catalog | dark | desktop | Cabinet catalog at mobile viewport |
| 18 | `s18-mobile-responsive-cabinet-dark-mobile-cabinet.webp` | /catalog | dark | mobile | Cabinet catalog at mobile viewport |
| 18 | `s18-mobile-responsive-cabinet-light-desktop-cabinet.webp` | /catalog | light | desktop | Cabinet catalog at mobile viewport |
| 18 | `s18-mobile-responsive-cabinet-light-mobile-cabinet.webp` | /catalog | light | mobile | Cabinet catalog at mobile viewport |
| 18 | `s18-mobile-responsive-cabinet-system-desktop-cabinet.webp` | /catalog | system | desktop | Cabinet catalog at mobile viewport |
| 18 | `s18-mobile-responsive-cabinet-system-mobile-cabinet.webp` | /catalog | system | mobile | Cabinet catalog at mobile viewport |
| 19 | `s19-theme-appearance-dark-desktop.webp` | /admin | dark | desktop | Theme/appearance context on admin dashboard |
| 19 | `s19-theme-appearance-dark-mobile.webp` | /admin | dark | mobile | Theme/appearance context on admin dashboard |
| 19 | `s19-theme-appearance-light-desktop.webp` | /admin | light | desktop | Theme/appearance context on admin dashboard |
| 19 | `s19-theme-appearance-light-mobile.webp` | /admin | light | mobile | Theme/appearance context on admin dashboard |
| 19 | `s19-theme-appearance-system-desktop.webp` | /admin | system | desktop | Theme/appearance context on admin dashboard |
| 19 | `s19-theme-appearance-system-mobile.webp` | /admin | system | mobile | Theme/appearance context on admin dashboard |
| 20 | `s20-toasts-notifications-dark-desktop.webp` | /cabinet/products | dark | desktop | Success notification after add-to-cart |
| 20 | `s20-toasts-notifications-dark-mobile.webp` | /cabinet/products | dark | mobile | Success notification after add-to-cart |
| 20 | `s20-toasts-notifications-light-desktop.webp` | /cabinet/products | light | desktop | Success notification after add-to-cart |
| 20 | `s20-toasts-notifications-light-mobile.webp` | /cabinet/products | light | mobile | Success notification after add-to-cart |
| 20 | `s20-toasts-notifications-system-desktop.webp` | /cabinet/products | system | desktop | Success notification after add-to-cart |
| 20 | `s20-toasts-notifications-system-mobile.webp` | /cabinet/products | system | mobile | Success notification after add-to-cart |

## Usage during PR3/PR4

1. Open captures from `docs/audits/visual-baselines/gap-024-filament3/`.
2. Re-capture the same routes/states after migration on identical viewport/theme where possible.
3. Treat unexplained layout, spacing, palette, toolbar, or `novalidate`/form-regression
   changes as merge blockers per GAP-024 §17.

## Filename convention

`s{NN}-{surface-slug}-{theme}-{viewport}[-{sub-state}].webp`

- `NN` — §16 row number (01–20)
- `theme` — `light`, `dark`, or `system`
- `viewport` — `desktop` (1280×900) or `mobile` (390×844)
- Optional `sub-state` suffix for multi-page rows (e.g. `governance`, `price-list-item`, `cabinet`)
