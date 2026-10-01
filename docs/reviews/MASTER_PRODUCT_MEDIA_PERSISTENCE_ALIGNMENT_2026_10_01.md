# Master Product Media persistence alignment — 2026-10-01

STATUS: Proposed implementation contract. Application code is blocked pending Product Owner approval.

## Goal

Implement the already-approved Media Policy v1 as a first-class, source-neutral Master Media domain
without redefining the merchant UX and without breaking certified Magento REST V1 media export.

## Current state

- `products.images` JSON is the only Master-side runtime storage.
- Master Product Workspace can display that list but cannot manage first-class assets.
- Magento Stage 3D media export is real and certified, but consumes the legacy JSON representation.
- `03-DOMAIN_MODEL.md` explicitly names `MediaAsset / ProductMedia / VariantMedia` as the conceptual target
  and explicitly says persistence is not yet frozen.

## External benchmark check

- Shopify separates media objects/files from Product identity and preserves access to the original source.
- Pimcore treats Assets as reusable first-class files referenced by data objects and recommends highest-quality originals.
- Akeneo separates reusable Assets from Product links and supports images, videos and documents.
- Adobe Commerce media gallery remains a target/channel representation rather than a suitable universal Master model.

## Proposed physical contract

### MediaAsset

Workspace-owned reusable Original identity.

Minimum fields:
- immutable UUID `id`;
- `workspace_id`;
- `asset_type` initially `image | video | document | other` (360 remains a later logical presentation, not dozens of gallery rows);
- `storage_disk` + `storage_path` for a managed Original when materialized;
- `source_url` nullable for external/legacy provenance;
- `original_filename` nullable;
- `mime_type`, `byte_size`, `content_sha256` nullable until materialized/diagnosed;
- image diagnosis: `width_px`, `height_px` nullable;
- `diagnosis_status` with a small technical lifecycle: `pending | ready | attention | failed`;
- `diagnosis_json` for additive machine evidence, not merchant-facing product fields;
- `provenance_json` for source/AI/edit lineage metadata;
- timestamps.

The Original asset row is not channel-specific and is not a responsive/CDN rendition.

### ProductMedia

Association between Product and MediaAsset.

Minimum fields:
- immutable UUID `id`;
- `workspace_id`;
- `product_id`;
- `media_asset_id`;
- `role` initially `primary | gallery`;
- `sort_order`;
- `locale` nullable;
- timestamps.

One Product has at most one `primary` ProductMedia association.
Ordering is explicit and is not inferred from MediaAsset creation time.

### VariantMedia

Same association semantics for ProductVariant:
- immutable UUID `id`;
- `workspace_id`;
- `variant_id`;
- `media_asset_id`;
- `role`, `sort_order`, nullable `locale`;
- timestamps.

Variant-specific media does not change Variant identity.

## Legacy compatibility and cutover

1. Add new tables/models without deleting `products.images`.
2. Backfill each valid legacy URL as an image MediaAsset + ProductMedia row in list order.
3. Backfilled URL assets begin as external legacy references; migration does not download remote bytes.
4. New Media read model prefers first-class associations and falls back to legacy JSON only when no first-class rows exist.
5. Adapt ProductExecutionAggregateBuilder to consume the new read model while preserving the exact ordered URL semantics expected by certified Magento V1.
6. Only after regression + real-target verification may `products.images` stop being a runtime owner. Column removal is a separate later migration.
7. No connector-specific columns or Adobe roles enter MediaAsset.

## Ingest boundary

New merchant ingest must:
- validate workspace authorization;
- preserve the uploaded/fetched Original before transformations;
- compute immutable content hash and diagnosis;
- never use a lossy channel output as the parent of a later semantic edit;
- keep external URL fetching behind the existing SSRF-safe transport principles;
- perform no channel transform until a destination or explicit Improve action exists.

This campaign may implement the foundation + image ingest diagnosis + gallery management.
Video/document processing and 360 authoring may remain capability-extensible without fake UI.

## Invariants / tests

- workspace composite FK guards for every association;
- no cross-workspace Product/Variant/Asset association;
- deterministic single-primary enforcement on MySQL;
- stable explicit ordering;
- deleting Product/Variant removes only its association, not a reusable asset used elsewhere;
- deleting MediaAsset is restricted while referenced;
- legacy JSON backfill is idempotent;
- new read model reproduces legacy ordered image URLs;
- Magento ProductExecutionImageInput is unchanged semantically after cutover adapter;
- source-owned Product authority rules are preserved for media according to future authority policy; no silent assumption that 1C owns all media;
- full MySQL gate before merge.

## Product impact

No new merchant concept is introduced. The merchant still sees a simple Media gallery with primary image,
additional images, ordering, and contextual actions. Technical Original/diagnosis/provenance details stay hidden
unless an asset needs attention.

## Stop & Amend

Required now only because the repository explicitly left Media persistence unresolved.
Once this document is approved, implementation can proceed without another architecture review unless a real blocker
proves the proposed contract insufficient.
