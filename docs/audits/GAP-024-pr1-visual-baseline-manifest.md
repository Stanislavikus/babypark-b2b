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

The baseline screenshots are **not** stored inside this git repository.

### Cloud Agent capture (ephemeral)

During GAP-024 PR1, a Cursor Cloud Agent captured 11 `.webp` files on its own VM
at `/opt/cursor/artifacts/gap-024-baseline/`. That path is **not present** on the
pilot host, smoke checkout, or any normal deployment server — do not expect to find
it under `/opt/cursor/artifacts/` when reviewing on production infrastructure.

### Durable references for review

Use one or more of these instead:

1. **PR #109 review attachments / walkthrough** — the Cloud Agent run attached the
   baseline screenshots to the PR for human review.
2. **Re-capture on the pilot smoke checkout** — recommended for server-side parity.
   On `/var/www/babypark-b2b-smoke` (or equivalent), check out baseline commit
   `b45e01385778a9fd69b7051389452f447ad9a85d`, run the app, and capture the
   surfaces listed in the table below at desktop ~1280px. Store captures outside
   the application repo (for example a host-local `~/gap-024-baseline/` directory).

The filenames in the table below are the **canonical naming convention** for
either PR attachments or a host-local re-capture set.

## Surfaces captured (audit §16 mapping)

Canonical 11-file baseline set on commit `b45e013` (final Cloud Agent capture;
filenames below should be reused for any host-local re-capture):

| # | Surface | Route / context | Theme | File |
|---|---------|-----------------|-------|------|
| 1 | Admin login | `/admin/login` | Light | `01-admin-login.webp` |
| 2 | Admin login (form) | `/admin/login` | Light | `02-admin-login-form.webp` |
| 3 | Admin dashboard | `/admin` | Light | `03-admin-dashboard.webp` |
| 4 | Admin products table | `/admin/products` | Light | `04-admin-products-list.webp` |
| 5 | Cabinet login | `/cabinet/login` | Light | `05-cabinet-login.webp` |
| 6 | Cabinet dashboard | `/cabinet` | Light | `06-cabinet-dashboard-light.webp` |
| 7 | Cabinet products | `/cabinet/products` | Light | `07-cabinet-products-light.webp` |
| 8 | Cabinet products | `/cabinet/products` | Dark | `08-cabinet-products-dark.webp` |
| 9 | Admin products table | `/admin/products` | Dark | `09-admin-products-dark.webp` |
| 10 | Admin dashboard | `/admin` | Dark | `10-admin-dashboard-dark.webp` |
| 11 | Cabinet dashboard | `/cabinet` | Dark | `11-cabinet-dashboard-dark.webp` |

## Surfaces not captured in PR1

The following §16 high-value surfaces remain for manual or follow-up capture
during PR3 visual verification if not already covered by the files above:

- Shared data-list toolbar responsive `md` breakpoint behavior
- Product context drawer / modal
- Field Matrix, Governance, Price Inspector custom pages
- Admin price lists and customer edit form (captured in an earlier partial Cloud
  Agent run with alternate filenames; not in the canonical 11-file set above)
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

1. Open PR #109 attachments **or** re-capture on smoke checkout at `b45e013`.
2. Re-capture the same routes after migration on the same viewport/theme where possible.
3. Treat any unexplained layout, spacing, palette, or `novalidate`/form-regression
   change as a merge blocker per GAP-024 §17.
