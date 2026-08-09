# GAP-024 PR4 — Filament 5 / Livewire 4 visual comparison

Compared **PR4 after-captures** (`docs/audits/visual-baselines/gap-024-filament5-pr4/`)
against **PR3 Filament 4 baseline** (`docs/audits/visual-baselines/gap-024-filament4-pr3/`).

Manifest reference: `docs/audits/GAP-024-pr1-visual-baseline-manifest.md`.

Ephemeral copies: `/opt/cursor/artifacts/gap-024-pr4-filament5-visual/`.

## Capture method

- Playwright Chromium headless against `http://127.0.0.1:8765`
- Admin: `admin@babypark.ua` / cabinet: `dytiachyi-svit` (B2BSeeder first customer)
- Themes: light / dark / system (Filament `localStorage.theme` + `colorScheme`)
- Viewports: desktop 1280×900, mobile 390×844 (**s05**: desktop 1024×900, mobile 767×900)
- Interaction states: s06 ViewAction slideOver, s15 cart drawer, s17 lightbox, s20 toast, s10–12 connector fixtures
- Output: WebP quality 85 (sharp)

## Summary counts

| Classification | Count |
|---|---|
| Identical | 53 |
| Negligible (<0.5% pixels) | 3 |
| Framework / expected delta | 39 |
| High-risk surface delta | 49 |
| Missing / failed | 0 |
| **Total** | **144** |

## High-risk surfaces

| Surface | Files with high-risk-delta | Notes |
|---|---|---|
| s06 | 6 | ViewAction slideOver — compare supplemental-s06 approach |
| s07 | 7 | Admin forms (product / price list / delivery) |
| s09 | 0 | Field Matrix + Governance |
| s10–12 | 18 | Connector accounts fixture |
| s15 | 6 | Quantity + cart drawer |
| s17 | 6 | Product photo lightbox |
| s20 | 6 | Toast after add-to-cart |

## Per-file classification

| File | vs PR3 | Pixel diff % | Classification |
|---|---|---:|---|
| `s01-admin-login-dark-desktop.webp` | yes | 0.00 | identical |
| `s01-admin-login-dark-mobile.webp` | yes | 0.00 | identical |
| `s01-admin-login-light-desktop.webp` | yes | 0.00 | identical |
| `s01-admin-login-light-mobile.webp` | yes | 0.00 | identical |
| `s01-admin-login-system-desktop.webp` | yes | 0.00 | identical |
| `s01-admin-login-system-mobile.webp` | yes | 0.00 | identical |
| `s02-cabinet-login-dark-desktop.webp` | yes | 0.00 | identical |
| `s02-cabinet-login-dark-mobile.webp` | yes | 0.00 | identical |
| `s02-cabinet-login-light-desktop.webp` | yes | 0.00 | identical |
| `s02-cabinet-login-light-mobile.webp` | yes | 0.00 | identical |
| `s02-cabinet-login-system-desktop.webp` | yes | 0.00 | identical |
| `s02-cabinet-login-system-mobile.webp` | yes | 0.00 | identical |
| `s03-admin-navigation-dark-desktop.webp` | yes | 0.00 | identical |
| `s03-admin-navigation-dark-mobile.webp` | yes | 0.00 | identical |
| `s03-admin-navigation-light-desktop.webp` | yes | 0.00 | identical |
| `s03-admin-navigation-light-mobile.webp` | yes | 0.00 | identical |
| `s03-admin-navigation-system-desktop.webp` | yes | 0.00 | identical |
| `s03-admin-navigation-system-mobile.webp` | yes | 0.00 | identical |
| `s04-admin-products-table-dark-desktop.webp` | yes | 3.38 | framework-delta |
| `s04-admin-products-table-dark-mobile.webp` | yes | 10.61 | framework-delta |
| `s04-admin-products-table-light-desktop.webp` | yes | 4.80 | framework-delta |
| `s04-admin-products-table-light-mobile.webp` | yes | 7.18 | framework-delta |
| `s04-admin-products-table-system-desktop.webp` | yes | 4.73 | framework-delta |
| `s04-admin-products-table-system-mobile.webp` | yes | 9.87 | framework-delta |
| `s05-data-list-toolbar-dark-desktop.webp` | yes | 0.00 | identical |
| `s05-data-list-toolbar-dark-mobile.webp` | yes | 0.00 | identical |
| `s05-data-list-toolbar-light-desktop.webp` | yes | 0.00 | identical |
| `s05-data-list-toolbar-light-mobile.webp` | yes | 0.00 | identical |
| `s05-data-list-toolbar-system-desktop.webp` | yes | 0.00 | identical |
| `s05-data-list-toolbar-system-mobile.webp` | yes | 0.00 | identical |
| `s06-product-context-drawer-dark-desktop.webp` | yes | 97.10 | high-risk-delta |
| `s06-product-context-drawer-dark-mobile.webp` | yes | 65.84 | high-risk-delta |
| `s06-product-context-drawer-light-desktop.webp` | yes | 96.36 | high-risk-delta |
| `s06-product-context-drawer-light-mobile.webp` | yes | 93.48 | high-risk-delta |
| `s06-product-context-drawer-system-desktop.webp` | yes | 64.94 | high-risk-delta |
| `s06-product-context-drawer-system-mobile.webp` | yes | 93.63 | high-risk-delta |
| `s07-admin-forms-dark-desktop.webp` | yes | 6.31 | high-risk-delta |
| `s07-admin-forms-dark-mobile.webp` | yes | 0.00 | identical |
| `s07-admin-forms-delivery-setting-dark-desktop-delivery-setting.webp` | yes | 0.00 | identical |
| `s07-admin-forms-delivery-setting-dark-mobile-delivery-setting.webp` | yes | 0.00 | identical |
| `s07-admin-forms-delivery-setting-light-desktop-delivery-setting.webp` | yes | 0.00 | identical |
| `s07-admin-forms-delivery-setting-light-mobile-delivery-setting.webp` | yes | 0.00 | identical |
| `s07-admin-forms-delivery-setting-system-desktop-delivery-setting.webp` | yes | 0.00 | identical |
| `s07-admin-forms-delivery-setting-system-mobile-delivery-setting.webp` | yes | 0.00 | identical |
| `s07-admin-forms-light-desktop.webp` | yes | 10.52 | high-risk-delta |
| `s07-admin-forms-light-mobile.webp` | yes | 0.00 | identical |
| `s07-admin-forms-price-list-item-dark-desktop-price-list-item.webp` | yes | 0.00 | identical |
| `s07-admin-forms-price-list-item-dark-mobile-price-list-item.webp` | yes | 0.00 | identical |
| `s07-admin-forms-price-list-item-light-desktop-price-list-item.webp` | yes | 0.62 | high-risk-delta |
| `s07-admin-forms-price-list-item-light-mobile-price-list-item.webp` | yes | 1.47 | high-risk-delta |
| `s07-admin-forms-price-list-item-system-desktop-price-list-item.webp` | yes | 0.62 | high-risk-delta |
| `s07-admin-forms-price-list-item-system-mobile-price-list-item.webp` | yes | 1.47 | high-risk-delta |
| `s07-admin-forms-system-desktop.webp` | yes | 6.30 | high-risk-delta |
| `s07-admin-forms-system-mobile.webp` | yes | 0.00 | identical |
| `s08-price-inspector-dark-desktop.webp` | yes | 0.23 | negligible |
| `s08-price-inspector-dark-mobile.webp` | yes | 0.84 | framework-delta |
| `s08-price-inspector-light-desktop.webp` | yes | 0.22 | negligible |
| `s08-price-inspector-light-mobile.webp` | yes | 6.52 | framework-delta |
| `s08-price-inspector-system-desktop.webp` | yes | 0.30 | negligible |
| `s08-price-inspector-system-mobile.webp` | yes | 0.78 | framework-delta |
| `s09-field-matrix-dark-desktop.webp` | yes | 0.00 | identical |
| `s09-field-matrix-dark-mobile.webp` | yes | 0.00 | identical |
| `s09-field-matrix-light-desktop.webp` | yes | 0.00 | identical |
| `s09-field-matrix-light-mobile.webp` | yes | 0.00 | identical |
| `s09-field-matrix-system-desktop.webp` | yes | 0.00 | identical |
| `s09-field-matrix-system-mobile.webp` | yes | 0.00 | identical |
| `s09-governance-dark-desktop-governance.webp` | yes | 0.00 | identical |
| `s09-governance-dark-mobile-governance.webp` | yes | 0.00 | identical |
| `s09-governance-light-desktop-governance.webp` | yes | 0.00 | identical |
| `s09-governance-light-mobile-governance.webp` | yes | 0.00 | identical |
| `s09-governance-system-desktop-governance.webp` | yes | 0.00 | identical |
| `s09-governance-system-mobile-governance.webp` | yes | 0.00 | identical |
| `s10-connector-account-list-dark-desktop.webp` | yes | 4.58 | high-risk-delta |
| `s10-connector-account-list-dark-mobile.webp` | yes | 1.70 | high-risk-delta |
| `s10-connector-account-list-light-desktop.webp` | yes | 3.66 | high-risk-delta |
| `s10-connector-account-list-light-mobile.webp` | yes | 1.28 | high-risk-delta |
| `s10-connector-account-list-system-desktop.webp` | yes | 3.66 | high-risk-delta |
| `s10-connector-account-list-system-mobile.webp` | yes | 1.28 | high-risk-delta |
| `s11-connector-account-detail-dark-desktop.webp` | yes | 26.07 | high-risk-delta |
| `s11-connector-account-detail-dark-mobile.webp` | yes | 34.17 | high-risk-delta |
| `s11-connector-account-detail-light-desktop.webp` | yes | 24.31 | high-risk-delta |
| `s11-connector-account-detail-light-mobile.webp` | yes | 35.17 | high-risk-delta |
| `s11-connector-account-detail-system-desktop.webp` | yes | 24.31 | high-risk-delta |
| `s11-connector-account-detail-system-mobile.webp` | yes | 35.17 | high-risk-delta |
| `s12-connector-connection-history-dark-desktop.webp` | yes | 26.07 | high-risk-delta |
| `s12-connector-connection-history-dark-mobile.webp` | yes | 41.11 | high-risk-delta |
| `s12-connector-connection-history-light-desktop.webp` | yes | 24.31 | high-risk-delta |
| `s12-connector-connection-history-light-mobile.webp` | yes | 39.21 | high-risk-delta |
| `s12-connector-connection-history-system-desktop.webp` | yes | 24.31 | high-risk-delta |
| `s12-connector-connection-history-system-mobile.webp` | yes | 39.21 | high-risk-delta |
| `s13-b2b-catalogue-table-dark-desktop.webp` | yes | 9.81 | framework-delta |
| `s13-b2b-catalogue-table-dark-mobile.webp` | yes | 9.74 | framework-delta |
| `s13-b2b-catalogue-table-light-desktop.webp` | yes | 9.19 | framework-delta |
| `s13-b2b-catalogue-table-light-mobile.webp` | yes | 9.19 | framework-delta |
| `s13-b2b-catalogue-table-system-desktop.webp` | yes | 9.02 | framework-delta |
| `s13-b2b-catalogue-table-system-mobile.webp` | yes | 10.10 | framework-delta |
| `s14-b2b-catalogue-cards-dark-desktop.webp` | yes | 63.11 | cabinet-theme-limitation |
| `s14-b2b-catalogue-cards-dark-mobile.webp` | yes | 58.70 | cabinet-theme-limitation |
| `s14-b2b-catalogue-cards-light-desktop.webp` | yes | 63.04 | cabinet-theme-limitation |
| `s14-b2b-catalogue-cards-light-mobile.webp` | yes | 52.53 | cabinet-theme-limitation |
| `s14-b2b-catalogue-cards-system-desktop.webp` | yes | 63.90 | cabinet-theme-limitation |
| `s14-b2b-catalogue-cards-system-mobile.webp` | yes | 57.38 | cabinet-theme-limitation |
| `s15-qty-cart-checkout-dark-desktop.webp` | yes | 19.64 | high-risk-delta |
| `s15-qty-cart-checkout-dark-mobile.webp` | yes | 42.15 | high-risk-delta |
| `s15-qty-cart-checkout-light-desktop.webp` | yes | 17.60 | high-risk-delta |
| `s15-qty-cart-checkout-light-mobile.webp` | yes | 37.40 | high-risk-delta |
| `s15-qty-cart-checkout-system-desktop.webp` | yes | 17.56 | high-risk-delta |
| `s15-qty-cart-checkout-system-mobile.webp` | yes | 37.83 | high-risk-delta |
| `s16-availability-pricing-dark-desktop.webp` | yes | 13.41 | cabinet-theme-limitation |
| `s16-availability-pricing-dark-mobile.webp` | yes | 23.31 | cabinet-theme-limitation |
| `s16-availability-pricing-light-desktop.webp` | yes | 20.95 | cabinet-theme-limitation |
| `s16-availability-pricing-light-mobile.webp` | yes | 18.76 | cabinet-theme-limitation |
| `s16-availability-pricing-system-desktop.webp` | yes | 12.43 | cabinet-theme-limitation |
| `s16-availability-pricing-system-mobile.webp` | yes | 18.34 | cabinet-theme-limitation |
| `s17-product-photo-lightbox-dark-desktop.webp` | yes | 99.68 | high-risk-delta |
| `s17-product-photo-lightbox-dark-mobile.webp` | yes | 98.97 | high-risk-delta |
| `s17-product-photo-lightbox-light-desktop.webp` | yes | 99.71 | high-risk-delta |
| `s17-product-photo-lightbox-light-mobile.webp` | yes | 98.56 | high-risk-delta |
| `s17-product-photo-lightbox-system-desktop.webp` | yes | 99.74 | high-risk-delta |
| `s17-product-photo-lightbox-system-mobile.webp` | yes | 98.93 | high-risk-delta |
| `s18-mobile-responsive-admin-dark-desktop.webp` | yes | 10.79 | framework-delta |
| `s18-mobile-responsive-admin-dark-mobile.webp` | yes | 12.05 | framework-delta |
| `s18-mobile-responsive-admin-light-desktop.webp` | yes | 9.62 | framework-delta |
| `s18-mobile-responsive-admin-light-mobile.webp` | yes | 13.45 | framework-delta |
| `s18-mobile-responsive-admin-system-desktop.webp` | yes | 9.54 | framework-delta |
| `s18-mobile-responsive-admin-system-mobile.webp` | yes | 18.30 | framework-delta |
| `s18-mobile-responsive-cabinet-dark-desktop-cabinet.webp` | yes | 59.12 | framework-delta |
| `s18-mobile-responsive-cabinet-dark-mobile-cabinet.webp` | yes | 55.45 | framework-delta |
| `s18-mobile-responsive-cabinet-light-desktop-cabinet.webp` | yes | 58.38 | framework-delta |
| `s18-mobile-responsive-cabinet-light-mobile-cabinet.webp` | yes | 53.13 | framework-delta |
| `s18-mobile-responsive-cabinet-system-desktop-cabinet.webp` | yes | 58.60 | framework-delta |
| `s18-mobile-responsive-cabinet-system-mobile-cabinet.webp` | yes | 54.37 | framework-delta |
| `s19-theme-appearance-dark-desktop.webp` | yes | 0.00 | identical |
| `s19-theme-appearance-dark-mobile.webp` | yes | 0.00 | identical |
| `s19-theme-appearance-light-desktop.webp` | yes | 0.00 | identical |
| `s19-theme-appearance-light-mobile.webp` | yes | 0.00 | identical |
| `s19-theme-appearance-system-desktop.webp` | yes | 0.00 | identical |
| `s19-theme-appearance-system-mobile.webp` | yes | 0.00 | identical |
| `s20-toasts-notifications-dark-desktop.webp` | yes | 21.33 | high-risk-delta |
| `s20-toasts-notifications-dark-mobile.webp` | yes | 40.15 | high-risk-delta |
| `s20-toasts-notifications-light-desktop.webp` | yes | 19.91 | high-risk-delta |
| `s20-toasts-notifications-light-mobile.webp` | yes | 36.49 | high-risk-delta |
| `s20-toasts-notifications-system-desktop.webp` | yes | 19.95 | high-risk-delta |
| `s20-toasts-notifications-system-mobile.webp` | yes | 36.50 | high-risk-delta |

## Notes

- **s06**: PR3 supplemental `supplemental-s06/` documents the valid ViewAction-open gate; PR4 main-matrix files should match that interaction state.
- **s05**: Captured at manifest md-contract widths (1024 desktop / 767 mobile).
- **Livewire /catalog** (s14, s16, s17, s18 cabinet): dark/system may match light (pre-existing non-Filament limitation).
- **s15**: Checkout UI not implemented — cart dropdown only (same as PR1/PR3).

Generated by `scripts/gap-024-pr4-visual-capture.mjs`.
