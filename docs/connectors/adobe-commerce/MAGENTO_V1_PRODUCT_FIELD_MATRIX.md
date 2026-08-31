# Magento / Adobe Commerce V1 Product Field Matrix

**Contract version:** `2.1.0`
**Repository base:** `133a31ab056ea0292faee5512d77cef0f3986c59`
**Refresh date:** `2026-08-31`
**Completion state:** `partial_pending_real_target`

This is the current-base Magento V1 Product field and capability inventory for the
canonical campaign branch.

The adjacent JSON contract is authoritative. This markdown is the human-readable audit.

## Non-negotiable interpretation rules

- Every row below is an individual field or capability outcome.
- Cluster summaries are classification only; they are **not** field-complete certification.
- Installation-dependent rows do **not** claim a universal Magento custom-attribute list.
- A real connected target must still expand the installation-dependent family into one
  row per discovered field before field-by-field certification.
- Public support truth remains unchanged: `Adobe Products / Export / Live = false`.
- Trusted Receive identity remains unchanged: trusted `ExternalRecordLink` plus logical
  `entity_id` plus exact trusted SKU precondition.
- The accepted runtime seams remain unchanged:
  - `AdobeProductDocumentReader` reuses
    `AdobeProductRemoteStateClient::sendReadOnlyGetWithContext()`
  - trusted simple Product execution consumes
    `AdobeSafeSyncClient::writeSimpleProduct()`
  - no duplicate Product GET transport/client/signing stack exists
  - no trusted SKU-addressed consequential Product writer exists

## Current primary-source refresh

| ID | Source | Current refresh outcome |
|---|---|---|
| `S1` | Adobe Commerce catalog product REST reference | Refreshed successfully on `2026-08-31`. |
| `S2` | Adobe Commerce `2.4.9` release notes | Refreshed successfully on `2026-08-31`; media-gallery store-view inheritance behavior is current. |
| `S3` | Magento `Catalog\Api\Data\ProductInterface` | Current `2.4-develop` contract reviewed. |
| `S4` | Magento `Catalog\Api\ProductRepositoryInterface` | Current `2.4-develop` contract reviewed. |
| `S5` | Magento `Catalog\Api\Data\ProductAttributeInterface` | Current `2.4-develop` contract reviewed. |
| `S6` | Adobe Commerce product data attributes reference | Current official export/import attribute surface reviewed. |
| `S7` | Adobe Commerce simple product docs | Current official admin/product semantics reviewed. |
| `S8` | Adobe Commerce special prices docs | Current special-price and date-range semantics reviewed. |
| `S9` | Magento `ConfigurableProduct\Api\LinkManagementInterface` | Current `2.4-develop` configurable child-relation contract reviewed. |
| `S10` | Magento `ConfigurableProduct\Api\OptionRepositoryInterface` | Current `2.4-develop` configurable option contract reviewed. |
| `S11` | Magento `Catalog\Api\ProductAttributeMediaGalleryManagementInterface` | Current `2.4-develop` media-gallery management contract reviewed. |
| `S12` | Adobe Commerce inventory source-items guide | Current MSI source-item REST surface reviewed; exact raw source-path proof remains explicitly bounded. |

## Protocol-required inventory dimensions now enforced mechanically

The JSON contract and `MagentoV1ProductFieldMatrixTest` now require every row to carry:

- external entity/object
- external field/key/path
- type and shape
- required semantics
- null semantics
- clear semantics
- external READ contract
- external WRITE contract
- external restrictions / system ownership
- version / edition / API scope
- cluster
- platform domain owner
- platform representation / binding
- connector READ seam
- connector WRITE seam
- explicit current connector READ / WRITE state
- explicit current Safe Sync READ / WRITE state
- real-validation state
- result / blocker

The same test also guards the verified stable official inventory count so a known
stable field cannot silently disappear from the matrix.

## Stable official Product surface

Stable official Product-field rows currently inventoried: **28**

### Stable Product document fields

| ID | External key/path | Type / shape | Current connector truth | Current result / blocker |
|---|---|---|---|---|
| `product-id` | `product.id` | positive integer | READ supported via `AdobeProductDocumentReader`; trusted Safe Sync read also returns logical id | trusted identity authority remains ERL + entity-bound Safe Sync |
| `product-sku` | `product.sku` | string | READ supported; trusted WRITE does **not** mutate SKU | Safe Sync keeps `expected_sku` as precondition only |
| `product-name` | `product.name` | string | READ supported; trusted simple WRITE consumed internally | real-target certification and public support still pending |
| `product-attribute-set-id` | `product.attribute_set_id` | integer id | READ supported | current trusted entity-bound update path does not mutate attribute set |
| `product-price` | `product.price` | decimal | READ supported; trusted simple base-price WRITE consumed internally | advanced pricing remains separate and uncertified |
| `product-status` | `product.status` | enum/int | READ supported; trusted simple WRITE consumed internally | configurable completion, real-target proof, and public support still pending |
| `product-visibility` | `product.visibility` | enum/int | READ supported; trusted simple WRITE consumed internally | current runtime no longer truthfully reads as “universally blocked before every write” |
| `product-type-id` | `product.type_id` | enum/string | READ supported via `AdobeProductDocumentReader`; trusted Safe Sync read also returns `type_id` | reusable full Product document READ now exists |
| `product-created-at` | `product.created_at` | datetime string | READ supported | read-only system metadata |
| `product-updated-at` | `product.updated_at` | datetime string | READ supported | read-only system metadata |
| `product-weight` | `product.weight` | decimal | READ supported | current trusted simple write allowlist does not carry `weight` |
| `product-extension-attributes` | `product.extension_attributes` | nested object | READ supported as evidence only | every nested structure still needs owner-specific classification |
| `product-product-links` | `product.product_links` | relation array | READ supported | no current relation write runtime for related/up-sell/cross-sell transport |
| `product-options` | `product.options` | custom-option array | READ supported | no current governed Product Option owner/runtime for Adobe Product V1 |
| `product-media-gallery-entries` | `product.media_gallery_entries` | media-entry array | READ supported; internal media WRITE runtime exists | entity-bound Safe Sync media completion and real-target certification remain pending |
| `product-tier-prices` | `product.tier_prices` | tier-price array | READ supported | current trusted simple write only covers one base price value |
| `product-custom-attributes` | `product.custom_attributes` | attribute array | READ supported; scalar-string mapped write path exists only partially | contained fields still require row-by-row certification |

### Stable official attribute rows

| ID | External key/path | Current connector truth | Current result / blocker |
|---|---|---|---|
| `attribute-description` | `product.custom_attributes.description` | READ supported; WRITE only candidate via scalar `mapped_attributes` | row-level certification and store-scope proof remain pending |
| `attribute-short-description` | `product.custom_attributes.short_description` | READ supported; WRITE only candidate via scalar `mapped_attributes` | row-level certification remains pending |
| `attribute-special-price` | `product.custom_attributes.special_price` | READ supported | current trusted simple WRITE does not implement advanced pricing |
| `attribute-special-price-from-date` | `product.custom_attributes.special_price_from_date` | READ supported | no advanced-pricing schedule writer exists |
| `attribute-special-price-to-date` | `product.custom_attributes.special_price_to_date` | READ supported | no advanced-pricing schedule writer exists |
| `attribute-url-key` | `product.custom_attributes.url_key` | READ supported; WRITE only candidate via scalar `mapped_attributes` | rewrite-history semantics remain uncertified |
| `attribute-meta-title` | `product.custom_attributes.meta_title` | READ supported; WRITE only candidate via scalar `mapped_attributes` | row-level certification remains pending |
| `attribute-meta-keywords` | `product.custom_attributes.meta_keywords` | READ supported; WRITE only candidate via scalar `mapped_attributes` | singular/plural surface-key variance is now explicit and cannot disappear silently |
| `attribute-meta-description` | `product.custom_attributes.meta_description` | READ supported; WRITE only candidate via scalar `mapped_attributes` | row-level certification remains pending |
| `attribute-tax-class` | `product.custom_attributes.tax_class` | READ supported at semantics level | target class binding remains target-dependent and uncertified |
| `attribute-cost` | `product.custom_attributes.cost` | READ supported as stable attribute code | current connector does not export internal cost data |

## Stable capability families

| ID | Surface | Current connector truth | Current result / blocker |
|---|---|---|---|
| `product-attribute-metadata-discovery` | `GET /V1/products/attributes` | implemented | real target still must expand target-dependent rows one field at a time |
| `product-configurable-options` | `V1/configurable-products/{sku}/options` | internal runtime present | trusted entity-bound Safe Sync configurable completion still pending |
| `product-configurable-children` | `V1/configurable-products/{sku}/children` | internal runtime present | `executeSimpleChild()` remains fail-closed for trusted Safe Sync consumption |
| `product-media-gallery-management` | `V1/products/{sku}/media` | internal runtime present | trusted entity-bound Safe Sync media completion still pending |
| `product-category-links` | category assignment surfaces | no current Adobe Product V1 runtime | category owner/runtime remains absent |
| `product-inventory-source-items` | `V1/inventory/source-items` | no current Adobe Product V1 runtime | availability remains outside current Product V1 scope/runtime |

## Installation-dependent family that remains target-dependent

| ID | Why it remains target-dependent | Current certification state |
|---|---|---|
| `product-installation-dependent-discovered-attributes` | Magento has no universal target-independent custom-attribute list. A real connected target must expand discovered attributes into one row per actual field before field-by-field certification. Repository fixtures remain evidence only, not universal schema. | `pending_real_target_expansion` |

## Current Slice 2 / Slice 3 truth now reflected

- `product-name` no longer claims WRITE is “future” or “pending executor rewiring”.
- `product-type-id` no longer claims the reusable full Product document reader is missing.
- `product-visibility` no longer claims the runtime universally blocks before every consequential write.
- The matrix keeps the accepted boundary:
  - trusted Receive remains entity-bound Safe Sync READ
  - trusted simple WRITE remains one entity-bound Safe Sync write after all gates
  - configurable/media/public Live completion remain pending
  - `Adobe Products / Export / Live` remains `false`

## Current trusted simple Safe Sync request-field allowlist

The current trusted simple Product Safe Sync request contract remains exactly:

- `expected_sku`
- `name`
- `status`
- `visibility`
- `price`
- `mapped_attributes`

Current row-level matrix truth remains aligned with that request contract:

- `expected_sku` is a trusted precondition only and does not create a writable field row.
- `name`, `status`, `visibility`, and `price` are the only rows whose current
  `safe_sync_write_state` is `SUPPORTED`.
- `mapped_attributes` remains an envelope for bounded scalar custom-attribute writes; it
  does not justify claiming every discovered attribute is already certified for WRITE.

## Current discovery inputs currently normalized in repository code

Current `AdobePaaSAttributeNormalizer` still accepts exactly:

`text`, `textarea`, `texteditor`, `date`, `datetime`, `boolean`, `select`,
`multiselect`, `price`, `media_image`, `gallery`, `weight`

Any other `frontend_input` remains fail-closed until explicitly verified and mapped.

## What this correction deliberately keeps unchanged

- no duplicate Product GET transport or OAuth stack
- no change to Entity Trust identity authority
- no change to Safe Sync handshake/readiness boundary from PR `#184`
- no real Magento WRITE execution in this correction pass
- no deploy
