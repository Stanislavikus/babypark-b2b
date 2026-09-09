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

## Lead neighbor-pattern finding — missing BigCommerce condition mapping/value evidence

Lead verdict: **ACCEPT — MATERIAL SAFE-AUTOMATION GAP**.

BigCommerce `Product.condition` is a writable provider-controlled field with exact allowed values `New`, `Used`, `Refurbished`. The canonical registry already has `condition` plus universal options `new`, `used`, `refurbished`, and cross-platform synthesis explicitly lists BigCommerce `condition` evidence. Yet there is no BigCommerce canonical field mapping and zero BigCommerce option mappings.

This is a high-confidence missing mapping opportunity rather than an ambiguous provider semantic. Correction candidate: add a versioned BigCommerce `condition -> Product.condition` mapping and explicit BigCommerce option mappings for `New`, `Used`, `Refurbished`, with provider evidence and deterministic tests. Runtime activation remains governed by the canonical field's own lifecycle/status.

## Current Lead routing before GPT-5.4 overlay

Accepted correction classes so far:
1. Product/ProductVariant representation and binding evidence must be corrected; do not implement Sonnet's operation-specific fix verbatim.
2. BigCommerce weight mapping must be downgraded/deferred under DEC-009.
3. Shared compatibility rules must distinguish documented fallback from independent co-existing values.
4. Fix the `inheritanceContract()` negation heuristic.
5. Refine tax and `custom_url` disagreement representations.
6. Add the missing, directly evidenced BigCommerce `condition` field/value mapping family.
Rejected / narrowed Sonnet claims:
- no global rule that every mapping over a `DEFERRED_REVIEW` provider concept must be downgraded;
- `recommended_retail_price -> retail_price` remains strongly evidenced by BigCommerce's current MSRP definition;
- `min_order_quantity -> order_quantity_minimum` remains semantically evidenced; the OPEN question is mis-scoped ownership/family governance, not field-name correspondence;
- do not replace the official retail/sale Variant fallback text with a guessed same-field fallback;
- `max_order_quantity` is already an explicit deferred cross-platform candidate, not a newly discovered omission.

No external arbitration model is required yet. Wait for the independent GPT-5.4 evidence/mapping audit, then overlay it here. Escalate only a surviving concrete dispute that primary evidence and frozen project decisions cannot resolve.
