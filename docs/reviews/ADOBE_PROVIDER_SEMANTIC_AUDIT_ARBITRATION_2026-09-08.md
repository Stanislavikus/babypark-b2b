# Adobe Provider Semantic Audit — Arbitration Ledger

Status: **LEAD ARBITRATION COMPLETE — CORRECTION PASS REQUIRED**

Authoritative review base: `develop @ abd65e5988f6b115f835a92c3cc3a430957e988f`

Purpose: preserve the independent provider-by-provider semantic audit before further automatic mapping implementation. This ledger is review-only. It does not reopen broad Adobe research and does not itself change frozen canonical/runtime artifacts.

## Inputs

- Sonnet High Adobe semantic challenge (2026-09-08).
- GPT-5.4 Adobe evidence/coverage audit (2026-09-08), run independently without the Sonnet arbitration.
- Independent Lead verification against the exact review base.
- Current Adobe/Magento primary evidence used only to verify contested surface semantics.

## Review coverage

GPT-5.4 reported a full pass over the frozen Adobe audit surface:

- 411 physical Adobe coverage rows;
- 359 Adobe concepts;
- 86 cross-platform synthesis rows;
- 65 canonical fields;
- 17 canonical options;
- 12 canonical option mappings;
- 8 Adobe channel decisions.

This agrees with the deterministic repository counts on the authoritative base.

## Final cross-review arbitration table

| ID | Finding | Sonnet | GPT-5.4 | Lead status | Severity | Correction scope |
|---|---|---|---|---|---|---|
| S1 | `base_image` / `thumbnail_image` surface semantics | Found | Not raised | **ACCEPT WITH SEVERITY ADJUSTMENT** | MATERIAL | Adobe representation evidence only |
| S2 | `presentation_layout` promoted as reusable ProductData | Found | Considered but did not promote to a proven gap because it does not reach canonical synthesis | **ACCEPT** | MATERIAL | Adobe provider classification |
| S3 | `authoritative_attribute_metadata.options` vs `attribute_option.value/label` false split | Found | Not raised | **REJECT AS STATED** | — | No correction |
| S4 | classic Magento product attribute `cost` absent from top-level Adobe baseline | Found | Not raised | **ACCEPT WITH REFRAMING** | MATERIAL | Adobe Pricing representation |
| S5 / G4 | `save_rewrites_history` provider classification | Found as over-promoted provider field | GPT called canonical omission a false positive | **ACCEPT SONNET / REJECT GPT CONCLUSION** | MINOR | Adobe connector/channel context |
| G1 | Adobe `status` vs canonical `status` | Not raised; Sonnet explicitly rejected this challenge after reading DEC-010 | Raised as MAJOR lifecycle contradiction | **REJECT MAJOR FINDING; ACCEPT MINOR LEDGER CLARIFICATION** | MINOR docs consistency only | Adobe disagreement wording / decision reference |
| G2 | Adobe `weight` used as evidence for canonical `net_weight` while semantic kind is OPEN | Not raised | Found | **ACCEPT** | MATERIAL | Cross-platform synthesis evidence wording only; mapping remains deferred |
| G3 | `country_of_manufacture` used as Adobe evidence for canonical `country_of_origin` | Not raised | Found | **ACCEPT** | MATERIAL | Cross-platform synthesis evidence wording; cross-platform wording carry-forward |

## S1 — Media role representation boundary

Repository evidence:

- `docs/data/adobe_commerce_v1_inventory_master.csv` currently labels `base_image`, `base_image_label`, `thumbnail_image`, `thumbnail_image_label` with a combined `Import + Admin REST + Catalog Service READ` surface.
- `docs/data/adobe_commerce_v1_structured_object_fields.csv` separately records `media_gallery_entry.types` with roles such as `image/small_image/thumbnail` for Admin REST/core GraphQL.
- `docs/data/adobe_commerce_v1_alias_groups.csv` has no explicit media-role cross-surface representation entry.
- Runtime is already correct: `app/Support/Connectors/AdobePaaS/Media/AdobeProductMediaRole.php` maps Primary to `['image', 'small_image', 'thumbnail']`; Stage 3D media tests use the same tokens.

Primary-source verification:

- Adobe product-image import documentation uses CSV columns `base_image`, `small_image`, `thumbnail_image`.
- Adobe REST media-gallery examples use `types: ['image', 'small_image', 'thumbnail']`.

Lead correction shape:

- Correct frozen source-surface/representation evidence; do not advertise `base_image`/`thumbnail_image` as literal REST field names.
- Preserve Media-domain ownership.
- Model cross-surface equivalence explicitly. For labels, do not invent role-specific REST label fields: REST label belongs to the media entry and must be related to role assignment structurally.
- No cross-platform canonical vocabulary change.

## S2 — Adobe presentation/layout fields

Repository state: `custom_design`, `custom_design_from`, `custom_design_to`, `custom_layout_update`, `page_layout` are currently `REUSABLE_SEMANTIC`, owner `ProductData`, representation `provider_field_candidate`, but none entered `cross_platform_product_field_synthesis.csv` or `canonical_product_fields.csv`.

Primary-source verification: Adobe defines these values as product-page theme/layout scheduling and layout-update configuration; `custom_layout_update` is layout XML/configuration, not portable merchandising data.

Lead correction shape: downgrade to provider/channel presentation context. Exact provider-only disposition/owner token must reuse existing vocabulary; do not create a new cross-platform domain merely to house these rows.

Why GPT did not confirm this is not a conflict: GPT's task prioritized evidence that incorrectly graduated into canonical outputs, whereas Sonnet's task also audited provider-local clustering. The provider classification is still wrong even though the mistake has not yet escaped into canonical synthesis.

## S3 — Attribute option structure

Rejected correction rationale:

- Parent coverage `adobe:attribute_schema:attribute_definition` owns metadata container `authoritative_attribute_metadata.options`.
- Parent coverage `adobe:select_multiselect_options:attribute_options` owns option-row structure members `attribute_option.value`, `attribute_option.label`, `attribute_option.swatch_data`.
- The resolved `Adobe attribute normalization` contract in `docs/03-DOMAIN_MODEL.md` sources exactly one `options[]` list from `GET /V1/products/attributes`; each option is normalized as `{value,label}` and persisted inside `normalized_payload` for selectable types.
- Container and element-member fields are not aliases or equivalent concepts. Aliasing them would be a semantic category error.

No correction accepted.

## S4 — Classic `cost` representation

Confirmed facts:

- Current top-level Adobe inventory includes `cost_storage`, while the structured Catalog Pricing cost payload contains `cost`, `store_id`, `sku`.
- Canonical `cost_price` already exists and is Pricing-owned/internal-only.
- Magento `2.4-develop` `Magento\Catalog\Api\Data\ProductAttributeInterface` defines `CODE_COST = 'cost'`, confirming `cost` as a standard catalog-product attribute identity.
- Adobe current Admin documentation exposes Product Advanced Pricing → Cost as the actual item cost.
- The Adobe baseline is deliberately finite and live EAV discovery remains authoritative for installed-store reality, so this omission does not imply live discovery cannot see `cost`.

Lead correction shape:

- Add/recognize the classic EAV `cost` surface as an Adobe representation of the already-existing cost-price business meaning.
- Keep it distinct from dedicated Catalog Pricing `cost_storage` until read/write equivalence and product-type/edition behavior are certified.
- Do not add another canonical `cost` field and do not assume a generic Product `custom_attributes` write path without certification.

## S5 / G4 — `save_rewrites_history`

GPT correctly observed that its absence from platform canonical fields is intentional, but that does not answer Sonnet's provider-local finding.

Actual frozen Adobe coverage still classifies `save_rewrites_history` as:

- `REUSABLE_SEMANTIC`;
- owner `ProductData`;
- representation `provider_field_candidate`.

The source inventory itself calls it `connector_context`, and Adobe documentation defines it as the behavior that creates a 301 rewrite when a new `url_key` is supplied; Adobe also exposes `catalog/seo/save_rewrites_history` as URL-redirect configuration.

Lead correction shape: keep the semantic behavior in Adobe evidence, but downgrade ownership/representation to provider connector/channel routing behavior. It remains absent from platform canonical fields.

## G1 — Adobe `status` / lifecycle

GPT-5.4 raised a MAJOR contradiction because `adobe_status_lifecycle` remains OPEN while canonical mappings contain `status -> is_active_boolean_to_adobe_enabled_disabled`.

Lead arbitration rejects the MAJOR finding.

DEC-010 explicitly resolves the current boundary:

- canonical `status` is **currently a boolean `is_active` contract**;
- it is not the future three-state `draft/active/archived` lifecycle;
- `true -> Adobe status 1 (enabled)` and `false -> Adobe status 2 (disabled)` is a deliberate deterministic transformed mapping;
- the richer future lifecycle remains deferred.

Therefore the verified current Adobe mapping does not depend on proving the broader future lifecycle equivalence GPT challenged.

Accepted minor cleanup: `adobe_status_lifecycle` wording/decision reference is stale enough to invite this exact misread. The correction pass should clarify that the OPEN question concerns any **future richer lifecycle beyond the current boolean active contract**, and should reference DEC-010 rather than implying the current boolean mapping is unresolved.

No canonical mapping removal.

## G2 — Adobe `weight` vs canonical `net_weight`

GPT's substantive point is accepted after independent verification.

Repository evidence:

- `adobe_weight_semantic` is OPEN: Adobe `weight` has not been proven as item, shipping, net, package, or gross weight.
- Adobe coverage keeps `weight` under `DEFERRED_REVIEW` and states no cross-platform equivalence asserted.
- DEC-009 defines canonical `net_weight` as product mass excluding packaging and explicitly says connector mappings remain deferred until channel semantics/packaging level/unit are verified.
- DEC-010 separately keeps Adobe `net_weight` deferred.
- `canonical_product_field_channel_decisions.csv` has verified Adobe `net_weight = deferred`.
- No Adobe `net_weight`/`gross_weight` mapping row exists.
- Nevertheless `cross_platform_product_field_synthesis.csv` currently writes bare `adobe_evidence = weight` on the `net_weight` row.

The synthesis file is research, not runtime registry, so this is not a current mapping bug. But frozen research evidence must not visually imply an equivalence the authoritative decisions explicitly reject.

Lead correction shape:

- Keep canonical `net_weight` unchanged.
- Keep Adobe mapping deferred.
- Change Adobe synthesis evidence from bare `weight` to explicit qualified evidence, e.g. `weight — related Adobe product-weight surface; semantic kind unresolved; NOT established as net_weight; mapping deferred (DEC-009/DEC-010)`.
- Recheck the `gross_weight` Adobe evidence wording as the same measurement family; do not assert a direct Adobe mapping without a proven packaging-level semantic.
- No new provider concept is required: `adobe:core_product_scalar:weight` already preserves the ambiguous provider fact.

## G3 — `country_of_manufacture` vs `country_of_origin`

GPT's core finding is accepted, but its proposed "missing provider concept" is rejected because the exact Adobe provider concept already exists.

Repository evidence:

- Adobe coverage has explicit `adobe:core_product_scalar:country_of_manufacture`.
- The provider concept is `REUSABLE_SEMANTIC` but explicitly says no cross-platform equivalence asserted.
- No Adobe mapping row exists for canonical `country_of_origin`.
- `cross_platform_product_field_synthesis.csv` nevertheless uses bare `country_of_manufacture` as Adobe evidence for `country_of_origin`.
- Current Adobe documentation defines `country_of_manufacture` specifically as the country where the product was manufactured.

Lead correction shape:

- Preserve canonical `country_of_origin`; independent Shopify/Amazon evidence supports an origin concept.
- Preserve Adobe `country_of_manufacture` as its exact provider meaning.
- Qualify Adobe synthesis evidence: related country/manufacturing fact, **not established identity-equivalent** to canonical country of origin.
- Do not create a second Adobe provider concept; it already exists.
- Do not create an Adobe canonical mapping until equivalence/transform policy is explicitly proven.

Cross-platform carry-forward: canonical `country_of_origin` currently has description `Manufacturing or origin country ISO code`, which itself mixes two notions. Do not silently change it in the Adobe-only correction pass; flag it for the final cross-platform arbitration after all five provider reviews.

## Stable decisions carried forward

The following remain unchanged:

- `manufacturer` is not automatically equivalent to canonical `brand` (DEC-010).
- Adobe `attribute_set_id` remains Connector-owned execution/schema context and is not platform ProductType.
- Current canonical `status` remains the documented interim boolean `is_active` contract; generic future lifecycle semantics remain deferred.
- MSI topology remains Inventory-owned and distinct from Availability/salable projections.
- EAV pricing semantics must not be collapsed blindly with dedicated Catalog Pricing storage APIs.
- Attribute Set / attribute-definition / applicability metadata remain schema mechanics, not ordinary Product fields.
- Price decimal scale remains a Pricing/runtime contract, not an Adobe coverage-field concept.
- `url_key` remains a slug/path input, not canonical absolute URL equivalence.

## Accepted Adobe correction set

The Adobe correction pass must stay narrow and deterministic:

1. **Media representation:** split/qualify CSV image-role keys vs REST media-role tokens; preserve Media ownership and current correct runtime behavior.
2. **Presentation/layout:** downgrade five Magento theme/layout rows from reusable ProductData to provider/channel presentation context.
3. **Classic `cost`:** add/recognize standard Adobe EAV `cost` representation without collapsing it into `cost_storage` or adding a new canonical field.
4. **URL rewrite behavior:** downgrade `save_rewrites_history` from reusable ProductData to provider connector/channel behavior.
5. **Status ledger:** clarify the OPEN disagreement so it does not contradict DEC-010's already-resolved boolean mapping; no mapping removal.
6. **Weight synthesis:** qualify Adobe `weight` evidence as semantically unresolved; keep `net_weight`/`gross_weight` mappings deferred.
7. **Country synthesis:** qualify `country_of_manufacture` as related but not established equivalent to `country_of_origin`; keep Adobe mapping absent.

Explicitly **not accepted**:

- no alias between `authoritative_attribute_metadata.options` container and `attribute_option.value/label` members;
- no removal of the current boolean Adobe `status` mapping;
- no new universal `cost` concept;
- no new Adobe `weight` provider concept (already exists);
- no new Adobe `country_of_manufacture` provider concept (already exists);
- no promotion of `save_rewrites_history` to platform canonical.

## Arbitration routing

No Opus/Gemini arbitration is required at this point. The apparent Sonnet/GPT disagreements are resolvable from the authoritative repository decisions and current Adobe primary evidence.

Next gate:

1. Apply only the accepted correction set on a clean branch from `develop @ abd65e5988f6b115f835a92c3cc3a430957e988f`.
2. Regenerate Adobe coverage/concepts/disagreements deterministically rather than hand-edit generated shards where generator ownership applies.
3. Add regression tests for each corrected semantic boundary.
4. Run Adobe CanonicalCoverage tests, full CanonicalCoverage suite, Pint, diff-check, then authoritative MySQL CI.
5. Lead-verify final artifacts; declare `ADOBE PROVIDER REVIEW FREEZE` only after green CI.
6. Then move to Google Merchant with a fresh independent Sonnet/GPT pair.
