# Canonical vocabulary coverage ledger — BigCommerce provider pass

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

Every shared concept has an explicit compatibility rule. Numeric Product values encoded as strings and nullable numeric Variant overrides normalize to the neutral semantic type `decimal`; identifiers normalize to `string`, quantities to `integer`, and free-shipping status to `boolean`. The validator compares entity, wire type, cardinality, READ/WRITE contract, required/operation context, and documented inheritance/fallback for every member. An unruled shared concept fails validation.

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

The pass yields 119 provider concepts from 136 physical rows. It makes no Adobe, Google, Amazon, Shopify, or cross-platform equivalence claim. The source inventories top-level fields/capabilities rather than nested members, so `STRUCTURE_MEMBER` is not used here. Ambiguities such as tax ownership, MAP persistence, reference-price equivalence, Product/Variant final binding, and customization architecture remain for blind semantic review; retaining a provider concept does not resolve them.

## Review sampling

All 136 coverage rows were mechanically reconciled. High-risk row-level review covered all Product/ProductVariant duplicate names, all 20 pricing and Price List rows, all 17 inventory/availability rows, all 17 Product Option/Modifier rows, all nine external identities, all ten projections, all media rows, storefront visibility, generated option/modifier names and types, and endpoint-only read/write asymmetries. This review corrected the tempting but invalid merges of calculated/base price, Product/option/modifier type, and Product Option/Modifier option values.

## Gate 2 handoff

After all five provider passes, blind reviewers receive the content-addressed manifest, provider shards, provider concept graphs, validator output, resolved-decision extracts, and a cross-platform disagreement queue. They may inspect source rows through file/ordinal/hash provenance. Cross-platform merge begins only then; this BigCommerce pass must not be treated as evidence that similarly named fields from another ecosystem are equivalent.
