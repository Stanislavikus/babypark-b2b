# GAP-024 PR4 supplemental like-for-like visual comparison

Historical **Filament 4** reference: detached worktree at `eb23a62` (s10–s20) and PR3 `supplemental-s06/` (s06).
Current **Filament 5** captures: PR4 branch supplemental output + corrected canonical `s06-product-context-drawer-*.webp`.

Fixture bootstrap: `scripts/gap-024-visual-fixture-bootstrap.php` (deterministic product image, connector account, connection history).

## s06 root cause (canonical PR4 matrix)

**Capture synchronization defect** — not a Filament 5 application regression.

The original PR4 matrix captures fired before the ViewAction slideOver finished translating into the viewport (narrow off-screen strip at the right edge). After `waitForViewActionSlideOverSettled()` (heading visible, panel >25% viewport width, `Артикул`/`BP-00001` present, bounding-box stable), PR4 F5 captures show the same settled ViewAction-open state as Filament 4. Corrected canonical `s06-product-context-drawer-*.webp` files were replaced; PR3 historical baselines were **not** overwritten.

## Invalid canonical pairs (do not use for migration regression)

| Surface | Issue |
|---|---|
| Canonical s06 (original PR3↔PR4 matrix) | PR4 captures were taken before slideOver settled — **corrected** in canonical PR4 s06 files |
| Canonical s17 | PR3 baseline lacked lightbox-open state |
| Canonical s10–s12 | Different connector fixture names/states (`Visual Baseline Adobe` vs `GAP-024 Visual Fixture`) |
| Canonical s15 | PR3 lacked cart-dropdown-open state |
| Canonical s20 | PR3 lacked toast-visible state |

## Supplemental like-for-like metrics

| Group | Compared pairs | Identical | Negligible | Framework delta | Fixture noise | High-risk | Missing F4 |
|---|---:|---:|---:|---:|---:|---:|---:|
| s06-viewaction | 6 | 0 | 0 | 6 | 0 | 0 | 0 |
| s10-connector-list | 6 | 6 | 0 | 0 | 0 | 0 | 0 |
| s11-connector-detail | 6 | 3 | 2 | 1 | 0 | 0 | 0 |
| s12-connector-history | 6 | 3 | 2 | 1 | 0 | 0 | 0 |
| s15-qty-cart | 6 | 3 | 0 | 3 | 0 | 0 | 0 |
| s17-lightbox | 6 | 0 | 0 | 0 | 6 | 0 | 0 |
| s20-toast | 6 | 0 | 0 | 6 | 0 | 0 | 0 |

## Per-surface interpretation (supplemental F4→F5)

| Surface | Verdict |
|---|---|
| **s06** | ViewAction slideOver open and settled on both sides. 1–5% framework chrome delta vs PR3 supplemental F4. |
| **s10** | Identical connector list with `GAP-024 Visual Fixture` / `visual-fixture-store` / `Не перевірено`. |
| **s11** | Same connector detail fixture; ≤1.3% framework delta (F5 form chrome). |
| **s12** | Same connection-history rows (succeeded / failed / queued); ≤1.3% framework delta. |
| **s15** | Cart dropdown open (`Кошик`, line item, `Разом`); toast dismissed; ≤2.4% framework delta. |
| **s17** | Lightbox open with deterministic `picsum` seed image on both sides. High pixel % is **catalog background fixture drift** (seeder/date differences between `eb23a62` and PR4), not lightbox regression. |
| **s20** | Toast `Додано до кошика` visible on both sides; 2–10% framework notification chrome delta. |

## Per-file results

| File | F4 source | Pixel diff % | Classification |
|---|---|---:|---|
| `pr4-f5-s06-product-context-viewaction-dark-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament4-pr3/supplemental-s06/pr3-f4-s06-product-context-viewaction-dark-desktop.webp` | 1.71 | framework-delta |
| `pr4-f5-s06-product-context-viewaction-dark-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament4-pr3/supplemental-s06/pr3-f4-s06-product-context-viewaction-dark-mobile.webp` | 4.88 | framework-delta |
| `pr4-f5-s06-product-context-viewaction-light-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament4-pr3/supplemental-s06/pr3-f4-s06-product-context-viewaction-light-desktop.webp` | 1.08 | framework-delta |
| `pr4-f5-s06-product-context-viewaction-light-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament4-pr3/supplemental-s06/pr3-f4-s06-product-context-viewaction-light-mobile.webp` | 2.13 | framework-delta |
| `pr4-f5-s06-product-context-viewaction-system-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament4-pr3/supplemental-s06/pr3-f4-s06-product-context-viewaction-system-desktop.webp` | 1.07 | framework-delta |
| `pr4-f5-s06-product-context-viewaction-system-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament4-pr3/supplemental-s06/pr3-f4-s06-product-context-viewaction-system-mobile.webp` | 2.13 | framework-delta |
| `pr4-f5-s10-connector-account-list-dark-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s10-connector-list/pr4-f4-s10-connector-account-list-dark-desktop.webp` | 0.00 | identical |
| `pr4-f5-s10-connector-account-list-dark-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s10-connector-list/pr4-f4-s10-connector-account-list-dark-mobile.webp` | 0.00 | identical |
| `pr4-f5-s10-connector-account-list-light-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s10-connector-list/pr4-f4-s10-connector-account-list-light-desktop.webp` | 0.00 | identical |
| `pr4-f5-s10-connector-account-list-light-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s10-connector-list/pr4-f4-s10-connector-account-list-light-mobile.webp` | 0.00 | identical |
| `pr4-f5-s10-connector-account-list-system-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s10-connector-list/pr4-f4-s10-connector-account-list-system-desktop.webp` | 0.00 | identical |
| `pr4-f5-s10-connector-account-list-system-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s10-connector-list/pr4-f4-s10-connector-account-list-system-mobile.webp` | 0.00 | identical |
| `pr4-f5-s11-connector-account-detail-dark-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s11-connector-detail/pr4-f4-s11-connector-account-detail-dark-desktop.webp` | 1.33 | framework-delta |
| `pr4-f5-s11-connector-account-detail-dark-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s11-connector-detail/pr4-f4-s11-connector-account-detail-dark-mobile.webp` | 0.00 | identical |
| `pr4-f5-s11-connector-account-detail-light-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s11-connector-detail/pr4-f4-s11-connector-account-detail-light-desktop.webp` | 0.43 | negligible |
| `pr4-f5-s11-connector-account-detail-light-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s11-connector-detail/pr4-f4-s11-connector-account-detail-light-mobile.webp` | 0.00 | identical |
| `pr4-f5-s11-connector-account-detail-system-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s11-connector-detail/pr4-f4-s11-connector-account-detail-system-desktop.webp` | 0.43 | negligible |
| `pr4-f5-s11-connector-account-detail-system-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s11-connector-detail/pr4-f4-s11-connector-account-detail-system-mobile.webp` | 0.00 | identical |
| `pr4-f5-s12-connector-connection-history-dark-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s12-connector-history/pr4-f4-s12-connector-connection-history-dark-desktop.webp` | 1.33 | framework-delta |
| `pr4-f5-s12-connector-connection-history-dark-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s12-connector-history/pr4-f4-s12-connector-connection-history-dark-mobile.webp` | 0.00 | identical |
| `pr4-f5-s12-connector-connection-history-light-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s12-connector-history/pr4-f4-s12-connector-connection-history-light-desktop.webp` | 0.43 | negligible |
| `pr4-f5-s12-connector-connection-history-light-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s12-connector-history/pr4-f4-s12-connector-connection-history-light-mobile.webp` | 0.00 | identical |
| `pr4-f5-s12-connector-connection-history-system-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s12-connector-history/pr4-f4-s12-connector-connection-history-system-desktop.webp` | 0.43 | negligible |
| `pr4-f5-s12-connector-connection-history-system-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s12-connector-history/pr4-f4-s12-connector-connection-history-system-mobile.webp` | 0.00 | identical |
| `pr4-f5-s15-qty-cart-checkout-dark-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s15-qty-cart/pr4-f4-s15-qty-cart-checkout-dark-desktop.webp` | 2.42 | framework-delta |
| `pr4-f5-s15-qty-cart-checkout-dark-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s15-qty-cart/pr4-f4-s15-qty-cart-checkout-dark-mobile.webp` | 0.00 | identical |
| `pr4-f5-s15-qty-cart-checkout-light-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s15-qty-cart/pr4-f4-s15-qty-cart-checkout-light-desktop.webp` | 1.06 | framework-delta |
| `pr4-f5-s15-qty-cart-checkout-light-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s15-qty-cart/pr4-f4-s15-qty-cart-checkout-light-mobile.webp` | 0.00 | identical |
| `pr4-f5-s15-qty-cart-checkout-system-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s15-qty-cart/pr4-f4-s15-qty-cart-checkout-system-desktop.webp` | 1.08 | framework-delta |
| `pr4-f5-s15-qty-cart-checkout-system-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s15-qty-cart/pr4-f4-s15-qty-cart-checkout-system-mobile.webp` | 0.00 | identical |
| `pr4-f5-s17-product-photo-lightbox-dark-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s17-lightbox/pr4-f4-s17-product-photo-lightbox-dark-desktop.webp` | 50.23 | fixture-noise |
| `pr4-f5-s17-product-photo-lightbox-dark-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s17-lightbox/pr4-f4-s17-product-photo-lightbox-dark-mobile.webp` | 48.15 | fixture-noise |
| `pr4-f5-s17-product-photo-lightbox-light-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s17-lightbox/pr4-f4-s17-product-photo-lightbox-light-desktop.webp` | 55.79 | fixture-noise |
| `pr4-f5-s17-product-photo-lightbox-light-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s17-lightbox/pr4-f4-s17-product-photo-lightbox-light-mobile.webp` | 41.18 | fixture-noise |
| `pr4-f5-s17-product-photo-lightbox-system-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s17-lightbox/pr4-f4-s17-product-photo-lightbox-system-desktop.webp` | 49.42 | fixture-noise |
| `pr4-f5-s17-product-photo-lightbox-system-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s17-lightbox/pr4-f4-s17-product-photo-lightbox-system-mobile.webp` | 49.22 | fixture-noise |
| `pr4-f5-s20-toasts-notifications-dark-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s20-toast/pr4-f4-s20-toasts-notifications-dark-desktop.webp` | 2.07 | framework-delta |
| `pr4-f5-s20-toasts-notifications-dark-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s20-toast/pr4-f4-s20-toasts-notifications-dark-mobile.webp` | 2.57 | framework-delta |
| `pr4-f5-s20-toasts-notifications-light-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s20-toast/pr4-f4-s20-toasts-notifications-light-desktop.webp` | 4.35 | framework-delta |
| `pr4-f5-s20-toasts-notifications-light-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s20-toast/pr4-f4-s20-toasts-notifications-light-mobile.webp` | 2.61 | framework-delta |
| `pr4-f5-s20-toasts-notifications-system-desktop.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s20-toast/pr4-f4-s20-toasts-notifications-system-desktop.webp` | 4.23 | framework-delta |
| `pr4-f5-s20-toasts-notifications-system-mobile.webp` | `docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental/s20-toast/pr4-f4-s20-toasts-notifications-system-mobile.webp` | 10.17 | framework-delta |

## Migration regression count (supplemental like-for-like only)

**0** — no supplemental pairs show layout breakage, missing controls, or stuck/off-screen slideOver after valid state alignment.

Original PR3/PR4 canonical matrix files (except corrected PR4 s06) remain as historical evidence only.
