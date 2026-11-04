# GAP-024 PR3 — Filament 4 / Tailwind 4 visual comparison

Compared against PR1 Filament 3 baseline in
`docs/audits/visual-baselines/gap-024-filament3/` (manifest:
`docs/audits/GAP-024-pr1-visual-baseline-manifest.md`).

PR3 after-captures live in this directory (144 WebP files, filename-parity
with the PR1 baseline). Ephemeral copies also under
`/opt/cursor/artifacts/gap-024-pr3-filament4-visual/`.

Correction-pass updates (this revision):

* **s05** after-captures replaced at the manifest md-contract widths
  (desktop **1024×900**, mobile **767×900**).
* **s06** like-for-like ViewAction-open evidence added under
  `supplemental-s06/` (does not overwrite historical PR1 baseline files).

## Capture method

- Playwright Chromium headless against `php artisan serve` on this PR3 branch
- Authenticated storage state for admin (`admin@babypark.ua`) and cabinet
  (`dytiachyi-svit`)
- Themes: light / dark / system via document `dark` class + colorScheme
- Viewports: desktop 1280×900, mobile 390×844 (**except s05** — see below)
- Output: WebP quality 85

## Matrix result

| Metric | Count |
|---|---|
| Required captures | 144 |
| Captured | 144 |
| Filename parity with PR1 | yes |
| Heavy duplicate hashes | none |
| Supplemental s06 pairs | 12 (6 F3 + 6 F4) |

## High-risk surface review

| Surface | Verdict | Notes |
|---|---|---|
| s04 product table | Acceptable framework delta | Search remains flex-1 toolbar child; active filter chip + column manager preserved; table density/columns match baseline intent |
| s05 data-list toolbar | Acceptable (corrected widths) | Recaptured at manifest md-contract **1024×900** desktop and **767×900** mobile on `/admin/field-matrix`; prior 1280/390 pair invalidated and replaced |
| s06 product drawer/modal | Valid via supplemental pair | Historical PR1 s06 files are an **invalid baseline pair** (selection-bar / non-ViewAction state). Supplemental F3@`b45e013` + F4@PR3 ViewAction-open captures provide the like-for-like gate — see `supplemental-s06/README.md` |
| s07 forms | Acceptable | Product / price-list / delivery edit forms captured; Filament 4 schema section chrome differs slightly |
| s09 Field Matrix + Governance | Acceptable | Custom Blade pages render |
| s10–s12 connector accounts | Acceptable | List + detail + history captured with seeded fixture account |
| s13 cabinet catalogue table | Acceptable | Cart toolbar hook present |
| s15 qty/cart | Acceptable within baseline limit | Cart/quantity surface; no checkout page (same as PR1) |
| s17 lightbox | Captured | Livewire `/catalog` dark/system still identical to light (pre-existing non-Filament limitation; not fixed in PR3) |
| Theme modes | Acceptable | Light/dark class switching works on Filament panels |

## s05 correction detail

| Item | Value |
|---|---|
| Route | `/admin/field-matrix` |
| Desktop contract | **1024×900** (was incorrectly 1280×900) |
| Mobile contract | **767×900** (was incorrectly 390×844) |
| Themes | light / dark / system |
| Durable files | `s05-data-list-toolbar-*.webp` in this directory (replaced) |
| Review artifacts | `/opt/cursor/artifacts/gap-024-pr3-s05-corrected/` |

## s06 supplemental detail

| Item | Value |
|---|---|
| Manifest state | `/admin/products` → neutral row click → ViewAction slideOver |
| Historical PR1 files | **Not overwritten** — treated as baseline capture defect |
| F3 reference | Worktree at `b45e013` — `pr1-f3-s06-product-context-viewaction-*.webp` |
| F4 PR3 | `pr3-f4-s06-product-context-viewaction-*.webp` |
| Viewports | desktop 1280×900, mobile 390×844 |
| Like-for-like valid? | **Yes** (supplemental set) |
| Functional check | ViewAction opens with product fields (`Артикул`, name, pricing); no bulk/selection-only state |

Unavoidable framework chrome delta on the valid pair: Filament 4 slide-over
markup/section cards differ from Filament 3 while preserving the same
interaction and product content.

## Unavoidable framework differences (documented, not blockers)

1. **Filament 4 modal/drawer chrome** — ViewAction presentation uses F4
   slide-over/modal markup (header/footer, section cards) instead of F3
   styling (confirmed on the supplemental like-for-like pair).
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

* `/opt/cursor/artifacts/gap-024-pr3-visual-review/`
* `/opt/cursor/artifacts/gap-024-pr3-visual-review/s05-corrected/`
* `/opt/cursor/artifacts/gap-024-pr3-visual-review/s06-supplemental/`
