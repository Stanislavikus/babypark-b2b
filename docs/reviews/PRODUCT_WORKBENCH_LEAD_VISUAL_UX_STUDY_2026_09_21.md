# Product Workbench — Lead Visual UX Study — 2026-09-21

> **Status: DRAFT VISUAL/INTERACTION STUDY — NOT [Resolved].**
>
> Authoritative structural base:
> `docs/reviews/PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md`
> ([Resolved — 2026-09-21]).
>
> Repo base inspected: `origin/develop @ 25296a81710cb23fa9a14dd026bf3fc31ea9ccaa`.

## Goal

Turn the newly frozen Product Workbench structure into a merchant-facing interaction model that:

- opens Magento on recognizable current store products;
- keeps publication preparation separate from remote observation;
- keeps linking/matching explicit and safe;
- avoids the current button maze (`Основний каталог`, `Вибрати товари`, `Налаштування даних`, `Перевірити готовність`, separate remote-catalog page);
- reuses current Filament/Product-grid interaction patterns instead of inventing a second UI framework;
- can later add CREATE, Import, SEO, AI and Media without redesigning the page shell.

## Current state verified in code

### Current channel page

`ManageAdobeProductsChannel` currently:

- queries **selected local Products** as its main table;
- shows peer actions for opening Master Catalogue, selecting Products, data setup, and Preview;
- shows Remote Catalogue only as a secondary card linking to another page.

This now conflicts with the resolved 2026-09-21 presentation ordering.

### Current Remote Catalogue page

`ManageAdobeRemoteCatalog` already provides useful building blocks:

- current successful remote snapshot;
- search by name / SKU / remote identifier;
- link-state filter;
- remote type;
- remote provider status;
- remote updated timestamp;
- trusted-link state;
- row-level Entity Trust linking flow.

It does not currently provide:

- thumbnail data from Adobe;
- brand;
- category/path;
- bulk candidate matching;
- bulk link confirmation.

### Current Product grid

`ProductResource` already provides reusable merchant interaction patterns:

- configurable columns;
- search/filtering;
- Category, Brand, Status, Availability, Product Type and Channel filters;
- bulk actions;
- row click -> `ViewAction->slideOver()`;
- table-first operational UX.

This should be reused rather than creating a bespoke grid system.

### Current Attribute Set setup

`ManageAdobeProductsExportSetup` still exposes one account/configuration-wide Attribute Set.
The resolved target model supersedes that as final architecture, but current runtime still depends on it.
It must not remain a prominent permanent Workbench concept.

### Current Preview/Live page

`ManageAdobeProductsExportPreview` is a powerful execution/readiness surface, but it contains both Preview and Live histories/worklists and is too heavy to be the default merchant navigation step.
Workbench should expose causal current state and launch execution contextually; the existing page can remain an implementation target/detail surface until later consolidation.

## External UX evidence used

### Plytix

Current Plytix Views save columns + filters + sorting + Product Family + hierarchy levels and appear as tabs in the Product Overview.
This supports system preset Workbench Views rather than separate unrelated screens.

Reference: https://help.plytix.com/en/table-views

### Akeneo

Akeneo's Product Grid keeps configurable filters/columns and exposes completeness as a task-relevant product property.
Category hierarchy is a navigation/filter concept rather than a hardcoded two-level Category/Subcategory domain model.

References:
https://help.akeneo.com/serenity-get-familiar-with-the-product-grid
https://help.akeneo.com/serenity-understand-product-completeness

### ChannelEngine

ChannelEngine's listed-product UX separates export status, channel status, validation/feedback, category and selection concepts instead of collapsing them into one state.

References:
https://support.channelengine.com/hc/en-us/articles/4409484715421-ChannelEngine-listed-products
https://support.channelengine.com/hc/en-us/articles/4409502604061-ChannelEngine-listed-products-validation-and-feedback

## Lead UX principle

The merchant should always be able to answer:

1. **What is actually in Magento?**
2. **What am I preparing to send there?**
3. **Which Magento rows correspond to which Master Products?**
4. **What needs my attention now?**
5. **What is the one safe next action?**

Do not answer all five questions with one table or one status chip.
# Proposed Workbench shell

## Persistent page header

Desktop target:

```text
Magento
Adobe Commerce · <account name>                          [Оновити каталог]

Підключено · Каталог оновлено 18 хв тому · 1 026 товарів
```

Rules:

- title is the human channel name, not a technical route name;
- account name is secondary;
- connection health is compact and not mixed with Product readiness;
- last successful catalogue refresh is visible;
- refresh remains a secondary utility action;
- credentials / endpoint repair live under Integration/account context, not the Product grid;
- no permanent global `Налаштування даних` button.

If refresh is running, keep the latest successful catalogue visible and show `Оновлюємо каталог…`.
Do not blank the table while a new scan runs.

## System View tabs

First frozen tabs:

```text
[ Огляд ]   [ Публікація ]   [ Зв'язки ]
```

Future additive Views may include Content/SEO and Media, but they are not required for the first Workbench implementation.

Tabs are merchant concepts, not separate unrelated products. A specific View should be deep-linkable/bookmarkable.

First release uses **system preset Views only**. Do not build custom/private/shared View persistence before a real need proves it.

---

# View 1 — Огляд

## Row universe

Current successful Magento Remote Catalogue snapshot.

## Merchant purpose

“Show me my Magento store.”

This must be the first useful surface after connection.

## Initial deliverable columns — current projection only

Until Projection V2 exists:

| Column | Source |
|---|---|
| Magento SKU | current remote projection |
| Назва | current remote projection |
| Тип | current remote projection |
| Стан у Magento | current remote projection, human-formatted |
| Зв'язок | trusted-link derived state |
| Оновлено | remote updated timestamp |
| Problems / action | only if honestly derivable from current evidence |

Do not show a dead Thumbnail/Brand/Category column full of dashes merely because the target design wants them later.

## Target columns after Remote Catalogue Projection V2

```text
□ | Фото | SKU | Назва | Бренд | Категорія | Стан у Magento | Зв'язок | Проблеми | Оновлено
```

Target filters:

- search: SKU / name;
- Category tree;
- Brand;
- Attribute Set when Projection V2 proves a scalable source;
- Product type;
- Magento provider state;
- link state;
- problems where available;
- updated/freshness window where useful.

The default row should stay compact. Long remote data belongs in a drawer/details surface.

## Row actions

Current capability:

- `Пов'язати` for unlinked rows;
- open linked Master Product context when link exists.

Future capability-gated actions:

- `Забрати до каталогу` only after remote-only -> Master Product import is certified;
- bulk exact-match confirmation only after candidate classification/bulk trust flow exists.

No checkbox should be shown merely for decoration. Add row selection when at least one safe bulk action is genuinely available.

## Empty states

### No successful scan yet

```text
Каталог Magento ще не зчитано.
Зчитайте його, щоб побачити товари цього магазину.
[Зчитати каталог]
```

### Successful scan, zero products

```text
У цьому Magento-магазині поки немає товарів.
Товари з вашого каталогу можна підготувати у вкладці «Публікація».
[Перейти до публікації]
```

No empty Overview should pretend local selection is the remote store.
# View 2 — Публікація

## Row universe

Local Master Products selected/linked for outbound preparation for this Magento account.

## Merchant purpose

“Prepare my catalogue for this Magento and safely send supported changes.”

## Header behavior

One principal selection action:

`Вибрати товари`

Do not show both `Основний каталог` and `Вибрати товари` as peer actions.

Preferred target interaction:

- selector/modal/drawer over Master Products when practical;
- existing ProductResource channel-context page is an acceptable transitional fallback;
- returning to Workbench must preserve current View/filter context.

Execution action is **causal**, not permanent:

- if Products are not checked -> `Перевірити`;
- if latest evidence is stale -> `Перевірити знову`;
- if current Preview is valid and current Live is allowed -> `Передати зміни`;
- if blocked -> show the one remediation owner/action instead of an enabled destructive button.

Current V1 must not expose enabled remote CREATE.

## Target table

After Decision-B runtime exists:

```text
□ | Фото | SKU | Назва | Категорія Magento | Набір характеристик | Готовність | Проблеми | Наступна дія | Останній результат
```

Notes:

- Category and Attribute Set are target-classification concepts and can be manually corrected;
- `Набір характеристик` is merchant wording candidate; never display raw `attribute_set_id`;
- readiness is profile-specific, not a generic universal health score;
- Problems is an aggregate signal with drill-down;
- last run result is not the same thing as readiness;
- link state/provider state remain separate if shown.

## Product row/detail interaction

Use the existing ProductResource slide-over pattern as the starting interaction.

Target drawer sections:

- Основне;
- Magento;
- Контент;
- SEO;
- Медіа;
- Історія.

Only render sections/capabilities that exist. The drawer must not invent AI/SEO operations before those domains ship.

## Classification correction

Merchant may click/pencil:

- Magento Category -> hierarchical selector;
- Attribute Set -> existing observed sets;
- `Повернути автоматичний вибір` removes sparse override.

For existing trusted Magento Product, a ProductType recommendation that disagrees with remote Attribute Set is informational/blocking context only; do not turn it into an implicit `change Attribute Set` operation.

---

# View 3 — Зв'язки

## Row universe

Remote Magento rows annotated with trusted Master Product correspondence and candidate matching evidence where available.

## Merchant purpose

“Tell me which Magento products are ours, and let me correct uncertain correspondence.”

## First implementation

Reuse current safe Entity Trust flow:

```text
Magento SKU | Magento назва | Стан зв'язку | Master Product | Дія
```

Filters:

- linked;
- unlinked;
- search SKU/name.

Row action:
`Пов'язати` -> current review/confirmation flow.

## Target scale behavior

When candidate classification exists:

- exact/high-confidence candidates;
- probable candidates;
- none.

Similarity alone never creates a trusted link.

Future bulk action may confirm an explicitly reviewed group of exact candidates.
Keep row-level correction for exceptions.

Do not build a separate `link database` outside ExternalRecordLink truth.

---

# Status semantics

Do not create one `Status` column that tries to explain everything.

Keep independently filterable concepts:

- Magento provider state;
- link state;
- publication readiness;
- current problems;
- last governed publication result;
- platform Product active/inactive only where the View needs it.

Visual compactness is allowed; semantic collapse is not.

---

# Filtering / columns

## Defaults

Adopt the mature grid pattern:

- search always visible;
- a short set of high-value default filters;
- additional filters under a filter control;
- column chooser;
- sorting;
- pagination suitable for large catalogues.

Do not turn the first screen into a permanent toolbar containing every possible operation.

## Category

Display breadcrumb/path.
Filter with hierarchical tree selector.

Do not hardcode exactly Category + Subcategory as two domain levels.

## Long text

Description, Meta Description and similar values must not be default grid columns.
They belong to Content/SEO View and drawer/detail surfaces.
# What should disappear from the current Magento channel landing

As permanent top-level peer buttons:

- `Основний каталог`;
- `Налаштування даних`;
- `Перевірити готовність`;
- separate `Переглянути каталог Magento`.

Their capabilities are redistributed:

- product selection -> Publication;
- current global Attribute Set setup -> contextual legacy remediation until Decision-B migration, then replaced by classification workflow;
- Preview/Live -> causal Publication action/status;
- Remote Catalogue -> Overview itself.

The existing pages/services can remain behind the new shell while migration happens. Do not delete proven runtime merely because its current navigation is poor.

---

# First implementation sequence recommended by Lead

## Campaign UX-1 — Workbench shell + remote Overview

**Risk:** YELLOW, frozen architecture.

Goal:

- Magento channel opens on remote Overview;
- add system tabs `Огляд / Публікація / Зв'язки`;
- move existing Remote Catalogue table/functionality into the Workbench experience;
- preserve old runtime services;
- remove duplicate top-level navigation choices;
- keep current Projection fields only;
- preserve refresh/current-successful-snapshot behavior;
- preserve current Entity Trust row action.

No new DB architecture.

Acceptance evidence:

- opening Magento shows actual current remote rows;
- switching Views does not mix row universes;
- no unsupported CREATE/import action appears;
- no Projection V2-only column is promised;
- existing linking flow still passes.

## Campaign UX-2 — Remote Catalogue Projection V2

**Risk:** YELLOW unless provider-read findings expose new external-write/DB invariants.

Goal:

- scalable thumbnail;
- brand;
- category/path;
- optionally Attribute Set if cost-effective;
- no N+1 provider reads at 10k–100k scale.

This campaign must be researched against Magento response shapes before implementation.

## Campaign B-runtime — classification defaults + sparse overrides + planner migration

**Risk:** ORANGE.

Goal:

- implement resolved Decision B persistence/mutation/isolation;
- per-Product effective Attribute Set;
- category overrides;
- Preview classification snapshot/revision;
- semantic planner migration.

This is not a UI-only campaign.

## Campaign UX-3 — Publication classification/readiness UI

**Risk:** YELLOW after B-runtime is frozen/implemented.

Goal:

- real Category / Attribute Set presentation and correction;
- contextual readiness/problems/next action;
- causal Preview/Live entry.

## Separate RED campaign — Magento Product CREATE

Keep separate from Workbench shell.
Requires GPT-5.4 + Opus 5 blind studies after Lead repo/provider research.

---

# Lead conclusion

The visual Workbench problem is now sufficiently constrained for prototyping.

Do **not** use Opus as the next reviewer for this visual phase.

Recommended next external step:

1. give a visual prototyping tool/designer the strict brief derived from this study;
2. ask for two visual treatments of the same frozen semantics;
3. evaluate visual hierarchy/usability only;
4. do not permit the prototype tool to redefine domain/runtime rules.

Opus should be reserved for the separate RED Magento CREATE architecture where its cost is justified.
