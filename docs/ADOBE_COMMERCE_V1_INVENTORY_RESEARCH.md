# Adobe Commerce / Magento V1 Product & Capability Inventory — Initial Research

Status: **INITIAL_RESEARCH — NOT FROZEN**

This document is a review artifact for building the platform-wide Adobe Commerce / Magento product connector inventory. It deliberately does **not** use the current smoke store as the definition of Magento scope. A connected store is only live evidence for later validation.

## Goal

Build the broadest defensible Adobe Commerce / Magento product-data and product-capability inventory from authoritative Adobe sources, classify it by transport/domain mechanics, then subject the result to an independent adversarial review before implementation/certification.

This follows `09-CONNECTOR_DELIVERY_PROTOCOL.md`: external inventory first, then classify, map to platform owners/representations, prove representative real READ/WRITE by cluster, fix blockers, then certify every field/capability.

## Authoritative Adobe surfaces included in the first pass

1. Adobe Commerce / Magento Open Source **catalog_product Import JSON API** — broad product payload covering core data, SEO, media, stock settings, product links, custom options, bundle/configurable/grouped/downloadable structures, and Adobe Commerce gift-card fields.
   - https://developer.adobe.com/commerce/webapi/rest/modules/import/
2. Adobe Commerce **2.4.8 Admin REST reference** — product attributes, Attribute Sets, options, product custom options, media gallery/video, configurable, bundle, downloadable, website assignment and other product APIs.
   - https://adobe-commerce.redoc.ly/2.4.8-admin/
3. Adobe Commerce / Magento Open Source **core GraphQL ProductInterface and product-type implementations** — Simple, Virtual, Configurable, Grouped, Bundle, Downloadable and Adobe Commerce GiftCard product surfaces.
   - https://developer.adobe.com/commerce/webapi/graphql/schema/products/interfaces/
4. Adobe Commerce **Catalog Service / Storefront Services GraphQL** — read-only ProductView representation, attributes, images, links, input options, stock flags, contextual price/priceRange, variants and video.
   - https://developer.adobe.com/commerce/webapi/graphql/schema/catalog-service/queries/products
   - https://developer.adobe.com/commerce/webapi/graphql/schema/storefront-services/
5. Adobe Commerce **Inventory Management REST** — SourceItem, Source, Stock and multi-source inventory semantics.
   - https://developer.adobe.com/commerce/webapi/rest/inventory/
6. Adobe Commerce **catalog pricing REST** — base, special, tier and cost storage.
   - https://developer.adobe.com/commerce/webapi/rest/modules/catalog/catalog-pricing
7. Adobe Commerce **B2B Shared Catalog** — product membership and shared-catalog tier-price capability.
   - https://developer.adobe.com/commerce/webapi/rest/b2b/integrations/

## Important boundary: finite baseline + live target schema

Magento product schema is extensible through EAV attributes and modules. Therefore no static list can represent every merchant installation forever.

The connector needs two complementary layers:

- **Versioned Adobe baseline knowledge**: standard fields, product-type mechanics, API capability families, known edition-specific features and platform ownership classifications.
- **Live account schema reality**: immutable discovery snapshots plus Attribute Set membership/options from the connected target.

A future mapping/readiness flow should work on the intersection of baseline semantics and live target reality. Custom merchant/module attributes discovered live remain first-class inventory items; they are not required to appear in the baseline list beforehand.

## Initial cluster inventory

See `docs/data/adobe_commerce_v1_capability_clusters.csv`.

The first pass currently contains **154 named fields/capability entries grouped into 25 clusters/families**. The CSV intentionally preserves both raw Adobe field names and higher-level capability entries where Adobe exposes structured objects rather than flat attributes.

Primary clusters include:

- attribute schema / Attribute Sets / option vocabularies;
- identity and lifecycle metadata;
- core Product scalar data;
- dynamic EAV attributes;
- select/multiselect option identity;
- catalog/store/website/Attribute-Set execution context;
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

This explains the current Mapping screen's 28 internal Product/ProductVariant rows. Those 28 rows are **our current canonical vocabulary**, not “the Magento fields”. They should later be expanded/refined only after cross-platform research (Adobe + Shopify + Google + additional standards/platforms) demonstrates a reusable semantic field or capability.

## Cross-platform field-library strategy

Do not mirror Adobe fields into the core database one-for-one.

For each Adobe field/capability, determine one of these outcomes:

1. maps to an existing canonical platform field;
2. proves a new **universal** FieldDefinition candidate useful across multiple channels/platforms;
3. belongs to an existing domain owner (Pricing, Availability/Inventory, Media, Category/relations, Product family/composition) and therefore is not a generic FieldDefinition;
4. belongs to connector/channel configuration (Attribute Set, website/store/store-view context, visibility or vendor-specific execution metadata);
5. is external/system-owned or derived/read-only;
6. remains unresolved pending Shopify/Google/other-platform comparison or independent review.

Only outcome (2) should expand the platform base field library, and only after semantic comparison with other major ecosystems.

## Schema freshness model

The existing immutable connector discovery architecture is the right base for keeping target schemas current:

`baseline Adobe knowledge + latest successful live discovery snapshot + Attribute Set membership/options -> effective suggestions/readiness`

When a fresh snapshot changes:

- new fields become candidates for classification/mapping;
- changed metadata/options trigger re-evaluation;
- disappeared fields do not silently delete confirmed mappings; existing frozen contract keeps them and marks readiness/remediation as unresolved;
- vendor/module custom fields stay account-specific unless cross-platform evidence justifies promotion to the platform library.

A later product UX can expose this as a simple connector page such as “Fields detected / Automatically matched / Need attention / Changed since last scan”, while the detailed inventory remains internal by default.

## Explicit non-decisions in this research pass

- No DB schema change.
- No automatic persistence of FieldMappings; frozen contract still requires merchant confirmation for effective mappings.
- No decision that every Adobe import field deserves a platform FieldDefinition.
- No assumption that Adobe Import API names equal Admin REST field paths one-to-one.
- No assumption that Catalog Service read-only fields imply write capability.
- No final cluster freeze until independent review.
- No UX page implementation yet; first establish the inventory/ownership truth that the page should present.

## Next review task

Give this document + cluster CSV + current `develop` to an independent reviewer (Opus/Sonnet). Ask only for:

- missing Adobe Commerce / Magento product fields or capability families;
- edition/version-specific surfaces that should be added or separated;
- fields placed in the wrong cluster;
- incorrect Product/ProductVariant/domain/connector/system ownership;
- over-broad or under-broad clusters;
- capabilities incorrectly treated as FieldMapping candidates;
- useful universal field candidates that should later be compared with Shopify/Google;
- duplicates/aliases where multiple Adobe API surfaces represent the same semantic value.

Do **not** ask the reviewer to redesign the entire connector architecture unless it finds a concrete contradiction with authoritative project docs.
