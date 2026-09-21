# Product Workbench + Channel UX Synthesis — 2026-09-21

> **Status: DRAFT RESEARCH INPUT — NOT YET NORMATIVE / NOT [Resolved].**
>
> Purpose: preserve the whole merchant-facing product-work model before implementation,
> so individual Magento/SEO/AI/media tasks cannot drift into disconnected screens.
> Freeze only after independent UX/repo review and Lead synthesis.

## 1. Product goal

The platform must let a non-technical merchant understand and operate product work without
learning connector/runtime vocabulary. The working mental model is:

- one governed **Master Catalogue** owned by the platform/workspace;
- one or more **Channels** such as Magento, Google, Shopify, Amazon, native B2B;
- each channel exposes the provider-side catalogue/state without becoming a second editable
  copy of Master Product truth;
- users select/filter products, inspect readiness/problems, perform bulk actions, review
  proposals, and publish/apply through governed connector writers;
- technical stages such as SyncConfiguration, Preview, Entity Trust, reconciliation,
  FieldMapping and revision guards remain runtime concepts unless a causal merchant action
  genuinely requires exposing them.

## 2. Primary UX reference pattern

Use **one universal Product Workbench** with configurable Views rather than independent,
unrelated tables for Magento, SEO, AI, Media, Shopify, Google and B2B.

Conceptually each View is:

```text
ROW UNIVERSE
+ COLUMNS
+ FILTERS
+ READINESS PROFILE
+ AVAILABLE ACTIONS
+ FRESHNESS / EVIDENCE CONTEXT
```
The same underlying product/work data may therefore be presented as:

- Master Catalogue;
- Magento / Overview;
- Magento / Publication;
- Magento / Content & SEO;
- Magento / Media;
- Magento / Links;
- future Shopify / Google / Amazon equivalents;
- B2B Ready / Google Ready / SEO Ready views.

Plytix is the primary reference for grid mechanics / saved views / configurable columns.
Akeneo is a reference for completeness, enrichment, review and human-readable field work.
ChannelEngine is a reference for channel publication state, validation feedback and
selection/categorization/mapping progression. Do not copy any single product literally.

## 3. Magento daily-work entry

Connection management and daily product work are different jobs:

- **Інтеграції → Magento** owns credentials, target/account health, connection checking and
  connection repair.
- **Канали → Magento** is the proposed daily merchant work entry.

The Magento workspace should expose connection health in the header so merchants do not have
to re-enter Integrations merely to confirm that the connector is healthy.

Default workspace focus should be the **actual Magento catalogue/state**, because this gives
the merchant immediate confidence that the connected store was read successfully.

## 4. Default Magento catalogue grid

Default visible columns should start from the strongest scan/orientation fields:

| Column | Default | Notes |
|---|---|---|
| Selection checkbox | yes | familiar bulk-selection affordance |
| SKU | yes | primary business identifier; keep prominent |
| Thumbnail | yes | lightweight preview; remote index must eventually supply it |
| Назва | yes | UI label for canonical `Product.name` / remote name |
| Бренд | yes | canonical brand where available |
| Категорія | yes | show breadcrumb/path; filters must support branch/leaf |
| Стан даних / completeness | candidate | profile-specific completeness, not generic sync truth |
| Зв'язок з каталогом | yes | linked / candidate / remote-only; distinct from readiness |
| Magento state | yes | provider publication/enabled state; distinct from platform status |
| Проблеми | yes | compact count/indicator with drill-down |
| Оновлено | yes | meaningful freshness timestamp |
Columns such as Description, Meta Title, Attribute Set, image count, price, stock,
SEO evidence and other fields remain available through configurable columns and Views even
when not default-visible.

### Category depth

Do **not** hardcode the domain model to exactly two category levels.
The default UI may optimize for Category + Subcategory because it is a common merchant
workflow, but must preserve deeper provider hierarchies. Preferred display is a breadcrumb
such as:

`Дитячі товари › Коляски › Прогулянкові`

Optional columns/filters may expose L1/L2 separately for operators who prefer that layout.

### Attribute Set / Attribute Group

- Magento **Attribute Set** is useful as a filter/column, especially for preparation work.
- Magento **Attribute Group** is presentation structure inside a set and should normally
  appear in product/detail/enrichment UI, not clutter the default overview.
- Merchant action language should be **Заповнити характеристики**, not “fill attribute group”.

If automatic classification chooses the wrong Category or Attribute Set, an authorized user
must be able to override it from the provider's existing structures. Automation must be
assistive, never an uneditable black box.

## 5. Platform product lifecycle/status

A channel grid needs a visible **platform product lifecycle/status** distinct from Magento
enabled/disabled state and distinct from connector publication state.

The product may exist as an early draft created from 1C/import with only identity/basic data,
then be enriched by AI/human review, then become publishable. Exact persistence/status enum
is **OPEN** and must not be invented in this draft.

UX requirement: drafts must be discoverable/filterable and must not remain forgotten forever.
Readiness/completeness and lifecycle status are separate dimensions.
## 6. Core Views

### 6.1 Огляд

Default scanning/navigation view. Candidate defaults:

```text
☑ | SKU | Thumbnail | Назва | Бренд | Категорія
  | Lifecycle | Зв'язок | Magento state | Проблеми | Оновлено
```

Whether a compact completeness indicator also belongs here remains an implementation UX
question; do not duplicate information merely because Publication also needs completeness.

### 6.2 Публікація / підготовка до Magento

Same product universe, different columns/actions:

```text
☑ | SKU | Thumbnail | Назва | Magento Category | Attribute Set
  | Обов'язкові дані | Зображення | Next action | Last result
```

The runtime may decide Create / Update / No change / future Delete, but merchant labels should
describe the consequential effect clearly.

This View must eventually support the newly required **Magento Product CREATE** capability;
current certified Magento V1 remains UPDATE-only until CREATE receives its own architecture,
implementation and real-target certification.

### 6.3 Контент і SEO

Candidate columns:

```text
☑ | SKU | Thumbnail | Назва | Опис | Meta title | Meta description
  | Keywords evidence | Search/ranking evidence | AI proposal state
  | Review state | Evidence freshness
```

Long content is summarized in-grid and opened in a drawer/detail comparison.
SEO keywords/ranking are **evidence**, not direct canonical Product truth.

### 6.4 Зображення

Candidate columns:

```text
☑ | SKU | Thumbnail | Назва | Image count | Readiness
  | AI media proposals | Review state | Channel result
```
Full original/derived media, approvals, language/state and provenance belong in the
product drawer/detail, not as dozens of grid columns.

### 6.5 Зв'язки

Candidate columns:

```text
☑ | Magento SKU | Thumbnail | Magento name | Candidate Master Product
  | Confidence/evidence class | Trust state | Row action
```

Bulk confirmation is required for scale, while row-level action remains available.
No automated candidate match becomes trusted merely from similarity; existing
ExternalRecordLink rules remain authoritative.

## 7. Product drawer / one-product work

Selecting/opening a row should preserve list context and open a side drawer where practical.

Candidate sections:

- Основне;
- Magento;
- Контент;
- SEO;
- Медіа;
- Історія.

Manual editing must remain possible for users who do not want AI.
AI proposals should be shown beside current values, never silently overwrite approved truth.

## 8. Bulk-action principle

Bulk actions appear from the current View + current row selection instead of showing every
possible capability all the time.

Examples:

- Додати товари в Magento;
- Забрати з Magento / future governed import;
- Зв'язати з каталогом;
- Дослідити ключові слова;
- Отримати/оновити пошукові позиції;
- Згенерувати контент AI;
- Заповнити пропущені характеристики;
- Перекласти;
- Підготувати/перевірити публікацію;
- Переглянути зміни;
- Опублікувати / оновити.
Preview remains a mandatory runtime safety gate where required, but it should normally be a
stage inside the merchant's chosen action rather than a frightening standalone technical page.

## 9. Completeness / readiness

Completeness is profile-specific. One percentage cannot truthfully mean all of:

- basic product data;
- Magento publish readiness;
- SEO readiness;
- media readiness;
- B2B readiness.

The grid may show a compact percentage for the active profile. Clicking it should explain
which fields are complete/missing in human terms. The existing readiness-profile architecture
should be reused rather than inventing connector-specific hardcoded percentages.

## 10. SEO + AI workflow preserved from earlier BabyPark process

The historical spreadsheet workflow contained useful capability groups, including:

- identity/classification: category, manufacturer/brand, model, EAN, SKU, name, options;
- media generation: languages, dimensions/weight constraints, processing, approval, outputs;
- content generation: language, depth, keywords, title/name, description, short description,
  meta title, meta description, FAQ;
- reviews generation/approval;
- per-locale generation/review states and logs.

Do not reproduce the spreadsheet as a 100-column SaaS grid.
Translate it into:

`Grid → View → bulk action → evidence/proposal → human review → governed apply`.

### Evidence vs proposal vs approved value

Keep these distinct:

1. **Evidence** — keywords, search rankings, competitors, provider observations, timestamps;
2. **AI proposal** — suggested new field values/content;
3. **Approved value** — canonical/channel value authorized for apply.

Existing descriptions must not be overwritten merely because a new keyword study ran.
Every SEO/search result displayed later must carry source/context and freshness so an old
ranking cannot be mistaken for current evidence.

## 11. Settings ownership layers

Do not create one giant “Settings” page.

### 11.1 Platform/System owner settings

Reserved for system-wide operational policy and defaults, for example:

- supported AI/SEO providers and provider credentials;
- global safety/limit policies;
- available generation models/capabilities;
- allowed image processing profiles/technical ceilings;
- connector capability configuration unavailable to normal workspace users;
- default templates that workspaces may inherit.

These are platform-owner/admin concerns and must not leak into everyday merchant UX.

### 11.2 Workspace / client settings

Merchant-configurable defaults, for example:

- preferred content style/tone;
- target content language(s);
- image output dimensions/weight preferences within platform constraints;
- SEO research defaults;
- AI review/approval preferences where policy allows;
- channel-specific defaults that genuinely apply account-wide.

### 11.3 Per-job / per-selection overrides

Bulk actions may override defaults for one operation, e.g.:

- this generation uses Extended SEO research;
- these 120 products use a different image size;
- this batch generates only UA+EN;
- these products use a specific existing Magento Attribute Set.

Resolution order should conceptually be:

`platform constraints → workspace defaults → channel defaults where valid → job override`.

Exact persistence entities are **OPEN** until implementation research proves what is needed.
## 12. Magento structures and manual correction

The system should recommend/select existing Magento structures where possible, but an
authorized merchant/content operator must be able to correct:

- Magento category / subcategory/path assignment;
- Magento Attribute Set;
- supported attribute values/options.

First CREATE scope should prefer **existing provider structures**. Creating Magento Categories,
Attribute Sets, Attributes or options from this platform is a separate administrator capability,
not something a content manager should trigger accidentally.

## 13. Data freshness

Any observation/evidence whose usefulness decays must expose freshness.

Examples:

- remote Magento catalogue read time;
- provider Product updated time where reliable;
- SEO/search-position research time;
- AI proposal generation time/model/version where needed;
- last successful publish/update;
- last media derivation.

Do not present stale evidence as current truth.

## 14. Current implementation gaps discovered during UX audit

Confirmed against current `develop`:

- Magento Remote Catalogue exists and successfully enumerates the real test store.
- Remote index currently has entity ID, SKU, name, type, status and update time but the Magento
  scanner does **not** currently fill thumbnail locator.
- Current remote index also does not yet provide the richer category/brand/Attribute Set grid
  projection proposed here.
- Category relation runtime/persistence exists, but merchant-facing category-mapping UX is not
  yet a complete daily workflow.
- Current export setup exposes one SyncConfiguration-level Attribute Set selection; a universal
  multi-category/multi-Attribute-Set publication model remains unresolved.
- Current Product CREATE is not a shipping Magento capability and is now a required next
  product capability.
- Existing Receive foundation does not yet equal “select remote-only Magento products and
  create governed Master Products”.
## 15. Decisions accepted as working UX direction

The following are accepted as the current research direction, subject to independent review:

1. One universal Product Workbench rather than separate unrelated tables.
2. Configurable/saved Views control visible columns, filters, sorting and actions.
3. Magento workspace defaults to the real Magento catalogue/state.
4. SKU, Thumbnail, Name, Brand and Category are strong default orientation columns.
5. Long Description is optional/configurable, not default-visible.
6. Attribute Set is available but not necessarily default-visible; Attribute Groups belong in
   enrichment/detail presentation.
7. Row checkbox + bulk actions are first-class; row actions remain available.
8. Side drawer/detail is the primary one-product edit/review surface.
9. AI is proposal-first with human review; manual editing remains first-class.
10. SEO/search data is evidence with freshness, not silent Product truth.
11. Platform/system settings, workspace defaults and per-job overrides are separate layers.
12. Automation must always permit an authorized correction when category/Attribute Set
    inference is wrong.
13. Product lifecycle status, completeness/readiness, provider state and sync result must not
    be collapsed into one ambiguous “status”.

## 16. Open questions that must be resolved before implementation

- Exact Product lifecycle/status model: reuse existing state or introduce a governed draft/
  ready/active concept?
- Exact universal category presentation/filter model and whether L1/L2 columns are default.
- Universal classification model connecting Master Category/ProductType to target category,
  target Attribute Set and target required fields.
- Exact source and scalable read shape for remote Magento thumbnail, brand, category path and
  Attribute Set on 10k–100k catalogues.
- Saved Views persistence scope: system presets only first, or user/workspace custom Views?
- Which completeness/readiness indicators are default in Overview vs Publication.
- Exact settings persistence and inheritance model.
- Magento CREATE semantics and remote-only Magento → Master import semantics.
- Price/stock high-frequency sync path vs content Product Workbench; do not route around the
  platform merely for convenience, but do not force content-style manual workflows onto
  inventory/price feeds.
## 17. Mandatory pre-implementation reviews

### UX/product challenge

Use Sonnet 5 High to challenge:

- whether the proposed Workbench/View model remains understandable for a first-time merchant;
- whether default Magento columns and information hierarchy are correct;
- whether any action/view naming is ambiguous;
- whether critical workflows require too many context switches;
- whether platform owner/workspace/job settings are understandable and discoverable;
- how proven PIM/channel-management patterns can simplify the flow further.

### Repo/architecture challenge

Use GPT-5.4 for targeted repo archaeology on the OPEN questions above. It must not redesign
frozen foundations merely for preference.

### Separate RED campaigns

Magento Product CREATE and remote-only Magento → new governed Master Product import must not be
smuggled into a UX-only PR. They require dedicated architecture/certification work.

## 18. Implementation gate

Do not produce the final implementation task until:

1. Sonnet findings are arbitrated against this draft and actual repo;
2. targeted GPT-5.4 findings for structural open questions are arbitrated;
3. Lead writes a frozen implementation contract or explicitly marks unresolved capability
   slices as deferred;
4. the first implementation campaign has observable acceptance evidence and a bounded scope.

The purpose of this document is to keep every future screen/task checked against the whole
product system before code is changed.
