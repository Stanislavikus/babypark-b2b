# TASK FOR GROK 4.6 — Correct Product Workbench visual prototype once

**Project:** B2B Product Data Platform
**Repository:** `Stanislavikus/babypark-b2b`
**Branch:** `docs/product-workbench-visual-ux-study`
**Starting HEAD:** `e1306768e12d20e5de8892ff1a2897e5880aafb7`
**Task type:** narrow visual correction only — NO architecture redesign, NO runtime implementation.

## Goal

Correct the existing Product Workbench prototype according to the Lead arbitration:

`docs/reviews/PRODUCT_WORKBENCH_GROK_VISUAL_ARBITRATION_2026_09_21.md`

Do not restart design from scratch. Preserve the parts already accepted.

## Required corrections

1. **Publication checkboxes**
   - remove row and Select-all checkboxes from first-scope Publication;
   - do not claim a bulk review action exists.

2. **Publication execution action**
   - remove row-level `Передати зміни`;
   - show configuration-level causal action above the Publication table:
     `Перевірити`, `Перевірити знову`, or `Передати зміни` depending on demonstrated state;
   - row `Наступна дія` is remediation only;
   - ready row has no per-row Live action.

3. **Links evidence**
   - remove invented fuzzy/similarity states such as `Є схожий товар`, `Близька назва, інший SKU`, or pre-ranked candidates;
   - first-scope Links shows factual trust state + current safe `Пов'язати/Переглянути` flow only;
   - candidate search may appear after merchant opens the link action, but do not claim automatic scoring.

4. **Overview Problems**
   - do not show a generic problem count just because a row is unlinked;
   - omit Problems in current prototype unless supported by independent evidence;
   - keep Link state separate.

5. **Select Products UX**
   - replace the toy two-row picker with a scalable table-oriented selector concept;
   - include search and representative filters;
   - show multi-select only here because selecting Products for Publication is a real membership operation;
   - alternatively annotate that first implementation routes to current ProductResource channel-context grid.

6. **Product drawer Basic tab**
   - do not imply editable Product name/brand/category unless current Product edit ownership permits it;
   - show core Product data read-only in this prototype;
   - keep Magento Category / Attribute Set corrections in Magento tab.

7. **Drawer tabs first scope**
   - show only `Основне` and `Magento` in the corrected first-scope merchant UI;
   - future `Контент / SEO / Медіа / Історія` may be mentioned in annotations only.

8. **CREATE unavailable state**
   - replace disabled per-row CREATE button with quiet neutral state `Ще немає в Magento` / no action;
   - no enabled CREATE anywhere.

## Hybrid visual target

Do not choose A or B wholesale.

- use Variant B-style page hierarchy and one-line View purpose;
- use Variant A-style operational table density, adjusted to normal readable Filament density;
- target roughly 14px body text and 40–44px rows, not A's very tiny density and not B's ~57px rows;
- use wider calmer drawer closer to B;
- keep search visually primary;
- keep Filters/Columns secondary;
- preserve all frozen row-universe semantics.

## Deliverables

Update the same prototype files and regenerate screenshots for the corrected **Hybrid**:

- `hybrid_overview_current_projection.png`
- `hybrid_overview_projection_v2.png`
- `hybrid_publication.png`
- `hybrid_links.png`
- `hybrid_product_drawer.png`
- `hybrid_select_products.png`

Update the visual annotation document so it no longer claims unsupported bulk review or similarity scoring.

Keep old A/B screenshots for history unless there is a strong reason not to.

## Validation

- `git diff --check`
- no changes under `app/**`, migrations, runtime, tests or `[Resolved]` contracts;
- report changed files, final HEAD and clean tree.

## Do not

- add new persistence/status enums;
- redesign Decision A/B;
- implement CREATE/import;
- invent similarity/confidence engine;
- turn Live into per-Product execution;
- implement runtime code.