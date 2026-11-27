# TASK FOR FABLE — Product Workbench visual prototype

**Project:** B2B Product Data Platform
**Repository:** `Stanislavikus/babypark-b2b`
**Branch:** `docs/product-workbench-visual-ux-study`
**Authoritative product base:** `origin/develop @ 25296a81710cb23fa9a14dd026bf3fc31ea9ccaa`
**Task type:** visual UX prototyping only — NO domain redesign, NO code.

## Goal

Create a merchant-facing desktop prototype for the Magento Product Workbench that makes an existing Magento store immediately recognizable and keeps remote observation, outbound publication preparation, and identity linking clearly separated.

## Mandatory reading

1. `docs/reviews/PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md` — [Resolved], must not be reinterpreted.
2. `docs/reviews/PRODUCT_WORKBENCH_LEAD_VISUAL_UX_STUDY_2026_09_21.md` — Lead visual/interaction study.
3. `docs/06-UI_DESIGN_SYSTEM.md` — table-first and shared UI rules.
4. `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md` — merchant/operator vocabulary boundaries.

Current implementation reference only:

- `app/Filament/Pages/Sync/ManageAdobeProductsChannel.php`
- `app/Filament/Pages/Sync/ManageAdobeRemoteCatalog.php`
- `app/Filament/Resources/ProductResource.php`

Do not treat current navigation as authoritative where it conflicts with the [Resolved] 2026-09-21 Workbench contract.

## Frozen semantics

Three system Views:

- `Огляд` = current successful Magento Remote Catalogue rows;
- `Публікація` = local Master Products selected/linked for outbound preparation;
- `Зв'язки` = remote↔Master correspondence/matching.

Never merge these row universes into one default table.

Remote rows are provider observation. Master Product is platform truth. `ExternalRecordLink` is trusted correspondence.

Do not create one mega-status combining provider state, link state, readiness and last run result.

Current Magento V1 does NOT support Product CREATE. Do not render an enabled `Створити в Magento` action.

## Required prototypes

### 1. Magento — Огляд

Target visual layout:

- page title `Magento`;
- secondary account name;
- compact connection/catalogue freshness line;
- secondary `Оновити каталог` action;
- tabs `Огляд / Публікація / Зв'язки`;
- table-first layout;
- search, filters and column controls;
- row density suitable for thousands of products.

Create two states:

**Phase-1 current projection:**
- SKU;
- name;
- product type;
- Magento/provider status;
- link state;
- updated/freshness.

Do NOT show fake empty Thumbnail/Brand/Category columns in this phase.

**Target after Projection V2:**
- thumbnail;
- SKU;
- name;
- brand;
- category breadcrumb;
- Magento/provider state;
- link state;
- problems;
- updated.

Show one unlinked row with contextual `Пов'язати` action.

### 2. Magento — Публікація

Target columns:

- checkbox when bulk action exists;
- thumbnail;
- SKU;
- name;
- Magento Category;
- `Набір характеристик` (merchant wording for Attribute Set);
- readiness;
- problem count;
- causal next action;
- last publication result.

Top-level action:
`Вибрати товари`.

Do NOT show both `Основний каталог` and `Вибрати товари`.

Show examples of:

- ready Product;
- Product blocked by missing mapping/classification;
- stale Preview requiring `Перевірити знову`;
- Product where current V1 can Update but not Create.

### 3. Magento — Зв'язки

Target columns:

- Magento SKU;
- Magento name;
- link state;
- Master Product/candidate;
- evidence/confidence presentation if useful;
- row action.

First-scope row action remains review/confirmation, not automatic trust.

Do not imply candidate similarity automatically creates a link.

### 4. Product drawer

Prototype a side drawer that preserves grid context.

Sections may include:

- Основне;
- Magento;
- Контент;
- SEO;
- Медіа;
- Історія.

For the first visual, focus on Basic + Magento and show how Category/Attribute Set correction would look.

Provide a clear `Повернути автоматичний вибір` control for sparse overrides.

Do not invent AI/SEO functionality that is not yet shipped.

## Visual constraints

- fit the existing Filament/Tailwind admin language; do not redesign the whole application;
- table-first, dense but calm;
- no giant hero cards;
- no permanent row of 8–10 primary buttons;
- search should stay visually primary;
- filters/columns should be discoverable but secondary;
- statuses should be compact badges/indicators but remain semantically separate;
- long descriptions/meta text must not be default grid columns;
- Category filter should visually support hierarchy/tree selection;
- avoid raw technical vocabulary such as `attribute_set_id`, `entity_id`, `snapshot`, `projection`, HTTP codes.

## Required output

Produce **two visual treatments** of the same frozen semantics:

### Variant A — dense operational
Closer to mature PIM/table productivity: compact rows, more information visible at once.

### Variant B — calmer merchant-first
Slightly more whitespace and stronger visual hierarchy for a non-technical content manager.

For each variant provide:

1. `Огляд` screen;
2. `Публікація` screen;
3. `Зв'язки` screen;
4. Product drawer;
5. short annotation of visual hierarchy and why each control is where it is.

## Evaluation criteria

The prototype succeeds if a new merchant can answer in under a few seconds:

- “Это точно мой Magento-магазин?”
- “Какие товары уже есть там?”
- “Какие товары я готовлю к публикации?”
- “Что связано с моим Master Catalogue?”
- “Что сейчас требует моего действия?”

## Do not

- redefine A+B;
- add new persistence or status enums;
- invent CREATE/import availability;
- make Remote Catalogue editable Product truth;
- collapse statuses;
- turn the prototype into a wizard;
- design new settings architecture;
- solve Magento CREATE in this task.
