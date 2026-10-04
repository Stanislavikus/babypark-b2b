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
