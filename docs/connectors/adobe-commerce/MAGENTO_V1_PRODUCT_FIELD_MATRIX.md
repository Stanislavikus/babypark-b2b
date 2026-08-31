# Magento / Adobe Commerce V1 Product Field Matrix

**Contract version:** `2.0.0`  
**Repository base:** `133a31ab056ea0292faee5512d77cef0f3986c59`  
**Refresh date:** `2026-08-31`  
**Completion state:** `partial_pending_real_target`

This is the current-base Magento V1 Product field and capability inventory for the
canonical campaign branch.

It exists to satisfy the delivery sequence from `09-CONNECTOR_DELIVERY_PROTOCOL.md`:

`inventory -> classify -> map -> missing seam -> representative READ/WRITE probe -> actual error -> smallest correction -> rerun -> field-by-field certification`

The adjacent JSON contract is authoritative. This markdown is the human-readable audit.

## Non-negotiable interpretation rules

- Every row below is an individual field or capability outcome. A cluster is only a
  classification aid.
- Cluster summaries are **not** field-complete certification.
- Installation-dependent EAV rows do **not** claim a universal Magento schema.
- Real-target certification remains pending until the target inventory and probes are
  executed against an actual connected store.
- Public support truth remains unchanged: `Adobe Products / Export / Live = false`.
- Trusted Receive identity remains unchanged: trusted `ExternalRecordLink` plus
  logical `entity_id` discriminator plus exact SKU precondition.
- The existing entity-bound Safe Sync seams remain the only approved trusted product
  READ/WRITE primitive family:
  - `AdobeSafeSyncClient::readProduct()`
  - `AdobeSafeSyncClient::writeSimpleProduct()`

## Current primary-source refresh

The current refresh preserved the useful research direction from donor PR `#180`,
but replaced stale retrieval claims and stale repository-state conclusions.

Primary-source refresh used current Adobe or Magento-owned sources:

| ID | Source | Current refresh outcome |
|---|---|---|
| `S1` | Adobe Commerce REST reference | Refreshed successfully on 2026-08-31; `V1/products`, configurable product, media, and inventory surfaces remain present. |
| `S2` | Adobe Commerce 2.4.9 release notes | Refreshed successfully on 2026-08-31; current 2.4.9 notes record REST media-gallery store-view inheritance behavior. |
| `S3` | Magento `Catalog\Api\Data\ProductInterface` | Current `2.4-develop` contract reviewed. |
| `S4` | Magento `Catalog\Api\ProductRepositoryInterface` | Current `2.4-develop` contract reviewed. |
| `S5` | Magento `Catalog\Api\Data\ProductAttributeInterface` | Current `2.4-develop` contract reviewed. |
| `S6` | Magento `ConfigurableProduct` API contracts | Current `2.4-develop` contract reviewed. |
| `S7` | Magento media gallery API contracts | Current `2.4-develop` contract reviewed. |
| `S8` | Magento MSI inventory APIs | Adobe REST inventory surface refreshed; exact raw source-path retrieval for one MSI interface remained not re-verified in current tooling, so MSI row conclusions stay explicitly bounded. |

## Current repository truths frozen by this matrix

- Current stock Magento GET transport seam already exists and must be reused:
  - `AdobeProductCommandRequestFactory::buildGet()`
  - `AdobeProductRemoteStateClient::sendReadOnlyGetWithContext()`
- Current Receive remains trusted-link and entity-bound Safe Sync read:
  - trusted `ExternalRecordLink`
  - strict logical entity discriminator
  - exact trusted expected SKU
  - current proposal output remains `Product.name` only
- Current trusted simple export runtime still stops fail-closed at
  `entity_bound_mutation_bridge_required`.
- Current Safe Sync write request allowlist remains:
  - `expected_sku`
  - `name`
  - `status`
  - `visibility`
  - `price`
  - `mapped_attributes`
- Safe Sync `expected_sku` is an identity precondition, not a trusted writable SKU
  mutation path.

## Discovery inputs currently normalized in repository code

Current `AdobePaaSAttributeNormalizer` still accepts exactly:

`text`, `textarea`, `texteditor`, `date`, `datetime`, `boolean`, `select`,
`multiselect`, `price`, `media_image`, `gallery`, `weight`

Any other `frontend_input` remains fail-closed until explicitly verified and mapped.

## Master matrix

| ID | External field/capability | Cluster | External READ | External WRITE | Platform owner | Connector READ seam | Connector WRITE seam | Real validation | Result / blocker |
|---|---|---|---|---|---|---|---|---|---|
| `product-entity-id-discriminator` | Magento logical `id` / trusted discriminator | identity_trust | Stock `GET /V1/products/:sku` can expose `id`; Safe Sync trusted read returns verified logical id | Not a mutable Product save field | Entity Trust + `ExternalRecordLink` | Safe Sync `readProduct()` for trusted identity; stock GET remains read-only candidate evidence | None; trusted writes stay entity-bound and use logical id outside stock PUT/POST identity | `repository_contract_only` | Trusted identity authority remains ERL + entity-bound Safe Sync; stock GET must not replace post-trust identity authority. |
| `product-sku` | `sku` | core_identity | Stock product GET | Stock POST/PUT at Magento surface | ProductVariant sellable identity + ERL external identity | Stock GET and Safe Sync trusted read precondition | No trusted SKU mutation path; Safe Sync uses `expected_sku` only as precondition | `repository_contract_only` | Trusted consequential write cannot become SKU-addressed stock Product PUT/POST; public Live remains false. |
| `product-name` | `name` | core_identity | Stock product GET and Safe Sync trusted read | Stock POST/PUT; Safe Sync simple write allowlist | Product | Receive proposal service already maps trusted name read to `Product.name` proposal | Existing entity-bound Safe Sync simple write primitive can carry `name` once the trusted simple executor is rewired | `repository_contract_only` | Current branch already has trusted READ and name proposal; trusted WRITE consumption is still pending executor wiring. |
| `product-type-id` | `type_id` | core_type | Stock product GET | Product type is effectively fixed by creation path, not current trusted mutation slice | Product family / connector execution planning | Stock GET only on current base | None in current trusted simple write slice | `real_target_pending` | Useful for full product document read and capability classification, but no reusable full-document reader exists on current base yet. |
| `product-status` | `status` | lifecycle_projection | Stock product GET | Stock POST/PUT; Safe Sync allowlist | Product lifecycle semantics | Stock GET only on current base | Safe Sync simple write allowlist contains `status` | `repository_contract_only` | Write primitive exists but current trusted simple executor still stops fail-closed before invoking Safe Sync. |
| `product-visibility` | `visibility` | lifecycle_projection | Stock product GET | Stock POST/PUT; Safe Sync allowlist | Connector-owned external display projection | Stock GET only on current base | Safe Sync simple write allowlist contains `visibility` | `repository_contract_only` | Same current blocker as `product-status`; public Live support remains false. |
| `product-price` | `price` | pricing | Stock product GET | Stock product save; Safe Sync allowlist | Pricing domain | Stock GET only on current base | Safe Sync simple write allowlist contains `price` | `repository_contract_only` | Safe Sync trusted READ does not currently expose price; Receive pricing flow is not implemented. |
| `product-custom-attribute-scalar` | Installation-specific scalar `custom_attributes[]` | eav_scalar | Stock product GET plus attribute metadata discovery | Stock product save via `custom_attributes`; Safe Sync `mapped_attributes` is scalar-string only | Attribute Dictionary | Attribute metadata discovery + stock GET candidate read | Safe Sync simple write `mapped_attributes` for scalar strings only | `real_target_pending` | Installation-dependent; no universal custom-attribute list exists; real target inventory must expand one row per discovered field later. |
| `product-custom-attribute-select` | Installation-specific select `custom_attributes[]` | eav_select | Stock product GET plus attribute metadata/options discovery | Stock product save via target option ids; Safe Sync `mapped_attributes` can carry one mapped scalar value | Attribute Dictionary + FieldOptionMapping | Attribute discovery plus stock GET candidate read | Safe Sync simple write `mapped_attributes` for single mapped scalar option ids | `real_target_pending` | Option labels and ids are target-dependent; current repository evidence is sufficient for partial mapping only. |
| `product-custom-attribute-multiselect` | Installation-specific multiselect `custom_attributes[]` | eav_select | Stock product GET plus attribute metadata/options discovery | Magento supports multiselect writes, but current connector trusted write seam does not | Attribute Dictionary + FieldOptionMapping | Attribute discovery plus stock GET candidate read | None in current trusted write seam; current `mapped_attributes` contract is scalar only | `real_target_pending` | Current connector lacks governed multi-option serialization and Safe Sync request support. |
| `product-media-gallery-entries` | `media_gallery_entries[]` | media | Stock product/media REST surfaces | Magento media create/update/delete surfaces | Media domain | Stock GET candidate read; dedicated media APIs exist externally | None in current trusted Safe Sync product save; media remains intentionally excluded | `real_target_pending` | 2.4.9 media store-view inheritance behavior was refreshed; current connector still has no entity-bound Safe Sync media contract. |
| `product-configurable-options` | Configurable option structure | configurable_structure | Configurable option APIs and stock payload structures | Configurable option APIs | Product/ProductVariant relation plus mapped dimension fields | Existing stock configurable GET seam family already exists in request factory / remote state client | None in current Safe Sync simple write primitive | `real_target_pending` | Current branch has internal configurable seams, but no trusted entity-bound Safe Sync configurable mutation contract. |
| `product-configurable-children` | Configurable child relations | configurable_structure | Configurable child APIs | Configurable child relation APIs | Product to ProductVariant relation | Existing stock configurable child GET seam family already exists | None in current Safe Sync simple write primitive | `real_target_pending` | Relation ownership is clear; trusted simple Safe Sync write does not cover configurable relation mutation. |
| `product-category-links` | `extension_attributes.category_links[]` | category_relation | Stock product/category APIs | Category relation APIs | Category domain | Stock GET candidate read only | None in current trusted product seam | `real_target_pending` | No current connector relation mapping/runtime exists for category receive or trusted write. |
| `product-tier-and-special-pricing` | Tier pricing / special pricing capability | pricing | Product/tier-price APIs | Tier-price / advanced pricing APIs | Pricing domain | External APIs exist; current connector does not consume them | None in current trusted product seam | `real_target_pending` | Current connector only carries one base price value in Safe Sync simple write. |
| `product-inventory-source-items` | MSI inventory / source items capability | availability | Magento inventory APIs | Magento inventory APIs | Availability domain | No current Product V1 connector read seam | None in current Product V1 trusted write seam | `not_reverified_exact_source_path` | Must stay outside Product column semantics; Adobe REST inventory surface exists but exact raw MSI interface retrieval was not re-verified in current tooling. |
| `product-extension-attributes-generic` | Generic `extension_attributes.*` | extension_structure | Product GET may expose module-defined nested structures | Module-defined service contracts vary | Existing owning domain only after classification | None as a generic connector seam | None as a generic connector seam | `real_target_pending` | No generic passthrough is allowed; every nested structure must first be classified to an owner. |
| `product-attribute-metadata-discovery` | Product attribute metadata inventory capability | discovery_metadata | `GET /V1/products/attributes` | Not part of Product mutation | Connector discovery | Existing Adobe discovery capability | Not applicable | `repository_contract_only` | Current discovery seam exists, but completion remains partial because target-dependent inventory still needs real target expansion. |
| `product-service-only-null-frontend-input` | Service-only/system metadata skip rule | discovery_metadata | Attribute metadata discovery | Not applicable | Connector discovery eligibility | Existing discovery normalization and skip rule | Not applicable | `repository_contract_only` | Current skip rule is intentionally narrow and evidence-based; do not broaden to a universal null-frontend-input skip. |

## Cluster notes that are useful but not certifying

- `identity_trust`: frozen around trusted ERL, logical `entity_id`, and exact SKU
  precondition.
- `core_identity` / `core_type` / `lifecycle_projection` / `pricing`: the stable
  ProductInterface surface that can be inventory-tested field by field.
- `eav_*`: target-dependent and must expand from real discovery, not from a universal
  repository fiction.
- `media`, `configurable_structure`, `category_relation`, `availability`:
  legitimate Magento product capabilities, but still blocked by missing or intentionally
  unimplemented connector seams.
- `discovery_metadata`: repository evidence exists today, but real target certification
  still requires a concrete connected Magento inventory run.

## What was preserved from donor `#180`

- official-source inventory mindset;
- cluster taxonomy as a classification layer;
- explicit platform-owner analysis;
- machine-readable adjacent JSON contract;
- mechanical contract testing.

## What was deliberately rejected or superseded from donor `#180`

- the old `17-row` presentation as if it were field-complete certification;
- aggregate rows such as combined `status, visibility`;
- stale repository-base truth;
- stale retrieval-failure text claiming current Adobe/Magento sources were inaccessible;
- any claim that installation-dependent EAV rows are a universal Magento field list;
- any stale status copied forward without recomputing current Receive, Safe Sync,
  readiness, Entity Trust, and support-gating state.
