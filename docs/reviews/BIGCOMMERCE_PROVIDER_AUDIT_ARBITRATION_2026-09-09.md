# BigCommerce Provider Audit Arbitration — 2026-09-09

Status: LEAD ARBITRATION IN PROGRESS — SONNET OVERLAY RECORDED, GPT-5.4 PENDING

Authoritative review base: `develop @ 8bc70b809732ed83292b78272ca39de3c2bf0360`.

This ledger preserves the independent BigCommerce provider review campaign after Adobe Commerce and Google Merchant were frozen. Reviewer findings are challenges, not authority. Corrections are accepted only when provider evidence, current repository state, and the runtime/registry contract form a concrete contradiction.

## Frozen BigCommerce input at review start

- Physical source rows: 136.
- Provider concepts: 119.
- OPEN disagreement families: 8.
- BigCommerce canonical field mappings: 18, all `v3-openapi-2026-09-07`.
- BigCommerce canonical option mappings: 0.
- Object-family counts: Product 77, ProductVariant 26, ProductModifier 9, ProductOption 8, PriceList 8, Inventory 8.

## Sonnet High semantic/domain review

Sonnet reported `BIGCOMMERCE SEMANTIC CORRECTIONS REQUIRED` and proposed ten findings plus a missing `max_order_quantity` candidate. Lead independently rechecked each finding against the frozen inventory, canonical registry/runtime contracts, and current BigCommerce documentation.

### S1 — Product/Variant-only canonical mapping surface

Lead verdict: **ACCEPT — MATERIAL, BUT REJECT THE PROPOSED FIX AS WRITTEN**.

Current registry maps the shared family only to `ProductVariant.*`: `sku`, `gtin`, `mpn`, `price`, `sale_price`, `cost_price`, `recommended_retail_price`, `net_weight`, `depth_mm`, `width_mm`, `height_mm`. No corresponding `Product.*` mapping exists.
BigCommerce current Create Product contract requires `name`, `type`, `weight`, and `price`; the official create example writes Product-level `price` and `weight`. Variant `price`, `weight`, and dimensions are nullable override representations with documented Product fallback. Therefore the provider evidence has two real entity-level representations and the registry currently records only one side.

However Sonnet's proposed `create -> Product.* / update -> ProductVariant.*` mapping contract is too execution-specific for the current registry. `FieldMapping` is direction-neutral semantic correspondence, not an execution plan; current runtime mapping suggestions also ignore mapping applicability and would fail closed if two external keys compete for one binding. The correction pass must first model both provider representations and preserve the binding ambiguity without inventing an operation planner.

This is a provider-freeze blocker, not evidence that an implemented BigCommerce exporter is currently broken; there is no BigCommerce runtime connector in this slice.

### S2 — blanket rule: DEFERRED provider concept cannot have verified mapping

Lead verdict: **REJECT AS A SYSTEMIC RULE; ACCEPT THREE SUBCASES FOR INDEPENDENT REASONS**.

The provider coverage ledger uses `DEFERRED_REVIEW` to preserve unresolved portable ownership/binding. The canonical mapping registry has a separate verification contract: a `verified` mapping requires mapping evidence, but no frozen invariant says every underlying provider concept must already be `PROVIDER_VERIFIED`. A provider owner/binding question may remain open while a narrower field-to-field semantic correspondence is proven.

Therefore do not add a global validator rule `DEFERRED_REVIEW concept => mapping cannot be verified`.

Subcases:
- `net_weight -> ProductVariant.weight`: materially overclaims DEC-009; accept downgrade independently (S4).
- `price -> ProductVariant.price`: entity representation is incomplete under S1; binding requires correction.
- `sku -> ProductVariant.sku`: same Product/Variant representation problem under S1.
- `recommended_retail_price -> ProductVariant.retail_price`: do **not** downgrade merely because `bigcommerce_reference_price` is OPEN; current BigCommerce migration documentation explicitly defines Retail Price as manufacturer suggested retail price.
- `min_order_quantity -> Product.order_quantity_minimum`: do **not** downgrade merely because the disagreement is OPEN; provider wording matches canonical "minimum purchasable quantity" directly, and no Variant counterpart exists.
### S3 — shared Product/Variant compatibility shape is too uniform

Lead verdict: **ACCEPT — MINOR/MATERIAL PROVIDER-HARDENING**.

Current coverage correctly keeps row-local entity binding, but `compatibilityRules()` groups several structurally different cases under broad shared-concept explanations. Evidence supports at least three distinct patterns:
- documented nullable override with Product fallback: `price`, `weight`, `depth`, `height`, `width` and some pricing siblings subject to their own evidence caveats;
- co-existing Product/Variant values without proven fallback: identifiers/content such as `sku`, `gtin`, `mpn`, `upc`, `bin_picking_number`;
- independent non-nullable flags such as `is_free_shipping`.

The current model does not literally assert that nullable GTIN inherits Product GTIN, so Sonnet overstates the present defect. Still, the compatibility metadata should encode the structural distinction explicitly before it becomes runtime input.

### S4 — `net_weight -> BigCommerce weight`

Lead verdict: **ACCEPT — MATERIAL**.

DEC-009 defines canonical `net_weight` as physical product mass excluding packaging. BigCommerce current documentation describes Product weight as shipping/store weight used for shipping calculations and does not prove the packaging boundary. BigCommerce migration guidance is even more explicit: `weight` is the shipping weight of the product. This is insufficient for verified `net_weight` equivalence.

Minimal correction: downgrade/defer the canonical BigCommerce weight mapping while retaining provider weight semantics and unit conversion evidence. Product-vs-Variant representation is a separate S1 question.

### S5 — `inheritanceContract()` negation bug

Lead verdict: **ACCEPT — MINOR CODE DEFECT**.

`inheritanceContract()` currently treats the bare substring `price list` as positive fallback evidence. `product_variant.cost_price` says it is **not affected by Price List prices**, so the heuristic returns the opposite classification. The value is currently only completeness metadata, but the defect is real and should be fixed before this evidence becomes load-bearing.
### S6 — MAP storefront behavior

Lead verdict: **NO CORRECTION; CURRENT OPEN STATUS IS CORRECT**.

Provider evidence establishes `map_price` as Minimum Advertised Price but does not prove a native enforcement relation to `is_price_hidden` or `price_hidden_label`. Keep the MAP disagreement open and keep those storefront controls separate.

### S7 — retail/sale fallback text allegedly contains a BigCommerce copy/paste error

Lead verdict: **REJECT THE INFERRED CORRECTION; RECORD DOCUMENTATION AMBIGUITY**.

Current BigCommerce Variant documentation literally says `retail_price` and `sale_price` fall back to the Product resource's `price` field when the variant value is null and no Price List applies. It may be documentation noise, but the repository cannot replace official text with an inferred same-field fallback without independent evidence. Fail closed: preserve the literal provider evidence and mark the fallback relationship uncertain until live-store/runtime certification.

### S8 — modifier adjusters

Lead verdict: **NO CURRENT DEFECT**.

Nested modifier adjusters reinforce `OrderCustomization` ownership but are outside the frozen top-level 136-row denominator. Preserve as a future structured-member research note; do not expand this correction pass solely for nested adjusters.

### S9 — tax owner family

Lead verdict: **ACCEPT AS DISAGREEMENT REFINEMENT, NOT A CANONICAL MAPPING CHANGE**.

`tax_class_id` is a store/account-specific BigCommerce tax-class reference. `product_tax_code` is explicitly a pass-through taxonomy/code used by third-party tax providers such as Avalara. They are already separate concepts, but the single `UnresolvedTaxOwner` question hides two different non-portability classes. Narrow the disagreement/representation notes without prematurely creating a universal Tax field.
### S10 — `custom_url`

Lead verdict: **ACCEPT — MINOR REPRESENTATION CLARIFICATION**.

Current BigCommerce examples show `custom_url.url` as a storefront-relative path such as `/brands/Common-Good.html`; canonical DEC-008 `url` is an absolute customer-facing Product URL. No BigCommerce canonical `url` mapping exists today, which is correct. Narrow the OPEN question to a structured relative-storefront-path representation and require store/base-URL context before any future absolute-URL transform.

### Sonnet missing `max_order_quantity` claim

Lead verdict: **REJECT AS A NEW DEFECT; PRESERVE THE EXISTING DEFERRED CANDIDATE**.

`cross_platform_product_field_synthesis.csv` already contains `max_order_quantity` as `missing_or_candidate` with `DEFER_NEW_DOMAIN_DECISION`. The rationale explicitly says BigCommerce + Amazon prove a strong candidate but adding it would introduce a new unresolved business constraint; it must be decided together with `min_order_quantity` / `order_step`. This is intentional deferral, not a silent omission.

## Lead neighbor-pattern check — BigCommerce `condition` mapping/value opportunity

Lead verdict after deeper binding check: **DO NOT ADD MAPPING IN THIS CORRECTION PASS**.

BigCommerce `Product.condition` is a writable provider-controlled field with exact allowed values `New`, `Used`, `Refurbished`, and the canonical registry already has matching condition options. However canonical `condition` is `binding_strategy=product_variant` and currently `status=proposed`, while BigCommerce exposes condition at Product level. A direct field/option mapping would therefore assert a Product-to-Variant binding that is not proven safe for products whose variants could require distinct condition semantics.

The absence of BigCommerce option mappings is conservative, not a current defect. Preserve the provider evidence and revisit only with an explicit Product-vs-Variant condition binding decision.

## Current Lead routing before GPT-5.4 overlay

Accepted correction classes so far:
1. Product/ProductVariant representation and binding evidence must be corrected, but only where canonical binding and provider entity level actually conflict; do not implement Sonnet's operation-specific fix verbatim.
2. BigCommerce weight mapping must be downgraded/deferred under DEC-009.
3. Shared compatibility rules must distinguish documented fallback from independent co-existing values.
4. Fix the `inheritanceContract()` negation heuristic.
5. Refine tax and `custom_url` disagreement representations.
Rejected / narrowed Sonnet claims:
- no global rule that every mapping over a `DEFERRED_REVIEW` provider concept must be downgraded;
- `recommended_retail_price -> retail_price` remains strongly evidenced by BigCommerce's current MSRP definition;
- `min_order_quantity -> order_quantity_minimum` remains semantically evidenced; the OPEN question is mis-scoped ownership/family governance, not field-name correspondence;
- do not replace the official retail/sale Variant fallback text with a guessed same-field fallback;
- `max_order_quantity` is already an explicit deferred cross-platform candidate, not a newly discovered omission.

No external arbitration model is required yet. Wait for the independent GPT-5.4 evidence/mapping audit, then overlay it here. Escalate only a surviving concrete dispute that primary evidence and frozen project decisions cannot resolve.

## GPT-5.4 independent evidence/mapping audit

GPT-5.4 reported `BIGCOMMERCE COVERAGE/CANONICALIZATION HAS MATERIAL GAPS` after checking 136/136 physical rows, 119/119 concepts, all 18 mappings, and the major shared Product/Variant families. Its final report proves the BigCommerce provider slice is complete and internally reproducible, but it contains one internal inconsistency: the intermediate issue-validation JSON rejects `recommended_retail_price -> retail_price` as a false positive, while the final prose later re-promotes the same family to a Major verified-vs-OPEN contradiction. Lead resolves that inconsistency from primary BigCommerce evidence rather than report ordering.

### G1 — `net_weight -> ProductVariant.weight`

Lead verdict: **ACCEPT — MATERIAL**.

GPT independently confirms Sonnet S4: BigCommerce weight is shipping/store weight, not proven DEC-009 net weight. The mapping must not remain `verified` as canonical `net_weight`.

### G2 — `recommended_retail_price -> ProductVariant.retail_price`

Lead verdict: **REJECT DOWNGRADE; RESOLVE/NARROW THE OPEN PROVIDER QUESTION**.

Current BigCommerce migration documentation defines `retail_price` as the manufacturer suggested retail price, and current storefront pricing documentation describes MSRP/RRP as list/manufacturer suggested retail price. That is direct evidence for canonical `recommended_retail_price`. The mapping can remain verified. The stale/open `bigcommerce_reference_price` question should be closed or narrowed so the provider concept no longer appears simultaneously semantically unresolved and directly evidenced as MSRP.
### G3 — `min_order_quantity -> Product.order_quantity_minimum`

Lead verdict: **REJECT DEFECT**.

GPT agrees with Lead: the exact provider field is Product-level and its meaning directly matches the canonical minimum purchasable quantity. Keep the verified mapping. Reframe `bigcommerce_order_constraints` as the still-open broader family/domain decision rather than evidence against this mapping.

### G4/G5 — `price` and `sku` variant mappings

Lead verdict: **REJECT AS MAPPING DEFECTS IN THEIR CURRENT NARROW SCOPE**.

Canonical `price` and `sku` are variant-bound (pricing-domain / ProductVariant respectively), and the BigCommerce mappings target the matching Variant surface. Product-level BigCommerce defaults/co-existing values are real provider representations, but their existence does not invalidate the narrower variant correspondence. Product-level create requirements belong to connector execution/planning, not to a direction-neutral FieldMapping row.

### G6 — fallback/null semantics missing from transform names

Lead verdict: **REJECT AS STATED; ACCEPT PROVIDER-METADATA HARDENING**.

GPT correctly notes that transformations do not encode inheritance/null/Price List precedence, but the registry does not define mapping transformations as a complete execution planner. Keep row-local fallback evidence in provider metadata and harden compatibility classification; do not require transformation names to encode every execution rule.

## Lead deeper cross-file binding audit

Comparing every BigCommerce mapping to canonical `binding_strategy`, applicability `entity_level`, and external object prefix found exactly four structural mismatches:
- `net_weight` (`binding_strategy=product`) -> `ProductVariant.weight`;
- `depth_mm` (`product`) -> `ProductVariant.depth`;
- `width_mm` (`product`) -> `ProductVariant.width`;
- `height_mm` (`product`) -> `ProductVariant.height`.
BigCommerce current docs explicitly state ProductVariant width/height/depth/weight can inherit Product defaults. For the three dimensions, canonical ownership is Product-level and provider Product fields exist directly, so the current verified Variant mappings overstate entity binding and can misdirect the exact-key suggestion layer. Correction candidate: retarget `depth_mm/width_mm/height_mm` to `Product.depth/width/height`, preserving Variant overrides only as provider-local representation/fallback evidence.

For weight, entity binding should likewise not remain a verified Variant mapping, but DEC-009 is the stronger blocker: preserve BigCommerce Product/Variant weight evidence and downgrade canonical correspondence until packaging semantics are proven. If a partially-verified mapping is retained, Product-level `Product.weight` is the canonical-binding-aligned representation.

This finding narrows Sonnet S1 to the subset where canonical binding and external entity level actually conflict. The other shared mappings (`sku`, `gtin`, `mpn`, pricing fields) align with their canonical variant-level binding strategies.

## Final Lead arbitration after Sonnet + GPT-5.4

**BIGCOMMERCE CORRECTION PASS REQUIRED.** No Gemini/Opus arbitration is currently needed: the surviving disagreements are resolved by current BigCommerce documentation plus frozen project binding contracts.

Accepted correction set:
1. Downgrade/remove verified `net_weight -> ProductVariant.weight` under DEC-009; preserve Product/Variant weight as provider representations.
2. Retarget product-bound canonical dimensions from `ProductVariant.depth/width/height` to `Product.depth/width/height`; keep Variant null/inheritance evidence provider-local.
3. Refine shared compatibility metadata into documented nullable fallback vs co-existing independent values vs independent flags.
4. Fix `inheritanceContract()` so negated `not affected by Price List prices` is not classified as positive fallback evidence.
5. Resolve/narrow `bigcommerce_reference_price`: `retail_price` is directly evidenced as MSRP/RRP, so keep `recommended_retail_price` mapping verified.
6. Reframe `bigcommerce_order_constraints` so it does not imply the proven `min_order_quantity` mapping is unresolved; keep `max_order_quantity` as the existing deferred family candidate.
7. Refine tax owner and `custom_url` representation notes without adding premature canonical mappings.

Explicit non-corrections:
- keep `price`, `sale_price`, `cost_price`, `recommended_retail_price`, `sku`, `gtin`, `mpn` Variant mappings where canonical binding is variant-level;
- do not add Product-level pricing mappings merely to satisfy Create Product execution requirements; that belongs to future BigCommerce connector planner/runtime certification;
- do not add BigCommerce `condition` field/option mappings in this pass because canonical condition is variant-bound/proposed while BigCommerce condition is Product-level;
- do not invent same-field retail/sale fallback when current provider docs literally reference Product `price`;
- no global validator rule `DEFERRED_REVIEW provider concept => no verified canonical mapping`.
## Correction implementation status

Correction branch: `fix/bigcommerce-provider-semantic-corrections` from `develop @ 8bc70b809732ed83292b78272ca39de3c2bf0360`.

Implemented outcomes:
- `net_weight` now targets Product-level `Product.weight` and is `partially_verified` under DEC-009;
- `depth_mm`, `width_mm`, `height_mm` now target Product-level BigCommerce fields;
- ProductVariant dimension rows remain provider-local nullable override/fallback evidence;
- `bigcommerce_reference_price` is removed from OPEN because current BigCommerce evidence defines `retail_price` as manufacturer suggested retail price;
- shared compatibility metadata distinguishes documented fallback, independent co-existing values, and independent flags;
- negated Price List statements and unrelated `default location` text no longer count as inheritance evidence;
- tax and `custom_url` representation notes are narrowed without creating new canonical fields;
- `bigcommerce_order_constraints` is reframed around unresolved max/order-step family governance while preserving the proven Product-level minimum mapping.

Source inventory and manifest are unchanged: this pass corrects normalization/registry/synthesis, not the frozen 136-row physical evidence.

Final local gates before commit:
- BigCommerce targeted provider tests: 19 tests / 1,878 assertions PASS;
- full `tests/Unit/CanonicalCoverage`: 93 tests / 33,243 assertions PASS;
- full `FieldMappingSuggestionReadModelTest`: 29 tests / 89 assertions PASS;
- Canonical Registry validator: Errors 0;
- BigCommerce coverage validator: 136 rows / 119 concepts / 7 disagreements, all integrity metrics PASS;
- Pint: 1,318 files PASS;
- `git diff --check`: PASS.
