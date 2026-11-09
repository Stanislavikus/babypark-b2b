# TASK FOR GPT-5.4 — Product Workbench targeted repo/architecture study

**Project:** B2B Product Data Platform
**Repository:** `Stanislavikus/babypark-b2b`
**Authoritative base:** `origin/develop @ ec47527ab1a71f65dbdce3b605bdc4f9f82d15f3`
**Task type:** targeted repo archaeology + architecture clarification — NO implementation.

## Goal

Resolve only the structural questions that remain after:

- Lead draft: `PRODUCT_WORKBENCH_CHANNEL_UX_SYNTHESIS_2026_09_21.md`
- Sonnet review;
- Lead arbitration: `PRODUCT_WORKBENCH_SONNET_ARBITRATION_2026_09_21.md`

Do **not** re-review the entire Workbench concept from scratch. The core direction “one Product
Workbench + Views + bulk actions + drawer” is accepted unless actual repo evidence proves a
blocker.

## Mandatory reading

1. `docs/Project_Documentation_Map.md`
2. `docs/05-AI_WORKING_AGREEMENT.md`
3. `docs/00-WHY.md`
4. `docs/01-PRODUCT_VISION.md`
5. `docs/02-ATTRIBUTE_DICTIONARY.md`
6. `docs/03-DOMAIN_MODEL.md`
7. `docs/04-ARCHITECTURE_PRINCIPLES.md`
8. `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md`
9. `docs/PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md`
10. `docs/reviews/PRODUCT_STRUCTURE_UX_AI_FINAL_SYNTHESIS_2026_09_13.md`
11. `docs/reviews/PRODUCT_STRUCTURE_UX_AI_IMPLEMENTATION_CONTRACT_2026_09_13.md`
12. `docs/reviews/ADOBE_PROVIDER_SEMANTIC_AUDIT_ARBITRATION_2026-09-08.md`
13. `docs/reviews/PRODUCT_WORKBENCH_CHANNEL_UX_SYNTHESIS_2026_09_21.md`
14. `docs/reviews/PRODUCT_WORKBENCH_SONNET_ARBITRATION_2026_09_21.md`

Inspect current `develop` code and tests for every conclusion.
## NON-NEGOTIABLE boundaries

- Do not redesign frozen connector/sync foundations for preference.
- Do not collapse Master Product truth with remote provider observation.
- Do not weaken ExternalRecordLink trust.
- Do not create Product CREATE/import architecture in this task; identify dependencies only.
  Magento CREATE and remote-only→Master import are separate RED campaigns.
- Do not invent tables/enums until repo archaeology proves current structures cannot express
  the required behavior.
- Keep price/stock automated channel flow inside the platform; do not recommend bypassing the
  platform solely for convenience.

## Question A — Magento workspace row universe / frozen-contract amendment

Product-owner direction now says:

> daily Magento workspace should open on the **actual current Magento catalogue**, proving that
> the store was read successfully; local Master Products selected for publication are a
> Publication/preparation View rather than the first table.

The frozen 2026-09-15 contract currently frames local selected Products as the primary table
and remote-only catalogue as a separate secondary surface.

Research:

1. Identify every doc/test/page/service assumption that depends on the old surface ordering.
2. Distinguish presentation-only assumptions from real data/runtime invariants.
3. Propose the **smallest Stop-and-Amend wording** that makes:
   - Magento Overview row universe = current successful remote catalogue snapshot;
   - Publication row universe = local Master Products selected/linked for that channel;
   - Links view = correspondence/matching between the two;
   without mixing remote-only rows into Master Product ownership.
4. Identify which existing tests must change and which must remain unchanged.
## Question B — Product lifecycle / draft workflow

Merchant requirement:

- a Product may arrive from 1C/import with only minimal identity/basic fields;
- it may wait for a content manager/AI enrichment;
- drafts must be discoverable/filterable and not silently remain forgotten forever.

Current repo evidence says canonical Product status is boolean `is_active`, and a richer
draft/active/archived lifecycle was explicitly deferred.

Research:

1. Inventory every current use of `products.is_active`:
   - B2B visibility;
   - sync/export mapping;
   - filters;
   - API/import;
   - product creation defaults;
   - availability/business logic.
2. Determine whether first-version “draft work queue” can be represented safely by existing
   state + readiness/completeness + channel membership without a new lifecycle column.
3. If not, state the exact missing semantic that forces a richer lifecycle.
4. Do **not** choose a new enum unless unavoidable. If unavoidable, explain migration,
   compatibility with current Adobe boolean status mapping, and why a separate field is safer
   than mutating `is_active` semantics.

Return one recommendation:
- `REUSE CURRENT STATE`
- `ADD SEPARATE LIFECYCLE LATER`
- `NEW LIFECYCLE REQUIRED BEFORE WORKBENCH`

with repo evidence.

## Question C — Remote Catalogue Index V2 at 10k–100k scale

Target default Magento Overview needs, where safely available:

- remote entity ID;
- SKU;
- thumbnail;
- name;
- brand;
- category path/IDs;
- Product type;
- Attribute Set;
- provider enabled/disabled state;
- updated timestamp.

Research current Magento REST/API read shapes and current scanner implementation.

For each desired field:

| Field | Current source | Extra provider calls? | Can batch/page? | Stable enough for index? | Cost/risk |
|---|---|---|---|---|---|

Then propose the cheapest safe enumeration strategy that avoids N+1 calls on 10k–100k
catalogues.

Do not assume every custom attribute must be copied. If brand/category/thumbnail cannot be
obtained cheaply in the first scan, propose a lazy or staged projection and explain UX impact.
## Question D — Category / Attribute Set / required-field preparation

Inventory current repo owners for:

- Master Category;
- ProductType / AttributeGroup;
- connector category mapping;
- Adobe category relation;
- Adobe Attribute Set selection;
- FieldMapping / FieldOptionMapping;
- readiness/completeness;
- required provider fields.

Answer:

1. Why current SyncConfiguration-level `attribute_set_id` is or is not sufficient for a
   multi-category catalogue.
2. What is the smallest universal model that allows each publishable Product/family to resolve:
   - target Magento category/ies;
   - target Attribute Set;
   - required target fields/options;
   without hardcoding Magento into Product rows.
3. Which existing structures can be reused.
4. What remains genuinely new architecture.
5. How manual merchant correction should persist when AI/automation chose the wrong category or
   Attribute Set.
6. How multiple Magento category assignments should be represented without pretending there is
   exactly one “subcategory”.

Do not design Magento structure creation here; assume first content-manager scope chooses from
existing provider structures.

## Question E — Completeness/readiness derivation

Determine whether current readiness-profile and Product Structure foundations can power:

- Basic Information completeness;
- Magento publication readiness;
- SEO readiness;
- Media readiness.

Inventory existing services/config/persistence.

Recommend:
- which profiles can be implemented now without new persistence;
- which require new definitions/rules;
- whether Overview should show a compact active-profile indicator while Publication exposes
  detailed readiness;
- how root-cause aggregation and row-level counts reuse the same evidence.
## Question F — Saved Views persistence

Before inventing a `product_views` table:

1. inspect Filament/current user table preferences/session/local-storage patterns;
2. identify any existing saved-filter/table-column persistence;
3. determine the minimum first implementation:
   - system preset Views only;
   - user-local preferences;
   - workspace-shared Views;
4. state what persistence is actually required for the first useful release.

Target direction: system presets first; custom/private/shared Views later unless current
framework support makes them cheap and safe.

## Question G — Settings inheritance

Inventory existing workspace/account/system settings mechanisms.

Test the proposed conceptual hierarchy:

`platform constraints → workspace defaults → channel defaults where valid → job override`

against actual repo structures.

Use concrete examples:
- AI provider/model availability;
- SEO provider integration;
- content style/tone;
- languages;
- image size/weight preferences;
- SEO depth/market defaults;
- Magento account-wide settings;
- one-batch override.

Recommend existing owners to reuse before proposing any new settings persistence.

## Question H — Price/stock high-frequency path

Current business requirement: 1C currently drives price/stock automatically to the storefront;
future Magento/other channels must not require content-manager approval for every price/stock
change and should not bypass this platform.

Inventory:
- Price/PriceList ownership;
- stock/availability ownership;
- current sync semantic operations/capabilities;
- 1C-relevant planned/existing connector seams;
- Magento price/stock writer support if any.

Clarify:
1. which price/stock updates belong in automated channel sync rather than Product Workbench
   manual review;
2. what Workbench should display about price/stock without becoming their execution engine;
3. what future architecture/capability is required for end-to-end 1C → platform → Magento.
## Question I — Current capability matrix for Workbench

Produce a factual matrix:

| Merchant capability | Exists now | Partial foundation | Missing runtime | UX may expose now? |
|---|---|---|---|---|
| Browse real Magento catalogue | | | | |
| Thumbnail | | | | |
| Brand/category/Attribute Set in remote list | | | | |
| Bulk link confirmation | | | | |
| UPDATE existing Magento Product | | | | |
| CREATE new Magento Product | | | | |
| Remote-only → new Master Product | | | | |
| Manual field edit | | | | |
| AI proposal/review | | | | |
| SEO evidence | | | | |
| Price automatic sync | | | | |
| Stock automatic sync | | | | |

This matrix will prevent UI from promising unavailable capabilities.

## Required output

### 1. Executive verdict
For each Question A–I: `RESOLVED BY EXISTING ARCHITECTURE / SMALL AMENDMENT / NEW ARCHITECTURE REQUIRED`.

### 2. Evidence table
Every conclusion must cite exact doc/code/test seams.

### 3. Accepted minimal corrections to the 2026-09-21 draft
Do not rewrite the whole UX; list exact corrections.

### 4. Required Stop-and-Amend decisions
Only genuinely necessary decisions.

### 5. No-new-persistence opportunities
Explicitly list where current structures are sufficient.

### 6. Capability matrix
As specified in Question I.

### 7. Shortest safe campaign sequence
Separate:
- GREEN/YELLOW Workbench UX/runtime projection work;
- ORANGE/RED capability campaigns such as CREATE/import.

### 8. Unresolved questions
Do not guess.

## Do not implement code.
