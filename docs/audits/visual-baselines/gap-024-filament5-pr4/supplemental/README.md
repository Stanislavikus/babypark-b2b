# GAP-024 PR4 supplemental like-for-like visual evidence

## Why this exists

Independent review of the canonical PR3↔PR4 144-file matrix found that several high-risk surfaces were **not like-for-like** comparisons (missing modal/toast/drawer, different connector fixtures, or capture timing defects). The original PR3 canonical baseline files are **preserved as historical evidence** and were **not overwritten**.

This directory holds supplemental F4↔F5 pairs captured with:

- the same routes, themes (light/dark/system), and viewports (desktop 1280×900 / mobile 390×844);
- deterministic visual-test fixtures via `scripts/gap-024-visual-fixture-bootstrap.php`;
- robust interaction waits (slideOver settled, cart dropdown open, lightbox open, toast visible).

## Sides

| Prefix | Framework | Code state | Server |
|---|---|---|---|
| `pr4-f4-*` | Filament 4 | Detached worktree at `eb23a62` | `http://127.0.0.1:8766` |
| `pr4-f5-*` | Filament 5 | PR4 branch | `http://127.0.0.1:8765` |
| `pr3-f4-*` (s06 only) | Filament 4 | PR3 supplemental reference | see `gap-024-filament4-pr3/supplemental-s06/` |

s06 F4 comparison uses existing PR3 `supplemental-s06/pr3-f4-*` files (same methodology as the PR3 s06 correction).

## File inventory (84 WebP + report)

| Directory | Files | Interaction state |
|---|---:|---|
| `s06-viewaction/` | 12 | ViewAction slideOver open (`/admin/products`) |
| `s10-connector-list/` | 12 | Connector account list with visual fixture |
| `s11-connector-detail/` | 12 | Connector detail (`Не перевірено`) |
| `s12-connector-history/` | 12 | Connection history section scrolled into view |
| `s15-qty-cart/` | 12 | Quantity set + cart dropdown open (toast dismissed) |
| `s17-lightbox/` | 12 | Catalog lightbox open (`bpOpenLightbox`) |
| `s20-toast/` | 12 | Add-to-cart success toast visible |

Plus `SUPPLEMENTAL-COMPARISON.md` with per-file pixel metrics and classification.

## Capture commands

```bash
# F5 (PR4 branch)
php artisan migrate:fresh --seed
php scripts/gap-024-visual-fixture-bootstrap.php
php artisan serve --host=127.0.0.1 --port=8765
node scripts/gap-024-pr4-supplemental-visual-capture.mjs --side=f5

# F4 (eb23a62 worktree)
git worktree add /tmp/gap024-f4 eb23a62
# … composer install, npm ci && npm run build, sqlite migrate:fresh --seed …
GAP024_APP_ROOT=/tmp/gap024-f4 php scripts/gap-024-visual-fixture-bootstrap.php
php artisan serve --host=127.0.0.1 --port=8766  # in worktree
node scripts/gap-024-pr4-supplemental-visual-capture.mjs --side=f4 \
  --app-root=/tmp/gap024-f4 --base-url=http://127.0.0.1:8766
```

Corrected canonical PR4 s06 files are written to the parent `gap-024-filament5-pr4/` folder during F5 s06 capture.
