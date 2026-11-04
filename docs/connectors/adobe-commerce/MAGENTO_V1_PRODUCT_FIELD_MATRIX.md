# Magento / Adobe Commerce V1 Product Field Matrix

**Contract version:** `3.0.0`
**Repository base:** `75465daf210cc019377282f4015fae2153b21e07`
**Refresh date:** `2026-09-19`
**Completion state:** `export_live_supported_bounded_v1`
**Source manifest:** `docs/connectors/adobe-commerce/magento_v1_product_external_inventory.json`

This markdown is the human-readable audit for the authoritative machine contract in
`magento_v1_product_field_matrix.json` and the adjacent source-derived inventory
manifest.

**Per-field real-target progress is tracked separately and must not be reconstructed from these aggregated rows.** Read `MAGENTO_V1_FIELD_PROGRESS.md` and `magento_v1_real_target_field_progress_2026_09_12.csv` for the current 102-field discovery snapshot, canonical/Magento-standard/workspace-custom bucket counts, certification status, evidence, and exact resume action.

## Non-negotiable interpretation rules

- Repository external inventory is source-complete for the official Magento/Adobe
  surfaces researched in this contract.
- Per-field certification remains partial for installation-dependent and intentionally unadvertised behavior classes; this does not reopen the certified bounded Export Live capability.
- Installation-dependent EAV still requires real target expansion.
- Inventory presence does **not** mean current connector support.
- **[Resolved 2026-09-19] Public support truth:** `Adobe Products / Export / Preview = true`; `Adobe Products / Export / Live = true`; `Adobe Products / Import / Live = false`. Truth-flip evidence: `magento_v1_products_export_live_certification_2026_09_19.json`.
- The accepted runtime seams remain unchanged:
  - `AdobeProductDocumentReader` reuses
    `AdobeProductRemoteStateClient::sendReadOnlyGetWithContext()`
  - trusted simple Product execution consumes
    `AdobeProductStockSimpleWriteExecutor` over stock `PUT /V1/products/{sku}`; Safe Sync remains optional Enhanced Safety
- trusted Receive uses `AdobeProductDocumentReader` (stock `GET /V1/products/{sku}`)
  - trusted existing-family configurable children use the stock Simple GET→PUT→GET writer only when fresh Product GET proves media-role label materialization safety; otherwise they fail closed before PUT
  - no duplicate Product GET transport, OAuth signer, or request factory exists
  - trusted stock `PUT /V1/products/{sku}` consequential writer is public for the certified merchant-confirmed Export Live V1 scope; Product CREATE remains unsupported and Import Live remains false

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
- Target-specific field evidence is recorded in `magento_v1_real_target_field_certification_2026_09_11.json`: 38 fields are verified (4 core + 34 present scalar/select custom attributes), each through mutation, `AdobeProductDocumentReader` observation, and restore. Eight present custom attributes are intentionally excluded from generic scalar certification because they belong to relation, media, system-owned, or URL-rewrite semantics.

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
- keeps the family incomplete on purpose
- records that this certification target has already been expanded and 24 dynamic child attributes were write/read/restore verified
- still requires each future Magento installation to expand and certify its own discovered child set rather than inheriting this target's list

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

The subsequent field-by-field campaign certifies **all four current core Simple fields plus 34 present scalar/select custom attributes on this target** through the same production stock writer, `AdobeProductDocumentReader` observation, and exact restore. The target ledger is `docs/connectors/adobe-commerce/magento_v1_real_target_field_certification_2026_09_11.json`. After the EAV campaign the full 42-custom-attribute baseline and final snapshot were identical (`42 -> 42`, diff count `0`) and the core Product state was also restored exactly. **At that certification stage** public Live support was not flipped and configurable, relation, URL-rewrite side effects, system-owned flags, and custom-attribute clear semantics still required separate work; the later 2026-09-19 truth-flip closure supersedes only the support-status part of that historical statement.

A follow-on P-03 campaign on 2026-09-18 certifies clear semantics by **behavior tuple, not field code**. Optional store `text`, `textarea`, and `date` use an empty-string payload and require fresh GET absence; optional global `select` uses `null` and requires fresh GET absence. Required fields and all unproved type/scope combinations remain fail-closed. The date probe additionally proved that `null` may return HTTP 200 without clearing the value. `AdobeProductAttributeClassifier v2` exposes these states through behavior-signature `clear_semantics`, so discovery/UI/AI can reason about the same capability contract as the writer. Evidence: `docs/connectors/adobe-commerce/magento_v1_custom_attribute_clear_certification_2026_09_18.json`.

Media was then certified separately through the stock media surface. Real target gallery entry `id=1` accepted a metadata-only PUT changing `label: null -> [B2B Media Cert]`, reconciliation returned `KnownApplied / media_put_reconciled`, and gallery metadata was returned to `null` with the same content SHA-256 prefix `337699db3f3e` and roles `image`, `small_image`, `thumbnail`, plus preserved unmanaged `swatch_image`. Full Product verification then exposed a Magento side effect: `image_label`, `small_image_label`, and `thumbnail_label` had been materialized with the certification text even though gallery `label` was already `null`. A default-store media PUT with `label=""` removed those role-label EAV projections while Magento normalized gallery label back to `null`; the final Product returned to the original 42 custom attributes with core/media state intact. Request serialization now preserves this default-store reset rule, while non-default store-view inheritance/explicit-empty semantics remain pending. This proves default-store metadata mutation/reconciliation and full side-effect restore for the existing entry; it does not certify media create/delete, non-default store-view label clearing, or connector ownership of `swatch_image`.

A read-only real-target probe for a deliberately absent SKU returned HTTP 404 with a `message` key only and no structured `parameters`; the current classifier therefore conservatively returns `untrusted_or_failed`. The synthetic structured trusted-missing fixture is not treated as real-target evidence.

## Current discovery inputs currently normalized in repository code

Current `AdobePaaSAttributeNormalizer` still accepts exactly:

`text`, `textarea`, `texteditor`, `date`, `datetime`, `boolean`, `select`,
`multiselect`, `price`, `media_image`, `gallery`, `weight`

Any other `frontend_input` remains fail-closed until explicitly verified and mapped.

## What this correction deliberately keeps unchanged

- no runtime architecture redesign
- no Product core expansion merely because Magento exposes a field
- no support flip **as a side effect of that historical correction alone**
- no Safe Sync module rewrite
- no public support flip or deployment **from that historical correction alone**
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
- Existing-family configurable children now consume the same stock Simple GET→PUT→GET writer under MerchantConfirmed ERL identity, preserve the fresh remote child name, and require fresh media-role label materialization-safety evidence before PUT; unsafe or unknown media state fails closed with zero consequential writes. Before any child HTTP, the configurable coordinator preflights the trusted parent discriminator plus fresh parent identity/type; parent drift requiring PUT is also gated by media-role label materialization safety, and orphaned role-label projections are classified unsafe.
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
- follow-on P-03 clear certification on the same trusted Simple Product now admits only behavior tuples proven on real target: optional store `text` and `textarea` clear with `""`, and optional global `select` clear with `null`; every successful clear requires fresh GET absence, while required/unknown/unsupported type-scope tuples remain zero-write fail-closed. Evidence: `docs/connectors/adobe-commerce/magento_v1_custom_attribute_clear_certification_2026_09_18.json`.

### Existing-family Configurable linked UPDATE certification — 2026-09-18

PR #226 real-target certification proved the existing-family Configurable core UPDATE path without Product CREATE:

- trusted parent `J-Fl-DUOs-01` / logical `entity_id=6` completed a controlled name PUT and exact restore through `AdobeConfigurableParentCommandExecutor`; options and child links remained unchanged;
- certification discovered that an ordinary default-store Product PUT on Junama children with non-empty gallery labels but absent `image_label` / `small_image_label` / `thumbnail_label` projections materialized those projections as a side effect; the target was restored exactly through the already-certified P-09 default-store media-label reset;
- runtime commit `d6bb5f3d18277fe3ec2086e707fc1ffb4bd3b515` therefore makes Configurable child execution preserve the fresh remote child name and fail closed before PUT unless the fresh Product GET proves every assigned image/small-image/thumbnail gallery label is already represented by the equal role-label projection (or both are absent); standalone Simple semantics are unchanged;
- the negative Junama rerun returned `KnownNotApplied / configurable_child_media_role_label_side_effect_not_safe` with zero consequential writes and unchanged family state;
- positive family `524000027bbg` then completed two child price writes (`20700→20701`, `22000→22001`) with `KnownApplied / stock_write_verified`, one PUT plus one reconciliation GET per child; parent/options/links were no-op, and both children were restored through the same stock writer;
- final independent default-store and `all` reads matched the pre-write raw baselines except Magento-managed `updated_at`; merchant child names, entity ids, option values `63/79`, media role-label state, and links were preserved.

Durable evidence: `docs/connectors/adobe-commerce/magento_v1_configurable_linked_update_certification_2026_09_18.json`. At the close of that 2026-09-18 slice public Adobe Products / Export / Live support was still false; the 2026-09-19 bounded E2E truth-flip evidence supersedes that historical status.

Follow-on Configurable structure evidence on the same date certifies the **existing-option UPDATE-only** seam against family `524000027bbg`: validation setup changed only option `id=3` position `0→1`; the production coordinator restored `1→0` through exactly one option PUT plus one reconciliation GET and returned `SYNCHRONIZED`; independent GET proved the exact option id/attribute/label/value set and child links returned to baseline. Missing option CREATE remains fail-closed. Destructive option-value removal is not exposed; the standard existing-option path preserves remote values absent from the active desired set instead of deleting them. Durable evidence: `docs/connectors/adobe-commerce/magento_v1_configurable_structure_certification_2026_09_18.json`.

The same structure campaign then certified **trusted desired-child relink**. Validation-only setup removed `524000027bbg-1`, which reduced remote option values from `[63,79]` to `[79]`. Production runtime admitted the repair only after read-only option preflight and fresh MerchantConfirmed parent/child identity checks, issued exactly one child-link POST plus one reconciliation GET, then re-read and restored semantic option state through one existing-option PUT plus one reconciliation GET. Final links and option semantics matched baseline. Magento rebuilt only its provider-generated configurable option row id (`8→9`); runtime therefore treats that id as a fresh remote handle, never platform identity. Remote unlink/removal is not exposed as a production capability.

The final structure slice certified **trusted inactive linked-child lifecycle** on `524000027bbg-Чорний` / logical `entity_id=13` through the production coordinator using the real active-only execution shape: desired configurable values contained only active-child value `63`, while remote option values remained `[63,79]`. The coordinator preserved remote value `79` with `KnownApplied / configurable_option_remote_values_preserved` and zero option PUT, kept both child links intact, then fresh identity/type/link/media-safety checks admitted exactly one status PUT `1→2` plus one reconciliation GET (`KnownApplied / inactive_linked_child_disabled`). The fixture was then returned to active and the already-certified configurable-child core writer restored `2→1` with `KnownApplied / stock_write_verified`, one PUT plus one GET. Independent final read proved entity id, SKU/type, price `22000`, media roles/gallery, both links and option semantics exactly restored; no emergency restore was required.


Post-Ready review did not broaden the certified write surface. It added fail-closed parent preflight before child HTTP, applied the observed media-label materialization guard to parent PUT admission, and rejected orphaned role-label projections. These corrections only remove unsafe consequential attempts; the real-target mutation/restore evidence above remains the certification basis.

A separate read-only missing-SKU probe returned HTTP 404 with a message-only body and no
structured `parameters`. The classifier therefore remained conservative `untrusted_or_failed`;
this target did not prove the synthetic `TrustedKnownMissing` fixture shape.

### Scope that remains pending after the 2026-09-19 Export Live truth flip

The bounded standard Magento V1 capability is now public for `Adobe Products / Export / Live = true`, backed by `magento_v1_products_export_live_certification_2026_09_19.json`. This does **not** mean every inventory row is writable or that every deferred behavior has been promoted into the advertised V1 surface. Separate evidence remains required where applicable for installation-dependent EAV behavior outside the certified tuples, direct `swatch_image` ownership, non-default Store View media-label clear/inheritance behavior, negative-path permission-denial target evidence, and future product-type/surface expansion. Adobe Products / Import / Live remains false under the separate Receive contract.

The matrix's `safe_sync_write_state` continues to describe the optional Safe Sync primitive where
it exists; it is not the owner of the standard moduleless Export Live runtime.
