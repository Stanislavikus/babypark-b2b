# Product Workbench — Two Structural Decisions — Lead Study 2026-09-21

> **Status: PROPOSED — discussion / independent challenge before [Resolved].**
> Base: `origin/develop @ ec47527ab1a71f65dbdce3b605bdc4f9f82d15f3`.
> Decision B has now received Gemini independent challenge; use `PRODUCT_WORKBENCH_DECISION_B_GEMINI_ARBITRATION_2026_09_21.md` as the corrected Lead synthesis for that seam.

## Decision A — Magento daily workspace row universe

### Goal

A merchant who opens the Magento channel should immediately understand that the correct store
was connected and see what actually exists there, without learning selection/Preview/runtime
concepts.

### Options considered

#### A1 — local selected Master Products remain the primary table

Advantages:
- matches the current 2026-09-15 frozen contract;
- naturally supports outbound preparation;
- Master Product ownership is obvious.

Problems:
- a newly connected existing Magento store can contain thousands of products, yet the first
  screen may show only platform-selected local products;
- the merchant does not immediately get the confidence signal “these are the products from my
  store”;
- remote-only existing products are pushed behind a secondary action even though they are the
  dominant reality during onboarding;
- it optimizes for a greenfield marketplace-export workflow rather than an existing-store hub.

#### A2 — actual Magento Remote Catalogue is the default Overview; publication is a separate View

Advantages:
- first screen proves the store read succeeded;
- scales naturally to browse/search/filter existing Magento products;
- link state, provider state, issues and freshness can be overlaid without transferring
  ownership;
- local Products waiting for publication remain a different row universe where readiness and
  future Create/Update actions make sense;
- matches mature channel-manager separation between channel/listed-product state and product
  selection/preparation.

### Lead proposal

**Propose A2.**

Magento workspace presets:

1. **Огляд** — row universe = current successful Magento Remote Catalogue snapshot.
2. **Публікація** — row universe = local Master Products selected/linked for this account and
   therefore relevant to outbound preparation.
3. **Зв'язки** — correspondence between remote rows and Master Products.
4. Later Views such as **Контент і SEO** / **Медіа** operate on an explicitly chosen supported
   scope; they do not silently convert remote observation into Master truth.

Ownership remains unchanged:

- remote row = provider observation;
- Master Product = platform truth;
- ExternalRecordLink = trusted correspondence;
- selection membership = outbound intent.

No default union table should mix remote-only products and local-not-yet-created Products into
one ambiguous row universe.

### Required Stop-and-Amend

Amend only the information-architecture ordering in
`PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md`:

- replace “primary Product table = selected local Products” with a View-aware contract;
- freeze `Огляд = remote snapshot`;
- freeze `Публікація = selected/linked local Master Products`;
- preserve every existing ownership, snapshot, ExternalRecordLink and Preview/Live invariant.

No DB/runtime ownership redesign is required for Decision A.

---

## Decision B — target Category + Magento Attribute Set + manual correction

### Goal

For every local Product/family being prepared for Magento, resolve the provider structure that
determines where the product belongs and which attributes are applicable/required, while
allowing an authorized human to correct automation without putting Magento-specific fields on
the Master Product.

### Current repo truth

- Master Product has one internal `category_id` and one `product_type_id`.
- `ConnectorCategoryMapping` currently maps one internal Category to one external category per
  ConnectorAccount.
- Magento category execution already has a dedicated relation runtime and preserves provider-only
  category relations for existing linked products.
- Adobe `attribute_set_id` is currently one SyncConfiguration-level execution setting.
- Domain docs explicitly reject `ProductType == Adobe attribute_set_id`.
- Domain docs explicitly allow a future connector-owned mapping from Product classification/type
  to Adobe attribute sets.
- Adobe Product payload has one `attribute_set_id` but may have multiple category links.

### Options considered

#### B1 — keep one SyncConfiguration Attribute Set + one category mapping

Reject.

It cannot represent a mixed catalogue such as strollers, highchairs, toys and accessories that
need different Magento structures.

#### B2 — persist full effective Magento classification on every Product

Example: every selected Product stores category IDs + attribute set, even if values are fully
derived from its ProductType/Category.

Reject as the default model.

It duplicates derived defaults, creates stale copied state when a category/type mapping changes,
and forces mass rewrites for what should be rule/default changes.

#### B3 — reusable defaults + sparse per-Product overrides

**Lead proposal.**

Derive the effective Magento publication classification using two levels.

### B3.1 Default rules

**Category default**

Reuse existing account-scoped `ConnectorCategoryMapping`:

`Master Category -> one default target Magento leaf category`.

First scope keeps one default target category per Master Category. The Magento hierarchy is
displayed as a breadcrumb/tree; assigning the leaf does not require inventing separate domain
columns for category/subcategory.

**Attribute Set default**

Add a connector-owned Magento mapping:

`ConnectorAccount + ProductType -> Magento attribute_set_id`.

This is not a Product field and does not make ProductType equivalent to Attribute Set.
Many ProductTypes may map to the same Magento Attribute Set.

### B3.2 Sparse Product override

When automatic/default classification is wrong, allow an authorized merchant to override for
that Product/account.

Effective resolution:

1. explicit confirmed Product override, if present;
2. otherwise derived account-level defaults from Product Category + ProductType;
3. otherwise unresolved blocker.

AI/recommendation is not effective state until merchant confirmation.

The Product override must support:

- exactly one Magento Attribute Set;
- zero/one/many Magento category IDs as an explicit replacement set;
- “reset to automatic” by removing the override.

Do **not** invent a Magento “primary category” unless real provider/runtime behavior requires it.
Magento accepts multiple category links; first model should treat them as a set. Provider-only
relations on already-existing remote Products remain protected by the certified relation runtime
and are not silently deleted by a preparation override.

### Why sparse override is preferred

- broad corrections happen once at Category/ProductType mapping level;
- exceptional products do not force changing their canonical ProductType/Category;
- derived defaults stay live instead of being copied onto every Product;
- manual correction is explicit and reviewable;
- future AI can recommend defaults/overrides without becoming authority;
- the universal Master Product remains connector-neutral.

### Connector-specific versus generic persistence

Do not create a generic stringly-typed “classification dimension/value” DSL merely to claim
multi-platform universality.

Keep truly universal concepts universal:
- Master Category;
- ProductType;
- selection;
- readiness;
- mapping/revision/governance patterns.

Keep Magento-only semantics connector-owned:
- Adobe Attribute Set mapping/override.

Category mapping may remain the existing generic connector-category seam.

Exact table/class names remain open until independent challenge, but the semantic split should
be preserved.

### Preview / revision integration

Any effective change to:
- ProductType -> Attribute Set mapping;
- Product-specific Attribute Set override;
- Product-specific target category override;
- existing Category -> target category mapping

must invalidate stale publication readiness/Preview evidence through the existing mutation /
snapshot/revision pattern. Do not let Preview read mutable “latest” classification behind a
previously admitted revision.

### Merchant UX

Normal user sees human actions:

- `Категорія Magento` — tree selector, recommendation + manual correction;
- `Набір характеристик Magento` (or final researched label) — existing Attribute Set selector;
- `Заповнити характеристики` — derived from the effective Attribute Set/field mappings.

At scale:
- edit mapping for a whole Master Category/ProductType;
- bulk apply/confirm recommended mappings;
- per-row pencil for exceptions.

### Remaining architecture questions for independent challenge

1. Is ProductType the correct reusable default owner for Magento Attribute Set, or should the
   default be keyed by a compound classification such as ProductType + Category?
2. Should the Product override persistence be one Magento-specific header with normalized child
   categories, or separate narrowly owned tables?
3. What exact revision owner should include these mappings so Preview/Live stale-detection stays
   deterministic?
4. Does first CREATE scope need multiple target categories, or can multiple categories remain an
   optional override while default mapping stays one leaf category?
5. Can any existing account-scoped structure mapping persistence be reused without semantic
   abuse?

---

## Lead routing

### Decision A

Risk: **GREEN/YELLOW documentation + UX ordering**.
No additional architect required unless repo tests reveal hidden coupling.

### Decision B

Risk: **ORANGE**.
New connector-owned persistence + revision/readiness semantics are involved.
Use one independent architecture challenge before freeze. Gemini is sufficient and cheaper than
Opus here because there is no new tenant/security/distributed-transaction foundation in this
decision.

Magento Product CREATE itself remains a separate **RED** campaign and still requires the
previously planned GPT-5.4 + Opus 5 blind architecture studies.
