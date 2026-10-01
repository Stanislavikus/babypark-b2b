# Master Product Media persistence alignment — 2026-10-01

STATUS: [Resolved — 2026-10-01] — PRODUCT OWNER APPROVED

Reviewed against repository state and independent Sonnet/Grok challenge. The physical contract below is frozen for #249 unless real implementation evidence proves a blocker.

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
- `storage_disk` + `storage_path` for a managed asset when materialized;
- nullable self-FK `parent_media_asset_id`; `NULL` means an Original, non-NULL means a semantic derivative whose lineage parent is another MediaAsset in the same Workspace;
- `source_url` nullable for external/legacy provenance;
- `original_filename` nullable;
- `mime_type`, `byte_size`, `content_sha256` nullable until materialized/diagnosed;
- image diagnosis: `width_px`, `height_px` nullable;
- `diagnosis_status` with a small technical lifecycle: `pending | ready | attention | failed`;
- `diagnosis_json` for additive machine evidence, not merchant-facing product fields;
- `provenance_json` for source/AI/edit lineage metadata;
- timestamps.

The Original asset row is not channel-specific and is not a responsive/CDN rendition.
Current ProductMedia / VariantMedia gallery associations may point only to Original rows (`parent_media_asset_id IS NULL`).
Semantic derivatives may be retained for lineage, diagnosis and later approved reuse, but do not silently become ordinary gallery items.
Technical channel artifacts remain channel/publication outputs rather than Master gallery assets.

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
`primary` is the Master default, not a permanent channel binding. In the compatibility adapter for certified Magento V1, the primary association is also export position 0; changing the Master primary therefore updates `sort_order` so the selected primary is first. A future Channel Binding may override channel roles without rewriting Master ownership.
Ordering is explicit and is not inferred from MediaAsset creation time.
`locale IS NULL` is the common/fallback association. A non-null locale is an explicit locale-specific creative exception, not alt text and not a default authoring path.

### VariantMedia

Same association semantics for ProductVariant:
- immutable UUID `id`;
- `workspace_id`;
- `variant_id`;
- `media_asset_id`;
- `role`, `sort_order`, nullable `locale`;
- timestamps.

Variant-specific media does not change Variant identity.
A Variant primary image is never inherited automatically from ProductMedia. A variant presentation may append common Product gallery items after its own VariantMedia rows, but its primary remains explicit. Certified Magento V1 Product image export continues to consume ProductMedia only until a separately scoped child/variant media capability is implemented and certified.

## Legacy compatibility and cutover

1. Add new tables/models without deleting `products.images`.
2. Backfill each valid legacy URL as an image MediaAsset + ProductMedia row in list order. Backfill `sort_order` equals the legacy declaration index, the first association is `primary`, all later associations are `gallery`, and `locale` is null.
3. Backfilled URL assets begin as external legacy references; migration does not download remote bytes. A legacy Product whose `images` value is structurally malformed is left entirely on the legacy path rather than partially backfilled, so existing fail-closed Magento media semantics are not hidden.
4. Backfill is idempotent and runs once per Product. After a Product has first-class media associations, `products.images` is not accepted as an independent write owner. It is a compatibility projection for legacy readers until cutover.
5. New Media read model prefers first-class associations and falls back to legacy JSON only when no first-class rows exist.
6. Adapt ProductExecutionAggregateBuilder to consume ProductMedia ordered strictly by `sort_order`, preserving the exact ordered URL semantics expected by certified Magento V1. VariantMedia is not mixed into that Product export list.
7. During compatibility, writes flow only through the first-class Media service. That service updates the legacy JSON projection when a legacy reader still requires it; direct legacy JSON editing is forbidden after backfill for that Product.
8. Only after regression + real-target verification may `products.images` stop being a runtime compatibility projection. Column removal is a separate later migration.
9. No connector-specific columns or Adobe roles enter MediaAsset.

## Ingest boundary

New merchant ingest must:
- validate workspace authorization;
- preserve the uploaded/fetched Original before transformations;
- compute immutable content hash and diagnosis;
- deduplicate/reuse only within the same `workspace_id`; never create a cross-workspace shared MediaAsset merely because `content_sha256` matches;
- never use a lossy channel output as the parent of a later semantic edit;
- keep external URL fetching behind the existing SSRF-safe transport principles;
- perform no channel transform until a destination or explicit Improve action exists.

This campaign may implement the foundation + image ingest diagnosis + gallery management.
Video/document processing and 360 authoring may remain capability-extensible without fake UI.

## Invariants / tests

- workspace composite FK guards for every association and for `parent_media_asset_id`;
- no cross-workspace Product/Variant/Asset association;
- `parent_media_asset_id` cannot cross Workspace; Originals have null parent;
- ProductMedia / VariantMedia associations used by the current gallery may reference only Originals;
- deterministic single-primary enforcement on MySQL;
- Master primary must be sort position 0 and compatibility projection order must match ProductMedia order;
- stable explicit ordering;
- deleting Product/Variant removes only its association, not a reusable asset used elsewhere;
- deleting MediaAsset is restricted while referenced;
- duplicate content hashes never cause cross-workspace asset reuse;
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

This Stop & Amend is resolved. Repository truth had left Media persistence explicitly unresolved, so the physical contract was frozen here before application code.
Implementation now proceeds in #249 without another architecture review unless real evidence proves this contract insufficient.
