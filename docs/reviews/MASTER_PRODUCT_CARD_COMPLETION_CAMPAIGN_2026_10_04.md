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
