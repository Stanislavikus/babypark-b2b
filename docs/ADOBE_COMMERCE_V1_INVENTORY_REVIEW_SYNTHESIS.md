# Adobe Commerce / Magento V1 Inventory — Lead Review Synthesis

Status: **SECOND RESEARCH PASS — NOT FROZEN**

Reviewed blind inputs:
- GPT-5.4 completeness/repo-archeology challenge;
- Sonnet semantic/classification challenge;
- current project `[Resolved]` contracts and shipped `MAGENTO_V1_PRODUCT_FIELD_MATRIX.md`;
- current Adobe primary documentation.

This synthesis does not redesign connector architecture. It decides which reviewer findings should change the inventory research package.

## ACCEPT — add or expand inventory

1. **Dynamic EAV value envelope**: `additional_attributes` / `custom_attributes` are containers, not scalar semantic fields. Preserve `attribute_code + value` semantics explicitly under the existing dynamic-attribute family rather than creating a new platform field per container.
2. **Attribute storefront/swatch metadata**: add product-attribute metadata including `is_filterable`, `is_html_allowed_on_front`, `is_wysiwyg_enabled`, `is_used_for_promo_rules`, `used_in_product_listing`, `update_product_preview_image`, `use_product_image_for_swatch`, `swatch_input_type`. Adobe `customAttributeMetadataV2` documents these for both SaaS and PaaS.
3. **Category link object**: add structured `category_id + position` semantics for `extension_attributes.category_links`.
4. **MSI topology**: explicitly inventory `Source`, `Stock`, `StockSourceLink`, `SalesChannel` and source-link `priority`; separate account/topology configuration from per-SKU source quantities and derived salable quantity.
5. **Grouped composition**: add grouped child/member shape including child identity, `qty`, and `position`.
6. **Customizable file option constraints**: add `file_extension`, `image_size_x`, `image_size_y` plus existing option price/price_type/sku identity.
7. **SEO redirect behavior**: add `save_rewrites_history`; keep write-side redirect semantics distinct from the semantic `url_key` value.
8. **Alias coverage already required by shipped matrix**: add explicit `meta_keyword` vs `meta_keywords`, `tax_class_id` vs `tax_class_name`; document `product_websites` vs REST website IDs/assignment as related surface aliases, not automatic equality.
9. **Gift-card amount shape**: record `giftcard_amount` / gift-card preset amounts as repeated/list-shaped values, not one scalar amount.
10. **Nested reference identities**: structured-object `sku` fields used as relation/object anchors must not be promoted as separate canonical Product semantic fields.
11. **Product links**: keep `product_links` as a structured relation capability carrying relation type, target identity and position, not a flat domain value.

## ACCEPT WITH QUALIFICATION

1. **`has_options` / `required_options`**: include as Magento product/custom-option mechanics because they are real observed/system fields and affect option state, but classify conservatively as external/system mechanics pending stronger primary-source write semantics. Do not expose them as ordinary merchant FieldMapping candidates.
2. **RMA / returnability**: replace the guessed generic `returnable` assumption with a sourced Adobe-Commerce-only product RMA eligibility capability. `is_returnable` is a read representation; exact stock Admin REST write attribute identity must not be guessed without verified source/live evidence.
3. **Gift wrapping**: Adobe documents product-level Allow Gift Wrapping and GraphQL `gift_wrapping_available` / `gift_wrapping_price`, but non-message gift options require Adobe Commerce. Replace the guessed generic `gift_wrapping` row with sourced Adobe-Commerce-only product gift-wrap capability/read representations; do not invent an Admin REST write key.
4. **Dynamic-attribute clustering**: GPT suggested a new `dynamic_attribute_values` top-level cluster; Sonnet preferred keeping the current cluster. Lead decision: keep the existing `dynamic_attributes` family and add structured value/schema subfamilies. This preserves transport clarity without artificial cluster proliferation.

## REJECT — conflicts with current authoritative project decisions

### `visibility` -> ordinary `semantic_field`

Rejected.

The shipped Magento matrix proves that current runtime can WRITE `visibility`, but write capability does not imply generic FieldMapping ownership. Current `[Resolved]` Domain Model explicitly classifies Adobe `visibility` with connector-owned operation configuration/metadata alongside `attribute_set_id`, `type_id`, and store-scope execution requirements. Keep `visibility` in connector/channel execution context unless a newer authoritative domain decision changes that boundary.

## Edition/API corrections

- RMA/product return eligibility: **Adobe Commerce only**, not Magento Open Source.
- Product gift wrapping (beyond gift message): **Adobe Commerce only**; gift messages remain available in Magento Open Source.
- `customAttributeMetadataV2`: current Adobe documentation exposes the query for both SaaS and PaaS and includes EAV/storefront/swatch metadata.
- B2B Shared Catalog remains Adobe Commerce B2B; PaaS-only write caveats must remain explicit where Adobe documents them.
- Adobe Commerce SaaS and PaaS/Magento Open Source remain separate API surfaces; Storefront/Catalog Service READ does not prove WRITE.

## Primary-source anchors used in arbitration

- `customAttributeMetadataV2`: https://developer.adobe.com/commerce/webapi/graphql/schema/attributes/queries/custom-attribute-metadata-v2/
- Inventory sources: https://developer.adobe.com/commerce/webapi/rest/inventory/manage-sources
- Inventory stocks: https://developer.adobe.com/commerce/webapi/rest/inventory/manage-stocks
- Stock/source links: https://developer.adobe.com/commerce/webapi/rest/inventory/link-stocks-sources
- Inventory architecture: https://developer.adobe.com/commerce/webapi/rest/inventory/
- Grouped product structure: https://developer.adobe.com/commerce/webapi/graphql/schema/products/interfaces/types/
- Product category links example: https://developer.adobe.com/commerce/webapi/rest/use-rest/retrieve-filtered-responses
- Customizable file option: Adobe GraphQL `CustomizableFileValue` current reference
- Import API: https://developer.adobe.com/commerce/webapi/rest/modules/import/
- RMA configuration: https://experienceleague.adobe.com/en/docs/commerce-admin/stores-sales/order-management/returns/rma-configure
- Product gift options: https://experienceleague.adobe.com/en/docs/commerce-admin/catalog/products/settings/product-gift-options
- Gift-options API edition boundary: https://developer.adobe.com/commerce/webapi/graphql/schema/cart/mutations/set-gift-options

## Next action

Apply the accepted/qualified corrections mechanically to the row-level master CSV, structured-object subfield CSV, cluster summary and source matrix. Then run a final internal consistency pass. Only after that decide whether a third blind reviewer is useful.

## Lead primary-source follow-up refinements

After applying reviewer findings, Lead primary-source verification additionally:
- moved `tax_class_id` / `tax_class_name` to a dedicated Pricing/Tax-owned cluster per the shipped Magento matrix;
- normalized swatches to nested `swatch_data { type, value }` rather than invented flat option fields;
- added current Adobe attribute applicability/search/filter/storefront metadata;
- added SaaS-specific file/image custom-attribute value implementations;
- added explicit read-only URL projections (`url_rewrites`, `canonical_url`, `url_suffix`, deprecated `url_path`);
- added a machine-readable alias matrix for source keys that share semantics but require ID/code/label translation.

These refinements do not alter connector runtime or frozen domain ownership.

## Gemini 3.1 final completeness arbitration

Gemini reviewed corrected HEAD `2d55f5992b90e690a6d9f9b5460ebd3b96c6a901` and returned `REQUIRES ONE MORE CORRECTION PASS`. Lead rechecked material findings against current Adobe primary sources and project `[Resolved]` ownership.

**Accepted:** exact Bundle option/link structures; exact Configurable option/value/child-link structures; Downloadable link/sample subfields; `media_gallery_entries` as structured capability; SaaS file/image as READ-only projections; scoped Gift Card amount READ representation.

**Modified:** Bundle `*_type` / `*_view` values are scalar externally but remain domain-owned Bundle composition values. `[Resolved]` treats Bundle/Kit as a distinct product composition capability, so scalar shape does not make them generic `semantic_field` / FieldMapping candidates. Import keys such as `configurable_variations`, `associated_skus`, and `categories` remain real inventory entries and are related to REST/GraphQL shapes through explicit alias groups rather than deleted as duplicates.

**Rejected:** `tier_price.customer_group_id` for the current Catalog Pricing storage surface. Current Adobe `TierPriceStorageInterface` uses `customer_group`; Import advanced pricing exposes `tier_price_customer_group`. A legacy ID-based endpoint is a different surface and must not be collapsed into this contract.

No new platform persistence/domain entity was introduced by this pass.
