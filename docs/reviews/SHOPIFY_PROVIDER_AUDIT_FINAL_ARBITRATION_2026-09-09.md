# Shopify Provider Semantic Audit — Final Lead Arbitration

Date: 2026-09-09  
Repository: `Stanislavikus/babypark-b2b`  
Authoritative base: `origin/develop @ 24b64407604a724c02e202f474c221882d24d464`  
Review branch before this commit: `review/shopify-provider-semantic-audit-2026-09-09`

## Inputs

This arbitration combines:
- `SHOPIFY_PROVIDER_AUDIT_GPT54_LEAD_BASELINE_2026-09-09.md`;
- Sonnet 5 High adversarial overlay supplied after that baseline;
- fresh Lead verification of current `[Resolved]` contracts, registry CSVs, runtime suggestion code, Shopify coverage classifier, and Shopify primary API documentation.

The physical Shopify inventory remains frozen. This file does not reopen inventory acquisition, `shopify_short_title_ownership`, or `shopify_unit_pricing_ownership`.

## Goal

Before Shopify activation, canonical knowledge must not produce high-confidence FieldMapping suggestions that overclaim Shopify semantics, entity level, applicability, or runtime version authority.

## Final architecture decision

**No new architecture/domain decision is required.** The needed work is correction/hardening inside already-frozen canonical registry and suggestion-runtime contracts.

The current Shopify merchant setup path is not enabled: Shopify is an Active `ConnectorDefinition`, but there is no enabled Shopify account-setup/discovery profile. Findings are therefore latent activation blockers, not a proven current merchant incident.

## Final arbitration — S1 to S6

### S1 — `Product.status`

**ACCEPT Sonnet substantive finding; correction narrowed to existing registry mechanics.**

Shopify `ProductStatus` is `ACTIVE | ARCHIVED | DRAFT | UNLISTED`. Shopify explicitly states that ACTIVE is not automatically published, while platform canonical `status` is the current boolean `products.is_active` contract and directly participates in B2B catalogue eligibility.

Therefore `status -> Product.status` must not remain a high-confidence verified mapping. No Shopify option mapping exists that could prove a deterministic lossless read contract.

**Correction:** downgrade the Shopify mapping from `verified` to `partially_verified` so generic suggestions fail closed. Do not invent a new lifecycle field, do not change canonical `status`, and do not create a directional value contract in this campaign.

### S2 — `ProductVariant.barcode` / GTIN

**ACCEPT Sonnet partial-semantic finding.**

Shopify's current `barcode` field is generic. The 2026-10 changelog explicitly introduces typed UPC/EAN/ISBN/GTIN/ASIN barcodes and permits untyped/custom values, confirming that legacy/current `barcode` is not GTIN-exclusive.

A known canonical GTIN can be written into a Shopify barcode representation, but an arbitrary Shopify barcode cannot be imported as canonical GTIN without validation/type authority.

**Correction:** downgrade both Shopify GTIN/barcode mappings to `partially_verified`. The legacy 2024-10 row must also stop referencing Google applicability `a004`; give it a Shopify-owned applicability record or remove that obsolete mapping if historical evidence is preserved elsewhere.

### S3 — legacy REST / runtime schema version

**REJECT Sonnet's `current/latest channel_schema_version` filter.**

Current `[Resolved]` runtime-version semantics explicitly forbid arbitrary latest, lexical max, first-row, or config-order selection when the connected runtime version is not authoritative. `CanonicalRegistryReader::schemaVersionsForChannel()` existing does not create such authority.

The mere existence of both 2024-10 and 2026-07 evidence is not a defect. Literal external-key matching plus collision suppression is already fail-closed when multiple distinct keys target one binding.

**Correction:** do not add a latest-version selector. Preserve semantically sound legacy rows (`title`, `body_html`, `variants.sku`) as historical evidence if their provenance is repaired. Remove/demote only semantically unsafe rows. Exact runtime-version selection remains deferred under the existing `[Resolved]` contract.

### S4 — mapping/applicability invariant

**ACCEPT and strengthen as data-integrity + runtime defense-in-depth.**

The legacy Shopify GTIN row references `a004`, whose `channel_or_state=google_merchant`, while the validator currently checks only applicability existence and matching `internal_code`. The suggestion runtime likewise ignores applicability channel identity.

**Correction:**
- fix the bad Shopify GTIN applicability reference;
- make registry validation reject a mapping whose channel-specific applicability belongs to another channel;
- make the suggestion provider fail closed on the same mismatch rather than trusting malformed canonical data.

Do not infer operation or runtime schema version in this correction; those are separate authority questions.

### S5 — `country_of_origin`

**DISAGREE with Sonnet's mapping-level SAFE verdict. Provider value semantics are good; current canonical mapping is not fully proven.**

Shopify `InventoryItem` is variant inventory information; `countryCodeOfOrigin` is manufacturing/origin country on that inventory item. Canonical `country_of_origin` is currently `binding_strategy=product`, `status=proposed`, `verification_status=partially_verified`, while applicability `a072` correctly records `entity_level=product_variant`.

That mismatch is currently dormant because the canonical field itself does not pass the active+verified suggestion gate, but the provider mapping row should not advertise stronger confidence than the canonical/entity contract proves.

**Correction:** downgrade `country_of_origin -> InventoryItem.countryCodeOfOrigin` to `partially_verified`; keep `a072` product-variant applicability; do not promote the canonical field or silently change its binding strategy in this campaign.

### S6 — all remaining `KEEP_VERIFIED` rows

**KEEP VERIFIED:**
- `name -> Product.title`;
- `description -> Product.descriptionHtml`;
- `sku -> ProductVariant.sku`;
- `merchant_type -> Product.productType`;
- `tags -> Product.tags`;
- `meta_title -> Product.seo.title`;
- `meta_description -> Product.seo.description`;
- `shipping_required -> InventoryItem.requiresShipping`.

`Product.category` remains unmapped and provider-taxonomy owned.

Legacy `name -> title`, `description -> body_html`, and `sku -> variants.sku` may remain historical mappings if their evidence/provenance is explicit. Their age alone is not grounds for demotion.

`brand -> vendor` is **not** in this keep set; see below.

## Additional accepted findings

### `brand -> vendor`

**CONFIRMED OVERCLAIM.**

Shopify documents `vendor` as the product vendor name. The frozen Shopify coverage already classifies `Product.vendor` as channel semantic (`Connector / shopify_vendor_label`) and the cross-platform synthesis explicitly says vendor is not exact canonical brand equivalence.

**Correction:** this row must not remain suggestion-active `verified`. Prefer removing it from canonical mappings and representing the no-automatic-mapping result as a Shopify channel decision for that schema version; alternatively downgrade to `partially_verified` if the implementation keeps the row for historical relation evidence. Do not keep `mapping_type=renamed` as a high-confidence identity claim.

### `net_weight -> InventoryItem.measurement.weight`

**CONFIRMED OVERCLAIM, but Sonnet's proposed direct re-point to `gross_weight` is REJECTED.**

Shopify defines `InventoryItemMeasurement` as weight when packaged and `InventoryItem.measurement` as packaging dimensions. This disproves canonical DEC-009 `net_weight` (product mass excluding packaging).

However it does **not** prove exact canonical `gross_weight` either: DEC-009 gross weight is Product-level mass of the sellable unit including immediate consumer packaging, while Shopify `InventoryItem` is variant/inventory-level and the provider wording does not prove that packaging level.

**Correction:** downgrade/remove the `net_weight` mapping from verified suggestions. Do not create a verified `gross_weight` mapping in this campaign. Update synthesis wording to record Shopify packaged/variant-level weight as related evidence with unresolved packaging-level/entity-level equivalence.

### Unit-pricing structured classification

**ACCEPT Sonnet finding.**

`classifyMaster()` already routes unit-pricing rows to `PricingOrCompliance`, while `classifyStructured()` can label members as `VariantComposition` or `ProviderCapability` solely from surface-name heuristics. The same rows still enter `queue:shopify_unit_pricing_ownership`, so the frozen deferral itself is intact.

**Correction:** make `classifyStructured()` apply the same unit-pricing special case and emit `owner_candidate=PricingOrCompliance` without changing `STRUCTURE_MEMBER` fate or resolving the deferred ownership decision.

## Provenance findings

Four legacy verified Shopify mapping rows currently lack source-evidence rows:
- `description -> body_html`;
- `brand -> vendor`;
- `gtin -> variants.barcode`;
- `sku -> variants.sku`.

This is not proof that all four semantics are false. In the correction campaign:
- unsafe `brand` / `gtin` rows must first be demoted/removed for semantic reasons;
- historical `description` / `sku` rows may stay verified only with explicit primary-source provenance consistent with their 2024-10 surface.

Legacy `name -> title` already has source evidence.

## Frozen deferrals

Keep unchanged:
- `shopify_short_title_ownership`;
- `shopify_unit_pricing_ownership`.

No new canonical field, table, enum, lifecycle model, pricing model, or connector architecture is authorized by this review.

## Accepted correction set for implementation

1. `brand -> vendor`: remove from high-confidence verified mappings; preserve provider evidence without identity claim.
2. `status -> Product.status`: `verified -> partially_verified`.
3. Both Shopify GTIN/barcode mappings: `verified -> partially_verified`; fix/remove the legacy Google `a004` applicability reference.
4. `country_of_origin -> InventoryItem.countryCodeOfOrigin`: `verified -> partially_verified` because Product-vs-variant ownership is unresolved.
5. `net_weight -> InventoryItem.measurement.weight`: remove/downgrade verified mapping; **do not** replace with verified `gross_weight`.
6. Unit-pricing structured coverage: normalize `owner_candidate` to `PricingOrCompliance` while preserving the existing deferral.
7. Repair primary-source provenance for historical Shopify mappings that remain verified.
8. Add registry validation for mapping-channel vs channel-specific applicability-channel mismatch.
9. Add the same fail-closed guard in `CanonicalFieldMappingSuggestionProvider` as defense-in-depth.
10. Do **not** implement a `latest/current` schema-version selector; preserve the existing ambiguity/no-prefill semantics.
11. Update Shopify/cross-platform synthesis and review tests so the corrected semantic boundaries cannot regress.

## Acceptance evidence required

- `php artisan canonical-coverage:shopify --check` or the equivalent committed Shopify coverage validator passes with the frozen denominator unchanged.
- `ShopifyCoverageTest` and new Shopify provider semantic correction tests pass.
- canonical registry validator: `Errors: 0`; no new warning class introduced by the correction.
- `FieldMappingSuggestionReadModelTest` proves malformed cross-channel applicability cannot yield a suggestion and existing Adobe/Google/BigCommerce/Amazon safe suggestions remain unchanged.
- literal tests prove Shopify unsafe rows (`vendor`, barcode-as-GTIN, status, packaged weight, country-of-origin entity mismatch) are not high-confidence suggestion candidates.
- `git diff --check` and Pint pass.
- physical Shopify inventory files and source manifest remain byte-identical unless a source-provenance row in the canonical registry explicitly requires change.

## ROUTING DECISION

**Goal:** make Shopify canonical mappings semantically fail-closed before connector activation, without reopening the frozen five-platform vocabulary or inventing runtime version authority.

**Risk:** YELLOW.

**Why:** the campaign changes registry data, coverage classification, validator invariants, and the generic FieldMapping suggestion read path. It does not change persistence, auth, tenant isolation, transactions, external writes, or fundamental domain architecture.

**Architecture:** existing/frozen. No new decision required.

**Executor:** Codex / Composer 2.5.

**Post-review:** Lead AI verifies actual HEAD/diff, frozen denominator, registry validator, suggestion tests, full relevant CI, and no accidental source-inventory drift.

**Escalation:** only if implementation reveals a genuinely new unresolved lifecycle/entity/version authority decision. Opus is not warranted by the current evidence.

**Cost rationale:** substantial runtime correction on a frozen seam; frontier architecture review would repeat already-resolved work without reducing current risk.

## Final verdict

Sonnet materially improved the audit by proving the status/barcode semantics, finding the structured unit-pricing classifier inconsistency, and independently confirming the missing schema-version awareness. Its final report is **accepted with three Lead corrections**: no arbitrary latest-version filtering, no automatic `net_weight -> gross_weight` re-point, and no mapping-level SAFE verdict for `country_of_origin` while Product-vs-InventoryItem entity ownership remains mismatched.

This arbitration is the implementation source of truth for the Shopify semantic correction campaign.