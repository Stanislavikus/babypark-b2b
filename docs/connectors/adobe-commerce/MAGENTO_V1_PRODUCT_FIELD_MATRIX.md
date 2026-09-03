# Magento / Adobe Commerce V1 Product Field Matrix

**Contract version:** `3.0.0`
**Repository base:** `133a31ab056ea0292faee5512d77cef0f3986c59`
**Refresh date:** `2026-08-31`
**Completion state:** `partial_pending_real_target`
**Source manifest:** `docs/connectors/adobe-commerce/magento_v1_product_external_inventory.json`

This markdown is the human-readable audit for the authoritative machine contract in
`magento_v1_product_field_matrix.json` and the adjacent source-derived inventory
manifest.

## Non-negotiable interpretation rules

- Repository external inventory is source-complete for the official Magento/Adobe
  surfaces researched in this contract.
- Certification remains `partial_pending_real_target`.
- Installation-dependent EAV still requires real target expansion.
- Inventory presence does **not** mean current connector support.
- Public support truth remains unchanged: `Adobe Products / Export / Live = false`.
- The accepted runtime seams remain unchanged:
  - `AdobeProductDocumentReader` reuses
    `AdobeProductRemoteStateClient::sendReadOnlyGetWithContext()`
  - trusted simple Product execution consumes
    `AdobeSafeSyncClient::writeSimpleProduct()`
  - trusted Receive remains entity-bound `AdobeSafeSyncClient::readProduct()`
  - configurable child remains fail-closed
  - no duplicate Product GET transport, OAuth signer, or request factory exists
  - no trusted stock `PUT /V1/products/{sku}` consequential writer exists

## Source-derived coverage

The adjacent source manifest now inventories **55** independently auditable official
field/capability bindings.

By source surface family:

- `rest_product`: `12`
- `stable_system_eav`: `14`
- `bulk_import_export`: `6`
- `relations`: `6`
- `media`: `2`
- `pricing`: `3`
- `website_store_scope`: `3`
- `inventory`: `2`
- `configurable`: `3`
- `other_product_types`: `2`
- `dynamic_eav`: `2`

By manifest classification:

- `stable`: `50`
- `conditional`: `1`
- `module_dependent`: `2`
- `operational_only`: `1`
- `target_dependent`: `1`

The matrix resolves those **55** source items into **39** explicit current-state
outcomes. Completeness is no longer proven by a magic stable-field count.

## Mechanically enforced dimensions

`MagentoV1ProductFieldMatrixTest` now mechanically enforces:

- source manifest validity for every inventory item
- unique source inventory IDs
- exact source surface family coverage
- source-to-matrix coverage for every manifest item
- matrix-to-source provenance for every official row
- alias separation for:
  - `type_id` vs `product_type`
  - `status` vs `product_online`
  - `meta_keyword` vs `meta_keywords`
  - `tax_class_id` vs `tax_class_name`
  - `attribute_set_id` vs `attribute_set_code`
- explicit target-dependent incompleteness for dynamic EAV

## Current truth corrections

The matrix/manifest pair now records the final Slice 2 / Slice 3 runtime truth:

- `rest-product-name` reflects internal trusted simple Safe Sync WRITE consumption.
- `rest-product-price` reflects internal trusted simple Safe Sync WRITE consumption.
- `rest-product-status` reflects internal trusted simple Safe Sync WRITE consumption.
- `rest-product-visibility` reflects internal trusted simple Safe Sync WRITE
  consumption and no longer claims universal pre-write blocking.
- `rest-product-type-id` reflects the existing reusable full Product document READ
  through `AdobeProductDocumentReader`.

## Exact semantic corrections

`created_at`

- Exact binding remains `product.created_at`.
- Official Adobe docs record that the value is automatically generated when the
  product is created, but can be edited later on some external admin/import
  contexts.
- Current connector support remains READ-only evidence. It does **not** write
  `created_at`.

`meta keyword`

- Exact REST/EAV binding remains `product.custom_attributes.meta_keyword`.
- Exact bulk binding remains `meta_keywords`.
- These bindings are now mechanically separated and can no longer collapse into an
  invented plural REST path.

`tax class`

- Exact REST/EAV identifier binding remains `product.custom_attributes.tax_class_id`.
- Exact bulk binding remains `tax_class_name`.
- These bindings are now mechanically separated and remain owned by Pricing / tax
  configuration semantics.

`extension_attributes`

- `extension_attributes` is tracked as a stable REST container.
- Its children remain module-, edition-, or installation-dependent and require
  owner-specific rows.
- It is **not** treated as a universal scalar Product field.

Structured fields

- `product_links`
- `options`
- `media_gallery_entries`
- `tier_prices`

These remain structured relation/media/pricing capabilities with stable child
semantics, not scalar Product-field rows.

## Dynamic EAV remains target-dependent

There is still no universal installation-independent Magento custom EAV list.

Repository contract:

- inventories one explicit target-dependent family row
- keeps it incomplete on purpose
- requires real target discovery to expand that family into one row per actually
  discovered external field before field-by-field certification

## Surface highlights kept explicit

- website/store scope stays explicit through `store_view_code`, `product_websites`,
  and `website_id`
- category assignment and rewrite-side-effect bindings stay explicit through
  `categories` and `save_rewrites_history`
- bulk media role bindings stay explicit through `base_image`, `small_image`,
  `thumbnail_image`, and `additional_images` families
- MAP/MSRP stays explicit as conditional pricing surface, not omitted
- configurable bulk bindings stay explicit through `configurable_variations`,
  `configurable_variation_labels`, and `_super_*`
- grouped/bundle product types stay explicitly inventoried and classified
- inventory/availability stays owned by Availability rather than Product-column
  semantics

## Current trusted simple Safe Sync request-field allowlist

The current trusted simple Product Safe Sync request contract remains exactly:

- `expected_sku`
- `name`
- `status`
- `visibility`
- `price`
- `mapped_attributes`

Current row-level matrix truth remains aligned with that contract:

- `expected_sku` is a trusted precondition only and does not create a writable field
  row
- `name`, `price`, `status`, and `visibility` are the only current rows whose
  `safe_sync_write_state` is `SUPPORTED`
- `mapped_attributes` remains a bounded envelope and does **not** imply universal
  WRITE certification for every attribute row

## Current discovery inputs currently normalized in repository code

Current `AdobePaaSAttributeNormalizer` still accepts exactly:

`text`, `textarea`, `texteditor`, `date`, `datetime`, `boolean`, `select`,
`multiselect`, `price`, `media_image`, `gallery`, `weight`

Any other `frontend_input` remains fail-closed until explicitly verified and mapped.

## What this correction deliberately keeps unchanged

- no runtime architecture redesign
- no Product core expansion merely because Magento exposes a field
- no support flip
- no Safe Sync module rewrite
- no real Magento write
- no deploy

---

## Current runtime owner vs newly approved target architecture
[Recorded — 2026-09-03]

This matrix is a **current runtime / audit truth** snapshot. It must
continue to be read that way until a separate runtime task actually
changes the seams below.

### Current runtime owner (unchanged by this record)

- **`AdobeProductDocumentReader` reuses
  `AdobeProductRemoteStateClient::sendReadOnlyGetWithContext()`** for
  trusted full Product document READ.
- **Trusted simple Product execution** consumes
  `AdobeSafeSyncClient::writeSimpleProduct(...)`.
- **Trusted Receive** remains entity-bound
  `AdobeSafeSyncClient::readProduct(...)`.
- The configurable child path remains fail-closed.
- No duplicate Product GET transport, OAuth signer, or request factory
  exists in the standard seam.
- No trusted stock `PUT /V1/products/{sku}` consequential writer
  exists.

These runtime seams are not rewritten by this record. The matrix still
correctly names them as the **current** owner of the relevant
operations.

### Newly approved target architecture (direction only — not runtime)

After the Post-#168 / Post-D6 rebaseline recorded in
`docs/03-DOMAIN_MODEL.md` → **Magento V1 Moduleless-by-default
Stop-and-Amend**, the **approved product direction** for the standard
merchant path is:

- **Moduleless by default**: standard Magento V1 connector MUST NOT
  require the first-party `B2BPlatform_MagentoSafeSync` Composer
  component for connection, READ, field discovery, mapping, Preview,
  or normal Magento V1 operation once the stock public REST path is
  separately certified for the relevant operation.
- **Stock public REST as default runtime**: the standard path is
  expected to consume vendor stock public REST for connection, READ,
  field discovery, mapping, and Preview.
- **Safe Sync reclassified as optional Enhanced Safety candidate**:
  the first-party component remains a legitimate, implementation-true
  primitive, but it is no longer a basic connector prerequisite.

### Narrow distinction this record preserves

This record does **not** claim that:

- a stock public REST writer is already in production;
- stock public READ has been Tier-1 certified;
- the standard path is already running moduleless in production;
- any row's `safe_sync_write_state` is anything other than what
  the matrix already records;
- the first-party Composer envelope is widened.

This record also does **not** introduce new support rows. The matrix
itself remains the audit truth for what the **current** runtime
actually does. The Post-#168 rebaseline only rebaselines the
**product direction** for the future standard path; it does not
forbid the matrix from continuing to describe the **current** runtime
truth it audits.

Future field-matrix revisions that move a row from
"current runtime = Safe Sync consumption" to "current runtime = stock
public REST" must be backed by the separately-designed runtime
migration that actually changes the seam. Until that migration ships,
the matrix must continue to record the current runtime owner for that
row.
