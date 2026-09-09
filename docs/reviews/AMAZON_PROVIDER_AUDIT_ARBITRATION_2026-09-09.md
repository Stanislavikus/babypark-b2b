# Amazon Provider Audit Arbitration — 2026-09-09

Status: LEAD ARBITRATION IN PROGRESS — GPT-5.4 OVERLAY RECORDED, SONNET HIGH PENDING

Authoritative review base: `develop @ b4458f2293f16b15894f6edbaa3653f777a53685`.

This ledger preserves the independent Amazon Listings/PTD provider review after Adobe Commerce, Google Merchant and BigCommerce were frozen. Reviewer findings are challenges, not authority. A correction is accepted only when provider evidence, the canonical contract and actual runtime behavior form a concrete contradiction.

## Frozen Amazon input at review start

- Physical source rows: 101 = 78 public `LUGGAGE` PTD rows + 23 Product Type Definitions meta-model rows.
- Provider concepts: 90.
- OPEN disagreement families: 13.
- Amazon canonical field mappings: 20; all `verification_status=verified` and `channel_schema_version=ptd-2020-09-01-luggage-public-example`.
- Amazon canonical option mappings: 0.
- Physical review status: 30 `PROVIDER_VERIFIED`, 71 `DEFERRED_REVIEW`.
- Frozen LUGGAGE context: marketplace `ATVPDKIKX0DER`, requirements `LISTING`, parentage `NONE example`, PTD version token `U8L4z4Ud95N16tZlR7rsmbQ==`.

## GPT-5.4 evidence/runtime audit

GPT-5.4 reported `AMAZON COVERAGE/CANONICALIZATION HAS MATERIAL GAPS` after accounting for 101/101 physical rows, 90/90 concepts, all 20 mappings, 0 option mappings and all 13 disagreements.

Lead independently rechecked the report against the exact base, current common suggestion runtime and current Amazon documentation.
### G1 — PTD applicability/version context is not consumed by mapping suggestions

Lead verdict: **ACCEPT — MATERIAL / BLOCKER BEFORE AMAZON RUNTIME ACTIVATION; NOT A CURRENT OPERATIONAL INCIDENT**.

`CanonicalFieldMappingSuggestionProvider` selects mapping rows only by connector definition code and `verification_status=verified`, then requires exact `external_field` presence in the authoritative snapshot. It does not read `applicability_id`, mapping `channel_schema_version`, marketplace, product type, requirements set, parentage or PTD version.

`FieldMappingReadModelProjector` passes only workspace, connector definition code, external-field-key set and reservation sets. Although `ConnectorSchemaSnapshot` has a generic `schema_version`, the provider does not receive it; snapshot/domain models also have no first-class Amazon product-type/marketplace/requirements authority fields.

The registry preserves all 20 mappings as `amazon:ATVPDKIKX0DER:LUGGAGE` applicability rows, so the evidence layer is not losing context. The consumer is.

However the current connector foundation does **not** seed an `amazon` `ConnectorDefinition` or Amazon schema source. Therefore standard runtime cannot currently create/drive an Amazon connector account through this path. Treat this as a hard precondition for future Amazon activation, not as an already-live merchant incident.

Do not solve this by pretending the LUGGAGE mappings are globally applicable. Final correction choice remains pending Sonnet overlay: either keep semantic verification but introduce an explicit fail-closed runtime activation boundary, or downgrade operational eligibility until Amazon context-aware snapshot authority exists. Avoid a broad redesign during provider review.

### G2 — `product_highlights -> bullet_point`

Lead verdict: **REJECT GPT DOWNGRADE; KEEP NARROW MAPPING, REFINE OPEN SIBLING QUESTION**.

Canonical `product_highlights` is concise multi-value selling facts/bullets. Amazon describes bullet points as concise product features/benefits on the detail page. The public LUGGAGE PTD groups both `bullet_point` and `special_feature` under Product Details, but the existence of `special_feature` does not invalidate the direct bullet-point correspondence.
Current synthesis already treats `LUGGAGE.bullet_point / special_feature` as evidence for a reusable highlights family. The correction should narrow `amazon_product_highlights` to the unresolved relationship/representation of `special_feature`, not demote the proven `bullet_point` mapping by association.

### G3 — `recommended_retail_price -> list_price`

Lead verdict: **REJECT GPT DOWNGRADE; KEEP VERIFIED, NARROW PRICING DISAGREEMENT**.

Amazon's current SP-API changelog defines `ListPrice` as the suggested retail price supplied by a manufacturer, supplier or seller. Amazon's official mapping guide maps legacy `MSRPWithTax` to `purchasable_offer.list_price`. This directly supports canonical `recommended_retail_price`, whose contract is a non-transactional reference/list price.

`amazon_offer_pricing` should continue to distinguish `purchasable_offer` selling-price structures from `list_price`; it should not imply that the narrow RRP correspondence is unresolved.

### G4 — `gross_weight -> item_package_weight`

Lead verdict: **KEEP VERIFIED PENDING SONNET CHALLENGE; GPT DOWNGRADE NOT PROVEN**.

DEC-009 defines `gross_weight` as the sellable unit including immediate consumer packaging but excluding additional transport/shipping packaging. Amazon distinguishes item dimensions/weight from package dimensions/weight. Amazon Seller Central guidance describes item package measurements as the individual item package with the item inside, as received by the customer; FBA guidance distinguishes item, item package and case.

That evidence is substantially stronger than the Google/BigCommerce generic shipping-weight cases and supports the sellable packaged-unit interpretation. There may still be an edge case where Amazon-required per-unit prep packaging behaves like fulfillment packaging; keep this open to Sonnet attack, but do not downgrade solely because the provider concept is currently `DEFERRED_REVIEW`.

### G5 — `package_quantity -> number_of_items`

Lead verdict: **ACCEPT — MATERIAL SEMANTIC OVERCLAIM**.
Canonical `package_quantity` means units per consumer package. Amazon defines `number_of_items` more narrowly as the count of containers at the lowest level of branded packaging that encases the product, explicitly distinguishing container count from the product units themselves in nested-packaging examples.

The repository already contains the contradiction: cross-platform synthesis says Amazon `number_of_items` remains separate evidence from project `package_quantity`, while the mapping registry marks them verified equivalents.

Minimal correction: downgrade/remove the verified direct equivalence and preserve Amazon `number_of_items` as a related, PTD-scoped packaging/count representation until a transform contract proves the relevant packaging level.

### G6 — `condition -> condition_type`

Lead verdict: **REJECT SEMANTIC DOWNGRADE; RETAIN PTD/APPLICABILITY CAVEAT**.

Amazon `condition_type` is located in the `offer` property group and is listing/offer conditioned, but canonical `condition` is itself a ProductVariant-level sellable-condition semantic, not immutable Product identity. Current mapping being PTD/applicability-scoped is reasonable. The canonical field is `proposed`, so it does not pass the current suggestion-provider field-status gate today.

The real issue is G1 context authority, not evidence that `condition_type` has the wrong semantic meaning.

### G7 — `age_group -> age_range_description`

Lead verdict: **REJECT GPT DOWNGRADE AT FIELD LEVEL; KEEP VALUE-DOMAIN MAPPING DEFERRED**.

Amazon's current complex-attribute guidance defines Age Range Description as the age for which the product is intended, with examples such as Adult, Kid and Baby. Canonical `age_group` is the target consumer age segment. That is sufficient for a field-level semantic transform.

Zero Amazon option mappings is not proof that the field mapping is wrong. It correctly means we have not yet frozen exact canonical option/value correspondences. The canonical field itself remains `proposed` and partially verified, so it is not suggestion-active today.
## Lead runtime-surface correction to GPT severity

GPT described several Amazon mappings as currently suggestible based mainly on canonical field status. Actual runtime has additional gates: the canonical field must be active+verified+eligible, a global active `FieldDefinition` must exist, and a matching active Product/ProductVariant `FieldBinding` must exist.

At the authoritative base:
- seeded and active/verified canonical definitions include `name`, `brand`, `description`, `gtin`, `color`, `size`, `net_weight`, `gross_weight`;
- `condition` is proposed;
- `country_of_origin`, `manufacturer`, `model`, `material`, `age_group`, `gender` are not active+verified;
- `product_highlights`, `style`, `warranty`, `package_quantity` are not seeded as global FieldDefinitions in the current foundation;
- `recommended_retail_price` explicitly has `field_definition_eligibility=no`.

More importantly, `ConnectorFoundationSeeder` currently has no `amazon` connector definition at all. Therefore none of the Amazon registry mappings are merchant-operational through the standard connector path today.

This does not erase G1. It changes the severity wording from current production collision to a deterministic activation blocker: enabling Amazon without a context-aware guard would make the problem live immediately.

## GPT findings accepted/rejected so far

Accepted:
1. PTD applicability/version context is preserved in evidence but ignored by the common suggestion consumer; must be fail-closed before Amazon activation.
2. `package_quantity -> number_of_items` is over-verified and contradicts Amazon packaging semantics plus current cross-platform synthesis.

Rejected/narrowed:
- do not downgrade `product_highlights -> bullet_point`; narrow the sibling `special_feature` question instead;
- do not downgrade `recommended_retail_price -> list_price`; Amazon directly documents ListPrice as suggested retail price/MSRP-like reference price;
- do not downgrade `condition -> condition_type` for offer ownership alone;
- do not downgrade `age_group -> age_range_description` at field level merely because option mappings are absent;
- do not downgrade `gross_weight -> item_package_weight` without stronger contrary evidence; Amazon's item-package boundary is materially closer to DEC-009 than generic shipping weight.
## Preserved good boundaries from GPT pass

Lead independently confirms the current provider evidence remains fail-closed for:
- ASIN suggestion vs established external identity;
- identifier-exemption governance;
- Amazon taxonomy vs merchant Category authority;
- offer media vs product media;
- variation parentage schema;
- compliance portability;
- `purchasable_offer` and fulfillment availability as separate domain capabilities rather than flat Product mappings.

No new Amazon option mapping is accepted yet. Field-level condition/age/gender correspondence does not by itself prove exact value vocabularies across product types and marketplaces.

## Current routing before Sonnet High overlay

Amazon is **NOT READY TO FREEZE** yet, but no third-model arbitration is needed at this point.

Carry forward to Sonnet comparison:
1. Does Sonnet independently confirm the PTD-context/runtime activation blocker, and does it propose a narrower provider-only remedy?
2. Does Sonnet produce stronger contrary evidence on `gross_weight -> item_package_weight`?
3. Does Sonnet confirm the `number_of_items` vs `package_quantity` semantic mismatch?
4. Does Sonnet find any sibling problem in other product-type-scoped mappings that survives exact evidence review?

After the independent Sonnet report, Lead will freeze the correction set. Escalate only a surviving semantic dispute that primary Amazon evidence and existing canonical decisions cannot resolve.

## Sonnet High semantic/domain overlay

Sonnet independently reviewed the same authoritative base and returned `AMAZON SEMANTIC CORRECTIONS REQUIRED` with four material findings: condition ownership, DEC-009 weight mappings, `purchasable_offer` structure, and LUGGAGE-scoped applicability.

### S1 — `condition_type` owner

Lead verdict: **ACCEPT — MATERIAL PROVIDER SEMANTIC CORRECTION**.

Canonical `condition` is `product_variant`-bound, Amazon applicability `a097` already records `entity_level=product_variant`, and Amazon PTD requirements explicitly distinguish product facts from sales terms. `condition_type` and `condition_note` both belong to the physical `offer` property group. Keeping reusable `condition_type` under generic `ProductData` is internally inconsistent.

Minimal correction: retain the reusable `product_condition_enum` concept but change owner candidate to `ProductVariantData`; keep `condition_note` as Amazon listing-context semantics. Update the paired invariant.
### S2 — `item_weight` / `item_package_weight` under DEC-009

Lead verdict: **REJECT THE BLANKET DOWNGRADE; KEEP BOTH MAPPINGS VERIFIED AND STRENGTHEN THE EVIDENCE NOTE**.

Sonnet relies heavily on PTD property-group placement (`safety_and_compliance` / `shipping`) as negative semantic evidence. Amazon's PTD meta-model itself defines property groups as logical groupings for display/informational purposes, so those group names are not semantic packaging contracts.

Primary Amazon evidence distinguishes an item from a package containing the item. Amazon also describes item-package weight/dimensions as the individual listed unit including its own box/polybag, while item, item package, and case can have distinct measurements. This matches DEC-009's sellable-unit/immediate-packaging boundary far more closely than Google or BigCommerce shipping-weight surfaces. Amazon listing guidance for Item Weight states the value excludes packaging.

Therefore keep `net_weight -> item_weight` and `gross_weight -> item_package_weight` verified; add explicit evidence text so later reviewers do not infer from property-group names alone.
### S3 — `purchasable_offer` representation

Lead verdict: **ACCEPT — MATERIAL PROVIDER REPRESENTATION HARDENING, NO CANONICAL FIELD MAPPING**.

Amazon documents `purchasable_offer` as a structured offer envelope containing audience/currency/marketplace context and multiple pricing sub-facets (regular price, discounted schedules, seller min/max bounds and quantity-pricing structures). Current top-level Pricing ownership and deferred status are correct, but the generic representation name understates the shape.

Minimal correction: make the provider representation explicitly a structured offer/pricing envelope and record the sub-facets in the disagreement note. Do not create a scalar canonical `price` mapping.

### S4 — LUGGAGE applicability vs evidence scope

Lead verdict: **ACCEPT — MATERIAL SYSTEMIC RUNTIME HARDENING / BLOCKER BEFORE AMAZON ACTIVATION**.

Both independent reviewers found the same root problem: mappings preserve exact LUGGAGE/US/PTD applicability, but the common suggestion runtime consumes only channel + verified status + exact external key. The registry context is therefore preserved but not enforced.
Current runtime status matters: `ConnectorFoundationSeeder` does not seed an `amazon` connector definition or Amazon schema source, so this is not an active merchant-facing production defect today. It is a dormant activation hazard that must be closed before Amazon connector enablement.

A generic fix is preferable to an Amazon hard-code. `CanonicalRegistryReader` already exposes applicability rows. Current verified mappings by context type are: Adobe/BigCommerce/Google=`channel`, Shopify=`channel/global`, Amazon=`product_type` for all 20 rows. Therefore automatic suggestions can safely fail closed for any mapping whose applicability context is more specific than `global` or `channel` until the runtime has authoritative context to prove it.

This disables only future Amazon LUGGAGE suggestions today and preserves all frozen provider automation.

### Cross-review resolution of remaining GPT findings

- `package_quantity -> number_of_items`: **ACCEPT MATERIAL**. Amazon `number_of_items` counts lowest-level branded package containers; project `package_quantity` means units per consumer package. Current synthesis already treats them as separate evidence, so the verified mapping is contradictory. Downgrade/remove operational verification while retaining provider evidence.
- `product_highlights -> bullet_point`: **KEEP VERIFIED**. Sonnet independently confirmed the family match; keep `special_feature` separate and record Amazon slot/length constraints in the transform/evidence note.
- `recommended_retail_price -> list_price`: **KEEP VERIFIED**. Sonnet independently confirmed Amazon's List Price is MSRP/RRP semantics. Narrow the OPEN pricing question to `purchasable_offer` structure versus settled list-price semantics.
- `age_group -> age_range_description`: **KEEP FIELD-LEVEL MAPPING**; no Amazon option mappings until value vocabulary is enumerated.
## Final Lead correction contract after GPT-5.4 + Sonnet

Accepted implementation scope:
1. Change `condition_type` owner candidate to `ProductVariantData` and update invariants/generated Amazon artifacts.
2. Keep Amazon `item_weight`/`item_package_weight` canonical mappings verified, but strengthen explicit DEC-009/Amazon evidence in registry/synthesis/review regressions.
3. Model `purchasable_offer` explicitly as a structured multi-facet offer/pricing envelope while keeping it deferred and unmapped.
4. Add generic fail-closed suggestion gating for applicability contexts more specific than `global`/`channel`; current Amazon product-type mappings therefore cannot auto-suggest until context-aware snapshot authority exists.
5. Downgrade/remove operational verification of `package_quantity -> number_of_items`; retain the Amazon field as separate provider evidence.
6. Keep `product_highlights -> bullet_point`, `recommended_retail_price -> list_price`, `age_group -> age_range_description`, `gender -> target_gender`, and the two weight mappings unless a future primary-source contradiction appears.
7. Preserve 0 Amazon option mappings; condition/age/gender value vocabularies remain unproven for automatic value mapping.

No Gemini/Opus arbitration is required: all model disagreements are resolved by Amazon primary evidence plus frozen repository contracts. Next step is a clean correction branch from `develop @ b4458f2293f16b15894f6edbaa3653f777a53685`, deterministic regenerate, targeted regressions, full CanonicalCoverage/mapping suites, then PR/CI.
