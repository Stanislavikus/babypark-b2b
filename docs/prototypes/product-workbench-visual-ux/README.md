# Product Workbench visual prototype (NON-RUNTIME)

Static Filament-language prototype for the Magento Product Workbench.

**Does not change runtime code.** This prototype is historical visual research. The authoritative current visual contract is:

- `docs/reviews/PRODUCT_WORKBENCH_VISUAL_UX_CONTRACT_2026_09_22.md` `[Resolved]`

The HTML still contains the historical three-tab `Огляд / Публікація / Зв'язки` prototype for comparison. The 2026-09-22 Stop-and-Amend supersedes that navigation: first-scope top-level Views are `Огляд / Публікація`, while correspondence lives inside `Огляд` through link state/filter/actions.

Research inputs retained for traceability:

- `docs/reviews/PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md` `[Resolved; A3 amended 2026-09-22]`
- `docs/reviews/PRODUCT_WORKBENCH_LEAD_VISUAL_UX_STUDY_2026_09_21.md`
- `docs/reviews/PRODUCT_WORKBENCH_GROK_VISUAL_ARBITRATION_2026_09_21.md`

Open `index.html` in a browser (or serve this folder). Prototype chrome at the top is **not** merchant UI.

Default visual is **Hybrid**. Deep links: `?capture=1&variant=hybrid&view=publication`. Hash form: `#hybrid/overview/p1`.

Corrected Hybrid screenshots:

- `screenshots/hybrid_overview_current_projection.png`
- `screenshots/hybrid_overview_projection_v2.png`
- `screenshots/hybrid_publication.png`
- `screenshots/hybrid_links.png`
- `screenshots/hybrid_product_drawer.png`
- `screenshots/hybrid_select_products.png`

Historical A/B screenshots remain (`a_*.png`, `b_*.png`).

## Chrome

| Control | Meaning |
|---|---|
| Hybrid | Corrected freeze: B hierarchy + Filament table density |
| A / B density | Historical density only; semantic corrections still apply |
| Огляд / Публікація / Зв'язки | Historical prototype navigation only; current `[Resolved]` UX uses top-level `Огляд / Публікація` |
| Current projection | Phase-1 Overview columns only |
| After Projection V2 | Target Overview after scalable thumbnail/brand/category |
| Product drawer | Side panel: Основне + Magento |
| Select products | Table-oriented publication membership |
| Link review | Confirmation after merchant search, no similarity score |

## What is intentionally absent

- Enabled `Створити в Magento` or a disabled per-row CREATE button
- Peer `Основний каталог` next to `Вибрати товари`
- Decorative Publication checkboxes / Select-all
- Per-row `Передати зміни`
- Fake Thumbnail/Brand/Category columns in Phase 1
- Overview Problems column filled from link state
- Invented similarity / candidate ranking in Зв'язки
- First-scope drawer tabs for Контент / SEO / Медіа / Історія
- One mega-status combining Magento state, link, readiness and last run

Short visual-hierarchy notes: `docs/reviews/PRODUCT_WORKBENCH_FABLE_VISUAL_PROTOTYPES_2026_09_21.md`.
