# Adobe Provider Semantic Audit — Arbitration Ledger

Status: **OPEN — Sonnet pass independently checked; GPT-5.4 pass pending**

Authoritative review base: `develop @ abd65e5988f6b115f835a92c3cc3a430957e988f`

Purpose: preserve the independent provider-by-provider semantic audit before further automatic mapping implementation. This ledger is review-only. It does not reopen broad Adobe research, does not change frozen canonical/runtime artifacts, and does not authorize implementation corrections until the GPT-5.4 pass is overlaid and Lead arbitration is complete.

## Inputs

- Sonnet High Adobe semantic challenge (2026-09-08).
- Independent Lead verification against the exact review base.
- Current Adobe/Magento primary evidence used only to verify contested surface semantics.

## Current arbitration table

| ID | Sonnet finding | Lead status | Severity | Scope | Current decision |
|---|---|---|---|---|---|
| S1 | `base_image` / `thumbnail_image` surface semantics | **ACCEPT WITH SEVERITY ADJUSTMENT** | MATERIAL | Adobe representation only | CSV columns and REST/media-role tokens are distinct representations. Frozen source-surface evidence must not imply literal REST field equality. Current runtime already uses REST roles `image` / `small_image` / `thumbnail`, so this is not a current runtime blocker. |
| S2 | `presentation_layout` promoted as reusable ProductData | **ACCEPT** | MATERIAL | Adobe representation only | `custom_design`, `custom_design_from`, `custom_design_to`, `custom_layout_update`, `page_layout` must be downgraded from `REUSABLE_SEMANTIC / provider_field_candidate` to provider/channel presentation context. No cross-platform canonical field addition. |
| S3 | `authoritative_attribute_metadata.options` vs `attribute_option.value/label` false split | **REJECT AS STATED** | — | No correction | `options[]` is the vocabulary container/member of attribute metadata; `attribute_option.value` and `.label` are structure members of each option row. Container and member fields are not aliases or equivalent concepts. Existing resolved normalization already has one runtime authority for normalized `options[]`. |
| S4 | classic Magento product attribute `cost` absent from top-level Adobe baseline | **ACCEPT WITH REFRAMING** | MATERIAL | Adobe representation / Pricing | Real coverage gap for a standard Adobe/Magento product attribute representation. This is **not** a new universal concept: canonical `cost_price` already exists. Add/retain an explicit Adobe `cost` representation and certify its read/write surface separately from `cost_storage`; do not assume raw surface equivalence. |
| S5 | `save_rewrites_history` classified reusable ProductData | **ACCEPT** | MINOR | Adobe connector/channel context | It controls URL redirect/rewrite behavior and must not be a generic reusable Product field candidate. Downgrade to provider/connector behavior/context. |

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

- Correct the frozen source-surface/representation evidence; do not advertise `base_image`/`thumbnail_image` as literal REST field names.
- Preserve Media-domain ownership.
- Model cross-surface equivalence explicitly. For labels, do **not** invent role-specific REST label fields: REST label belongs to the media entry and must be related to role assignment structurally.
- No cross-platform canonical vocabulary change.

## S2 — Adobe presentation/layout fields

Repository state: all five rows are currently `REUSABLE_SEMANTIC`, owner `ProductData`, representation `provider_field_candidate`, but none has entered `cross_platform_product_field_synthesis.csv` or `canonical_product_fields.csv`.

Primary-source verification: Adobe defines these values as product-page theme/layout scheduling and layout-update configuration; `custom_layout_update` is layout XML/configuration, not portable merchandising data.

Lead correction shape: downgrade to provider/channel presentation context. Exact provider-only disposition/owner token should be chosen in the correction pass using existing vocabulary; do not create a new cross-platform domain merely to house these rows.

## S3 — Attribute option structure

Rejected correction rationale:

- Parent coverage `adobe:attribute_schema:attribute_definition` owns metadata container `authoritative_attribute_metadata.options`.
- Parent coverage `adobe:select_multiselect_options:attribute_options` owns option-row structure members `attribute_option.value`, `attribute_option.label`, `attribute_option.swatch_data`.
- The resolved `Adobe attribute normalization` contract in `docs/03-DOMAIN_MODEL.md` sources exactly one `options[]` list from `GET /V1/products/attributes`; each option is normalized as `{value,label}` and persisted inside `normalized_payload` for selectable types.
- Therefore aliasing the container to its member fields would be a semantic category error. `swatch_data` remains separately structured/deferred as appropriate.

No canonical or provider correction is accepted for S3 unless GPT-5.4 supplies new contradictory evidence.

## S4 — Classic `cost` representation

Confirmed facts:

- Current top-level Adobe inventory includes `cost_storage`, while the structured Catalog Pricing cost payload contains `cost`, `store_id`, `sku`.
- Canonical `cost_price` already exists and is Pricing-owned/internal-only.
- Magento `2.4-develop` `Magento\Catalog\Api\Data\ProductAttributeInterface` defines `CODE_COST = 'cost'`, confirming `cost` as a standard catalog-product attribute identity.
- Adobe current Admin documentation also exposes Product Advanced Pricing → Cost as the actual item cost.
- The Adobe baseline is deliberately finite and live EAV discovery remains authoritative for installed-store reality, so this omission is not proof that runtime discovery cannot see `cost`.

Lead correction shape:

- Add/recognize the classic EAV `cost` surface as an Adobe representation of the already-existing cost-price business meaning.
- Keep it distinct from the dedicated Catalog Pricing `cost_storage` API representation until read/write equivalence and product-type/edition behavior are explicitly certified.
- Do not add another canonical `cost` field and do not assume a generic Product `custom_attributes` write path without certification.

## S5 — `save_rewrites_history`

Adobe current configuration documentation identifies `catalog/seo/save_rewrites_history` as the setting controlling creation of permanent redirects when URL keys change; Adobe product import also exposes `save_rewrites_history` as URL-rewrite behavior.

Lead correction shape: provider/connector/channel behavior, not reusable ProductData and not generic FieldMapping candidate.

## Stable decisions carried forward

The following Sonnet challenges were checked against current project decisions and remain unchanged unless GPT-5.4 produces concrete contrary evidence:

- `manufacturer` is not automatically equivalent to canonical `brand` (`CANONICAL_PRODUCT_FIELD_REGISTRY.md`, DEC-010).
- Adobe `attribute_set_id` remains Connector-owned execution/schema context and is not platform ProductType (`03-DOMAIN_MODEL.md`, resolved execution context).
- Current canonical `status` remains the documented interim boolean `is_active` contract; generic three-state lifecycle semantics remain deferred and must not be silently inferred.
- MSI topology remains Inventory-owned and distinct from Availability/salable projections.
- `price`/`special_price`/`cost` style EAV semantics must not be collapsed blindly with dedicated Catalog Pricing storage APIs; surface-specific support and edition/product-type rules remain relevant.
- Attribute Set / attribute-definition / applicability metadata remain schema mechanics, not ordinary Product fields.
- Price decimal scale remains a Pricing/runtime contract, not an Adobe coverage-field concept.

## Next arbitration step

1. Keep current automatic option/value implementation branch paused; do not merge further semantic assumptions.
2. Ingest GPT-5.4 Adobe evidence/coverage audit independently, without showing it this Sonnet arbitration first.
3. Overlay GPT findings here as `G*` entries and compare against `S1..S5`.
4. For each conflict: verify repo/primary evidence, then mark `ACCEPT`, `REJECT`, or `ARBITRATE`.
5. Use Opus/Gemini only for concrete unresolved disputes.
6. Only after Adobe corrections + deterministic tests/CI, declare `ADOBE PROVIDER REVIEW FREEZE` and move to Google Merchant.
