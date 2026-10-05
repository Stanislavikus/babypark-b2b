# Master Product Card Completion — campaign control

STATUS: Campaign control artifact — not a Product Decision.

This file exists so the campaign can be resumed safely after chat/session/tool interruption.
Product/domain truth remains the approved documentation and current code on `origin/develop`.

## Identity

- Goal: deliver a complete non-SEO merchant-facing Master Product Card for manual simple and configurable Products.
- Authoritative base at campaign start: `d4c93d34317e7596709b8c66a1b5c07329a3e32b`
- Branch: `feat/master-product-card-completion`
- Worktree: `/var/www/babypark-b2b-master-product-card`
- One campaign -> one branch -> one Draft PR.
- Do not create a replacement branch after interruption unless this branch is proven unusable.

## Frozen scope

The card must converge these approved capabilities into one merchant workspace:

`Basic -> Classification -> Characteristics -> Variants -> Product Media -> Variant Media -> Price -> Inventory -> Physical -> Right rail`

Excluded from this campaign:

- SEO / Search Brief / AI content;
- spreadsheet Smart Import runtime;
- supplier-document AI extraction;
- media Improve/background/channel processing/360;
- generic Publication ChangeSet implementation;
- new Magento VariantMedia behavior;
- WMS / merchant-facing location routing.

Standard Adobe/Magento REST V1 remains the baseline connector. Safe Sync remains optional.

## Frozen implementation inputs

Read and obey at minimum:

- `docs/Project_Documentation_Map.md`
- `docs/00-WHY.md`
- `docs/01-PRODUCT_VISION.md`
- `docs/02-ATTRIBUTE_DICTIONARY.md`
- `docs/03-DOMAIN_MODEL.md`
- `docs/04-ARCHITECTURE_PRINCIPLES.md`
- `docs/05-AI_WORKING_AGREEMENT.md`
- `docs/IMPLEMENTATION_GAPS.md`
- `docs/reviews/MASTER_PRODUCT_WORKSPACE_IMPLEMENTATION_CONTRACT_2026_09_30.md`
- `docs/reviews/MASTER_PRODUCT_WORKSPACE_DELIVERY_MATRIX_2026_10_01.md`
- `docs/reviews/MASTER_PRODUCT_MEDIA_PERSISTENCE_ALIGNMENT_2026_10_01.md`
- `docs/reviews/MASTER_VARIANT_MEDIA_AUTHORING_IMPLEMENTATION_CONTRACT_2026_10_03.md`

Also read exact relevant Pricing / Availability / Connector [Resolved] sections before those slices.

## Routing

Overall risk: ORANGE.

- UI/composition/mechanical work: continue without Product Owner interruption.
- Price ambiguity involving owner/scope/authority/write-back: architecture halt.
- Inventory DB/ledger/cache/transaction/concurrency ambiguity: architecture halt.
- Conflict with newer [Resolved], new DB invariant, new authority rule, new transaction semantics, or new domain entity: architecture halt.
- Cosmetic/UI wording/test-fixture issues: fix and continue.

External model routing if a new unresolved issue appears:

- Sonnet 5: product/UX/repo challenge.
- Opus 5.5: DB, inventory, pricing authority, transactions, locks/concurrency.
- Grok 4.7: optional cheaper second blind opinion.
- Do not use GPT-5.4 for this campaign.

## Inventory frozen implementation contract

Lead synthesis after Opus 5.5 review:

- `Stock.quantity` = location-level allocatable balance after confirmed allocations, before pending reservations.
- `ProductVariant.available_quantity_cache` = variant-level allocatable operational read balance.
- `AvailabilityResolver::netAvailable()` = sellable availability = cache minus active pending reservations.
- Pending reservations are subtracted exactly once.
- `InventoryRecord` is append-only movement evidence.

Master MVP inventory mutation is allowed only when the Variant has exactly one Stock row and
`Stock.quantity === available_quantity_cache`.

Zero-Stock first edit may lazily initialize one internal default location:
- no workspace locations -> create/reuse one deterministic internal default;
- exactly one active location -> use it;
- multiple active locations -> require exactly one active `is_default=true`;
- otherwise fail closed/read-only.
If zero Stock rows but cache > 0, fail closed as reconciliation-required.

Merchant mutation is CAS-protected and must atomically update Stock + cache + status + one
ManualAdjustment InventoryRecord under Variant/Stock locks.

Reservation confirmation must use deterministic
`Variant -> Stock(s) -> Reservation -> ledger` locking, re-check expiry under lock,
reject insufficient allocatable balance, never truncate with `max(0,...)`, and when the
single-Stock invariant holds decrement both Stock and cache atomically. Existing ambiguous
multi-location/mismatch variants retain compatibility cache-only confirmation and remain
read-only in Master; do not invent location allocation.

No automatic historical data migration. Any reconciliation is explicit/evidence-gated.

There is currently no InventoryLocation administration surface. Multiple-location workspaces
without one unique active default remain a concrete deferred/read-only state; do not expand
this campaign into WMS administration.

## Price preflight facts

Before merchant Price mutation, verify current [Resolved] owner semantics.

Known current facts to verify, not reinterpret:

- PriceResolver priority ends with `ProductVariant.base_price_cache` as final fallback.
- Default active PriceList precedes that fallback.
- Existing PriceListItem uses `price` as regular net price and optional lower `sale_price`;
  effective amount is `sale_price ?? price`.
- Do not write Product.price.
- Do not write `base_price_cache` merely because PriceResolver reads it.
- Confirm the approved writable owners for SELL, COMPARE_AT and COST before implementation.

## Execution sequence

1. Phase 0 factual card gap audit.
2. Card composition / Basic / Classification.
3. Characteristics + Variants + existing Product/Variant Media composition.
4. Physical/shipping.
5. Offer/Price merchant workflow after owner preflight.
6. Inventory merchant workflow using the frozen contract above.
7. Right rail / readiness.
8. Remove false pending states for capabilities made real.
9. Whole-card simple Product exercise.
10. Whole-card configurable Product exercise.
11. Visual/product pass.
12. Magento regressions + candidate full gate.

Do not stop after a normal successful slice.

## Test / push discipline

Inside a slice:

`implement -> focused tests -> inspect diff -> fix -> continue`

- Use local MySQL 8 for DB/concurrency-sensitive work.
- Do not trigger the ~47-minute GitHub MySQL gate after small corrections.
- Make logical local commits as checkpoints.
- Push this branch after each completed meaningful slice, not after each micro-fix.
- Before any long/high-risk transition, leave Git in a recoverable state when practical.
- One final candidate push -> authoritative full GitHub MySQL gate.
- No merge/deploy without explicit Product Owner OK.

## Resume protocol after interruption

On any new chat/session:

1. Do not create a new branch.
2. `git fetch origin develop`.
3. Inspect this file.
4. Inspect:
   - `git status --short --branch`
   - `git log --oneline --decorate -15`
   - `git diff`
   - remote branch HEAD.
5. Compare current `origin/develop` with campaign base and assess only real conflicts.
6. Continue from the latest factual checkpoint below.
7. If working tree is dirty, inspect and preserve valid work before any reset/rebase/checkout.

Chat memory is never the recovery source of truth.

## Progress checkpoint

Last completed functional checkpoint before campaign bootstrap: `d4c93d34317e7596709b8c66a1b5c07329a3e32b`

Actual branch HEAD must always be read from Git during resume; this file does not try to self-reference its own commit SHA.

Current phase: **Phase 0 — factual card gap audit**

Completed:
- PR #252 merged and verified before campaign start.
- Sonnet 5 review of campaign task completed.
- Opus 5.5 Inventory mutation review completed.
- Lead verification/synthesis of Inventory contract completed.
- Dedicated branch/worktree created from exact authoritative base.

Next exact work:
- inspect all current Master Product UI/runtime owners;
- build the factual gap table;
- immediately proceed into the first safe implementation slice unless a new architecture halt is proven.

Current blockers: **none**.

Latest test evidence for this branch: none yet — branch starts at verified `develop`.


## Phase 0 factual gap audit — 2026-10-04

| Capability | Current UI on campaign base | Runtime owner | Concrete gap | Risk / path |
|---|---|---|---|---|
| Basic | editable name/description plus identifiers | Product / governed column writers | composition/polish only | GREEN/YELLOW; reuse |
| Classification | Category/Brand/Tags/Product Type in Organization rail | Product relation/columns, TagManager, Product Structure | Category is a flat Select; hierarchy context is missing | YELLOW; hierarchy-aware selector over existing Category owner |
| Characteristics | grouped completeness + governed field editor | FieldDefinition/FieldBinding + governed writers | composition only | YELLOW; reuse |
| Variants | explicit axes/variants actions and summary | ProductVariantStructureService | composition only | YELLOW; reuse |
| Product Media | add/reorder/remove common Originals | ProductMedia services | processing intentionally deferred | YELLOW; reuse |
| Variant Media | #252 authoring/read services + drawer | VariantMediaReadService / VariantMediaMutationService | delivery matrix is stale; no redesign needed | YELLOW; reuse |
| Price | read-only summary + pending edit action | PriceResolver / PriceList / PriceListItem / pricing owners | merchant mutation owner must be proven, including base_price_cache fallback and COST | ORANGE preflight; halt if ambiguous |
| Inventory | read-only availability + pending edit action | AvailabilityResolver / Stock / InventoryRecord / Reservation writers | merchant mutation missing | ORANGE; frozen contract above |
| Physical/shipping | existing Product columns editable for local/manual products | Product Core columns + existing authority rule | applicability/presentation polish remains | YELLOW; do not invent new shipping domain |
| Right rail | Status/Organization/Data quality/Channels/Attention exists | Product summary + connector readiness | final composition/actionability polish | YELLOW |
| SEO / Search Brief / AI | visible read-only/pending | deferred | intentionally excluded | deferred |
| Import | pending action only | deferred Smart Import | intentionally excluded | deferred |

### Phase 0 findings

- Canonical Master `Category` has `workspace_id`, `name`, `parent_id` and stock-display threshold; it has no active/removed lifecycle field.
- Provider/Magento category catalogue lifecycle must not be promoted into Master Category semantics.
- No existing Master Category tree/drawer exists. `CategoryResource` is a flat administrative list and Product Workspace currently uses a flat relationship Select.
- Therefore Slice 1 will expose full Workspace Category hierarchy by ancestor-aware labels/options over the existing relation. It will not create another Category owner or new lifecycle state.
- Variant Media is already implemented by merged #252; only composition/reuse is allowed.
- Delivery matrix row for Variant Media is stale and must be corrected during this campaign.

### PRE-CODE ARCHITECTURAL ALIGNMENT

* **Task Type:** UI business logic / catalog presentation over existing relations; later pricing and availability services under separately frozen contracts.
* **Docs Checked:** `00-WHY.md`, `01-PRODUCT_VISION.md` progressive disclosure/readiness sections, `02-ATTRIBUTE_DICTIONARY.md` Product Fields/assignment/readiness sections, `03-DOMAIN_MODEL.md` Product Catalogue/Pricing/Availability and [Resolved] availability sections, `04-ARCHITECTURE_PRINCIPLES.md` 5-Layer Filter + mandates/checklist, `05-AI_WORKING_AGREEMENT.md`, Master Workspace contract/matrix, Product Media and Variant Media contracts.
* **Affected Domain Contexts:** Product Catalogue, Attribute Dictionary, Pricing, Availability, Connectors (regression boundary only), Workspace.
* **Primary Sources & Standards:** not required for Slice 1 because no external/provider semantics are introduced; current approved repo docs/code are authoritative.
* **Architecture Checklist Result:** workspace isolation preserved; no new Product columns/fields; Product/Variant separation preserved; no Product God Object; hidden default Variant untouched; AvailabilityResolver remains net-stock reader; connector logic remains isolated; no client hardcoding; technical terms remain hidden; Filament validation standard remains unchanged.
* **Architecture Risks Identified:** cross-workspace Category leakage, duplicate Category ownership, accidental provider lifecycle leakage into Master, later Price/Inventory authority/concurrency risk.
* **Chosen Technical Approach:** reuse canonical Category relation and add a workspace-scoped hierarchy projection for merchant labels; later reuse existing Price/Inventory owners only after their gates.
* **Non-Technical Simplicity Check:** merchant sees ordinary Category hierarchy/path, not remote IDs, provider catalogue state, EAV, resolver/cache/ledger terminology.
* **Stop & Amend Required:** No for Slice 1. Price retains its explicit ambiguity gate; Inventory uses the frozen contract above.


## Checkpoint — Slice 1 Classification hierarchy

Completed:
- Phase 0 factual gap audit and Strict Alignment gate.
- Added workspace-scoped `ProductCategoryTreeOptions` projection.
- Master Category selector now shows full ancestor path and retains canonical `Product.category_id` ownership.
- Cross-workspace category selection remains rejected by the existing relationship field validation.
- No Master active/removed Category lifecycle was invented; provider category lifecycle stays provider-owned.
- Delivery Matrix corrected for merged #252 Variant Media and current campaign pointer.

Evidence:
- `MasterProductCategoryTreeTest`: 3 tests / 16 assertions PASS.
- Combined Classification + Master shell run: 25 tests / 151 assertions, exit code 0.
- Pint changed PHP files PASS.
- `git diff --check` PASS.

Next exact work:
- verify Characteristics/Variants/Media need composition only and avoid duplicate implementation;
- inspect Physical/shipping applicability on current frozen fields;
- perform Price owner preflight before any Price mutation code.


## Checkpoint — Slice 2 Master Inventory editing

Completed:
- Added `MasterInventoryMutationService` as the Master-owned manual adjustment writer over the existing Inventory owner.
- Added `MasterInventoryReadService` to expose editable/read-only state without duplicating Availability semantics in Filament.
- Manual/source-neutral Product:
  - simple Product keeps the internal Variant hidden and edits its stock directly;
  - configurable Product selects one concrete current Variant and mutates only that Variant;
  - first edit from zero lazily creates/reuses one safe location according to the frozen contract;
  - exactly one Stock row is editable only when `Stock.quantity === ProductVariant.available_quantity_cache`;
  - multiple Stock rows, cache/Stock mismatch, ambiguous default location, and legacy cache-without-Stock are fail-closed/read-only;
  - every effective change writes one `InventoryRecord(source_type=manual_adjustment)`;
  - stale expected quantity is rejected atomically.
- Source-owned / 1C Product keeps Inventory read-only in the Master card until a separate approved authority/writeback contract exists.
- Added `AvailabilityStatusProjector` and kept the already-existing conservative status behavior: positive allocatable balance -> `in_stock`, zero -> `out_of_stock`; no new low-stock/pre-order policy was invented.
- Corrected `ReservationConfirmer` inside the approved inventory boundary:
  - deterministic lock order Variant -> Stock rows -> Reservation;
  - pending reservation that is already expired by time is rejected;
  - insufficient allocatable balance is rejected instead of truncated with `max(0)`;
  - when exactly one Stock row matches the cache it is decremented atomically with cache + ledger;
  - legacy mismatched Stock stays on the compatibility path and is not silently rewritten.

Evidence:
- focused SQLite/UI/Availability gate: 23 tests / 102 assertions PASS.
- broader pre-UI Inventory gate: 23 tests / 98 assertions with only the expected MySQL concurrency skip.
- real disposable MySQL 8 concurrency proof: 1 test / 18 assertions PASS in 23.999s.
  - simultaneous manual edits from expected 0 -> exactly one success + one stale;
  - one Stock, one ledger movement, one internal default location;
  - simultaneous confirmations of two qty=6 reservations against balance 10 -> one confirmed + one insufficient;
  - final Stock/cache = 4, one reservation remains pending, no oversell.
- Pint changed Inventory/UI files PASS.
- `git diff --check` PASS.

Deliberate boundaries:
- multi-location mutation remains read-only in Master MVP; no aggregate quantity redistribution was invented.
- source-owned/1C Inventory mutation remains read-only pending explicit authority.
- `low_stock` / `pre_order` mutation semantics are not invented.
- Price remains under the separate PRICE AMBIGUITY HALT below.

### PRICE AMBIGUITY HALT — still open, non-blocking for other slices

Current runtime cannot be mapped to the approved distinct `SELL / COMPARE_AT / COST` roles without an explicit semantic decision:
- `PriceListItem.price` is regular net price and is itself SELL when `sale_price` is absent;
- `sale_price` overrides it for effective SELL;
- `recommended_retail_price_cache` is RRP/reference, not resolved SELL;
- `base_price_cache` is resolver fallback;
- `cost_price` is Variant internal cost.
No newer [Resolved] mapping was found. Price code remains untouched until the requested Sonnet 5 semantic review returns.


## Checkpoint — Slice 3 Physical / Shipping + right rail

Completed:
- Expanded the existing collapsed Physical/Shipping section with canonical Product-owned package/logistics facts already present in the domain:
  - net/gross weight, width/height/depth, volume;
  - package quantity/type, units per box, boxes per pallet, lead time.
- Kept Variant-owned `shipping_required` and `backorder_policy` in governed Characteristics; no second shipping-policy owner was created.
- Manual/source-neutral Product can edit these facts; source-owned/1C Product remains read-only under the existing Master authority boundary.
- Clarified the Status rail so `products.is_active` is presented as **Master record state**, not publication lifecycle.
- Added explicit merchant-visible boundary: publication is separate per channel.
- Reworded data-quality/attention copy to remove technical `readiness` jargon and distinguish Master completeness from channel readiness.
- Added read-only `ProductChannelReadinessReadService` over existing channel selection + `AdobeProductClassificationReadService`.
- Magento channel row now exposes only **per-product classification** status:
  - `Класифікація готова`;
  - `Потрібне налаштування`;
  - `Потрібна перевірка`.
- Stable Magento classification blockers are translated into merchant-facing Ukrainian copy.
- The card explicitly states that Magento classification status is **not** publication readiness; full publication checks remain in the Magento channel/Workbench.

Evidence:
- Physical/shipping + Master shell: 10 tests / 115 assertions PASS.
- Channel readiness + Physical/shipping + Master shell final gate: 24 tests / 225 assertions PASS.
- The first combined run found one stale shell assertion for the old text `Інформаційно · не є готовністю конкретного каналу.`; runtime behavior was correct. The test was updated to the approved SAVE/PUBLISH wording and the same gate passed.
- Magento UI tests prove both unresolved classification (missing Magento category + attribute set) and ready classification, and prove the UI does not claim `Готовий до публікації`.
- Pint changed files PASS.
- `git diff --check` PASS.

Next exact work:
- inspect Characteristics / Variants / Product Media / Variant Media composition for duplicate or misleading pending states; reuse existing writers/actions only;
- continue whole-card composition while PRICE AMBIGUITY HALT awaits Sonnet 5 response;
- after Price is resolved, finish Offer editor, remove the final false pending state, then run simple/configurable whole-card browser flows and visual correction.


## Checkpoint — Slice 4 Master Offer SELL / COMPARE_AT

Independent Sonnet 5 arbitration approved the existing Pricing Foundation as compatible with the Master contract. Lead resolved the merchant/storage adaptation explicitly:

- merchant edits semantic **SELL** and optional **COMPARE_AT**;
- no promotion: `PriceListItem.price = SELL`, `sale_price = null`;
- promotion: `PriceListItem.price = COMPARE_AT`, `sale_price = SELL`;
- clearing COMPARE_AT keeps the current SELL as the new regular price;
- RRP remains `recommended_retail_price_cache` and is never COMPARE_AT;
- `base_price_cache` remains fallback only and is never mirrored by Master;
- Master owns only the active workspace default PriceList row for the concrete Variant at `quantity_min=1`;
- customer price lists, higher tiers, scheduled/suspended rows and legacy-invalid sale rows are outside the simple Master writer and fail closed/read-only;
- source-owned/1C Products remain read-only.

Implemented locally:
- Resolved alignment doc `MASTER_OFFER_PRICING_ALIGNMENT_2026_10_04.md` and Documentation Map entry.
- `MasterOfferReadService`.
- `MasterOfferMutationService` with workspace/product/variant/default-list/item locking, CAS protection, workspace ownership and GAP-014 writer-boundary validation.
- real Product Card action `Редагувати ціни` replacing the pending placeholder;
- simple Product hides its internal Variant;
- configurable Product selects one concrete explicit Variant;
- UI labels are merchant concepts `Ціна` and `Ціна до знижки`, not persistence column names;
- gross price is a read-only preview from the existing workspace VAT default; writer stores canonical net values.

Evidence:
- initial domain writer + PriceResolver/order regression: 24 tests / 90 assertions PASS, 1 existing skip.
- final Offer UI + domain + Master shell + PriceResolver/order regression: 37 tests / 215 assertions PASS, 1 existing skip.
- Pint changed files PASS.
- `git diff --check` PASS.

Remaining Offer boundary:
- `ProductVariant.cost_price` remains read-only in the card.
- `docs/02-ATTRIBUTE_DICTIONARY.md` requires financial fields such as COST to be gated behind a specific managerial permission, but current workspace RBAC has no pricing/cost permission.
- Product-owner decision required: introduce `manage_product_cost` with no automatic grant (recommended) versus auto-granting it to every role that currently has `manage_products`.
- SELL / COMPARE_AT implementation does not depend on this choice and is complete.


### Checkpoint — Slice 4B permission-gated COST

Product Owner selected **Option A** on 2026-10-04: `cost_price` receives its own assignable workspace permission rather than inheriting `manage_products`.

Implemented locally:
- canonical `WorkspacePermissions::MANAGE_PRODUCT_COST = manage_product_cost`;
- UA/RU/EN merchant labels in the existing Workspace Access role matrix;
- idempotent migration `2026_10_04_150000_add_manage_product_cost_permission.php` materializes the permission and grants it to **no role automatically**;
- existing `/admin/workspace-access?activeTab=roles` automatically exposes it in Create/Edit Role permissions;
- Master Offer writes COST only when the user has both `manage_products` and `manage_product_cost`;
- COST CAS is checked under the same Variant lock and commits atomically with SELL/COMPARE_AT;
- permission revoke during an open form rejects the whole save;
- users without the permission receive no COST value in offer form state and do not see Product COST or margin-derived values in edit/view/list surfaces;
- users with the permission can edit/clear `ProductVariant.cost_price` without changing `base_price_cache`.

Focused evidence:
- Pint changed PHP files PASS;
- `git diff --check` PASS;
- Workspace permission migration + Workspace Access localization + Workspace Authorization + Offer domain/UI + Master shell + PriceResolver + order pricing regression: **61 tests / 457 assertions PASS**, **1 existing skip**.

No new hard-coded business role was introduced. Merchant-facing roles remain configurable through Workspace Access. A future Content Manager role should be created from actual duties using the existing role editor, not encoded into Pricing.


## Checkpoint — Recovery after chat interruption: Product access + admin width

Factual recovery on 2026-10-04:

- the original campaign worktree survived the chat timeout cleanly;
- local commits `55a1708` (Master Offer editing) and `459eefa` (permission-gated COST)
  were recovered, re-tested and pushed to Draft PR #253;
- deployed application code at `/var/www/babypark-b2b` is current `develop`
  `d4c93d34317e7596709b8c66a1b5c07329a3e32b`;
- read-only production DB inspection proved that `workspace_permissions` contains
  neither `manage_products` nor `manage_product_structure`;
- therefore the existing `CreateProduct` route/form is hidden by
  `ProductResource::getCreateAuthorizationResponse()`, which correctly requires
  effective `manage_products`.

Root cause:

- both permissions were added to the canonical code/seeder catalogue after the
  original RBAC rollout;
- the running production database never materialized those later catalogue rows;
- no fallback to legacy Admin/Director role names is allowed.

Shortest safe correction in this campaign:

- add an idempotent additive migration that materializes the already-approved
  `manage_products` and `manage_product_structure` permission rows;
- grant neither permission automatically to a hard-coded role;
- preserve existing rows/assignments on rollback because migration provenance
  cannot safely distinguish rows previously materialized by a seeder;
- continue assigning both permissions through the existing Workspace Access role editor;
- regression-test that an actor with `manage_products` sees the Product-list Create action.

Admin layout finding:

- Magento `ManageAdobeRemoteCatalog` explicitly uses `Width::Full`;
- ordinary admin pages inherit Filament's narrower default because the Admin panel
  had no global max-content-width setting;
- set only the Admin panel to `Width::Full`, preserving its normal inner padding
  and leaving the customer Cabinet unchanged.

Verification evidence:

- Product permission migration + Admin width + Product creation + Workspace role UI focused gate:
  **18 tests / 118 assertions PASS**;
- combined RBAC + Product creation + Offer/COST + Master shell + PriceResolver + order pricing regression:
  **80 tests / 577 assertions PASS**;
- Pint changed PHP files PASS;
- `git diff --check` PASS;
- no production DB mutation, deployment or merge was performed.


## Checkpoint — Product mutation RBAC blocker

Classification: **BLOCKER — fixed in branch**.

Counterexample / trace found during whole-card merchant-flow audit:

1. The resolved Master Product contract makes `manage_products` the explicit workspace mutation authority.
2. `ProductResource::getCreateAuthorizationResponse()` already enforced it.
3. `ProductResource` had no edit authorization response and no ProductPolicy exists.
4. Filament's resource authorization helper defaults a missing policy ability to allow when strict authorization is not enabled.
5. Therefore a logged-in Admin-panel actor with no effective `manage_products` could mount `EditProduct` and reach ordinary Master form mutation.
6. The custom Product-list bulk tag actions also had no `manage_products` visibility/execution gate; their low-level batch service does not take an actor.

Violated invariant:

- **Explicit workspace Product mutation authority = `manage_products`; legacy role names are not authority.**

Minimal fix:

- add `ProductResource::getEditAuthorizationResponse()`;
- require current-workspace Product ownership plus effective `manage_products`;
- keep Create on the same shared permission helper;
- deny legacy Admin-without-permission at the resource boundary;
- gate Product bulk add/remove tags by the same permission both in visibility and execution;
- keep channel bulk on its existing `ProductChannelSelectionService` authorization;
- do not introduce role-name fallbacks or a second Product authorization mechanism.

Regression evidence:

- explicit local Product edit: denied without `manage_products`, allowed after grant;
- foreign-workspace Product edit: denied even with local permission;
- direct `EditProduct` mount by legacy Admin without permission: 403;
- bulk add/remove tags hidden without `manage_products`;
- source-owned Product read-only tests now grant Product mutation authority first, so they continue testing source authority rather than relying on the old implicit allow;
- complete Master Product UI regression after fixture alignment:
  **111 tests / 760 assertions PASS**.

No production mutation, deployment or merge was performed.


## Checkpoint — integrated simple/configurable merchant journeys + copy pass

Integrated application-flow evidence is now explicit rather than inferred from isolated section tests.

Simple Product journey:

- create a source-neutral Product through the real Filament Create Product page;
- edit category, brand, merchant type, tags and physical/package facts through the Master form;
- upload Product Media through the real media action;
- write SELL / COMPARE_AT / permission-gated COST through the real Offer action;
- write Inventory through the real Inventory action;
- prove the hidden default Variant remains merchant-invisible while its offer/inventory state is updated;
- verify ProductMedia, PriceListItem, Variant COST, Stock/cache and manual-adjustment InventoryRecord persistence.

Configurable Product journey:

- create through the same Product entry point;
- promote the existing simple Variant through the real Variant action;
- prove the original immutable VariantID is preserved;
- upload common Product Media;
- assign that existing asset as Variant Media to one concrete Variant through the real authoring action;
- write Offer/COST and Inventory only to that selected Variant;
- prove the sibling Variant receives no PriceListItem, Stock or VariantMedia side effect.

Evidence:

- focused connected journeys: **2 tests / 97 assertions PASS**;
- full Master Product UI regression including both journeys:
  **113 tests / 857 assertions PASS**;
- this is application-level Livewire/Filament evidence, not a claim of deployed-browser visual acceptance.

Merchant-copy pass:

- retained every approved pending capability and the explicit **«Чекає на підключення»** state;
- removed implementation vocabulary from user-facing explanations:
  `runtime`, `mapping`, `pipeline`, `pixel-transform`, `destination profile`,
  `metadata`, `evidence/proposals`, provider/workflow internals and ProductAssociation implementation terms;
- rewrote the copy as ordinary merchant outcomes without changing capability scope or implementation state;
- regression mounts all pending-action modals and proves the selected internal implementation terms are absent;
- copy + journey gate: **11 tests / 359 assertions PASS**.

Visual-evidence boundary:

- the current connected Remote Desktop/repository tool stack exposes no browser/screenshot runner;
- `composer.json` contains no Dusk/Playwright browser suite;
- therefore final deployed visual/design acceptance remains a separate Product Owner gate and is not inferred from Livewire rendering tests.


## Candidate adversarial Lead review — ORANGE seams

### Finding A — destructive COST permission rollback

Classification: **BLOCKER — fixed in branch**.

Counterexample / trace:

1. `manage_product_cost` is a canonical permission that may already exist because the RBAC catalogue seeder ran before this migration.
2. A merchant may then assign that permission to one or more Workspace roles.
3. The original migration `down()` deleted all role-permission assignments for the code and then deleted the permission row.
4. Therefore rolling back application code could destroy merchant RBAC configuration that the migration did not necessarily create.

Violated invariant:

- **RBAC rollback must not delete pre-existing canonical authority or merchant role assignments when provenance is unknowable.**

Minimal fix:

- make the COST permission migration additive on rollback, matching the later Product permission materialization migration;
- preserve the canonical row and role assignments;
- rely on older application code's fail-closed catalogue check when the permission code is no longer recognized.

Regression:

- migration still materializes `manage_product_cost` with no automatic role grant;
- explicit rollback test creates a role assignment, runs `down()`, and proves both permission and assignment remain.

### Finding B — source-owned Offer / Inventory writer bypass

Classification: **BLOCKER — fixed in branch**.

Counterexample / trace:

1. Existing Product Structure authority already treats either `Product.onec_guid` or a Variant `onec_guid` as source ownership for manual shape mutation.
2. Master Offer originally checked only Product `onec_guid`.
3. Master Inventory relied on a Product-level disabled UI action and had no source-ownership check inside the writer service.
4. Therefore a direct service call could mutate Inventory for a 1C-owned Product, and a manual Product containing a source-owned target Variant could receive manual Offer/Inventory writes for that Variant.

Violated invariant:

- **Source-owned Product/target Variant mutations fail closed until an explicit authority/writeback contract exists. UI disablement is not writer authorization.**

Minimal fix:

- after deterministic Product/Variant locks, both Offer and Inventory writers reject when either the locked Product or target Variant has a source reference;
- Offer/Inventory read state marks a source-owned target Variant read-only while preserving current values for display;
- COST input follows the same Offer `editable` state and is visually disabled;
- a source-owned sibling does not automatically block a different manual target Variant; Offer/Inventory authority is evaluated on the concrete target while Product Structure retains its stricter whole-shape rule.

Regression:

- direct source-owned Product Inventory write fails before location/Stock/ledger creation;
- direct source-owned Variant Inventory write leaves Stock/cache/ledger unchanged;
- source-owned Variant Offer retains existing SELL/COMPARE_AT for display and rejects mutation without changing the PriceListItem;
- UI tests prove SELL / COMPARE_AT / COST and quantity fields become read-only for a source-owned target Variant.

Focused authority/RBAC evidence after correction:

- COST migration + Offer domain/UI + Inventory domain/UI:
  **38 tests / 281 assertions PASS**.

### Finding C — money value beyond physical DECIMAL(15,2) storage

Classification: **SHOULD FIX — fixed in branch**.

Counterexample / trace:

- numeric input such as `1e20` passes PHP numeric validation and was normalized before MySQL rejected it as outside the existing `DECIMAL(15,2)` storage range.

Violated quality boundary:

- **Master Offer writer rejects invalid merchant input as a domain error before persistence rather than surfacing a storage-layer failure.**

Minimal fix:

- enforce the existing physical maximum `9999999999999.99` for SELL, COMPARE_AT and COST in the common money normalizer;
- do not alter schema, currency semantics or PriceResolver behavior.

Regression:

- oversized SELL, COMPARE_AT and COST are rejected before any PriceListItem/COST mutation;
- normal pricing regression remains green.

### Reservation / concurrency challenge

Classification: **NON-BLOCKING — architecture retained**.

Trace reviewed:

- Creator: Variant -> pending Reservations;
- Confirmer: Variant -> ordered Stock rows -> target Reservation -> ledger;
- Releaser: target Reservation only, with no reverse wait on Variant;
- confirmer re-checks target Variant identity, status and expiry after acquiring the Reservation lock;
- insufficient cache balance rejects rather than truncates;
- when one Stock row matches cache, Stock and cache decrement together;
- legacy Stock/cache mismatch stays on the compatibility path instead of being silently reconciled;
- pending reservation quantity is removed from the pending set when cache is decremented, so availability is not subtracted twice.

Focused Availability regression:
- Reservation/availability service suite green after review; no new lock-order or double-subtraction counterexample found.

No architecture change or external-model escalation was required because all findings resolved directly against frozen authority/RBAC/storage contracts.


### Candidate local gate after adversarial fixes

Current branch regression after Findings A–C:

- expanded Master Product + RBAC + Availability + Pricing + order regression:
  **198 tests / 1,573 assertions PASS**;
- focused Offer + Reservation/Availability suite:
  **29 tests / 115 assertions PASS**;
- fresh disposable MySQL 8 concurrency proof on the current working tree:
  **1 test / 18 assertions PASS**;
  - simultaneous manual inventory edits from expected 0 still produce exactly one success and one stale result;
  - one Stock row, one ledger movement and one deterministic internal default location remain;
  - simultaneous confirmation of two qty=6 reservations against balance 10 still produces exactly one confirmed and one insufficient result;
  - final Stock/cache remains 4 with no oversell;
- disposable MySQL database was removed after the proof.

The earlier GitHub MySQL run on `0d6f818` is now stale by definition because this review produced additional fixes. Final CI evidence must be taken only from the workflow started for the next pushed exact HEAD.


## Candidate CI correction — permission catalogue count fallout

GitHub MySQL workflow **#37236564717** on candidate HEAD
`010f5cd0a0fb8907d398ca69a51ee806dcb27d6d` failed at the
`SyncRun persistence foundation tests (MySQL)` step.

Root cause:

- the branch intentionally adds the already-approved canonical
  `manage_product_cost` permission;
- `WorkspacePermissions::catalogue()` therefore correctly contains **13**
  permissions;
- five older test files still hard-coded the previous catalogue size **12**;
- GitHub stopped on `SyncRunPersistenceFoundationTest` before the later RBAC
  and full-suite steps could run.

Classification: **test-contract fallout — branch-owned, fixed in branch**.

Minimal correction:

- exact catalogue-size assertions are updated from 12 to 13;
- seeder row-count assertions now compare against
  `count(WorkspacePermissions::catalogue())`, while existing canonical-code
  equality and no-auto-grant assertions remain intact;
- no production RBAC behavior or role assignment was changed.

Focused regression after correction:

- SyncRun / sync permission / RBAC catalogue group:
  **87 tests / 246 assertions PASS**.

### Clean-base control for unrelated local full-suite failures

A separate local full-suite run on the candidate exposed 16 failures around
Price Inspector / presentation / toolbar/form Ukrainian copy. These are **not**
attributed to this campaign:

- the exact same focused set was run from a detached clean
  `origin/develop` worktree at
  `d4c93d34317e7596709b8c66a1b5c07329a3e32b`;
- clean base reproduced the same **16 failed / 78 warnings / 478 assertions**;
- local repository testing has no `.env`, so Laravel falls back to
  `config/app.php` default `APP_LOCALE=en`;
- GitHub CI's Prepare application step copies `.env.example`, which explicitly
  sets `APP_LOCALE=uk` and `APP_FALLBACK_LOCALE=uk`.

Therefore the local English-vs-Ukrainian presentation failures are a proven
pre-existing local test-environment baseline and are not modified or imported
into PR #253.

The next pushed HEAD requires a fresh exact-HEAD MySQL workflow; #37236564717
remains historical failure evidence only.
