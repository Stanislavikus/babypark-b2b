# Adobe Commerce / Magento V1 Product & Capability Inventory — Initial Research

Status: **INITIAL_RESEARCH — NOT FROZEN**

This document is a review artifact for building the platform-wide Adobe Commerce / Magento product connector inventory. It deliberately does **not** use the current smoke store as the definition of Magento scope. A connected store is only live evidence for later validation.

## Goal

Build the broadest defensible Adobe Commerce / Magento product-data and product-capability inventory from authoritative Adobe sources, classify it by transport/domain mechanics, then subject the result to an independent adversarial review before implementation/certification.

This follows `09-CONNECTOR_DELIVERY_PROTOCOL.md`: external inventory first, then classify, map to platform owners/representations, prove representative real READ/WRITE by cluster, fix blockers, then certify every field/capability.

## Product/edition boundary

“Adobe Commerce / Magento” is not one immutable API surface.

The inventory must preserve at least these targets separately:

1. **Magento Open Source / Adobe Commerce PaaS** — broad Admin REST framework and core GraphQL. The current Adobe GraphQL reference exposes PaaS 2.4.9 as the newest reference and keeps older compatibility references.
2. **Adobe Commerce PaaS with B2B modules** — adds Shared Catalog and other edition-specific capabilities that must not be assumed on Magento Open Source.
3. **Adobe Commerce as a Cloud Service (SaaS)** — smaller REST subset, IMS authentication, different REST URL/store-scope conventions, and a SaaS GraphQL schema where core `products`/`categories` are replaced by Catalog Service equivalents.
4. **Catalog Service / Storefront Services** — read-optimized SaaS schemas available as extensions on PaaS and included on SaaS; they are explicitly read-only and must never be used as proof of Product WRITE support.

The V1 inventory can share semantic clusters across these targets, but every field/capability must retain edition/API-surface applicability. See `docs/data/adobe_commerce_v1_inventory_source_matrix.csv`.

## Authoritative Adobe surfaces included in the current pass

1. Adobe Commerce / Magento Open Source **catalog_product Import JSON API** — broad product payload covering core data, SEO, media, stock settings, product links, custom options, bundle/configurable/grouped/downloadable structures, and Adobe Commerce gift-card fields.
   - https://developer.adobe.com/commerce/webapi/rest/modules/import/
2. Adobe Commerce / Magento Open Source **PaaS REST API reference** — current administrative product/schema endpoints. Use the current Adobe reference as primary; older 2.4.8 evidence is compatibility evidence, not the current baseline.
   - https://developer.adobe.com/commerce/webapi/rest/reference/
3. Adobe Commerce / Magento Open Source **core GraphQL ProductInterface and product-type implementations** — Simple, Virtual, Configurable, Grouped, Bundle, Downloadable and Adobe Commerce GiftCard product surfaces.
   - https://developer.adobe.com/commerce/webapi/graphql/reference/
   - https://developer.adobe.com/commerce/webapi/graphql/schema/products/interfaces/types/
4. Adobe Commerce **Catalog Service / Storefront Services GraphQL** — read-only `ProductView` representation, merchant attributes, images, links, input options, stock flags, contextual price/priceRange, variants and video.
   - https://developer.adobe.com/commerce/webapi/graphql/schema/catalog-service/queries/products
   - https://developer.adobe.com/commerce/webapi/graphql/schema/storefront-services/
5. Adobe Commerce **Inventory Management REST** — Source, SourceItem, Stock and multi-source inventory semantics.
   - https://developer.adobe.com/commerce/webapi/rest/inventory/
6. Adobe Commerce **catalog pricing REST** — base, special, tier and cost storage, with product-type and edition restrictions.
   - https://developer.adobe.com/commerce/webapi/rest/modules/catalog/catalog-pricing
7. Adobe Commerce **B2B Shared Catalog** — company-specific product/category visibility and shared-catalog tier pricing.
   - https://developer.adobe.com/commerce/webapi/rest/b2b/integrations/
8. Adobe Commerce as a Cloud Service **SaaS REST / SaaS GraphQL** — separate edition surface, not silently equivalent to PaaS.
   - https://developer.adobe.com/commerce/webapi/rest/
   - https://developer.adobe.com/commerce/webapi/graphql/reference/

## Inventory entry kinds

The review must not treat every Adobe name as the same kind of thing. Each master-matrix row is provisionally classified as one of:

- **semantic_field** — a merchant/product value that may correspond to a platform field;
- **domain_value** — a value owned by Pricing, Inventory/Availability, Media, taxonomy/relations, or another platform domain rather than generic FieldMapping;
- **structured_capability** — configurable/bundle/grouped/downloadable/custom-option/product-family mechanics whose shape is not one scalar field;
- **connector_context** — Attribute Set, website/store/store-view, visibility/execution context, or other connector/channel configuration;
- **schema_metadata** — attribute definitions, Attribute Set membership, option vocabulary and applicability metadata;
- **external_system_metadata** — remote IDs/timestamps or vendor-managed state that is not merchant semantic data;
- **derived_projection** — read-only storefront/search/pricing/stock aggregate returned by Catalog Service or another derived surface.

This distinction is mandatory before deciding whether `FieldMapping`, `FieldOptionMapping`, a domain-specific seam, connector configuration, or no write path is appropriate.

## Product-type capability surface

The baseline explicitly includes product-type mechanics, not only common fields:

- Simple;
- Virtual;
- Configurable;
- Grouped;
- Bundle;
- Downloadable;
- Gift Card (Adobe Commerce edition-specific).

Adobe documents specialized attributes/mechanics for several of these types. They are represented as capability-family rows even when the platform later decides that a capability is deferred or intentionally unsupported in Magento V1.

## Important boundary: finite baseline + live target schema

Magento product schema is extensible through EAV attributes, merchant configuration and installed modules. Therefore no static list can represent every merchant installation forever.

The connector needs two complementary layers:

- **Versioned Adobe baseline knowledge**: standard fields, product-type mechanics, API capability families, known edition-specific features and platform ownership classifications.
- **Live account schema reality**: immutable discovery snapshots plus Attribute Set membership/options from the connected target.

A future mapping/readiness flow should work on the intersection of baseline semantics and live target reality. Custom merchant/module attributes discovered live remain first-class inventory items; they are not required to appear in the baseline list beforehand.

The live target does **not** define the platform-wide baseline. It proves which baseline/custom capabilities exist on that account and supplies real certification evidence.

## Current cluster inventory

See:

- `docs/data/adobe_commerce_v1_inventory_master.csv` — one row per current field/capability candidate;
- `docs/data/adobe_commerce_v1_capability_clusters.csv` — cluster summary;
- `docs/data/adobe_commerce_v1_inventory_source_matrix.csv` — edition/API source and freshness matrix.

The current pass contains **161 named field/capability entries grouped into 26 clusters/families**. This count intentionally mixes raw Adobe field names and named structured-capability entries; it is a research coverage count, not a claim that Adobe has exactly 161 Product attributes.

Primary clusters currently include:

- attribute schema / Attribute Sets / option vocabularies;
- identity and lifecycle metadata;
- core Product scalar data;
- dynamic EAV attributes;
- select/multiselect option identity;
- catalog/store/website/Attribute-Set execution context;
- product-type family capabilities;
- SEO/routing;
- pricing;
- inventory/availability and MSI source topology;
- media/image/video;
- categories and related/up-sell/cross-sell relations;
- customizable product options;
- configurable parent/child/options;
- bundle composition;
- grouped composition;
- downloadable links/samples;
- Adobe Commerce gift-card product mechanics;
- gift/merchandising and returns-policy fields;
- B2B Shared Catalog membership/pricing;
- storefront-derived read-only Catalog Service representations.

## Current platform Field Dictionary — comparison input, not authority

The existing `FieldDefinitionSeeder` provides a useful comparison vocabulary but must not define Adobe scope. Current seeded Product/ProductVariant definitions include:

**System:**
`internal_product_id`, `name`, `brand`, `category`, `description`, `status`, `url`, `sku`, `gtin`, `merchant_type`, `net_weight`, `gross_weight`, `volume_m3`.

**Platform library:**
`mpn`, `color`, `size`, `condition`, `short_description`, `material`, `country_of_origin`, `manufacturer`, `model`, `compatibility`, `battery_type`, `shipping_required`, `backorder_policy`, `technical_characteristics`, `instructions`.

This explains the current Mapping screen's 28 internal Product/ProductVariant rows. Those 28 rows are **our current canonical vocabulary**, not “the Magento fields”. They should later be expanded/refined only after cross-platform research (Adobe + Shopify + Google + standards/other major ecosystems) demonstrates a reusable semantic field or capability.

The `/admin/field-definitions` matrix is therefore an internal comparison aid, not a target schema specification.

## Cross-platform field-library strategy

Do not mirror Adobe fields into the core database one-for-one.

For each Adobe field/capability, determine one of these outcomes:

1. maps to an existing canonical platform field;
2. proves a new **universal** FieldDefinition candidate useful across multiple channels/platforms;
3. belongs to an existing domain owner (Pricing, Availability/Inventory, Media, Category/relations, Product family/composition) and therefore is not a generic FieldDefinition;
4. belongs to connector/channel configuration (Attribute Set, website/store/store-view context, visibility or vendor-specific execution metadata);
5. is external/system-owned or derived/read-only;
6. remains unresolved pending Shopify/Google/standards comparison or independent review.

Only outcome (2) should expand the platform base field library, and only after semantic comparison with other major ecosystems.

This later cross-platform pass should deliberately search for common concepts under different names, not copy source vocabulary. Examples to investigate include identifiers, dimensions/measurements, lifecycle/condition, manufacturer/brand/model, SEO, shipping/fulfillment, product relations, media roles, option/configuration semantics and channel-specific visibility.

## Schema freshness model

The existing immutable connector discovery architecture is the right base for keeping target schemas current:

`versioned Adobe baseline + latest successful live discovery snapshot + Attribute Set membership/options + edition/capability detection -> effective suggestions/readiness`

When a fresh snapshot changes:

- new fields become candidates for classification/mapping;
- changed metadata/options trigger re-evaluation;
- disappeared fields do not silently delete confirmed mappings; existing frozen contract keeps them and marks readiness/remediation as unresolved;
- vendor/module custom fields stay account-specific unless cross-platform evidence justifies promotion to the platform library.

A later product UX can expose this as a simple connector page such as:

- Fields detected;
- Automatically matched;
- Need attention;
- Changed since last scan;
- System/connector-managed — no action required.

The detailed technical inventory can remain internal/advanced by default.

## Concrete edition/surface findings to preserve in review

- Adobe Commerce as a Cloud Service REST has a smaller endpoint subset than PaaS/on-prem and uses IMS authentication.
- SaaS REST URLs do not use the PaaS `/rest/<store-code>/...` form; store scope is supplied with the `Store` header.
- Adobe Commerce as a Cloud Service does not use the core PaaS `products` GraphQL query; Catalog Service supplies the SaaS `products` representation.
- Storefront Services schemas are read-only. Their prices, stock flags, attributes, images and variants are useful representation evidence but not Product WRITE evidence.
- Inventory Management `SourceItem` owns per-source `sku`, `source_code`, `quantity`, and stock `status`; salable quantity is an aggregate concept and must not be collapsed into a generic product attribute.
- Catalog pricing has dedicated base/special/tier/cost endpoint families. Special/tier/cost support differs by product type, and some special-price requirements differ between Adobe Commerce and Magento Open Source.
- B2B Shared Catalog product membership and tier pricing are Adobe Commerce B2B capabilities, not universal Magento Open Source Product fields.

## Explicit non-decisions in this research pass

- No DB schema change.
- No automatic persistence of FieldMappings; frozen contract still requires merchant confirmation for effective mappings.
- No decision that every Adobe import field deserves a platform FieldDefinition.
- No assumption that Adobe Import API names equal Admin REST field paths one-to-one.
- No assumption that Catalog Service read-only fields imply write capability.
- No assumption that every PaaS capability exists on Adobe Commerce SaaS, or vice versa.
- No final cluster freeze until independent review.
- No UX page implementation yet; first establish the inventory/ownership truth that the page should present.

## Next review task

Give this document + three CSVs + current `develop` to independent reviewer(s), preferably Opus and/or Sonnet. Ask only for:

- missing Adobe Commerce / Magento product fields or capability families;
- edition/version-specific surfaces that should be added or separated;
- fields placed in the wrong cluster;
- incorrect Product/ProductVariant/domain/connector/system ownership;
- incorrect `semantic_field` / `domain_value` / `structured_capability` / `connector_context` / `schema_metadata` / `external_system_metadata` / `derived_projection` classification;
- over-broad or under-broad clusters;
- capabilities incorrectly treated as FieldMapping candidates;
- useful universal field candidates that should later be compared with Shopify/Google/standards;
- duplicates/aliases where multiple Adobe API surfaces represent the same semantic value;
- claims of READ/WRITE support that need stronger primary-source evidence.

Do **not** ask the reviewer to redesign the entire connector architecture unless it finds a concrete contradiction with authoritative project docs.
