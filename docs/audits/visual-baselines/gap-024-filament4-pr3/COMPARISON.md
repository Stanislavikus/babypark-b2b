# GAP-024 PR3 — Filament 4 / Tailwind 4 visual comparison

Compared against PR1 Filament 3 baseline in
`docs/audits/visual-baselines/gap-024-filament3/` (manifest:
`docs/audits/GAP-024-pr1-visual-baseline-manifest.md`).

PR3 after-captures live in this directory (144 WebP files, filename-parity
with the PR1 baseline). Ephemeral copies also under
`/opt/cursor/artifacts/gap-024-pr3-filament4-visual/`.

## Capture method

- Playwright Chromium headless against `php artisan serve` on this PR3 branch
- Authenticated storage state for admin (`admin@babypark.ua`) and cabinet
  (`dytiachyi-svit`)
- Themes: light / dark / system via document `dark` class + colorScheme
- Viewports: desktop 1280×900, mobile 390×844
- Output: WebP quality 85

## Matrix result

| Metric | Count |
|---|---|
| Required captures | 144 |
| Captured | 144 |
| Filename parity with PR1 | yes |
| Heavy duplicate hashes | none |

## High-risk surface review

| Surface | Verdict | Notes |
|---|---|---|
| s04 product table | Acceptable framework delta | Search remains flex-1 toolbar child; active filter chip + column manager preserved; table density/columns match baseline intent |
| s05 data-list toolbar | Acceptable | Field Matrix toolbar captured; PR3 desktop used 1280×900 (baseline used 1024 for md-contract note) |
| s06 product drawer/modal | Acceptable / improved interaction | PR3 opens ViewAction modal with product infolist; PR1 file shows selection-bar state more than an open drawer — not a migration layout break |
| s07 forms | Acceptable | Product / price-list / delivery edit forms captured; Filament 4 schema section chrome differs slightly |
| s09 Field Matrix + Governance | Acceptable | Custom Blade pages render |
| s10–s12 connector accounts | Acceptable | List + detail + history captured with seeded fixture account |
| s13 cabinet catalogue table | Acceptable | Cart toolbar hook present |
| s15 qty/cart | Acceptable within baseline limit | Cart/quantity surface; no checkout page (same as PR1) |
| s17 lightbox | Captured | Livewire `/catalog` dark/system still identical to light (pre-existing non-Filament limitation; not fixed in PR3) |
| Theme modes | Acceptable | Light/dark class switching works on Filament panels |

## Unavoidable framework differences (documented, not blockers)

1. **Filament 4 modal/drawer chrome** — ViewAction presentation uses F4 modal
   markup (header/footer, section cards) instead of F3 slide-over styling.
2. **Tailwind 4 default scale / radius / shadow tokens** — subtle spacing and
   shadow differences on inputs, badges, and cards; brand primary orange and
   `bp-muted-*` tokens preserved via shared theme.
3. **Filament 4 table header toolbar CSS** — native F4 applies `ms-auto` to the
   second toolbar child; project CSS restores search as flex-1 middle child.
4. **Legacy `/catalog` dark mode** — unchanged pre-existing limitation; not a
   PR3 regression.

No unexplained layout collapse, missing toolbar search, broken filters, or
authorization-visible create buttons were observed on reviewed high-risk
surfaces.

## Review artifacts

Side-by-side PNG exports for selected surfaces:

`/opt/cursor/artifacts/gap-024-pr3-visual-review/`
