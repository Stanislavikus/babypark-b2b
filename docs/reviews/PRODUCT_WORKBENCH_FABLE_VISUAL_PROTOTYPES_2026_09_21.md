# Product Workbench — Hybrid visual prototype — 2026-09-21

> **Status: NON-RUNTIME VISUAL CORRECTION — does not reopen the [Resolved] structural contract.**
>
> Corrected according to `docs/reviews/PRODUCT_WORKBENCH_GROK_VISUAL_ARBITRATION_2026_09_21.md`.
> Renderable screens: `docs/prototypes/product-workbench-visual-ux/index.html`
>
> Historical A/B screenshots remain in `screenshots/` (`a_*.png`, `b_*.png`).
> Corrected Hybrid screenshots: `hybrid_*.png`.

This note records **visual hierarchy only**. It does not add persistence, status enums, CREATE, import, mixed default tables, bulk review, or similarity scoring.

## Hybrid freeze

Do not choose Variant A or B wholesale.

- **B hierarchy:** clearer `Magento` title, one-line View purpose, wider drawer.
- **A operational table:** Filament-like density, about 14px body text and 40–44px rows.
- Search remains visually primary. Filters/Columns stay secondary.
- `Огляд / Публікація / Зв'язки` remain separate row universes.

```text
Magento
Adobe Commerce · Babypark UA Store                    [Оновити каталог]
Підключено · Каталог оновлено 18 хв тому · 1 026 товарів
[ Огляд ]  [ Публікація ]  [ Зв'язки ]
Товари, які зараз є у вашому магазині Magento.
[ search ................................ ]  Фільтри  Колонки
```

## Shared placement rules

1. **Channel title first.** Account name is secondary.
2. **Connection/freshness is a status line**, not a product status.
3. **`Оновити каталог` is secondary.**
4. **Tabs switch row universes**, not filters.
5. **No decorative checkboxes.** Overview, Publication and Links have none in first scope. Multi-select exists only in `Вибрати товари`, because publication membership is a real operation.
6. **Statuses stay split.** Magento/provider state, link state, readiness and last publication result are not one chip.
7. **No Overview Problems column** until independent remote/problem evidence exists. Unlinked is already `Зв'язок`.

---

## Hybrid · Огляд

Remote Magento catalogue. Phase-1 columns: SKU, name, type, Magento state, link, updated. Projection V2 adds thumbnail, brand and category breadcrumb only.

`Пов'язати` remains a row action for unlinked rows.

## Hybrid · Публікація

Local Master Products selected/linked for outbound preparation.

- Principal membership action: `Вибрати товари`.
- Configuration-level causal action above the table: `Перевірити` / `Перевірити знову` / `Передати зміни` from current run state. Demonstrated default: stale evidence → `Перевірити знову`.
- Row `Наступна дія` is remediation only (`Вказати категорію`). Ready rows have no `Передати зміни`.
- `Ще немає в Magento` is a quiet state with no CREATE control.

The selector is a table with search, Category/Brand/ProductType-style filters and multi-select. First implementation may route to the current ProductResource channel-context grid.

## Hybrid · Зв'язки

Factual trust only: `Пов'язано` / `Не пов'язано`, Master Product when trusted, row action `Пов'язати` or `Відкрити`.

No grid copy such as `Є схожий товар` or `Близька назва, інший SKU`. After `Пов'язати`, the merchant may search the catalogue (exact SKU). Similarity score is not claimed.

## Hybrid · Product drawer

First-scope tabs: `Основне` and `Magento`.

- **Основне:** SKU, name, brand, product type read-only.
- **Magento:** Category tree, `Набір характеристик`, `Повернути автоматичний вибір`. Existing Magento Attribute Set mismatch remains advisory.

Future `Контент / SEO / Медіа / Історія` stay in the roadmap, not as empty merchant tabs.

## Evaluation check

| Question | Where it is answered |
|---|---|
| Это точно мой Magento-магазин? | Title + account + freshness + remote rows |
| Какие товары уже есть там? | Огляд |
| Какие товары я готовлю к публикации? | Публікація |
| Что связано с моим Master Catalogue? | Зв'язки + link column on Огляд |
| Что сейчас требует моего действия? | Config-level `Перевірити знову`; row `Пов'язати` / `Вказати категорію` |

## Out of scope (unchanged)

Remote Catalogue Projection V2 implementation, Decision-B persistence, planner migration, Magento CREATE, remote-only import, similarity/candidate ranking, per-Product Live.
