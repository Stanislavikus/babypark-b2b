# 06-UI_DESIGN_SYSTEM.md

## Purpose

This document defines the user interface rules, design-system boundaries and AI UI decision protocol for the platform.

The platform is an enterprise-grade Product Data Platform under the hood, but it must remain simple enough for a small-business owner, sales manager, purchasing manager, warehouse employee or B2B buyer to operate without formal training.

The design system must prevent the product from becoming:

- an enterprise dashboard that requires training;
- a website builder;
- a theme marketplace;
- a CMS;
- a visually overloaded PIM;
- a technical ERP screen copied into a SaaS product;
- a system where technical architecture leaks into ordinary screens.

The design system must help the user complete practical business tasks:

- import or create products;
- find a product quickly;
- understand price and availability;
- adjust product information where allowed;
- publish a B2B catalogue;
- add products to an order;
- process customer orders;
- understand what requires attention now.

## Core Principle: Zero-Training Business Usability

The internal informal product slogan is:

> Наш enterprise SaaS доступен даже обезьяне!

The professional rule is:

> Zero-Training Business Usability Principle

A non-technical small-business user must be able to operate the platform by recognition, familiar patterns and forgiving reversible actions.

The user is not expected to study the platform.

The user is not expected to understand SaaS architecture.

The user is not expected to understand PIM, EAV, attribute dictionaries, price resolvers, availability resolvers, order status matrices, reservations, TTL, connector mappings or payment webhooks.

The UI must translate those internal concepts into simple business language.

The platform must feel like a calm, professional, well-organized business tool:

- visually familiar like Google Sheets, Gmail, Google Drive, Shopify Admin and clean spreadsheet-like SaaS tables;
- operationally compatible with real B2B trade habits such as SKU lookup, price lists, stock checking, customer-specific prices and order processing;
- visually clean;
- operationally fast;
- predictable;
- low-stress;
- free from decorative marketing noise.

1C / BAS is a business-context reference, not a visual reference.

The platform may support users who previously worked in 1C / BAS, Excel, Google Sheets or paper price lists, but the UI must not copy overloaded ERP screen patterns.

The desired feeling is:

> Calm Professional Simplicity

Not cheap simplicity.

Not playful simplicity.

Not “startup-dribbble” visual noise.

Calm, expensive-feeling, predictable simplicity.

### Critical UI Defaults

The following defaults exist to reduce ambiguity for implementation:

- admin product management starts in `Таблиця` view;
- B2B storefront starts in `Таблиця` view;
- product row neutral click opens drawer on desktop and full-screen panel on mobile;
- B2B ordering must work from table/card without opening product detail;
- quantity field starts empty, not `0`;
- simple checkout / order confirmation happens inside the cart drawer;
- cart success clears the cart and keeps a success summary visible;
- anonymous/public buyers see `Ціна`, not `Ваша ціна` or `Націнка`;
- cost and profitability are role-aware and hidden from buyer UI;
- exact stock must not leak when hidden by availability display policy;
- raw accent colors are never used directly for interactive text/buttons without accessible token processing.

## Relationship to Other Project Documents

This document does not replace the product architecture.

All UI work must remain aligned with:

- `00-WHY.md`;
- `01-PRODUCT_VISION.md`;
- `02-ATTRIBUTE_DICTIONARY.md`;
- `03-DOMAIN_MODEL.md`;
- `04-ARCHITECTURE_PRINCIPLES.md`;
- `05-AI_WORKING_AGREEMENT.md`.

UI convenience must never create a duplicate domain model.

UI convenience must never bypass approved domain services.

UI convenience must never expose internal technical concepts to ordinary users.

If a UI requirement needs a new business rule, entity, field, workflow or persistent configuration that is not already approved in the product/domain documents, AI must stop and propose a documentation patch before implementation.

Examples requiring domain documentation review before code:

- availability display policy;
- category-level availability display override;
- customer-facing link versus manufacturer/source link;
- anonymous/public channel price behavior;
- discount-code pricing;
- financial limits, debt or credit-related UI;
- minimum-order or payment-term rules;
- per-order-line customer comments;
- customer/channel-specific pricing behavior not already approved;
- checkout/cart persistence beyond approved cart/order flow;
- generated legal, fiscal, accounting or logistics documents.

## UI Source Priority and Best-Practice Check

AI must not invent UI patterns based on taste.

Before proposing a new UI pattern, AI must check, in this order:

1. Existing approved project UI patterns and components.
2. Existing implementation patterns already used consistently in the product.
3. Project architecture documents.
4. Official accessibility and usability sources where applicable.
5. Established SaaS, B2B, ecommerce, spreadsheet and productivity-tool best practices.
6. Real-world operational simplicity for non-technical small-business users.

Authoritative UI sources may include, when relevant:

- WCAG 2.2 for accessibility;
- W3C cognitive accessibility guidance for clear content, layout and reduced cognitive load;
- ecommerce product-list and product-page UX research for storefront and product-card patterns;
- official framework/component documentation for the actual UI stack;
- local legal, fiscal, ГОСТ, ДСТУ or regulatory sources only when the UI generates legal, fiscal, accounting, logistics or officially regulated documents.

ГОСТ, ДСТУ or local fiscal standards must not be applied blindly to ordinary SaaS screens.

They apply only when the target country, market, document type, connector or legal process actually requires them.

### Implementation Bias for Cursor / AI Coding Agents

Design rules must be implemented as simple, explicit defaults first.

When this document allows future flexibility, AI must not implement the future option unless the current task explicitly requires it.

Cursor / AI coding agents should prefer:

- existing components over new components;
- one clear default behavior over multiple optional branches;
- role-aware visibility over duplicated screens;
- small reversible interactions over modal-heavy flows;
- server-side validation for pricing, availability, quantity and order rules;
- comments in code only where they prevent architectural misunderstanding.

If a rule is ambiguous during implementation, AI must choose the simplest approved behavior and flag the ambiguity instead of inventing a new product concept.

## AI UI Decision Protocol

Before generating UI layouts, components or frontend code for a non-trivial task, AI must provide a short UI alignment check.

### PRE-UI DESIGN CHECK

```md
* **UI Area:** [Admin / B2B Storefront / Product Table / Product Detail / Import / Orders / Settings / Mobile / Other]
* **User Goal:** [What the user needs to finish]
* **Approved Pattern / Component:** [Existing pattern/component used; if new, explain why]
* **Architecture Touchpoints:** [Product / Pricing / Availability / Orders / Payments / Connectors / B2B Channel / None]
* **Hidden Complexity:** [What technical/domain details stay hidden from the user]
* **Mobile / Accessibility Impact:** [How it works on mobile and with keyboard/touch/contrast]
* **Stop & Amend Required:** [Yes/No; if yes, name the doc patch needed]
```

AI must stop before code if:

- the UI needs a domain concept not approved in `01` or `03`;
- the UI creates duplicate product, price, availability, order or customer data;
- exact hidden stock would leak into the client-side UI;
- customer-facing screens would expose internal cost, seller profitability, reservation, TTL or resolver terminology;
- a new component is proposed without checking existing components;
- a legal/fiscal/logistics document is involved and the applicable official source was not checked.

## Internationalization and Localization Rules

The UI must be international-ready from the beginning.

The platform may initially serve Ukrainian/Russian-speaking business workflows, but the product must not be architecturally locked to Ukraine-only labels, formats or assumptions.

Rules:

- English must be supportable as a primary product language.
- Ukrainian and Russian may be supported as localized UI languages.
- UI labels must not be hardcoded in application logic.
- Currency, dates, numbers and phone formats must follow workspace locale and currency settings.
- Country-specific legal and document requirements must not pollute the global UI.
- Connector-specific terms, such as 1C / BAS contractor terminology, must stay inside connector mapping screens.
- Ordinary UI must use `Customer`, not `Contractor`, unless a connector explicitly exposes that external term.
- Local fiscal, legal, ГОСТ or ДСТУ rules apply only when the selected country, connector, document type or legal workflow requires them.

Examples:

- Price fields use numeric input and workspace currency formatting.
- Phone fields use locale-aware formatting; no Ukrainian-only mask is global.
- Date fields use localized display format but stable internal storage.
- Tax/legal fields are shown only when relevant to country, connector or document type.

## Product Areas Covered

This design system covers:

- Admin workspace UI;
- B2B buyer catalogue UI;
- navigation;
- dashboard and operational checklist;
- product tables;
- product cards;
- product detail pages;
- product image handling;
- pricing display;
- availability display;
- quantity selection;
- cart preview, cart drawer and order success loop;
- external links and share actions;
- bulk actions;
- source-of-truth fields;
- connector mapping UI;
- mobile behavior;
- terminology, validation, errors and notifications.

## Navigation Rules

Primary navigation should stay short, business-oriented and familiar.

The user must not feel that they are entering an enterprise module tree.

Recommended MVP navigation:

- `Панель управління`;
- `Товари`;
- `Замовлення`;
- `Клієнти`;
- `B2B Каталог`;
- `Налаштування`.

`B2B Каталог` is the customer-facing catalogue/channel setup area. It is not the main internal product list; that belongs to `Товари`.

If the product later supports several catalogue/channel types, the label may become `Мій каталог` or a localized channel-specific label, but MVP must avoid ambiguous plain `Каталог` where it can be confused with the product table.

### Customers Navigation

`Клієнти` means the list of buyers and their business relationship with the workspace.

This area may include:

- customer list;
- customer contact details;
- customer orders;
- customer-specific prices where approved;
- customer group assignment;
- B2B access status.

It must not become a full CRM in MVP.

### Import / Export Navigation

`Імпорт / Експорт` should not be a default top-level navigation item in MVP.

Import is usually an action inside `Товари`, an onboarding step or a connector/mapping workflow.

Export is usually an action in the `Товари` table toolbar, the table overflow menu or selected-row bulk actions.

Export must not become a default top-level navigation item in MVP.

The user thinks:

```text
I need to add products.
```

not:

```text
I need to enter the import module.
```

### Prices in Navigation

The Pricing domain exists architecturally.

However, `Ціни` should not be a top-level menu item for simple MVP workspaces by default.

For simple workspaces, prices are managed inside:

- products;
- customers;
- customer groups;
- B2B catalogue settings where needed.

A top-level `Ціни` section may appear only when advanced pricing is enabled for the workspace, such as:

- multiple price lists;
- customer group pricing;
- customer-specific prices;
- channel-specific prices;
- approved advanced B2B pricing rules.

The UI must hide advanced pricing structure until the business actually needs it.

## Dashboard and Operational Checklist

`Панель управління` must be operational, not decorative.

The MVP dashboard must not be a graph-heavy analytics page by default.

The dashboard should answer:

```text
What needs my attention now?
```

Not:

```text
Look at these SaaS charts.
```

The MVP dashboard should show at most four high-priority attention blocks.

The first visible screen, without scrolling on normal desktop, should prioritize:

1. New orders requiring action.
2. Active/published products blocking sales because of missing price or missing required data.
3. Published products with stock problems that block ordering.
4. Setup/import/catalog publication actions if the workspace is not ready.

Do not show ordinary operational details as dashboard alerts unless they block sales.

Examples of non-dashboard details:

- products with expected stock when no action is required;
- normal stock fluctuations;
- decorative analytics;
- long trend charts;
- vanity KPIs.

### New Workspace State

A new workspace must not open into a confusing empty table without direction.

First-use state should show:

- `Імпортувати товари`;
- `Додати товар вручну`;
- `Налаштувати каталог`;
- a short setup checklist that can be skipped and resumed.

The checklist must be action-based, not educational.

Good:

```text
1. Add or import products
2. Check prices
3. Publish catalogue
4. Share link with customers
```

Bad:

```text
Watch this 12-minute platform overview.
```

Video tips must not be part of primary task completion.

They may exist in Help / learning resources and as secondary optional links.

## Table-First Operational Workspace

The primary operational UI pattern is table-first.

This applies especially to:

- product management;
- B2B buyer ordering;
- customer lists;
- order lists;
- import previews;
- field mapping.

The default user is not looking for a decorative dashboard.

The default user wants to find, compare, edit, add, order and process items quickly.

The table-first pattern is inspired by the familiarity of spreadsheets, email lists and clean SaaS admin tables.

It must not copy overloaded ERP table design.

Tables must support:

- search;
- filters;
- sorting;
- column visibility;
- saved or remembered user preferences where appropriate;
- bulk actions;
- stable loading states;
- keyboard-friendly workflows where useful.

## View Modes: `Таблиця` and `Картки`

The MVP supports two user-facing product view modes:

- `Таблиця`;
- `Картки`.

`Таблиця` is the default admin product management mode.

`Таблиця` is also the system default B2B storefront mode because B2B buyers often think in SKU, quantity, price and order totals.

A B2B channel may later configure a different default storefront view if the workspace business model clearly benefits from visual browsing.

`Картки` is a secondary visual browsing mode.

`List` is not a separate user-facing MVP mode.

On mobile or narrow screens, the table may collapse into compact row cards automatically. This is responsive behavior, not a third product mode.

Switching between `Таблиця` and `Картки` must preserve:

- search;
- filters;
- sorting;
- selected quantities;
- cart state;
- current user context.

The view switcher should be a compact segmented control or icon control with clear active state.

## Data List Search & Filter Pattern

Data list search and filter toolbar is a core UI pattern for read-only and
mutable list screens alike.

### Implementation by Data Source

- **Eloquent-backed lists** use the standard `Filament\Tables\Table` toolbar
  (search, filters, column controls) and must not reinvent a parallel toolbar
  system.
- **Non-Eloquent read models** (CSV arrays, Markdown governance records,
  registry projections) use the shared presentation shell at
  `resources/views/components/filament/data-list-toolbar.blade.php`.
- Do **not** copy or depend on `resources/views/vendor/filament-tables/`.
- Do **not** add data/runtime dependencies solely to support the toolbar
  component.
- Do **not** create a one-off local toolbar design on each page.

### Universal Search and Filter Interaction

Recommended layout:

Left / main area:

- search input (visible by default).

Right / action area:

- filter trigger (only when the page has structured row filters);
- comparison / column actions where relevant;
- view toggle, export, or overflow actions where relevant.

Rules:

- search remains visible; it is not hidden inside the filters dropdown;
- the filter trigger shows an active-count badge **only when count > 0**;
- active filters render as removable indicators with accessible remove
  controls and a **«Очистити все»** action when at least one row filter is
  active;
- comparison columns / channel comparison controls stay separate from row
  filters;
- pages without structured row filters must not render an empty **«Фільтри»**
  button.

The toolbar should remain accessible while scrolling long lists where
technically feasible.

Sticky toolbar/header behavior must stay compact and must not consume
excessive vertical space, especially on mobile.

### Mobile Toolbar Behavior

Filament Table header toolbar є базовим референсом: search, filters
trigger, column controls і page-specific actions належать до одного
горизонтального toolbar-рядка без `flex-wrap`.

Для shared Data List toolbar використовується детермінований responsive
контракт:

- на `md` (768px) і ширше search та окремі controls показуються в одному
  рядку;
- нижче `md` search лишається видимим (`min-w-0 flex-1`), а всі secondary
  controls об'єднуються в одну доступну overflow-кнопку (`shrink-0`) у
  тому самому рядку;
- overflow panel показує controls вертикально;
- не розміщувати filter/action triggers окремими рядками під search;
- у головному toolbar header-row не використовувати `flex-col`,
  `flex-wrap` або інший breakpoint замість стандартного `md`;
- `flex-col` усередині вертикального overflow panel і `flex-wrap` у
  removable active-filter indicators під toolbar дозволені;
- не використовувати runtime width detection або локальний
  нестандартний breakpoint для toolbar mode switch.

Filament core підтверджує one-row toolbar baseline, але не надає
автоматичного hamburger-collapse. Mobile overflow є затвердженим
project-level responsive extension, реалізованим публічними Filament
Blade components і стандартним Tailwind `md` breakpoint.

Content-scope switchers that navigate between genuinely different page
sections or workflows may use tabs. They must not be used merely to work
around toolbar layout.

### Required Scope Filters

A page-scope selector may use the standard structured-filter trigger when
it narrows one existing list and does not navigate to a different page,
workflow or data model.

Governance document type is the approved reference:

- DEC/GAP is rendered through the shared filter trigger, not through
  tabs or a page-specific action;
- exactly one document type is always selected;
- the current type is shown below the toolbar using the same visual
  language as active-filter indicators;
- because the selector has no neutral state, its indicator is not
  removable and the page does not show “Clear all” for this selector;
- desktop and mobile reuse one filter-panel content instance and one
  Livewire state value.

Tabs remain appropriate when the user switches between genuinely
different page sections or workflows. They must not be used merely to
work around toolbar layout.

### Toolbar Action Labels

At `md` and wider, primary or potentially ambiguous data-list toolbar
actions use an icon plus a persistent visible text label when space is
available. Approved examples include Filters, Compare channels, Columns,
Export and View mode.

Icon-only controls are allowed only for compact supplementary actions
whose icon is widely understood, or where the approved responsive layout
requires compactness. Every icon-only control must still have an
accessible label and, on hover-capable devices, a tooltip.

Below `md`, the shared Data List toolbar keeps search visible and moves
secondary controls into the approved single overflow trigger. Inside the
overflow panel, actions and sections use visible text labels.

Do not rely on hover-only tooltips to communicate the meaning of an
ambiguous primary toolbar action.

### Choosing a Selection Control

- Single choice from a compact list: Select or Radio, according to
  available space and whether options should remain visible.
- Small bounded multi-select whose options should be reviewed together:
  CheckboxList.
- Large or dynamic multi-select where showing every option is impractical:
  searchable multi-select.
- Boolean yes/no: Checkbox or Toggle depending on context.
- Do not use checkboxes for a single-choice filter.
- Do not use a searchable tag/chip select for a small fixed multi-select
  when an always-visible CheckboxList is clearer and more stable.

A checkbox is not selected merely because a filter has more than two
possible values. Checkboxes represent independent multiple selection.
When exactly one value may be active, use Select, Radio or an approved
segmented single-choice control.

### Filter and Selection Count Semantics

- The `Filters` trigger badge counts active filter dimensions, not every
  selected value inside those dimensions.
- A multi-select section inside the panel may show its own local count
  of selected values.
- A dedicated bounded-selection action such as `Compare channels` or
  `Columns` shows the number of selected items on its own trigger.
- Do not combine row-filter count and comparison/column count into one
  badge.
- A required scope filter with no neutral state counts as one active
  dimension; its visible indicator is non-removable.

Example:

```text
Фільтри [2]
```

means two active filter dimensions, not the sum of all checkbox values.

```text
Порівняти канали [4]
```

means four selected channels.

### Filter Panel Presentation

The project default for structured Data List filter/action panels is a
right-side slide-over (drawer / modal side sheet), not a page-specific
floating dropdown.

This is a project-level default built from established side-sheet
patterns and public Filament APIs. It is not presented as the only
internationally valid filter pattern.

Implementation remains data-source aware:

- Eloquent-backed lists continue to use native `Filament\Tables\Table`
  filters. When the page is migrated to this presentation, use
  `FiltersLayout::Modal` and customize the public filter trigger action
  with `slideOver()`.
- Non-Eloquent read models continue to use the shared
  `resources/views/components/filament/data-list-toolbar.blade.php`
  slide-over panel.
- Do not replace a native Eloquent table with the custom non-Eloquent
  toolbar merely to obtain the same visual presentation.

The slide-over preserves list context, uses the available screen height,
avoids fragile trigger-positioned overlays, and behaves consistently on
desktop and mobile.

Avoid repeating the exact same phrase as both the toolbar trigger and
the first section heading inside the opened panel. Use a shorter
instructional heading or omit it only when the control remains
unambiguously labelled and accessible.

### Migration Sequencing for Existing Pages

This standard applies to all current and future admin data lists, but
existing pages are not mass-rewritten in unrelated PRs.

When a page is next materially changed, its task must:

1. read this section;
2. preserve native Filament Tables for Eloquent-backed data;
3. align trigger labels, badge semantics, selection controls, active
   indicators and panel presentation;
4. include visual regression evidence.

A divergent existing page is not a precedent for new work.

### Product Table Toolbar Specialization

Product table toolbar follows the universal contract above and adds
product-specific rules below.

Recommended product layout:

Left / main area:

- search input.

Right / action area:

- filters;
- columns;
- view toggle;
- export / overflow actions where relevant;
- cart summary where relevant.

#### Product Search Rules

Product search must be simple and broad enough for real work.

Primary product search should cover:

- product name / `Назва`;
- SKU / `Артикул`;
- GTIN / EAN;
- brand / `Бренд`.

Brand must also remain available as a structured filter.

Brand search should match products from that brand and must not create unpredictable search behavior across unrelated fields.

The search input must support barcode scanner input that behaves like fast keyboard text entry.

A warehouse or sales user should be able to scan a barcode into the search field and find the product without special scanner setup.

Search must not require the user to select a search mode.

#### Product Filter Rules

Filters should expose structured business dimensions.

Initial useful filters:

- category;
- brand;
- status;
- availability;
- publication state where relevant.

Do not overload the default filter panel.

Advanced filters may exist but should be secondary.

#### Column Visibility Rules

Tables must support approved column visibility controls.

Column visibility must not become a custom table builder.

Allowed:

- show/hide approved columns;
- remember user preference;
- saved professional views later.

If saved views require server-side persistence, they need approved user/workspace preference storage before implementation.

URL-only filter presets may be implemented without creating a new domain model if they do not persist new business entities.

Forbidden:

- arbitrary SQL-like table customization;
- user-created technical columns that bypass the Attribute Dictionary;
- exposing hidden technical fields directly.

#### Session and Preference Persistence

Search, filters, sorting, pagination and current view mode should be preserved during the active browser session and represented in URL/query state where useful.

Refreshing the page should not unnecessarily reset the user's current table context when the state can be safely restored.

Cross-session persistence of table preferences is allowed only through approved user/workspace preference storage.

Column visibility may be remembered when the product already has an approved preference mechanism. If not, keep safe defaults and avoid creating a new persistence model without a documentation patch.


## Admin Product Table Pattern

Admin product management is table-first by default.

The table must help staff answer:

- what product is this;
- what is the selling price;
- is it available;
- is it active;
- what brand/category group does it belong to;
- does commercial performance need attention.

### Default Admin Columns

Default admin table design must balance compactness with real trade work.

Do not reduce the default view to an abstract UX rule if it removes trade-critical fields such as article/SKU or brand.

Desktop defaults may be denser than mobile defaults, but they must remain readable without horizontal panic.

Default admin product table columns:

- `Фото`;
- `Назва`;
- `Артикул`;
- `Бренд`;
- `Ціна`;
- `Наявність`;
- `Статус`;
- `Рентабельність`, only if the user role is allowed to see commercial performance data.

`Бренд` is part of the default admin table because brand sorting and filtering are operationally useful in trade workflows.

`Рентабельність` is useful for owners and commercial managers, but it is sensitive.

It must be role-aware and hideable.

For staff-safe, support, demo or shared-screen views, cost and profitability fields must be hidden by default.

A future `Safe View` / `Demo View` may exist as a saved view pattern, but MVP implementation must not require a separate demo-mode subsystem.

### Optional Admin Columns

Optional/toggleable columns may include:

- GTIN / EAN;
- category;
- RRP / `РРЦ`;
- `Вхідна ціна`;
- customer-facing product link;
- manufacturer/source link;
- sync/source status;
- additional approved product fields.

`Вхідна ціна`, `Рентабельність` and other sensitive commercial fields must not be forced into staff views that should not expose cost or profitability.

### Admin Commercial Metric

Admin-facing commercial performance label:

```text
Рентабельність
```

`Рентабельність` is seller-side commercial performance based on selling price and cost.

The exact calculation must come from the approved Pricing / Commercial Metrics domain logic, not from UI code.

The UI must show a tooltip in business language.

Example tooltip:

```text
Різниця між ціною продажу та вхідною ціною. У відсотках показує частку цієї різниці від ціни продажу.
```

The UI must not use `Маржа` as the default final label.

### Admin Table Behavior

Admin product table must support:

- sort by name, SKU, brand, price, availability and status where feasible;
- filter by category, brand, status and availability;
- column visibility;
- photo lightbox;
- product row drawer;
- full product page for deeper editing.

## B2B Buyer Table Pattern

B2B buyer catalogue table shares the visual language of the admin table, but it must use a different visibility policy.

The buyer must never see:

- cost price;
- seller profitability;
- internal source status;
- sync status;
- reservation/TTL terminology;
- internal manufacturer/source links unless explicitly approved as customer-facing links.

### Default B2B Columns

Default B2B buyer table must stay compact, but it may include trade-critical fields that generic ecommerce tables often hide.

For B2B purchasing, article/SKU and brand are operational fields, not decorative metadata.

Default B2B buyer table columns:

- `Фото`;
- `Артикул`;
- `Бренд`;
- `Товар` / `Назва`;
- `Наявність`;
- `Ціна` block;
- `Націнка`, only for identified buyers or approved customer/channel pricing context;
- `Кількість / Додати`.

The price block should group related values rather than forcing too many separate columns.

For an identified buyer:

- primary price: `Ваша ціна`;
- comparison price: `Ціна` or `РРЦ`, only when meaningful and different.

For anonymous/public buyers:

- label: `Ціна`;
- no `Ваша ціна` unless a customer/channel context exists;
- no `Націнка` by default.

### Buyer Commercial Metric

Buyer-facing commercial opportunity label:

```text
Націнка
```

`Націнка` helps the B2B buyer understand potential resale opportunity or difference between their buying price and a meaningful comparison price.

The exact calculation must come from approved Pricing / Commercial Metrics domain logic.

The UI must show a tooltip in business language.

Example tooltip:

```text
Орієнтовна різниця між вашою ціною закупівлі та ціною продажу / РРЦ.
```

The buyer-facing UI must not use `Маржа`.

### B2B Table Behavior

The B2B buyer table must support fast multi-line ordering.

A buyer must be able to add products to an order without opening product detail pages.

The table must support:

- search;
- filters;
- optional columns;
- quantity input directly in row;
- cart summary;
- row drawer for quick detail;
- product detail page for deeper information.

## Product Image and Lightbox Pattern

Product thumbnails may appear in admin and B2B tables.

Clicking the image is a separate action zone.

Image click must not trigger row navigation.

Thumbnail behavior:

- hover or focus shows discoverability cue such as zoom cursor/icon;
- click opens lightbox;
- lightbox does not change table position, filters, search or scroll state;
- closing lightbox returns to the same context.

The lightbox must support both light and dark theme tokens.

The lightbox must not use hardcoded white/dark backgrounds that break theme consistency.

If there are no images, show a neutral placeholder without click behavior.

No-image placeholder: a muted gray square with a simple product icon. Do not use text, brand marks, initials or broken-image icons as the placeholder.

### Product image size and format convention

**Resolved.**

Compiled from cross-referencing Shopify/Amazon/major platform image guidelines:
- Original upload/storage: up to 2048×2048px (source for all derived sizes).
- Thumbnail (tables, cart): ~150-300px.
- Catalog/card view: ~600-800px.
- Zoom/lightbox: ~1600-2048px.
- Delivery format: `.webp` where the browser supports it, JPEG fallback otherwise. Alt text is
  mandatory on every product image (accessibility + SEO).

**Scope note:** this convention applies to the platform's own product UI/storefront delivery
only. Connector/export jobs (e.g. sending images to a marketplace or Google Shopping feed) may
need to transform images to channel-specific requirements later (different platforms have their
own size/format rules) — that is a separate connector/channel-mapping concern (GAP-006), not
something this convention dictates. Do not treat `.webp` delivery as a marketplace-export
requirement.

This decision is closed and must not be reopened without a documentation-level decision.

## Row Action Zones

Rows may contain multiple independent action zones.

The UI must avoid accidental actions.

Required action-zone rules:

- clicking the neutral row area opens the row context drawer on desktop;
- clicking the neutral row area opens a full-screen panel on mobile;
- clicking product image opens lightbox;
- clicking external link opens a new tab;
- clicking quantity input edits quantity;
- clicking action button performs that action;
- none of these child actions may accidentally trigger row navigation.

## Context Drawer and Deep Link Rules

Context drawer is the preferred pattern for quick review and focused editing without losing table context.

On desktop:

- neutral row click opens side drawer;
- drawer should not fully replace the table;
- the table context should remain visible where possible.

On mobile:

- neutral row click opens full-screen panel.

Opening a drawer should update browser history/deep link where useful.

Browser Back should close the drawer before leaving the list.

Closing the drawer must preserve:

- search;
- filters;
- sorting;
- column settings;
- scroll position;
- cart state.

### Admin Product Drawer Content

Admin product drawer is for quick review and safe focused edits only.

It must not become a full product editor.

Admin product drawer should show quick operational data only:

- product image;
- name;
- SKU / article number;
- brand;
- selling price;
- availability;
- status;
- customer-facing link if present;
- manufacturer/source link if present and allowed;
- quick actions;
- link to full product page.

Sensitive fields such as cost price and profitability may appear only if the user role is allowed.

Full editing, long descriptions, technical characteristics, documents, channel readiness, history and complex field groups belong to the full product page.

The drawer should fit mostly within one compact screen before scrolling. If the user needs to manage many fields, use the full product page.

### B2B Product Drawer Content

B2B product drawer is for quick buying review and ordering.

It must not become a marketing product page or admin editor.

B2B product drawer should show quick buying data:

- product image;
- name;
- SKU / article number;
- brand;
- availability;
- primary price;
- comparison price if meaningful;
- quantity selector;
- action button;
- link to full product detail.

It must not expose admin-only data.

## Quantity Selector Pattern

B2B buyer table and product cards must include quantity selection directly in the ordering flow.

The buyer must be able to enter quantity without opening a product page.

The quantity selector is a separate action zone and must not trigger row navigation.

Recommended pattern:

- compact numeric input;
- optional `+` / `−` buttons where useful;
- empty field by default, not `0`;
- auto-select content on focus;
- numeric input only;
- server-side validation always required.

Keyboard behavior:

- `Enter` adds the current quantity to cart and moves focus to the next row quantity field where feasible;
- `Esc` clears the current quantity input;
- tab order must remain predictable.

Mobile behavior:

- frequent controls such as `+`, `−`, quantity input and primary action should target approximately 44×44 px/pt hit area where layout allows;
- controls must remain easy to tap with a thumb.

Business rules:

- `min_order_quantity` must be respected when approved in the domain model;
- `order_step` must be respected when approved in the domain model;
- manual input must be validated server-side;
- if exact stock is hidden by availability display policy, the input must not expose real stock through `max`, hidden attributes or client-side payloads;
- if the requested quantity requires confirmation, show business-language warning.

Good warning:

```text
Ця кількість потребує підтвердження менеджером.
```

Bad warning:

```text
You exceeded stock_display_threshold.
```

When quantity is changed to `0` in the cart drawer, remove the line and show a toast with Undo.

Do not show a modal confirmation for reversible cart quantity changes.

## B2B Action Buttons

B2B actions must describe what happens in business language.

Default action labels:

- in stock: `Додати`;
- expected / future arrival: `Замовити` with visible availability badge `Очікується`, or compact label `Замовити (очікується)` where space requires;
- unknown/request-only: `Додати в заявку`, only if the request/inquiry flow is implemented;
- unavailable without request flow: no primary action or muted `Наявність уточнюється` status.

Avoid using `Передзамовити` as the default MVP label unless usability testing or a specific market confirms that buyers understand it as ordering under future supply/backorder, not as consumer-style preorder for a new product.

Forbidden default labels:

- `Купити` in B2B table;
- `Бронювати` as default wording;
- labels exposing reservation/TTL/stock-locking internals.

`Додати в заявку` must not be shown if it leads nowhere.

If inquiry flow is not implemented, the UI must not pretend that it is.

## Cart Toolbar, Cart Drawer and Order Success Pattern

B2B table toolbar should support persistent cart summary.

Toolbar cart summary contains:

- cart icon;
- item count badge over the icon;
- current order total to the right of the icon.

In B2B, order total is often more important than item count.

Clicking the cart summary opens dropdown preview.

Dropdown preview should show:

- first few cart lines;
- product thumbnail;
- product name / SKU;
- quantity;
- line total;
- order total;
- `Переглянути кошик`;
- `Оформити`.

`Переглянути кошик` opens cart drawer without leaving the catalogue.

Cart drawer supports:

- viewing product thumbnails;
- changing quantity;
- removing items;
- viewing line totals;
- viewing order total;
- proceeding to checkout / order confirmation.

### Checkout / Order Confirmation Flow

For simple B2B orders, checkout / order confirmation happens inside the cart drawer so the buyer does not lose catalogue context.

The confirmation step should show the customer, order lines, quantities, line totals and order total.

A full checkout page is required only when the order flow collects additional information such as delivery address, payment terms, legal document data, complex order comments or multi-step approval.

If those extra fields are not approved in the domain model, the UI must not invent them inside the drawer.

Line-item customer comment is supported only after `OrderItem comment` is approved in the domain model.

After successful order submission:

- cart is cleared;
- success summary remains visible;
- order number is shown;
- order total is shown;
- view/download order document or PDF may be shown where available;
- `Продовжити закупівлю` returns to catalogue without losing search/filter context;
- `Перейти до замовлень` opens order history/details.

Future “repeat this order” behavior is a separate feature and must not be implied by the success state unless implemented.

## Admin Order Processing Pattern

Admin order handling must be simple, action-based and aligned with the approved order status model.

The order list is table-first by default.

Admin order drawer is for quick review and common actions without losing list context.

Order drawer should show:

- order number;
- customer;
- order date/time;
- current order status;
- payment status where approved;
- order total;
- key order lines summary;
- customer contact/action shortcut where available;
- approved next actions.

Common action buttons may include localized business labels such as:

- `Підтвердити`;
- `Прийняти в роботу`;
- `Скасувати`;
- `Позначити як оплачено`, only where manual payment-status change is approved.

Order status must not be edited through arbitrary technical dropdowns.

Available actions must come from the approved order status workflow / `WorkspaceOrderStatusMatrix` logic.

If an action is not available for the current order state, show it as disabled with a short tooltip or inline explanation. Do not silently hide primary actions when the user reasonably expects them.

Payment status and order status must not be confused. Payment-related actions update payment state only where the payment domain allows it.

Complex order editing, full line details, history, documents and integration errors belong to the full order page.

Mobile order processing must keep the next primary action reachable for a manager using a phone.

## Product Card / Card View Pattern

Card view is a secondary visual browsing mode.

It must not replace the table-first operational workspace.

A product card must help the user visually recognize the product, understand the relevant price, see availability and add the product to an order.

Product cards must not attempt to reproduce all table columns.

### Buyer Product Card

Required elements:

- product image;
- brand;
- product name;
- SKU / article number where useful;
- availability badge;
- primary price: `Ваша ціна` for identified buyers or `Ціна` for public buyers;
- one secondary comparison price: `Ціна` or `РРЦ`, only when meaningful and different;
- quantity selector;
- action button.

Allowed action labels:

- `Додати`;
- `Замовити` with availability badge `Очікується`;
- `Замовити (очікується)` for compact cards/buttons where the badge cannot be shown clearly;
- `Додати в заявку`, only when supported.

Not shown by default:

- GTIN / EAN;
- long technical attributes;
- external links;
- `Націнка` for anonymous/public buyers;
- internal system state.

### Admin Product Card

Required elements:

- product image;
- brand;
- product name;
- SKU / article number;
- primary operational price: `Ціна`;
- secondary operational price: `Вхідна ціна`, only for roles allowed to see cost;
- availability badge;
- status.

Admin product cards are for visual recognition and quick review.

Commercial analysis, profitability sorting, GTIN/EAN checks, URLs and full operational data belong to table view or product detail page.

### Responsive Card Behavior

Desktop card view may use a standard visual product card layout with image on top.

On mobile or narrow screens, cards may collapse into a compact horizontal layout with image on the left and product information on the right.

Switching between table and card view must preserve search, filters, sorting, quantities and cart state.

## Product Detail Page Pattern

Product detail page exists for deeper understanding.

It must not be required for routine B2B ordering.

A buyer must be able to order common items directly from table/card view.

Product detail page should use familiar ecommerce structure without becoming a retail marketing page.

### Buyer Product Detail Header

Recommended header:

- image/gallery;
- product name;
- SKU / GTIN where useful;
- availability;
- primary price;
- one comparison price if meaningful;
- quantity selector;
- action button.

### Product Detail Sections

Sections:

- `Опис`;
- `Технічні характеристики`;
- `Гарантія`;
- `Інструкція / Документи`;
- `Посилання`.

Show a section only if content exists.

On desktop, tabs or sections may be used if they remain clear.

On mobile, collapsible sections are preferred over dense horizontal tabs.

Technical characteristics must come from approved product fields / Attribute Dictionary, not from duplicated product-detail-only structures.

Documents and instructions must use approved media/document storage or approved external links.

## Pricing Display Rules

Pricing display must be role-aware and context-aware.

### Public / Anonymous Buyer

Default label:

```text
Ціна
```

Do not show `Ваша ціна` unless the buyer is identified or the session has approved customer/channel pricing context.

Do not show `Націнка` by default.

### Identified B2B Buyer

Primary label:

```text
Ваша ціна
```

Comparison price may be shown as:

- `Ціна`;
- `РРЦ`.

Show only meaningful differences.

Do not show duplicate prices.

### Buyer Metric

`Націнка` is default-visible for identified B2B buyers when there is a meaningful comparison price and the channel allows it.

It is hidden for anonymous/public buyers by default.

`Націнка` may support currency / percentage toggle.

It must not expose seller cost.

### Admin Metric

`Рентабельність` may be shown for authorized admin roles.

`Рентабельність` may support currency / percentage toggle.

Exact calculation belongs to approved Pricing / Commercial Metrics domain logic.

UI must not implement financial formulas independently.

### Missing Price Display

When a product has no applicable price, table cells should show a muted dash:

```text
—
```

In detail views or validation messages, use clear business wording:

```text
Ціна не вказана
```

Admin/operator screens may also show a compact `Без ціни` status where missing price blocks catalogue publication or ordering.

Buyer-facing screens must not allow normal ordering of a product without an applicable price unless an approved request/inquiry flow exists.

Do not show technical null/empty-state terms such as `price = null`, `missing resolver value` or `no price list item`.

## Availability Display Policy

Availability display must communicate business meaning without exposing internal availability architecture.

Forbidden customer-facing terms:

- `InventoryReservation`;
- `AvailabilityResolver`;
- `TTL`;
- `reserved row`;
- `stock_display_threshold`;
- `net stock formula`.

### Availability Labels

Customer-facing examples:

- `У наявності: >10 шт`;
- `У наявності: 6 шт`;
- `Очікується 07.06`;
- `Дата постачання уточнюється`;
- `Наявність уточнюється`;
- `Немає в наявності`;
- `Ця кількість потребує підтвердження менеджером`.

Near dates may use human-friendly labels:

- `Сьогодні`;
- `Завтра`;
- weekday name for near dates where useful;
- calendar date for farther dates.

If expected date is missing, do not show an empty field or technical error.

Use business wording such as:

```text
Дата постачання уточнюється
```

### Availability Colors

Availability status colors must be semantic and consistent:

- green / success: available now;
- amber / warning: low quantity or action may need confirmation;
- blue / info: expected / preorder / future arrival;
- gray / muted: unavailable or no active action;
- red / danger: actual error, failed action, destructive warning or critical blocking issue only.

Do not use red for ordinary out-of-stock states by default.

Color must never be the only signal.

Every colored availability state must also include text.

### Exact Stock Visibility

If exact stock is hidden by availability display policy, exact quantity must not be exposed through:

- labels;
- HTML attributes;
- input `max`;
- hidden fields;
- client-side payloads;
- API responses used by client-facing UI;
- validation messages.

If exact stock is hidden, server-side validation must still protect the order flow.

## Theme, Branding and Appearance Rules

Admin UI must support:

- Light;
- Dark;
- System.

System mode should be the default.

All design tokens must work in both light and dark mode.

### Workspace / B2B Channel Appearance

MVP workspace or B2B channel appearance settings may include:

- logo;
- company name;
- accent color;
- contact information;
- business hours;
- default storefront view;
- customer-facing column visibility;
- external product links on/off;
- availability display mode.

Forbidden in MVP:

- custom CSS;
- arbitrary font picker;
- theme marketplace;
- page builder;
- custom HTML blocks;
- drag-and-drop storefront layout builder.

### Accent Color Tokens

Do not apply raw user-selected hex color directly to text, buttons or focus states without contrast processing.

The accent color system should generate at least:

- `accent.raw` — original selected color, decorative use only;
- `accent.accessible` — adjusted color that passes required contrast for buttons/links;
- `accent.onAccent` — readable foreground color on accent background;
- `accent.softBackground` — low-opacity highlight/background token.

Primary actions, links and focus states must use accessible tokens.

## External Links and Share Pattern

Admin may support multiple product-related links:

- customer-facing product link;
- manufacturer/source link.

B2B buyer may see only customer-facing links enabled by the merchant/channel settings.

Client-facing external link label:

```text
Посилання
```

External link behavior:

- optional/toggleable column in B2B table;
- appears on product detail if enabled;
- opens in new tab;
- shows external-link icon where useful;
- does not trigger row navigation.

### Share Pattern

Mobile share should use native OS share sheet where available.

Desktop share should copy link to clipboard and show a toast.

Optional messenger shortcuts may exist for relevant markets:

- Telegram;
- Viber;
- WhatsApp.

Do not place technical URL protocols in this design-system document.

## Bulk Actions Pattern

Bulk actions are required for table-first workflows.

They appear only after selecting one or more rows.

Desktop behavior:

- selected-row BulkActionBar replaces the normal table toolbar at the top.

Mobile behavior:

- selected-row actions appear as a sticky bottom action bar.
- it must not overlap bottom navigation or B2B cart summary.

Bulk action bar must show the count of selected items.

Allowed examples:

- change status;
- assign category;
- show/hide in catalogue;
- export selected;
- update selected safe field;
- remove selected from catalogue.

Bulk actions must not expose technical operations.

### Select All Pattern

First selection selects visible rows on the current page.

If more records match the current filter, show a clear option:

```text
Вибрати всі 1 234 товари за поточним фільтром
```

Do not silently select all filtered items without explicit user confirmation.

## Source-of-Truth Field Rules

Some fields may be controlled by external systems such as 1C / BAS, ERP or supplier sync.

Read-only fields must explain where they are managed.

Good labels:

```text
Поле керується з 1С
Змінити в 1С
Дані оновлюються через інтеграцію
```

Bad labels:

```text
sync_locked
source_of_truth = external
disabled by connector
```

The UI must not allow ordinary editing of externally controlled fields unless the domain/connector rules explicitly allow it.

If an override is allowed, it must be clearly marked as an override and must not silently break sync behavior.

## Connector Mapping UI Pattern

Connector mapping UI must be spreadsheet-like and understandable.

The user must not see internal terms such as:

- `FieldMapping`;
- `AttributeDefinition`;
- `EAV`;
- `target_attribute_definition_id`.

User-facing columns:

- `Колонка з файлу`;
- `Поле товару`;
- `Приклад`;
- `Статус`;
- `Дія`.

Example:

```text
"Артикул"   → Артикул   ANX-123   Знайдено
"Цена"      → Ціна      1299      Підтвердити
"Цвет"      → Колір     Чорний    Перевірити
```

Rules:

- auto-match first;
- user confirms uncertain matches;
- low-confidence mapping must not import silently;
- new product fields go through the approved Attribute Dictionary anti-duplication flow;
- advanced mapping stays secondary.

Detailed import/mapping flows should be described in a future dedicated import-flow document. This design-system document defines only the approved UI pattern and boundaries.

## Data Model & Connectors (admin section, Task 4A scope)

Three screens under "Модель даних і коннектори":

1. **Матриця полів** — read-only. Rows: canonical fields. Columns: up to 6
   channel/version comparison columns at a time, each identified by
   `(channel, channel_schema_version)`, not
   by channel alone (see the Registry-channels-vs-ConnectorDefinitions
   distinction in 03-DOMAIN_MODEL.md — columns are derived from Registry
   channel values actually present in `mappings.csv`/
   `channel_decisions.csv`, enriched by ConnectorDefinition metadata only
   when codes match). If a channel has exactly one schema version present
   in the Registry, it is auto-selected; if more than one exists, the
   administrator must choose explicitly — never resolved by lexicographic
   "latest" sorting.

   Cell logic, evaluated per selected `(channel, channel_schema_version)`:
   - no mapping and no channel_decision row for this field → **Not assessed**
   - only mapping row(s) exist → the actual Registry `mapping_type` value
     (`direct` / `renamed` / `transformed` / `connector_only` — shown
     verbatim, never invented UI labels)
   - only channel_decision row(s) exist → `deferred` / `account_specific` /
     `unsupported` / `not_applicable`, shown verbatim
   - mapping and channel_decision both exist, but in different,
     non-overlapping applicability contexts → **Mixed** (this is a valid,
     non-conflicting state; the validator only hard-fails on *overlapping*
     contexts, per the Registry contract in section 2)
   - a single applicability context has both a mapping and a decision row
     → this cannot occur if the Registry validator is passing; if it is
     ever observed in the UI, that is a data-integrity alarm, not a
     rendering choice

   Multiple applicability contexts within one cell are shown as badges or
   a summary count, never silently collapsed into one label. Click opens a
   drawer listing every context separately, with its own
   mapping/applicability/transformation/sources/DEC/GAP detail. No
   Approve/Reject actions here.

2. **Платформи та джерела** — CRUD for `connector_definitions` +
   `connector_schema_sources`. An administrator can add a platform (as
   `draft`, promoted to `active` when ready), add multiple sources per
   platform (each with its own `schema_scope`, `source_kind`,
   `acquisition_mode`), edit URLs/versions, and mark verification status.
   No "Run discovery" action here — that is Task 4B.

3. **Governance** — read-only. DEC/GAP required document-type filter using
   the shared Data List toolbar; list and detail via
   `CanonicalGovernanceReader` (strict heading contract), and evidence
   sources from `canonical_product_field_sources.csv`.

Editing/decision workflows belong to Task 4C's single-connector-focused
Mapping Review screen, not to the Field Matrix.

## Operational Connection Pattern (reusable)

Applies to workspace-owned external connections (ERP, commerce platforms, feeds)
— not only Adobe.

### Surfaces

1. **Connection list** — platform, account name, store/base context, connection
   status, last successful check, last successful field discovery, attention
   message, primary action. Do not show internal model names (`ConnectorAccount`,
   auth profile codes, encrypted credentials).
2. **Connection settings** — name, deployment type (when vendor has variants),
   address/tenant context, store view where applicable, credential fields per
   auth profile, masked saved secrets, explicit replace/remove semantics (blank
   secret input does not erase saved value), **Перевірити з’єднання**, **Зберегти**.
3. **Connection check result** — success shows duration and next action (run
   discovery); errors use cause-specific copy with one next step. **401** →
   replace credentials; **403** → update integration role/scopes — never a
   generic “Connection failed” when cause is known.
4. **Discovery overview** — run status, source, captured time, field counts,
   diff summary, first-snapshot label, link to current snapshot.
5. **Discovery field list** — Data List Search & Filter Pattern (search visible,
   filters in right-side slide-over, badge semantics, mobile single overflow).
   Hundreds of fields → list + detail drawer/page, not accordion.
6. **Activity history** — connection checks and discovery runs with time, status,
   cause/actionability, initiator, duration, snapshot link, safe support reference.

### Current state vs history

Overview/list reads **current projection** on the account row. History is a
separate tab/section with search/filter when volume warrants it.

### Error microcopy

Use `user_message_key`-driven Ukrainian business language: explain situation +
one action; no `Error:` prefix, stack traces, or raw vendor messages. Support
may see `vendor_request_id` when safe.

### States to design explicitly

`Не перевірено`, `Підключено`, `Потребує уваги`, `Тимчасово недоступно`,
`Вимкнено`; discovery: first snapshot, no-change, partial/failure after prior
success.

Fixture-backed non-runtime prototype for Task 4B-0:
`docs/prototypes/task-4b0-connector-account/`.

#### Connector runtime polling (Resolved)

Connection check and discovery are asynchronous operator workflows. UI shows
human states (queued/waiting, running, succeeded, failed) without queue/job
terminology. Task 4B-2a ships connection surfaces together with check runtime;
Task 4B-2b ships Discovery Overview together with discovery runtime.

### Connector runtime state presentation

- The connector account projection remains the stable five-state result.
- An active connection check is displayed separately:
  - queued runtime state → localized "Очікує перевірки";
  - running runtime state → localized "Перевірка виконується".
- While a check is active, the previous stable account projection remains
  visible as "Останній результат: …".
- User-facing text must not expose queue, job, worker, retry, execution
  attempt, or transport terminology.
- The runtime state disappears after the check reaches a terminal outcome.
- Runtime and connection-check history surfaces refresh asynchronously.

## Empty States and Onboarding Rules

Empty states must help the user take the next action.

They must not become educational pages.

Good empty state:

```text
Товарів ще немає
[Імпортувати з Excel]
[Додати товар вручну]
```

Rules:

- one primary action;
- one secondary action where useful;
- short explanation;
- no large video embed in primary workflow;
- no long documentation blocks;
- no forced onboarding wizard.

Guided setup may exist if it is:

- short;
- skippable;
- resumable;
- action-based;
- focused on reaching first value.

## Toast and Notification Rules

Toast notifications are for short, non-blocking feedback.

Use toast for:

- saved;
- copied;
- added to cart;
- removed from cart with Undo;
- order submitted;
- reversible action confirmation.

Desktop position:

- top-right.

Mobile position:

- bottom-center, but not covering primary action buttons.

Duration:

- info/success: 3–4 seconds;
- Undo/action toast: 5–6 seconds or until dismissed if feasible;
- error: long enough to read and act, with a clear recovery path.

Do not use modal confirmations for reversible actions.

Use confirmation dialogs only for:

- destructive actions;
- legally significant actions;
- irreversible operations;
- actions affecting many records.

Error toast must say what failed and what the user can do next.

Bad:

```text
Error 500
```

Good:

```text
Не вдалося зберегти зміни. Перевірте підключення та спробуйте ще раз.
```

## Form Validation Rules

Validation must prevent mistakes without punishing the user while typing.

Rules:

- show formatting hints before or during input only when helpful;
- show field-level error on blur after the user has interacted with the field;
- run full validation on submit;
- avoid aggressive real-time errors while the user is still typing;
- errors must say what to fix.

Examples:

- price: numeric input, decimal separator handling, currency formatting after blur;
- phone: locale-aware mask or formatting, not hardcoded globally;
- date: localized display format, stable internal value;
- URL: validate structure on blur;
- required field: validate on submit and after first field interaction.

Validation must be localized.

Validation must not expose technical database or domain terms.

## Loading and Refresh States

Tables with large datasets must use stable loading states.

Rules:

- use skeleton rows or stable placeholders;
- avoid full-page flashing when filtering/searching;
- preserve previous results while refreshing where possible;
- show clear loading state for slow filters/imports;
- avoid layout jumps;
- keep toolbar usable where safe.

For product/order tables with hundreds or thousands of records, pagination or stable virtualized table behavior must be preferred over uncontrolled infinite scroll.

## Mobile Rules

Mobile UI must be usable, not merely responsive.

Breakpoint guidance:

- at approximately `<= 768px`, product tables may collapse into compact row cards;
- prefer framework-native breakpoints aligned to this range; do not mix unrelated breakpoints across components;
- exact implementation may follow the chosen UI framework, but behavior must remain consistent across product/order/customer tables.

Mobile compact row card should show:

- image;
- product name;
- SKU / article number;
- primary price;
- availability;
- quantity;
- primary action.

If B2B buyer mobile UI has multiple primary areas, it should use thumb-friendly bottom navigation for core buyer actions.

Typical B2B buyer bottom navigation items:

- `Товари`;
- `Кошик` or cart summary access;
- `Замовлення`;
- `B2B Каталог` / account context where relevant.

If a sticky cart summary and bottom navigation both exist, the cart summary must sit above the bottom navigation and must not overlap it.

Admin mobile UI may use collapsed sidebar or bottom navigation depending on complexity, but core actions must remain reachable without hunting.

Do not optimize complex import mapping, advanced pricing configuration or analytics as primary mobile workflows in MVP.

They may be accessible, but the main mobile value is:

- viewing orders;
- adding products to order;
- changing simple quantity/status where allowed;
- checking product price/availability;
- contacting customer;
- continuing B2B purchase.

## Accessibility and Cognitive Simplicity Rules

The interface must support users under pressure.

Rules:

- prefer clear words over clever wording;
- prefer recognition over recall;
- keep primary actions visible;
- avoid hidden primary actions in nested menus;
- avoid excessive warnings;
- do not rely only on color;
- keep focus states visible;
- support keyboard operation for tables and forms where practical;
- use sufficient contrast for text and controls.

High-frequency touch targets should be comfortably tappable, especially on mobile.

## Error, Help and Microcopy Rules

The UI must speak business language.

Bad:

```text
AvailabilityResolver exception
```

Good:

```text
Цю кількість потрібно підтвердити менеджером.
```

Bad:

```text
Tenant isolation violation
```

Good:

```text
Дію не виконано. Дані належать іншій компанії або сесії.
```

Bad:

```text
Foreign key constraint failed
```

Good:

```text
Це поле не можна видалити, бо воно використовується в товарах.
```

Help must be:

- short;
- contextual;
- dismissible;
- not required to complete routine tasks.

## Forbidden UI Patterns

The following patterns are forbidden unless explicitly approved by future design/architecture review:

- website builder behavior in B2B catalogue;
- theme marketplace;
- custom CSS per workspace in MVP;
- arbitrary font picker in MVP;
- technical terms in ordinary UI;
- customer-facing cost price or seller profitability;
- reservation / TTL / resolver terminology in merchant or buyer UI;
- modal-on-modal stacking;
- hidden primary actions;
- split buttons for primary B2B actions;
- infinite scroll without pagination or stable position recovery for product/order tables;
- hamburger menu as the only primary navigation for B2B buyer mobile UI;
- AI-generated suggestion UI in primary product/category flows in MVP, including sparkle/wand/magic indicators;
- permanent visual alarm noise;
- red warnings for ordinary non-critical states;
- AI-invented components when approved components exist;
- duplicate B2B product model for UI convenience;
- raw accent color directly applied to text/buttons without contrast validation.

## UI Review Checklist

Before approving UI implementation, check:

1. Does it use approved patterns?
2. Does it preserve architecture boundaries?
3. Does it avoid duplicate product/price/availability/order data?
4. Does it hide internal technical complexity?
5. Does it use business language?
6. Does it keep the main user flow table-first where appropriate?
7. Does it avoid unnecessary dashboards or charts?
8. Does it support search/filter/columns for large lists?
9. Does it avoid leaking exact hidden stock?
10. Does it keep cost/profitability role-aware?
11. Does it work on mobile as a real workflow, not just a squeezed desktop page?
12. Does it use accessible contrast and target sizes?
13. Does it avoid relying only on color?
14. Does it preserve user context when opening drawers, carts or product detail?
15. Does it support reversible actions with Undo where appropriate?
16. Does it avoid destructive confirmations for routine reversible actions?
17. Does it use localized formats and labels?
18. Does it stop for domain documentation patches when needed?

## Final Principle

The UI must make the platform feel obvious.

The architecture may be enterprise-grade.

The user must not feel that enterprise complexity.

The product should feel like a calm, clean operational workspace for selling products:

- search;
- filter;
- check price;
- check availability;
- add quantity;
- process order;
- continue work.

Anything that makes the user stop and ask “what is this system concept?” should be hidden, renamed or removed from the ordinary UI.

The best interface is not the one that shows how powerful the architecture is.

The best interface is the one that lets a small business sell without learning the architecture.
