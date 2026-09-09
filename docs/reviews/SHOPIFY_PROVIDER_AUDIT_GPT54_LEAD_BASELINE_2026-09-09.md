# Shopify Provider Semantic Audit — GPT-5.4 + Lead Verified Baseline

Date: 2026-09-09  
Repository: `Stanislavikus/babypark-b2b`  
Authoritative base: `origin/develop @ 24b64407604a724c02e202f474c221882d24d464`  
Campaign branch: `review/shopify-provider-semantic-audit-2026-09-09`

## Purpose

This file preserves the GPT-5.4 Shopify semantic audit after independent Lead verification against the authoritative repository, current `[Resolved]` decisions, actual suggestion runtime, and Shopify primary documentation.

It is the handoff baseline for Sonnet 5 High. Sonnet must **overlay/challenge this file**, not repeat Shopify inventory acquisition from zero.

Goal: existing Shopify provider knowledge must not cause platform-global FieldMapping suggestions to assert semantic equivalence, applicability, entity ownership, or value semantics that Shopify does not guarantee.

## Scope guard

The Shopify physical corpus is already frozen. Do not re-enumerate Shopify GraphQL objects, taxonomy attributes, webhook topics, or version matrices.

Frozen denominator on this base:
- master rows: 738
- structured rows: 926
- alias rows: 106
- taxonomy rows: 8,556
- freshness rows: 36
- version rows: 14
- coverage rows: 10,376
- concepts: 10,314
- disagreements: 2
- silent drops: 0

The two frozen disagreements remain intentionally open:
- `shopify_short_title_ownership`
- `shopify_unit_pricing_ownership`

This audit must not invent decisions for either one.

## Verification performed by Lead

Document-first was repeated on current `origin/develop`, including the documentation map, AI Working Agreement, canonical registry contract, Attribute Dictionary, Sync/FieldMapping domain contract, Shopify inventory research, coverage ledger, synthesis data, current registry CSVs, Shopify coverage generator/tests, and prior provider arbitration files.

Literal local verification on the exact base:
- `ShopifyCoverageTest`: **10 passed / 26,321 assertions**.
- `FieldMappingSuggestionReadModelTest`: **30 passed / 90 assertions**.
- `canonical-registry:validate`: **Errors 0**, 149 legacy/evidence warnings.
- All five provider coverage generators round-trip byte-identically inside `ShopifyCoverageTest`.

Important validator finding: four legacy Shopify mapping rows are marked `verified` but currently have no source-evidence row:
- `description -> body_html`
- `brand -> vendor`
- `gtin -> variants.barcode`
- `sku -> variants.sku`

Legacy `name -> title` does have explicit source evidence. Missing source evidence is a provenance defect, not by itself proof that the underlying semantic is false.

## Runtime reality

`CanonicalFieldMappingSuggestionProvider` currently requires mapping channel equality, mapping `verification_status=verified`, an existing applicability row whose `context_type` is `global` or `channel`, an active+verified canonical field, a matching active global FieldDefinition/FieldBinding, and literal presence of the external key in the authoritative snapshot.

It does **not** currently require:
- mapping `channel` to equal applicability `channel_or_state`;
- applicability verification status;
- operation equality;
- an authoritative runtime/API schema version;
- option/value semantic completeness;
- a reversible transformation.

Collision handling is fail-closed: multiple distinct external keys for one binding, or one external key for multiple bindings, suppress the suggestion.

Current `[Resolved]` runtime-version contract forbids choosing an arbitrary `latest`, lexical maximum, or first registry row when runtime schema authority is absent. Therefore GPT-5.4's generic recommendation to add a "current schema" filter is **not accepted as written**. The correction must not invent schema authority. Historical rows may remain evidence; unsafe rows must be demoted/superseded, and unresolved multi-version overlap must continue to fail closed.

## Current Shopify activation state

Shopify is seeded as an `Active` `ConnectorDefinition`, but `config/connectors.php` currently exposes an enabled account-setup/discovery profile only for Adobe Commerce.

The Integrations catalog intentionally hides an Active platform with zero accounts unless an enabled `account_setup` profile exists. Tests explicitly show Shopify hidden with no account and visible only when an existing Shopify account is already present.

Therefore GPT-5.4's phrase **"live hazard today" is too strong** for the normal merchant setup path. These defects are best classified as **latent Shopify activation blockers** (and potentially reachable for manually/legacy-created accounts with authoritative snapshots), not as a proven current merchant incident.

## Primary Shopify facts reverified

Official Shopify Admin GraphQL 2026-07/current documentation establishes:
- `Product.vendor`: the name of the product's vendor; it does not assert canonical Brand/Manufacturer equivalence.
- `Product.productType`: the product type merchants define; `Product.category` is a separate category ID.
- `ProductStatus`: `ACTIVE`, `ARCHIVED`, `DRAFT`, `UNLISTED`; ACTIVE is not automatically published and UNLISTED remains active.
- `ProductVariant.barcode`: a barcode string/value; Shopify does not guarantee that every value is a GTIN.
- `InventoryItem.measurement`: packaging dimensions; `InventoryItemMeasurement` is weight information for an InventoryItem **when packaged**.
- `InventoryItem.countryCodeOfOrigin`: manufacturing/production country, ISO 3166-1 alpha-2.
- `InventoryItem.requiresShipping`: whether the inventory item requires shipping.
- `Product.seo.title` / `.description`: SEO Title / SEO Description.
- `ProductVariant.unitPrice` and `unitPriceMeasurement`: explicit unit-pricing semantics.

Primary URLs used:
- https://shopify.dev/docs/api/admin-graphql/latest/input-objects/ProductInput
- https://shopify.dev/docs/api/admin-graphql/latest/enums/ProductStatus
- https://shopify.dev/docs/api/admin-graphql/latest/objects/ProductVariant
- https://shopify.dev/docs/api/admin-graphql/latest/objects/InventoryItem
- https://shopify.dev/docs/api/admin-graphql/latest/objects/InventoryItemMeasurement
- https://shopify.dev/docs/api/admin-graphql/latest/input-objects/InventoryItemInput
- https://shopify.dev/docs/api/admin-graphql/latest/objects/SEO
- https://shopify.dev/docs/api/admin-graphql/latest/objects/UnitPriceMeasurement

## Lead verdict matrix — all 17 Shopify mappings

Legend:
- `KEEP_VERIFIED` — semantic claim is sufficiently supported.
- `CONFIRMED_CORRECTION` — GPT finding independently confirmed.
- `SONNET_CHALLENGE` — Lead has a provisional position but wants an adversarial overlay before final arbitration.
- `DORMANT` — cannot currently yield a normal clean-seed suggestion because another gate blocks it.

| Mapping | GPT-5.4 verdict | Lead baseline verdict | Runtime reach / correction implication |
|---|---|---|---|
| `name -> title` (REST 2024-10) | KEEP | **KEEP_VERIFIED** | Historical REST representation; literal-key matching is fail-closed. Do not invent latest-version preference. |
| `description -> body_html` (REST 2024-10) | KEEP | **KEEP_VERIFIED + PROVENANCE_FIX_CANDIDATE** | Semantic HTML description correspondence is sound; source-evidence row is missing. |
| `brand -> vendor` (REST 2024-10) | REMOVE/SUPERSEDE | **CONFIRMED_CORRECTION** | Coverage already freezes `Product.vendor` as channel semantic. Demote/remove from verified suggestion-active knowledge; preserve rejected/provider evidence if useful. |
| `gtin -> variants.barcode` (REST 2024-10) | REMOVE/SUPERSEDE | **CONFIRMED_CORRECTION** | References Google applicability `a004`; barcode is broader than GTIN; source evidence also missing. Must not remain verified high-confidence Shopify knowledge. |
| `sku -> variants.sku` (REST 2024-10) | KEEP | **KEEP_VERIFIED + PROVENANCE_FIX_CANDIDATE** | SKU semantic is sound; source-evidence row is missing. |
| `name -> Product.title` | KEEP | **KEEP_VERIFIED** | Exact product-name semantic. |
| `description -> Product.descriptionHtml` | KEEP | **KEEP_VERIFIED** | Exact description semantic with HTML representation. |
| `status -> Product.status` | DOWNGRADE_PARTIAL | **SONNET_CHALLENGE — provisional DOWNGRADE_PARTIAL** | Canonical value is boolean; Shopify has four states, publication is separate, and no deterministic Shopify boolean value policy is frozen. |
| `sku -> ProductVariant.sku` | KEEP | **KEEP_VERIFIED** | Exact variant SKU semantic. |
| `gtin -> ProductVariant.barcode` | DOWNGRADE_PARTIAL | **SONNET_CHALLENGE — provisional DOWNGRADE_PARTIAL** | A known GTIN can be represented in barcode, but arbitrary Shopify barcode cannot safely be inferred as GTIN without validation. |
| `merchant_type -> Product.productType` | KEEP | **KEEP_VERIFIED** | Shopify explicitly defines merchant-defined product type; category/taxonomy is separate. |
| `tags -> Product.tags` | KEEP | **KEEP_VERIFIED / DORMANT** | Provider semantic is sound; clean `FieldDefinitionSeeder` currently has no `tags` definition/binding, so suggestion runtime does not activate it. |
| `meta_title -> Product.seo.title` | KEEP | **KEEP_VERIFIED / DORMANT** | SEO semantic is exact; no clean-seed FieldDefinition/binding today. |
| `meta_description -> Product.seo.description` | KEEP | **KEEP_VERIFIED / DORMANT** | SEO semantic is exact; no clean-seed FieldDefinition/binding today. |
| `country_of_origin -> InventoryItem.countryCodeOfOrigin` | KEEP | **SONNET_CHALLENGE — GPT missed binding/semantic boundary** | Canonical field is Product-level and proposed/partial; Shopify value is InventoryItem/variant-level and specifically manufacturing/production country. Do not call fully safe yet. |
| `shipping_required -> InventoryItem.requiresShipping` | KEEP | **KEEP_VERIFIED** | Canonical binding is variant-level and provider semantic is explicit. |
| `net_weight -> InventoryItem.measurement.weight` | DOWNGRADE_PARTIAL | **CONFIRMED_CORRECTION** | DEC-009 net weight excludes packaging; Shopify measurement is explicitly packaged weight. Must not remain verified equivalence. |

## Confirmed correction families

### C1 — `brand -> vendor`

Repository and Shopify evidence agree: `Product.vendor` is a provider/channel vendor label, not guaranteed canonical brand/manufacturer. Current legacy mapping contradicts the frozen Shopify coverage/synthesis boundary.

### C2 — legacy Shopify GTIN applicability

`gtin -> variants.barcode` references `a004`, whose `context_key=google:all_products`, `channel_or_state=google_merchant`, and operation is `advertise`.

`CanonicalRegistryValidator::checkApplicabilityFk()` checks only applicability existence and matching `internal_code`; it does not check mapping channel against applicability channel. The suggestion provider likewise accepts the row because `a004.context_type=channel`.

Smallest safe runtime invariant candidate: for `context_type=channel`, a suggestion-active mapping must have `applicability.channel_or_state == mapping.channel == connectorDefinitionCode`. For `global`, do not invent a channel requirement. Sonnet should challenge the exact invariant before implementation.

### C3 — Shopify packaged weight vs DEC-009

DEC-009 freezes canonical `net_weight` as product mass excluding packaging. Shopify freezes `InventoryItem.measurement` as packaging dimensions and `InventoryItemMeasurement` as packaged-item weight. This is not an exact canonical net-weight equivalence.

The current `verified` mapping must be downgraded or otherwise made non-suggestion-active until an exact portable weight boundary is proven. Do not silently relabel it as `gross_weight`: DEC-009 gross weight means sellable unit plus immediate consumer packaging, while Shopify's documentation does not prove that exact packaging boundary either.

### C4 — unit-pricing structured coverage

Top-level Shopify `unitPrice`, `showUnitPrice`, and `unitPriceMeasurement` correctly remain `DEFER_DECISION` under `shopify_unit_pricing_ownership` / `PricingOrCompliance`.

However `ShopifyCoverage::classifyStructured()` generically routes variant-containing structured source surfaces to `VariantComposition`, even when `questionsForStructured()` attaches the unit-pricing deferred decision.

This is a provider-coverage classification inconsistency. It can be corrected so all unit-pricing members remain under the same deferred ownership framing **without resolving** the portable Pricing/Compliance domain contract.

## Additional Lead finding — country of origin

GPT-5.4 marked `country_of_origin -> InventoryItem.countryCodeOfOrigin` semantically safe. That is incomplete.

Three independent boundaries remain:
1. canonical `country_of_origin` is currently `binding_strategy=product`, `status=proposed`, `verification_status=partially_verified`;
2. Shopify stores `countryCodeOfOrigin` on `InventoryItem`, which is variant/inventory-level, and `a072` itself records `entity_level=product_variant`;
3. Shopify defines the value as country where the item was manufactured or produced, while the current canonical description says "Manufacturing or origin country ISO code" and therefore mixes notions already flagged by the Adobe arbitration for final cross-platform review after all five providers.

The row is dormant today because `fieldRowQualifies()` rejects the proposed/partially-verified canonical field. It should nevertheless not remain silently classified as fully safe future knowledge. Sonnet must challenge whether the Shopify mapping itself should become partial/deferred pending the final canonical country semantic/entity-level arbitration.

## Provisional challenge points

### S1 — Product status

Lead leans toward GPT's `partially_verified` downgrade, but this is not final yet.

Why it differs from the already-frozen Adobe status case: Adobe had a deterministic current boolean mapping (`true -> enabled`, `false -> disabled`) explicitly protected by DEC-010. Shopify has four states. `ACTIVE` and `UNLISTED` are both active-like, while `DRAFT` and `ARCHIVED` are both non-active-like; publication remains separate. The repository currently contains only the transformation label `platform_lifecycle_to_shopify_status`, not a frozen deterministic value policy.

Sonnet must decide whether direction-neutral FieldMapping may still be `verified` as a broad lifecycle correspondence with operation-specific transformation deferred, or whether high-confidence suggestion semantics require downgrade to partial now.

### S2 — GTIN versus generic barcode

Lead leans toward `partially_verified`. Shopify proves the provider field is a generic barcode string, while canonical `gtin` is specifically GTIN. A known canonical GTIN can be written to the barcode field, but READ from arbitrary barcode requires GTIN validation.

Sonnet must test whether the current direction-neutral semantic-correspondence contract permits `verified` field-path knowledge plus operation validation, or whether this is an overclaim requiring partial status.

### S3 — historical REST rows / schema version

Lead rejects a generic "select current/latest schema" implementation. Existing `[Resolved]` semantics require fail-closed behavior when runtime version authority is absent.
Historical rows with sound semantics may remain verified evidence if exact snapshot identity supports them; unsafe rows must not remain suggestion-active merely because they are old. Sonnet should attack whether any additional provenance/status marker is needed, without inventing runtime version selection.

### S4 — applicability invariant

The deterministic defect is channel mismatch acceptance, not generic applicability complexity. Sonnet should challenge whether the minimal invariant should also require `applicability.verification_status=verified`, or whether that would incorrectly suppress legitimate historical/global knowledge such as `a003` without an explicit `[Resolved]` basis.

### S5 — country-of-origin entity level

Challenge Product-level canonical binding versus Shopify InventoryItem/variant-level reality and the mixed manufacturing/origin canonical description. Do not solve this by silently changing binding strategy.

### S6 — missed overclaims

Attack every row marked `KEEP_VERIFIED` above, but report only evidence-backed semantic or runtime contradictions. Do not create work to produce findings.

## What is NOT a new architecture decision

The following corrections fit existing architecture:
- demoting/superseding semantically unsafe mapping evidence;
- fixing mapping/applicability channel consistency validation;
- keeping ambiguous mappings out of high-confidence suggestion participation;
- aligning Shopify coverage classification with an already-deferred question;
- adding missing provider evidence for historically retained mappings.

No DB/auth/security/concurrency/transaction decision has appeared. No Opus escalation is justified at this stage.

## Existing decisions that remain closed or deferred

Do not reopen:
- Shopify vendor as provider/channel semantic rather than canonical brand;
- merchant `Product.productType` distinct from taxonomy/category identity;
- taxonomy attributes remain connector taxonomy context, not automatic FieldDefinitions;
- subtitle ownership remains deferred;
- unit-pricing portable ownership remains deferred;
- arbitrary runtime-version guessing remains forbidden.

## Provisional correction set before Sonnet overlay

Already strong enough to carry into final arbitration unless Sonnet produces contrary primary evidence:
1. demote/remove `brand -> vendor` from verified suggestion-active canonical knowledge;
2. demote/supersede legacy `gtin -> variants.barcode` and remove the invalid Google applicability relationship;
3. add validator/runtime coverage for mapping-channel versus channel-applicability mismatch;
4. downgrade or otherwise fail-close `net_weight -> InventoryItem.measurement.weight` under DEC-009;
5. normalize structured Shopify unit-pricing coverage so it remains attached to the existing deferred unit-pricing ownership question;
6. preserve semantically sound REST rows as historical evidence without implementing arbitrary "latest schema" preference;
7. consider adding missing source evidence for retained legacy `description` and `sku` mappings.

Pending Sonnet arbitration:
- `status -> Product.status`: verified vs partially_verified;
- `gtin -> ProductVariant.barcode`: verified-with-runtime-validation vs partially_verified;
- `country_of_origin -> InventoryItem.countryCodeOfOrigin`: provider mapping status and entity-level consequence;
- exact minimal applicability runtime/validator invariant;
- whether any other of the 17 mappings is overclaimed.

## SONNET 5 HIGH OVERLAY PROTOCOL

Sonnet must read this file **after** fetching current `origin/develop` and re-reading `docs/Project_Documentation_Map.md` + `docs/05-AI_WORKING_AGREEMENT.md`.

Do not repeat the Shopify inventory. Do not independently rebuild the 10,376-row corpus. Treat the corpus and denominator as frozen unless you prove a concrete integrity defect.

For every substantive baseline statement you challenge, return one of:
- `AGREE` — Lead baseline is supported;
- `DISAGREE` — cite exact repo seam + official Shopify primary evidence and give the corrected verdict;
- `NEW` — a material semantic/runtime defect not represented in this baseline.

Mandatory attack targets: S1 status, S2 barcode/GTIN, S3 REST/schema-version behavior, S4 applicability invariant, S5 country-of-origin entity level, S6 all KEEP_VERIFIED rows.

Sonnet output must be bounded to:
1. actual base SHA;
2. `AGREE / DISAGREE / NEW` table keyed to the baseline sections/findings;
3. exact primary evidence for disagreements/new findings;
4. proposed final verdict for each of S1–S6;
5. `NO NEW ARCHITECTURE DECISION REQUIRED` or one exact genuinely new decision;
6. minimal correction delta versus this baseline.

Non-negotiable:
- no code/CSV edits;
- no commits or PR;
- no broad Shopify research;
- official Shopify primary evidence only for provider claims;
- do not reopen `[Resolved]` without a direct conflict;
- do not invent `latest` schema selection;
- do not resolve deferred subtitle/unit-pricing ownership by inference;
- distinguish provider fact, repository fact, and reviewer inference.

## Lead state at handoff

GPT-5.4's core result is directionally strong, but it is **not adopted verbatim**. Lead independently confirms the vendor, legacy-applicability, packaged-weight, and unit-pricing-classification findings; narrows the runtime severity from current merchant incident to latent Shopify activation blocker; rejects arbitrary latest-schema filtering; and adds the country-of-origin entity-level/canonical-semantic issue plus the missing legacy evidence rows.

Final Shopify correction scope is **not frozen until the Sonnet overlay is arbitrated by Lead**.
