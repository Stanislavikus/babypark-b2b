# GAP-024 PR1 — Filament 3 visual baseline manifest

This manifest records the **pre-migration visual baseline** captured from current
Filament 3 on `develop` before GAP-024 PR3/PR4 UI work begins.

## Baseline commit

`b45e01385778a9fd69b7051389452f447ad9a85d` (merged PR #108 — Customer
`FilamentUser` contract)

## Capture method

Authenticated browser screenshots at desktop viewport (~1280px). Light theme for
most surfaces; dark theme samples for admin dashboard and cabinet products.
No session cookies, credentials, or customer-identifying data are stored in this
repository artifact set.

## Evidence location

Screenshots and `BASELINE_REPORT.md` are stored outside the application repo at:

```text
/opt/cursor/artifacts/gap-024-baseline/
```

PR review attachments may also reference these paths from the Cloud Agent run that
captured them during GAP-024 PR1.

## Surfaces captured (audit §16 mapping)

| # | Surface | Route / context | Theme | File |
|---|---------|-----------------|-------|------|
| 1 | Admin login | `/admin/login` | Light | `01-admin-login-light.webp` |
| 2 | Cabinet login | `/cabinet/login` | Light | `02-cabinet-login-light.webp` |
| 3 | Admin dashboard | `/admin` | Light | `03-admin-dashboard-light.webp` |
| 4 | Admin products table | `/admin/products` | Light | `04-admin-products-table-light.webp` |
| 5 | Admin price lists | `/admin/price-lists` | Light | `05-admin-price-lists-light.webp` |
| 6 | Admin customer edit form | customer edit page | Light | `06-admin-customer-edit-form-light.webp` |
| 7 | Cabinet products | `/cabinet/products` | Light | `07-cabinet-products-light.webp` |
| 8 | Cabinet products | `/cabinet/products` | Dark | `08-cabinet-products-dark.webp` |
| 9 | Admin dashboard | `/admin` | Dark | `09-admin-dashboard-dark.webp` |

## Surfaces not captured in PR1

The following §16 high-value surfaces remain for manual or follow-up capture
during PR3 visual verification if not already covered by the files above:

- Shared data-list toolbar responsive `md` breakpoint behavior
- Product context drawer / modal
- Field Matrix, Governance, Price Inspector custom pages (partial overlap with admin forms)
- Connector account list/detail/history polling UI
- B2B quantity selector, cart drawer, checkout
- Availability/pricing display tokens
- Product photo lightbox overlay
- Mobile breakpoints for both panels
- System appearance mode (only Light/Dark samples captured)
- Toast/notification stacking

PR3/PR4 must compare against this baseline **plus** any additional captures taken
before those PRs merge if gaps remain material to the changed surfaces.

## Usage during PR3/PR4

1. Check out the baseline commit or open the stored screenshots.
2. Re-capture the same routes after migration on the same viewport/theme where possible.
3. Treat any unexplained layout, spacing, palette, or `novalidate`/form-regression
   change as a merge blocker per GAP-024 §17.
