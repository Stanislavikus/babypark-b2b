# Product Workbench — Fable visual prototypes — 2026-09-21

> **Status: NON-RUNTIME VISUAL STUDY — does not reopen the [Resolved] structural contract.**
>
> Renderable screens: `docs/prototypes/product-workbench-visual-ux/index.html`
>
> Frozen base:
> - `docs/reviews/PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md`
> - `docs/reviews/PRODUCT_WORKBENCH_LEAD_VISUAL_UX_STUDY_2026_09_21.md`
> - `docs/reviews/PRODUCT_WORKBENCH_FABLE_PROTOTYPE_TASK_2026_09_21.md`

This note records **visual hierarchy only**. It does not add persistence, status enums, CREATE, import, or mixed default tables.

Both variants share the same shell:

```text
Magento
Adobe Commerce · Babypark UA Store                    [Оновити каталог]
Підключено · Каталог оновлено 18 хв тому · 1 026 товарів
[ Огляд ]  [ Публікація ]  [ Зв'язки ]
[ search ................................ ]  Фільтри  Колонки
```

## Shared placement rules

1. **Channel title first.** `Magento` is the page name; the account is secondary. The merchant should recognise “this is my store” before reading any row.
2. **Connection/freshness is a status line, not a product status.** It never sits in the table and is not mixed with readiness.
3. **`Оновити каталог` is secondary.** Gray utility, top-right, never a row of peer primaries.
4. **Tabs are universe switchers.** They are not filters. Огляд, Публікація and Зв'язки keep separate row sets.
5. **Search is visually primary** in the toolbar. Filters and columns are labeled, discoverable, and quieter.
6. **No decorative checkboxes.** Overview and Links have none. Publication has them because a bulk review action is real there.
7. **Statuses stay split.** Magento/provider state, link state, readiness, problem count and last publication result are adjacent badges/columns, never one chip.

---

## Variant A — dense operational

Closer to a mature PIM grid: compact rows, more of a 1 026-row catalogue on one screen.

### A1. Огляд

**Hierarchy.** Title + freshness consume little vertical space. Tabs are tight. Search is the longest control. Rows stay ~36px so SKU scanning feels like a spreadsheet.

**Phase-1 columns.** SKU, name, type, Magento state, link, updated. Thumbnail/Brand/Category are omitted so the grid does not lie about Projection V2.

**Phase V2.** Thumbnail, brand and category breadcrumb appear only in the target state. Category filter uses a tree (Дитячі товари → Коляски → Прогулянкові), not two fixed domain levels.

**Why controls sit here.** `Пов'язати` is a row action on the one unlinked Avent bottle. Nothing in the header asks the merchant to “go to remote catalogue” — Overview already is that catalogue.

### A2. Публікація

**Hierarchy.** Same shell. The only header-level primary is `Вибрати товари`. Classification, readiness, next action and last result are table columns, not a second toolbar.

**Row stories shown.**

- Ready Cybex Priam → `Передати зміни` (supported Update).
- Avent missing Magento category → `Вказати категорію`.
- Pampers with stale check → `Перевірити знову`.
- LEGO not in Magento → disabled `Створення недоступне` (V1 has no CREATE).

**Why.** Causal action lives in the row. Dense Variant A uses a text link so many rows stay comparable.

### A3. Зв'язки

**Hierarchy.** Magento identity first, then link state, then Master Product, then a hint. No bulk checkbox strip.

**Why.** First-scope work is review/confirmation. “Є схожий товар” is evidence, not trust. `Пов'язати` opens a confirmation dialog.

### A4. Product drawer

**Hierarchy.** Identity header, then section tabs. First visual focuses Основне + Magento.

**Why Magento fields are in the drawer.** Category tree and `Набір характеристик` are product/account exceptions, not a permanent Workbench settings button. `Повернути автоматичний вибір` sits on the override itself. A ProductType recommendation that disagrees with the existing Magento set is a warning, not an implicit change.

---

## Variant B — calmer merchant-first

Same frozen semantics, more air and stronger page voice for a non-technical merchandiser.

### B1. Огляд

**Hierarchy.** Larger `Magento` title. A one-line purpose under the tabs: “Товари, які зараз є у вашому магазині Magento.” Names are heavier than SKUs. Badges are larger. Rows are taller so type, state and link can be read without decoding a dense PIM.

**Why.** The merchant still gets a table, not hero cards. Extra copy only explains *which universe* they are in.

### B2. Публікація

**Hierarchy.** Purpose line: preparing this Magento. Next actions are real buttons in the row so “what do I do now?” is the loudest cell after the product name.

**Why.** Whitespace separates readiness from last result. Disabled CREATE stays a calm sentence, not a hidden capability.

### B3. Зв'язки

**Hierarchy.** Master column labelled `Товар у каталозі`. Hint column uses plain evidence (“Близька назва, інший SKU”). Row action is a labeled button.

**Why.** Calmer type still forbids auto-linking. Confirmation remains mandatory.

### B4. Product drawer

**Hierarchy.** Wider panel, larger section tabs, helper text that the grid stays open. Override callout and reset are grouped. SEO/Media/History are empty-capable sections without invented AI.

---

## Evaluation check (both variants)

A new merchant can answer, from the default Overview and the two sibling tabs:

| Question | Where it is answered |
|---|---|
| Это точно мой Magento-магазин? | Title + account + freshness + remote rows |
| Какие товары уже есть там? | Огляд |
| Какие товары я готовлю к публикации? | Публікація |
| Что связано с моим Master Catalogue? | Зв'язки + link column on Огляд |
| Что сейчас требует моего действия? | Row-level `Пов'язати` / classification / `Перевірити знову` / disabled CREATE |

## Out of scope (unchanged)

Remote Catalogue Projection V2 implementation, Decision-B persistence, planner migration, Magento CREATE, remote-only import.
