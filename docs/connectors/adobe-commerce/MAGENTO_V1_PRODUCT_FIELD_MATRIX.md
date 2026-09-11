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
    `AdobeProductStockSimpleWriteExecutor` over stock `PUT /V1/products/{sku}`; Safe Sync remains optional Enhanced Safety
- trusted Receive uses `AdobeProductDocumentReader` (stock `GET /V1/products/{sku}`)
  - configurable child remains fail-closed
  - no duplicate Product GET transport, OAuth signer, or request factory exists
  - trusted stock `PUT /V1/products/{sku}` consequential writer exists for merchant-confirmed simple Product updates; public support remains false

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

- `rest-product-name`, `rest-product-price`, `rest-product-status`, and `rest-product-visibility` are real-target WRITE + `AdobeProductDocumentReader` READ + restore verified through the moduleless stock runtime on 2026-09-11.
- Certified cycles: `name` = `Test Product -> Test Product [B2B Cert] -> Test Product`; `price` = `150 -> 151 -> 150`; `status` = `1 -> 2 -> 1`; `visibility` = `4 -> 2 -> 4`.
- Every cycle preserved Magento logical identity `entity_id=1`, exact SKU `1234567890`, simple type, attribute set, and all non-target core fields.
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

## Optional Enhanced Safety — Safe Sync request-field allowlist

The optional Safe Sync simple Product request contract remains exactly:

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

## Real-target certification evidence — 2026-09-11

At implementation commit `10d05db59b857ba51a4851338cd6186c969c19c9`, the standard moduleless trusted Simple runtime was exercised against the certification Magento target using the merchant-confirmed `Test Product` (`SKU 1234567890`, trusted logical `entity_id = 1`).

- baseline stock GET: `price=150`, exact SKU, `type_id=simple`, `attribute_set_id=9`, status `1`, visibility `4`;
- production `AdobeProductSimpleCommandExecutor` changed price `150 -> 151`;
- result: `KnownApplied`, `stock_write_verified`, one consequential PUT and one reconciliation GET;
- independent stock GET confirmed `price=151` with the same entity id/SKU/type;
- the same production runtime restored `151 -> 150`;
- result: `KnownApplied`, `stock_write_verified`; independent GET confirmed the original state restored.

This certifies the **moduleless trusted Simple stock WRITE/verify/restore core and the base-price field on this target**. It does not flip public Live support and does not certify every field, dynamic EAV, configurable, media, or custom-attribute clear semantics.

A read-only real-target probe for a deliberately absent SKU returned HTTP 404 with a `message` key only and no structured `parameters`; the current classifier therefore conservatively returns `untrusted_or_failed`. The synthetic structured trusted-missing fixture is not treated as real-target evidence.

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

## Moduleless runtime migration and real-target certification record
[Direction recorded — 2026-09-03; runtime migrated and core verified — 2026-09-11]

This matrix is a **current runtime / audit truth** snapshot. The historical 2026-09-03
moduleless-by-default rebaseline is now implemented for trusted Simple Product WRITE.

### Current runtime owner

- `AdobeProductDocumentReader` reuses
  `AdobeProductRemoteStateClient::sendReadOnlyGetWithContext()` for trusted full Product READ.
- Trusted Simple Product execution uses
  `AdobeProductSimpleCommandExecutor -> AdobeProductStockSimpleWriteExecutor` over stock
  `GET /V1/products/{sku}` + at most one `PUT /V1/products/{sku}` + read-only verification.
- Merchant-confirmed ERL identity is mandatory; the fresh pre-read must prove exact SKU,
  `type_id = simple`, and Magento entity `id` equal to the trusted discriminator before PUT.
- POST/create and blind consequential PUT retry are absent from the standard Simple path.
- Trusted Receive continues to use `AdobeProductDocumentReader` over stock Product GET.
- The configurable child path remains fail-closed for this standard Simple migration.
- Safe Sync remains implemented only as an optional Enhanced Safety primitive and is not the
  standard-path prerequisite.
- Mapping remains a **platform-owned workflow** over persisted and normalised discovered metadata;
  Mapping does **not** itself consume vendor stock REST as a runtime.
- Preview remains a **platform-owned orchestration** under the existing Preview contracts; bounded
  remote reads are allowed only where those contracts require them, and Preview performs no
  consequential mutation.

### Real-target evidence

On 2026-09-11 the certification target verified the core trusted Simple path with
`Test Product` / SKU `1234567890` / Magento entity `id = 1`:

- baseline price `150`;
- controlled stock WRITE `150 -> 151`;
- result `KnownApplied / stock_write_verified`;
- one consequential PUT and one read-only verification/reconciliation GET;
- independent GET confirmed price `151` with unchanged identity/type/status/visibility/attribute set;
- controlled restore `151 -> 150`;
- restore again returned `KnownApplied / stock_write_verified`;
- final independent GET confirmed the original price `150` and unchanged identity.

A separate read-only missing-SKU probe returned HTTP 404 with a message-only body and no
structured `parameters`. The classifier therefore remained conservative `untrusted_or_failed`;
this target did not prove the synthetic `TrustedKnownMissing` fixture shape.

### Scope that remains pending

This certification does **not** introduce public Live support and does not certify every matrix
row. `Adobe Products / Export / Live = false` remains authoritative. The following still require
separate evidence where applicable:

- field-by-field Simple Product WRITE validation beyond the verified base-price cycle;
- installation-dependent mapped EAV values and explicit clear semantics;
- configurable Product mutation;
- media mutation;
- remaining product-type and connector-owned surfaces.

The matrix's `safe_sync_write_state` continues to describe the optional Safe Sync primitive where
it exists; it is not the owner of the standard Simple runtime anymore.
