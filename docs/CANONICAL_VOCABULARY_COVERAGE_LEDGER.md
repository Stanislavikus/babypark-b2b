# Canonical vocabulary coverage ledger — provider-local Gate 1 campaign

This document records the first provider-local delivery of Gate 1. It is research and governance evidence, not a canonical vocabulary freeze, database design, or runtime mapping contract.

## Snapshot and corpus

The pass is based on repository commit `5be3ec03ec915e6831ad86777d5284aa083be206` and includes only `docs/data/bigcommerce_v3_product_capability_inventory.csv`. The immutable manifest records its exact byte hash, exact header hash, 136 physical data rows, OpenAPI basis, and capture date. Later provider passes may append manifest rows while retaining this provider's contract.

The logical coverage relation is sharded by provider. `docs/data/canonical-coverage/bigcommerce.csv` is the BigCommerce shard; no duplicate aggregate ledger is generated.

## Reproducibility contract

`php artisan canonical-coverage:bigcommerce` deterministically upserts its `(platform, source_file)` manifest row while preserving other valid provider entries, then rebuilds the coverage shard, provider concept graph, and disagreement queue. Manifest identities are unique and sorted deterministically. `php artisan canonical-coverage:bigcommerce --check` locates and validates only the BigCommerce manifest identity without requiring a one-row global manifest.

A physical row hash is SHA-256 over a UTF-8 JSON array of source values in source-header order. The coverage identity is SHA-256 of:

```text
snapshot_id + U+001F + source_file + U+001F + decimal_row_ordinal + U+001F + source_row_sha256
```

Ordinal and row hash are deliberately both retained: the former proves physical position and the latter proves content.

## Provider normalization

Each source record becomes exactly one provider semantic atom. Typed inventory classification, object family, type/reference, read/write contract, evidence pointer, and source description drive classification; names do not independently establish equivalence. Every non-terminal row links to one provisional `bigcommerce:*` concept.

The closed disposition enum is `REUSABLE_SEMANTIC`, `CATEGORY_ATTRIBUTE`, `DOMAIN_CAPABILITY`, `CHANNEL_SEMANTIC`, `EXTERNAL_IDENTITY`, `APPLICABILITY_METADATA`, `STRUCTURE_MEMBER`, `DERIVED_PROJECTION`, `TRANSPORT_MECHANIC`, `ALIAS_REPRESENTATION`, `OUT_OF_SCOPE`, and `DEFER_DECISION`. This source inventory needs five of these dispositions; unused values remain valid for later provider shards.

Provider concept keys are research identities only. They do not assert Platform Core/Library membership and cannot be adopted as final internal codes without the cross-platform pass.

## Same concept and different binding

Product and ProductVariant rows share a provider concept only for explicitly reviewed fields: bin picking number, cost/base/retail/sale/fixed-shipping prices, dimensions, weight, GTIN, MPN, UPC, SKU, inventory level/warning level, and free-shipping status. Each physical row retains its own entity level, type, read/write contract, and source description. Variant nullability, inheritance, Price List precedence, and fallback are therefore not erased by concept sharing.

Every shared concept has an explicit compatibility rule. Numeric Product values encoded as strings and nullable numeric Variant values normalize to the neutral semantic type `decimal`; identifiers normalize to `string`, quantities to `integer`, and free-shipping status to `boolean`. The semantic-audit correction distinguishes three binding shapes instead of inferring one generic override rule: documented nullable Product fallback/Price-List precedence, co-existing Product/Variant values with no proven fallback, and independent non-nullable flags. The validator compares entity, wire type, cardinality, READ/WRITE contract, required/operation context, and only explicit positive fallback/precedence evidence for every member. An unruled shared concept fails validation.

Product Options, ProductVariant option values, and Product Modifiers remain distinct concepts. A modifier is order-time customization; it does not create a variant dimension. Their option values and presentation/control types have explicit do-not-merge edges. Modifier/option adjustments remain in their structured capability rather than becoming base Product price.

MAP, reference retail price, base price, and sale price have distinct concepts and conflict edges. Calculated price is a derived projection. Product, option, and modifier `type` are also explicitly non-equivalent.

Category membership is owned by the Category relation candidate; related products by ProductAssociation; custom fields by the external dynamic-field container; variants/options by VariantComposition; and modifiers by OrderCustomization. These provider-local owner candidates preserve existing boundaries without selecting storage.

## Validation and limitations

`source_context_key` preserves the source `required_in` value together with the operation/schema evidence instead of duplicating object family. `applicability_key` is `not_applicable` because this pass defines no provider applicability relation.

The generated disagreement queue is validated with the other artifacts. Every concept reference and affected-row count must resolve; affected OPEN rows use `DEFERRED_REVIEW` plus `queue:<question_key>` references. Thus provider semantic classification can remain known without falsely claiming that unresolved portable ownership or binding is verified.

Validation proves manifest/file/header hashes, row counts, exact one-to-one physical coverage, row and coverage hashes, source declaration, disposition membership, concept FKs, evidence counts/platform sets, safe-merge compatibility rules, disagreement references/counts/statuses, non-dangling applicability, conflict references, and conflict/alias consistency. Current metrics are:

```text
coverage_ratio=1.000000
classification_ratio=1.000000
concept_link_ratio=1.000000
terminal_rationale_ratio=1.000000
silent_drop_count=0
safe_merge_conflicts_unexplained=0
open_disagreements_with_verified_rows=0
invalid_disagreement_refs=0
dangling_applicability_keys=0
manifest_provider_rows_preserved=PASS
```

The pass yields 119 provider concepts from 136 physical rows. It makes no Adobe, Google, Amazon, Shopify, or cross-platform equivalence claim. The source inventories top-level fields/capabilities rather than nested members, so `STRUCTURE_MEMBER` is not used here. After semantic-audit correction, BigCommerce `retail_price` is directly evidenced as manufacturer suggested retail price and is no longer an OPEN reference-price question. Tax representation, MAP persistence, selected Product/Variant binding, DEC-009 weight semantics, order-constraint family governance, URL transformation, and customization architecture remain explicitly deferred where evidence is still insufficient.

## Review sampling

All 136 coverage rows were mechanically reconciled. High-risk row-level review covered all Product/ProductVariant duplicate names, all 20 pricing and Price List rows, all 17 inventory/availability rows, all 17 Product Option/Modifier rows, all nine external identities, all ten projections, all media rows, storefront visibility, generated option/modifier names and types, and endpoint-only read/write asymmetries. This review corrected the tempting but invalid merges of calculated/base price, Product/option/modifier type, and Product Option/Modifier option values.

## Gate 2 handoff

After all five provider passes, blind reviewers receive the content-addressed manifest, provider shards, provider concept graphs, validator output, resolved-decision extracts, and a cross-platform disagreement queue. They may inspect source rows through file/ordinal/hash provenance. Cross-platform merge begins only then; this BigCommerce pass must not be treated as evidence that similarly named fields from another ecosystem are equivalent.

### BigCommerce provider semantic-audit correction — 2026-09-09

Independent Sonnet High semantic/domain review and GPT-5.4 evidence/mapping audit were run against `develop @ 8bc70b809732ed83292b78272ca39de3c2bf0360`, then independently arbitrated against the frozen registry contract and current BigCommerce primary documentation. The durable finding-by-finding record is `docs/reviews/BIGCOMMERCE_PROVIDER_AUDIT_ARBITRATION_2026-09-09.md`. This is a correction over the frozen 136-row inventory, not a new BigCommerce discovery pass.

The correction narrows the Product/ProductVariant issue to actual canonical-binding mismatches. Canonical `sku`, `gtin`, `mpn`, `price`, `sale_price`, `cost_price`, and `recommended_retail_price` remain Variant-level mappings. Canonical Product-level `depth_mm`, `width_mm`, and `height_mm` now map to BigCommerce `Product.depth`, `Product.width`, and `Product.height`; nullable `ProductVariant.*` dimensions remain provider-local override representations with documented Product fallback. `net_weight` is aligned to `Product.weight` but downgraded to `partially_verified`, because BigCommerce documents shipping/store weight and does not prove DEC-009's strict packaging exclusion.

The shared-compatibility classifier no longer treats bare words such as `default` or `price list` as fallback evidence. `product_variant.cost_price` therefore preserves its explicit “not affected by Price Lists” meaning, and inventory rows mentioning the default location no longer look like inheritance contracts. Positive fallback/precedence is recognized only from explicit provider wording.

BigCommerce `retail_price` is now provider-verified as manufacturer suggested retail price, so the old `bigcommerce_reference_price` disagreement is removed while the verified `recommended_retail_price` mapping remains. `min_order_quantity -> Product.order_quantity_minimum` also remains verified; its disagreement is reframed around broader max-order/order-step platform-domain governance rather than a false Product-vs-Variant location question. The OPEN queue moves from 8 to 7 families.

Tax evidence is kept split: `tax_class_id` is a Connector-owned account/store tax-class reference, while `product_tax_code` is a third-party tax-provider passthrough. `custom_url` is explicitly a relative storefront-path object requiring store/base-URL context before any canonical absolute-URL transform. No BigCommerce `condition` field/option mapping is added: canonical condition is still proposed and Variant-bound while BigCommerce condition is Product-level, so fail-closed deferral is safer than a name/value-only merge.

## Adobe Commerce provider pass

The Adobe pass now contains three denominator files in the common manifest: 184 top-level inventory rows, 189 structured members, and 51 explicit alias representations. Capability clusters and the source/edition matrix remain supporting consistency evidence rather than duplicate coverage rows. After the provider semantic-audit correction, the shard contains 424 physical coverage rows and 360 provisional Adobe-local concepts.

All structured families use an explicit mapping to a real top-level capability row. Nested IDs, UIDs, codes, and SKUs remain structure/reference members. All alias rows point to a valid top-level representation and retain the frozen `identity_rule`; ID/code/name translation is not treated as raw equality.

Adobe-local ownership preserves the frozen boundaries: visibility/store/website context stays with Connector; Attribute Sets with schema/applicability; pricing and tax with Pricing/Tax; MSI topology with Inventory; salable quantity with Availability as a projection; media with Media; category membership with Category; links with ProductAssociation; and configurable, customizable, bundle, grouped, downloadable, Gift Card, and Shared Catalog capabilities remain distinct provider families.

Fourteen generated OPEN disagreements cover lifecycle, tax, MAP, MSRP, weight, store scope/localization, RMA and gift-wrap write identity, customization/composition, execution Product Type, dynamic EAV, Shared Catalog, and Catalog Service projections. Every affected row is `DEFERRED_REVIEW` with an explicit queue reference; this does not reopen frozen visibility ownership or decide a cross-platform concept.

Adobe validation additionally reconciles the 28 cluster counts and resolves every composite master `source_surface` label to one or more of the 14 authoritative surface families. It enforces the strongest directly evidenced edition/surface boundaries: Catalog Service projections are read-only Storefront Services, Shared Catalog is B2B-only, MSI topology uses Inventory Management, price storage uses Catalog Pricing, and live-discovery rows resolve to live EAV discovery. It does not infer finer API versions absent from the frozen rows. Cross-provider tests run Adobe and BigCommerce generation in both directions and prove that the common manifest and both providers' generated shards remain byte-for-byte deterministic.

The corrected normalization keeps `additional_attributes` and `custom_attributes` as DynamicField envelopes while manufacturer, material, color, size, instructions, GTIN, MPN, and brand remain reusable Adobe semantic candidates with a separate EAV binding. Structured role classification uses family, subfield, entry kind, and semantic note: only media labels are media roles; Product anchors and customization SKUs differ; Pricing storage values differ from option/bundle modifiers. Structured `entry_kind` is retained in context, and explicit GraphQL READ/derived members and aliases are read-only.

Gift Card parentage is surface-specific: Import `giftcard_amount_list.amount` belongs to the Import `giftcard_amount` representation, while GraphQL `giftcard_amount` members belong to `giftcard_amounts`. The shared dynamic EAV member shape uses `custom_attributes` as its technical parent and records `additional_attributes|custom_attributes` as the complete envelope scope; validation requires both parents to exist.

All 23 alias groups have explicit compatibility contracts defining neutral owner, representation, value type, status, expected keys, and transformation rationale. Concept metadata is independent of physical row ordering. The composition disagreement now enumerates all matching configurable, bundle, and grouped top-level, alias, and structured concepts rather than only three alias nodes.

### Adobe provider semantic-audit correction

A later independent Sonnet High semantic/clustering challenge and GPT-5.4 evidence/coverage audit were run against the frozen five-provider base, then independently arbitrated against repository decisions and current Adobe primary evidence. The durable finding-by-finding record is `docs/reviews/ADOBE_PROVIDER_SEMANTIC_AUDIT_ARBITRATION_2026-09-08.md`. This was a correction pass over already-frozen evidence, not a restart of broad Adobe discovery.

The correction establishes a general invariant that any top-level Adobe `connector_context` row is Connector-owned `CHANNEL_SEMANTIC`, preventing presentation-layout and URL-rewrite controls from falling through to reusable ProductData merely because their cluster lacks a special case. It also separates Import image filename/label columns from Admin REST media-entry structure. Explicit non-raw-equal representation groups connect Base/Small/Thumbnail filename slots to REST role tokens and connect `additional_images`, `additional_image_labels`, and `hide_from_product_page` to REST media-entry `file`, `label`, and `disabled` members. REST media-entry `label` remains distinct from role-specific Import `*_image_label` columns.

Classic EAV `cost` is now inventoried explicitly as a Pricing-owned Adobe representation alongside, but not merged with, Catalog Pricing `cost_storage`. Cross-platform synthesis wording is tightened so Adobe `weight` is not evidence of `net_weight` or `gross_weight` equivalence, and `country_of_manufacture` is related evidence rather than proof of identity with `country_of_origin`. The current boolean Adobe status transform remains intact under DEC-010; only the OPEN disagreement wording is narrowed to any future richer lifecycle. No new platform canonical field is created by this Adobe correction.

## Google Merchant provider pass

The Google pass covers 145 processed ProductAttributes and 11 ProductInput wrapper/context rows. Processed output is always modeled as Google publication state, with writes normalized to `conditional_write_by_data_spec`; it is never asserted as authoritative Product truth. ProductInput separately preserves offer identity, resource-name/encoding mechanics, publication context, submission containers, and concurrency version behavior.

Vehicle and property applicability is now independent of semantic ownership: all 37 known vertical rows retain scope in `source_context_key`, while five vertical price/fee rows are Pricing-owned and five vehicle compliance rows remain explicitly deferred rather than being flattened into category attributes. No applicability FK is invented. The relationship family is split among ProductAssociation, VariantComposition, bundle composition, and deferred narrow multipack semantics.

All six `channel_or_specialized_context` rows have explicit fates: vehicle registration/model are vehicle-scoped attributes, sell-on-Google quantity remains publication context, both unit-pricing measures are deferred to Pricing-or-Compliance review, and sustainability incentives are deferred Compliance candidates. `shortTitle` is likewise deferred between FieldDefinition and Content ownership. Independent GPT-5.4 + Sonnet review then split `structuredTitle` / `structuredDescription` into a dedicated structured-content + digital-source-provenance question, distinguished Google-controlled `googleProductCategory` from merchant-defined `productTypes`, and marked property `numberOfUnits` as an availability-like vertical quantity. Thirteen generated disagreement families now preserve these decisions alongside unresolved availability, taxonomy, vertical applicability, identifier governance, compliance, preorder date, sibling URL roles, and processed-output ownership questions.

The same correction pass fixes the Products v1 literal offer key to `offerId`, corrects positive-polarity `identifier_exists` semantics, and downgrades Google `productWeight` / `shippingWeight` mappings because DEC-009's strict net/gross packaging boundaries are not proven by provider evidence. Primary `url -> link` remains a verified transformed mapping; only `canonicalLink` / `mobileLink` stay in the OPEN URL-role queue. Canonical gender `other` intentionally has no Google option mapping.

## Amazon Listings V1 provider pass — Gate 1D

The Amazon pass adds two denominator files to the common manifest: 23 Product Type Definitions meta-model rows and 78 rows from the pinned public `LUGGAGE` PTD example, for 101 physical coverage rows. The PTD meta-model is schema/applicability evidence; it is not Product data. The representative LUGGAGE schema is product-type/marketplace/requirements scoped and cannot prove that a property exists for every Amazon product type or marketplace.

The corrected Gate 1D normalization preserves the frozen applicability context (`product_type=LUGGAGE`, marketplace `ATVPDKIKX0DER`, requirements `LISTING`, parentage example and schema-version token) in `source_context_key` without inventing an applicability FK. PTD property presence is recorded as schema evidence, not as proof of live listing READ capability, and property writes remain PTD-conditioned rather than unconditional.

Identity, taxonomy, pricing, availability, compliance/media and variant-composition fates remain separate. `condition_type` is a reusable Product-condition candidate while `condition_note` remains Amazon listing context; taxonomy keys remain connector taxonomy; offer structures remain Pricing; fulfillment-channel availability remains Availability; product-type-specific LUGGAGE attributes remain `CATEGORY_ATTRIBUTE`. The final pass contains 90 Amazon-local concepts and 13 disagreement families after semantic correction.

The Amazon validator freezes the eight source identities accepted through Gate 1D but permits later provider passes to append new manifest identities. It still requires all pre-existing accepted rows to survive byte-for-byte; this is what allows Shopify Gate 1E to extend the common manifest without weakening Amazon provenance.

## Shopify provider pass — Gate 1E

The Shopify pass consumes the already frozen Shopify 2026-07 research baseline rather than re-researching the provider. Six physical source files form the denominator:

- 738 master Product/ProductVariant/domain/capability rows;
- 926 structured object/input/mutation members;
- 106 explicit cross-surface alias/representation rows;
- 8,556 pinned Shopify Standard Product Taxonomy attribute definitions;
- 36 webhook/Events freshness facts;
- 14 version/change boundaries.

The exact denominator is 10,376 physical rows. `shopify_v1_capability_clusters.csv`, `shopify_v1_inventory_source_matrix.csv` and the 15-row standard-metafield detail file remain supporting evidence rather than duplicate denominator rows. The 15 standard metafield definitions are already represented in the master; the validator proves that every supporting namespace/key is present there. The taxonomy pin remains commit `ad206247ecc45a95fe4b01bce2ad2f0e7bec3c66`, blob `455818cf3a5ae41f1db23c244adf1b5694691b88`, with 8,240 base attributes, 316 extended attributes and 74,820 controlled-value references.

Provider-local fate is intentionally narrower than the source inventory's `semantic_field` label. Shopify `semantic_field` means a meaningful value, not automatic Product Field Library membership. Product title/description, merchant type, lifecycle status, tags, SKU/barcode and selected logistics facts remain reusable provider candidates; Category, Media, Localization, Dynamic Metafield, Gift Card and Selling Plan values remain domain capabilities. `Product.vendor` remains Shopify channel semantics and is not promoted to brand/manufacturer.

All 41 Product CSV master rows are `TRANSPORT_MECHANIC`. CSV is destructive/conditional bulk representation evidence; alias rows connect it to the relevant Product/Variant/domain semantics without creating a second canonical field set. Structured members remain `STRUCTURE_MEMBER` and no parent row is invented when the frozen input-object family has no single physical top-level parent. Nested IDs therefore remain structure/reference evidence rather than becoming top-level external identity authority.

All 8,556 taxonomy definitions remain `CATEGORY_ATTRIBUTE` owned by `ConnectorTaxonomy`; none is promoted to a platform FieldDefinition by Gate 1E. Freshness rows remain transport hints and require authoritative Admin state to be re-read. Version rows remain applicability/schema metadata. Product/ProductVariant remote identities remain connector-owned external identity evidence, while async-operation and webhook-subscription IDs remain transport/configuration identities rather than business-record identity.

Two platform-ownership questions remain intentionally open in the Shopify shard: standard Product subtitle/short-title ownership and unit-pricing measure ownership. These rows are `DEFER_DECISION` / `DEFERRED_REVIEW`; the pass does not manufacture a Content, Pricing or Compliance contract merely to close the queue.

Current Gate 1E metrics are:

```text
master_rows=738
structured_rows=926
alias_rows=106
taxonomy_rows=8556
freshness_rows=36
version_rows=14
coverage_rows=10376
concepts=10314
disagreements=2
coverage_ratio=1.000000
classification_ratio=1.000000
concept_link_ratio=1.000000
silent_drop_count=0
invalid_alias_reference_count=0
invented_applicability_key_count=0
taxonomy_attributes_promoted_to_platform_fields=0
freshness_hints_promoted_to_state_authority=0
manifest_provider_rows_preserved=PASS
```

The five provider generators are byte-deterministic as one campaign: BigCommerce → Adobe Commerce → Google Merchant → Amazon → Shopify generation followed by all five validators leaves the common manifest and every provider coverage/concept/disagreement artifact unchanged. With Gate 1A–1E represented in the ledger, the next step is cross-platform synthesis/reconciliation; provider-local concepts remain evidence and do not themselves select platform storage or runtime mapping behavior.

## Gate 2 five-provider synthesis reconciliation

After Gate 1A–1E closed, the existing cross-platform synthesis was rechecked against the provider-local shards rather than rerunning broad provider research. The reconciliation changes only `docs/data/cross_platform_product_field_synthesis.csv`; it does not modify provider coverage, runtime Product/Variant storage, connector execution, FieldDefinitions, or merchant mappings.

Eight narrow corrections remove ownership/representation shortcuts that the stricter provider passes disproved:

- Product image collections remain Media-domain semantics even though current runtime persistence is the legacy/minimal `products.images` JSON field.
- Google `multipack` remains a narrower deferred identical-product quantity concept and is not evidence that generic `package_quantity` is equivalent.
- Google property and vehicle aggregates retain `PropertyVertical` / `VehicleVertical` category-attribute ownership; Pricing-owned and Compliance-deferred rows are explicitly excluded from those aggregates.
- BigCommerce view/review/sales metrics are external derived projections, not connector configuration.
- Amazon PTD `productType` is schema-applicability metadata and is separated from Amazon item-type taxonomy context.
- Amazon `item_type_keyword` / `item_type_name` remain provider taxonomy semantics rather than Product Type or merchant Category.
- The frozen Amazon evidence proves `merchant_suggested_asin` only as a seller-suggested reference; it does not establish authoritative Catalog ASIN identity.

The synthesis working set therefore moves from 85 to 86 rows because the previous mixed Amazon schema/taxonomy row is split into two provider-only concepts. Existing canonical KEEP/ADD/DEFER decisions remain unchanged except for the corrected semantic owner/representation evidence above. A dedicated regression test cross-checks these boundaries against the provider-local coverage shards.

This reconciliation is the input to final adversarial arbitration. Broad provider discovery is now frozen: future providers start from the stable canonical/provider evidence and may reopen the shared vocabulary only when a concrete, evidenced semantic gap is demonstrated.
