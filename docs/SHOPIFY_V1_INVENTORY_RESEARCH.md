# Shopify V1 Product & Capability Inventory Research

Status: **LEAD COMPLETENESS PASS — NOT FROZEN**

## Goal

Build the broadest defensible Shopify Product / ProductVariant field and capability inventory from authoritative Shopify sources before any Shopify runtime implementation or cross-platform Field Library expansion.

The goal is not to make the platform look like Shopify. Shopify is one external ecosystem. Its data shapes must be classified into platform semantic fields, existing domain owners, connector/channel context, external metadata, structured capabilities, or read-only projections before mapping or implementation.

This follows `docs/09-CONNECTOR_DELIVERY_PROTOCOL.md`: inventory first, then classification and platform ownership/representation, then representative real READ/WRITE by cluster, blocker correction, whole-cluster certification, and finally field/capability certification.

## Research boundary

- Authoritative project base: `develop @ 953d84c916ac8236a57537a859132da27d5d207a` (PR #197 merged Adobe research baseline).
- Current Shopify Admin GraphQL baseline: **2026-07**.
- Shopify releases versioned APIs quarterly; the inventory must be refreshed against the developer changelog and supported-version window rather than treating `latest` as timeless.
- REST Admin is legacy evidence only. New connector runtime must not be designed around REST Product resources.
- Storefront API is buyer-facing representation evidence. It is not proof of Admin WRITE capability.
- No connected Shopify shop is used to define the platform-wide baseline. A live shop will later supply account-specific schema/capability and real READ/WRITE evidence.

## Current research coverage

The current Lead completeness pass contains:

- **738** top-level Shopify field/capability rows;
- **745** structured-object/input/mutation-argument subfield rows;
- **35** capability clusters;
- **35** primary source/API/event surfaces;
- **106** cross-surface alias/representation rows;
- **36** product-adjacent freshness event/topic rows;
- **14** version/change boundary rows for 2026-07/current transition semantics;
- **8556** normalized Shopify Standard Product Taxonomy attribute definitions;
- **74820** controlled taxonomy value references represented by the pinned upstream taxonomy (not duplicated into this repository);
- **15** current product-related standard metafield definitions explicitly inventoried.

Counts are research coverage, not claims that Shopify has exactly that many "product fields". The master intentionally includes fields, nested capabilities, remote identities, derived projections and connector contexts because losing those distinctions would make later READ/WRITE certification unsafe.

## Artifacts

- `docs/data/shopify_v1_inventory_master.csv` — row-level Product/Variant/domain capability inventory.
- `docs/data/shopify_v1_structured_object_fields.csv` — nested structures and mutation/input semantics.
- `docs/data/shopify_v1_capability_clusters.csv` — cluster/owner summary.
- `docs/data/shopify_v1_inventory_source_matrix.csv` — API/source/version/freshness authority.
- `docs/data/shopify_v1_alias_groups.csv` — GraphQL / CSV / legacy REST / specialized-write representation relationships.
- `docs/data/shopify_v1_taxonomy_attribute_inventory.csv` — compact normalized inventory of all current Shopify taxonomy attributes.
- `docs/data/shopify_v1_standard_metafield_definitions.csv` — current product-related standard metafield vocabulary.
- `docs/data/shopify_v1_freshness_event_matrix.csv` — webhook/Event freshness hints and their authority caveats.
- `docs/data/shopify_v1_version_change_matrix.csv` — version-specific changes/deprecations that alter Product-adjacent semantics.

## Source hierarchy

Primary sources for this pass are Shopify-owned sources:

1. Admin GraphQL 2026-07 Product, ProductVariant and mutation/input schemas.
2. Product options / option values and taxonomy-linked metafield option semantics.
3. `productSet` and nested Product/Variant set inputs for external-source synchronization.
4. InventoryItem, InventoryLevel and quantity-state write mutations.
5. Metafield / MetafieldDefinition and standard metafield definitions.
6. Metaobject / MetaobjectDefinition and standard metaobject definitions.
7. Shopify Standard Product Taxonomy repository plus Admin taxonomy objects.
8. Collections and product grouping relations.
9. Publications / Publishable, including 2026-07 independent ProductVariant publication.
10. Markets / Catalogs / PriceLists / quantity pricing.
11. Product media and file/media subtype objects.
12. Bundles / product component relationships.
13. Combined Listings.
14. Selling plans / purchase options.
15. Delivery profiles.
16. Translations / locales / market-localized content.
17. Gift-card product specialized set surface.
18. ProductFeed / channel-feed configuration and resynchronization lifecycle.
19. Webhooks plus current Events/metafield-trigger freshness surfaces.
20. Official Shopify product CSV import/export representation.
21. Storefront API only for buyer-facing READ representation comparison.
22. API versioning and developer changelog for freshness/deprecations.

## Entry kinds

The master uses the same high-level research taxonomy as the frozen Adobe inventory:

- `semantic_field` — merchant/product value that may correspond to a platform field;
- `domain_value` — Pricing, Inventory, Media, Fulfillment, composition or other domain-owned value;
- `structured_capability` — relationship/collection/object mechanics that cannot safely be flattened into one FieldMapping;
- `connector_context` — Shopify channel/market/template/publication/routing execution context;
- `schema_metadata` — definitions, option/taxonomy/metafield/metaobject schema information;
- `external_system_metadata` — Shopify GIDs, legacy IDs, timestamps, cursors and system identities;
- `derived_projection` — aggregate/query/storefront/admin projections that are not direct merchant write values.

The classification is provisional until independent review. It is deliberately conservative about WRITE.

## Lead schema completeness evidence

The current pass performs a field-by-field comparison against the official 2026-07 Admin GraphQL type reference for the primary Product/Variant object graph and the adjacent mutation/input surfaces that can materially affect Product state or connector behavior.

The comparison currently covers **104 input-object families** and **45 primary READ/interface object families**. For that checked set, the local inventory has **zero missing official fields**. This includes Product create/update/set inputs, variant bulk/set inputs, options and ordering, InventoryItem and multi-state quantity mutations, Metafield/Metaobject value and definition inputs, Collection current/legacy inputs, Publications, Markets/Catalogs/PriceLists/quantity pricing, files/media, Selling Plans, DeliveryProfile assignment, Bundles, translations, webhooks and ProductFeed configuration.

This is completeness evidence for the declared checked surfaces, not a claim that every Shopify Admin type belongs in Product V1. Unrelated Shopify domains (orders, customers, payments, broad shipping-rate administration, etc.) remain outside this Product/capability inventory unless they directly define Product representation or connector execution context.

The pass also corrected an important inventory-shape mistake: the top-level `InventorySetQuantitiesInput` / `InventoryAdjustQuantitiesInput` contracts are now kept separate from their nested `InventoryQuantityInput` / `InventoryChangeInput` rows. The 2026-07 quantity row uses `changeFromQuantity` as the compare-and-swap expectation; passing `null` disables CAS only for a source-of-truth use case.

## Important Shopify-specific findings already preserved

### Product update identifiers are connector identity tools, not platform identity

Since Admin GraphQL `2026-04`, `productUpdate` accepts a separate identifier using Shopify `id`, `handle`, or a unique-metafield `customId`. This is useful for external-source reconciliation, but it does not make Shopify handle/custom metafield the platform identity authority. `ExternalRecordLink` and the platform identity contracts remain separate.

### `productSet` is a consequential sync surface

Shopify explicitly positions `productSet` for synchronizing an external source into Shopify. Its mutation semantics are not ordinary PATCH semantics:

- list fields create/update supplied entries and delete existing entries omitted from the supplied list;
- documented examples include collections, metafields and variants;
- non-list fields only change when provided;
- the operation can be synchronous or asynchronous.

This matters directly to later Safe Sync design. A connector must never translate "send these changed variants" into a replacement list without proving the intended reconciliation scope.

### Variant publication became independently controllable in 2026-07

`ProductVariant` is a `Publishable` in API 2026-07. Variant publication can be controlled per publication, but Product-level status/publication still takes precedence and newly created variants default to published unless explicitly created unpublished.

Therefore Product lifecycle status, Product publication and Variant publication are separate concepts in the inventory.

### Product/Variant options are structured identities

Shopify options are not plain `option1/option2/option3` strings in the current Admin GraphQL model. ProductOption and ProductOptionValue have GIDs, ordering, values, translations, swatches and linked-metafield/taxonomy semantics. Product CSV still exposes positional Option1..3 columns, so these are recorded as cross-surface representations rather than treated as one raw field.

### Metafields and metaobjects are different capabilities

- Metafields extend an existing Shopify resource with typed namespace/key values and owner-scoped definitions.
- Metaobjects define reusable structured entities with their own definition, fields, capabilities, access and references.

Neither is copied into one generic platform JSON field. Individual merchant semantics discovered through them can later map to FieldDefinition/FieldBinding or another platform domain as appropriate.

### Shopify standard definitions are valuable cross-platform evidence

Shopify publishes interoperable standard metafield definitions such as Product subtitle, Care guide, ISBN, UPC, EAN, Product rating/count, related/complementary products, trade item description, transport declaration, external URL and unavailable reason.

These are **candidates for later cross-platform comparison**, not automatic additions to our Field Library.

### Taxonomy is much larger than a few popular attributes

The pinned Shopify ProductTaxonomy 1.2.0 `attributes.yml` contains **8556** distinct attribute definitions in this pass, with **74820** controlled value references.

The compact local taxonomy inventory preserves every attribute definition's stable identifiers/handles and value-set shape while avoiding a duplicate copy of all upstream values/descriptions. Exact upstream evidence is pinned to:

- repository commit `ad206247ecc45a95fe4b01bce2ad2f0e7bec3c66`;
- `attributes.yml` blob `455818cf3a5ae41f1db23c244adf1b5694691b88`.

This taxonomy is a channel/standard-classification input. It is not 8,556 new platform FieldDefinitions.

### Collections use the 2026-07 sources model

Shopify 2026-07 replaces the old single `Collection.ruleSet` authority with composable `CollectionSource` objects and typed inclusion/exclusion conditions. Deprecated `CollectionInput` / `ruleSet` shapes remain queryable for migration compatibility, so the inventory keeps them explicitly as legacy representations rather than silently deleting them. Current connector work must use the sources model as authority.

### Markets and ProductFeed are channel context, not Product fields

Shopify 2026-07 supports channel Markets and exposes ProductFeed resources for sales-channel feed configuration. Markets can combine catalogs, publication, pricing, currency and delivery context; ProductFeed binds a channel/country/language and supports explicit full-sync triggering. These capabilities belong to connector/channel or Pricing context. They must not create fake canonical Product fields.

### Sub-region Markets are first-class in 2026-07

`MarketRegionSubdivision` can appear in Market region conditions. `Market.conditions.regionsCondition.regions` is the authority for region membership; deprecated region shortcuts can omit subdivision regions. The connector must not assume every Market is country-only.

### DeliveryProfile authority is conditional in 2026-07

App-owned DeliveryProfiles remain valid and can now use `coversAllItems`. Merchant-owned shipping configuration, however, is moving to `Market.delivery` under market-driven shipping. On migrated shops, merchant-owned DeliveryProfile reads can be stale and successful writes can fail to change the live merchant configuration. Real certification must therefore determine the effective shop shipping model before treating DeliveryProfile as authoritative.

### Metaobject `values` has replacement semantics

The 2026-07 streamlined Metaobject API adds JSON-style `values` reads/writes. It is not equivalent to patch-style `fields`: when `values` is supplied, omitted optional keys are cleared. The inventory preserves both representations so a future connector does not accidentally turn a partial update into destructive replacement.

### Freshness events are hints, never state authority

Classic webhook topics and the newer Events/metafield trigger surface can reduce polling and identify likely changes, including Product metafield changes. They do not prove the final Product/Variant/Inventory value. The connector must re-read authoritative Admin API state before deriving Receive/Export conclusions.

### Physical inventory preview is intentionally outside the stable V1 baseline

Shopify announced bins, physical counts and purchase-order primitives behind an `unstable` feature preview in July 2026. They are recorded as a future inventory/WMS boundary, not promoted into the stable Admin GraphQL `2026-07` Product connector inventory. This is an explicit exclusion, not an unknown gap.

### Inventory writes are concurrency/idempotency-sensitive

By the `2026-07` baseline, the earlier 2026-04 concurrency changes are already active: quantity mutations use explicit `changeFromQuantity` compare-and-swap semantics where applicable, and selected consequential inventory mutations require Shopify idempotency keys. A future connector must preserve command identity across retries and must not translate a timeout into a blind duplicate adjustment.

### Inventory is multi-state and location-scoped

InventoryItem and InventoryLevel are kept separate from ProductVariant semantic fields. Location-specific quantity states include concepts such as available, incoming, committed, damaged, on-hand, quality-control, reserved and safety-stock. Absolute `inventorySetQuantities` uses compare-and-set semantics and is intended for a system acting as source of truth; delta adjustment is a separate mutation family.

These semantics belong to Inventory/connector synchronization, not generic FieldMapping.

### Pricing is context-dependent

Variant base price and compare-at price are only part of the pricing surface. Catalogs can bind Publications and PriceLists to Markets or B2B CompanyLocation contexts. PriceListPrice can be fixed or adjustment-derived and can expose quantity price breaks. Market CSV columns are another bulk representation of related context, not raw equivalents.

### Media is richer than image URLs

Shopify Product media includes images, hosted video, 3D models and external video. File/media processing fields are system projections. Variant association is separate from Product media ownership. Product CSV carries image URLs but does not represent the whole GraphQL media capability.

### Gift-card local-currency settings are create-only

`GiftCardProductSetInput.issuanceCurrency` and `crossCurrencyRedeemable` can be set when the gift-card Product is created, but cannot be changed afterward. They remain specialized gift-card capability state rather than ordinary mutable Product fields.

### Composition families stay separate

The inventory separately tracks:

- ordinary Product + ProductVariant option families;
- fixed/variant bundles and component quantities/options;
- Combined Listings parent/child product merchandising;
- selling-plan purchase options.

These must not be flattened into generic Product fields merely because Shopify exposes them under Product/Variant objects.

## Existing repository Shopify state

The repository already has a `shopify` ConnectorDefinition and schema-source metadata, but there is no Shopify runtime connector/profile implementation. Existing canonical mappings are limited historical comparison evidence (`name→title`, `description→body_html`, `brand→vendor`, `gtin→variants.barcode`, `sku→variants.sku`) and reference legacy REST `2024-10` shapes.

This research does not alter those mappings or claim they are wrong. It establishes a modern 2026-07 GraphQL baseline so future connector work can explicitly migrate/qualify aliases rather than silently treating the old REST shape as current authority.

## Platform ownership rules for later synthesis

For every Shopify row, the later synthesis must choose among:

1. existing canonical platform FieldDefinition/FieldBinding;
2. new universal field candidate only after Adobe + Shopify + Google/standards evidence;
3. existing domain owner (Pricing, Inventory, Media, taxonomy/relations, composition, localization, fulfillment, purchase options);
4. connector/channel context;
5. external/system-owned or derived/read-only representation;
6. unresolved pending primary-source or cross-platform evidence.

No Shopify-specific name becomes a core field merely because Shopify makes it writable.

## Schema freshness model

Shopify is more explicitly versioned than the Adobe baseline. The future connector knowledge model should preserve:

`versioned Shopify baseline + developer changelog + pinned taxonomy version + latest successful live discovery/capability evidence -> effective connector readiness`

Specific freshness rules:

- Admin/Storefront/Webhook schema: evaluate quarterly API versions;
- REST: historical/compatibility only;
- unversioned surfaces: track separately because they can change without quarterly version guarantees;
- taxonomy: pin upstream release/commit and diff definitions/values on update;
- standard metafield/metaobject definitions: refresh from official lists/API templates;
- account/app feature entitlement (Bundles, Combined Listings, B2B/Markets features): prove on connected account before certification.

## Explicit non-decisions

This research pass does **not**:

- create a Shopify runtime connector;
- change DB schema;
- add Shopify fields to Product core;
- persist FieldMappings;
- promote taxonomy attributes or standard metafields to the platform Field Library;
- infer WRITE from a readable GraphQL field;
- claim every feature exists on every Shopify plan/account;
- use a smoke Shopify store as global schema authority;
- decide merchant-facing mapping UX.

## Next gate

Before freezing this Shopify baseline:

1. finish the final row/source/cluster consistency checks over the completed Lead schema pass;
2. commit/push one exact review HEAD to the existing Draft PR;
3. run independent adversarial review against that exact HEAD;
4. Lead-arbitrate findings against Shopify primary sources and `[Resolved]` platform ownership;
5. apply only evidence-backed corrections;
6. only then freeze the Shopify inventory for cross-platform synthesis.
