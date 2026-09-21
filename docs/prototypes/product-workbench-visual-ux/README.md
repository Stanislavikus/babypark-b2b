# Product Workbench visual prototype (NON-RUNTIME)

Static Filament-language prototype for the Magento Product Workbench.

**Does not change runtime code.** Frozen semantics come only from:

- `docs/reviews/PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md` `[Resolved]`
- `docs/reviews/PRODUCT_WORKBENCH_LEAD_VISUAL_UX_STUDY_2026_09_21.md`
- `docs/reviews/PRODUCT_WORKBENCH_FABLE_PROTOTYPE_TASK_2026_09_21.md`

Open `index.html` in a browser (or serve this folder). Prototype chrome at the top is **not** merchant UI.

Deep links also work with query strings, for example `?capture=1&variant=b&view=publication` (hides chrome). Hash form: `#a/overview/p1`.

## Variants

| Chrome control | Meaning |
|---|---|
| Variant A | Dense operational PIM/table productivity |
| Variant B | Calmer merchant-first hierarchy and spacing |
| Огляд / Публікація / Зв'язки | Frozen system Views / row universes |
| Current projection | Phase-1 Overview columns only |
| After Projection V2 | Target Overview after scalable thumbnail/brand/category |
| Product drawer | Side panel over the grid |
| Category filter | Hierarchical tree, not two hard-coded levels |
| Link review | Confirmation before trusted correspondence |
| Empty scan | Overview with no successful catalogue read yet |

## What is intentionally absent

- Enabled `Створити в Magento`
- Peer `Основний каталог` next to `Вибрати товари`
- Fake Thumbnail/Brand/Category columns in Phase 1
- One mega-status combining Magento state, link, readiness and last run
- AI/SEO invented actions
- Raw `attribute_set_id`, `entity_id`, snapshot, projection, HTTP codes

Short visual-hierarchy notes: `docs/reviews/PRODUCT_WORKBENCH_FABLE_VISUAL_PROTOTYPES_2026_09_21.md`.
