# Media admission limits and commercial storage / research GAP — 2026-10-16

**Status:** non-normative research only. **Not** a `[Resolved]` amendment,
billing proposal, schema change, or authorization to implement/raise limits.
**Repository:** `Stanislavikus/babypark-b2b`; research base
`develop c82e661abac9414f0092698defaadf062f46914a`.

## Goal / owner constraint

The platform is a commercial multi-workspace SaaS, not a single-store image
uploader. Merchants may need large high-quality originals and should eventually
have a way to pay for additional storage, but a defensible per-file and image
processing ceiling must prevent accidental or abusive uploads. **Owner decision
on 2026-10-16: keep existing V1 values unchanged pending independent research
and performance evidence.**

Existing authority:
- `docs/reviews/MASTER_ASSETS_V1_CORE_2026_10_08.md`,
  `Platform Image Admission Standard v1`: 20 MiB = 20,971,520 bytes,
  25,000,000 pixels, one canonical Original ingest with workspace identity and
  SHA-256 reuse. No smaller/higher channel limit may silently replace it.
- The upstream `OriginalImageUrlIngestService` inherits the canonical limits,
  with SSRF-safe transport, strict bounded downloads and separate error handling.
- `docs/reviews/MASTER_PRODUCT_WORKSPACE_IMPLEMENTATION_CONTRACT_2026_09_30.md`
  and `docs/reviews/MASTER_PRODUCT_MEDIA_PERSISTENCE_ALIGNMENT_2026_10_01.md`
  continue to govern the single Original authority, media associations and
  publication projections.
- There is **no approved** tiered storage accounting, price, billing state,
  new quota model, or derivative pipeline implied by this research.

## Primary-source international comparison — snapshot 2026-10-16

These are **different product interfaces**; a channel feed, SaaS file library,
upload API and store display optimizer must not be equated.

| Provider / scope | Vendor-stated constraint | What can and cannot be inferred |
| --- | --- | --- |
| Shopify Admin Files (images) | Max **20 MB**, **25 MP** | Comparable basic DAM intake. `MB` as vendor states it, not BabyPark's MiB; vendor also imposes aspect ratio 100:1–1:100. |
| Shopify Files account storage | **100 GB** Starter/Basic; **300 GB** Grow; **500 GB** Advanced; **1 TB** Plus; **10 TB** Enterprise | Account storage is separate from file admission. Paid storage increases are *early access* and available only on Plus/Enterprise, not proof every merchant can freely buy extra space. |
| Cloudinary image upload / plans | Free **10 MB/25 MP**, Plus **20 MB/25 MP**, Advanced **40 MB/50 MP**, Enterprise custom | Relevant precedent for tier-dependent admission. Cloudinary meters transformations, storage and bandwidth separately, but self-service plans consume one shared credit pool across these resources simultaneously; quota economics are not storage-only. |
| Google Merchant Center product image URL | Up to **16 MB**, **64 MP** | A downstream channel acceptance rule, **not** a general asset-storage upload ceiling. Beginning 2027-01-31 Google will enforce a 500×500 minimum for all categories; this is distinct from the maximum. |
| Adobe Commerce Product image / Media Gallery | Configurable frontend resizing; default maximum processed dimensions **1920×1200**; Media Gallery inserts optimized copies | This is an optimization workflow, **not proof** of a 1920×1200 raw admission cap. Useful for Original vs channel derivative separation. |
| Akeneo Supplier Data Manager import | Media files up to **500 MB**, unless asset family has a lower configured maximum | Specific supplier-media import capability; **not evidence** that every Akeneo PIM asset import or delivery path accepts 500 MB. |

Primary vendor sources (read 2026-10-16):
- Shopify image intake and store/paid storage:
  https://help.shopify.com/en/manual/shopify-admin/productivity-tools/file-uploads
- Cloudinary plan matrix:
  https://cloudinary.com/pricing/compare-plans
- Cloudinary pricing/credits:
  https://cloudinary.com/documentation/billing_and_plans
- Google Merchant image acceptance:
  https://support.google.com/merchants/answer/6324350?hl=en
- Adobe Commerce Product image resizing:
  https://experienceleague.adobe.com/en/docs/commerce-admin/catalog/products/digital-assets/product-image-config
- Adobe Commerce Media Gallery optimization:
  https://experienceleague.adobe.com/en/docs/commerce-admin/content-design/wysiwyg/gallery/media-gallery-image-optimization
- Akeneo Supplier Data Manager asset import:
  https://help.akeneo.com/supplier-data-management-project-and-job-management/manage-your-assets/version/6?kb_language=en_US

## Research synthesis (not a new product decision)

Separate at least three mechanisms:
1. **Per-file admission safety:** bytes, pixel area, frame count,
   compression/memory amplification, decompression limits, URL fetch deadlines,
   count/concurrency, format validation. This must remain bounded even when the
   workspace purchases more capacity.
2. **Workspace storage allowance:** account for currently stored Original
   bytes under the stable `MediaAsset.id`, derivative files if separately
   approved, and temporarily retained physical paths. Under the resolved V1
   Replace lifecycle, the same Asset identity receives new Original content;
   the previous path remains for a 14-day **operational recovery buffer**, not
   merchant-facing version history or permanently immutable Original content.
   Distinguish physical usage from any future billable allowance, and account
   for same-workspace SHA reuse, replacement, deferred cleanup and recovery
   without assuming that retired bytes must be charged as additional versions.
   Avoid promising a billable feature before accurate metering and lifecycle
   definitions exist.
3. **Downstream delivery profile:** normalized/optimized derivatives for Magento,
   Google Merchant and other channels with their own constraints. Channel rules
   should neither overwrite the Original nor redefine platform admission.

The publicly accessible Wikimedia Fronalpstock JPEG (10,109×4,542 =
45,915,078 pixels, ~14.7 million bytes from HEAD) illustrates that low bytes
do not imply safe/acceptable pixel area. Its currently observed non-200 URL
import was independently traced to source-side client identification and is
tracked in a **separate** media-URL defect PR; it is not evidence that 25 MP
validation is wrong.

**40 MB / 50 MP** is only a future test **candidate** supported by the
Cloudinary Advanced comparison. It is not a recommendation to raise limits now,
and is not proven safe for the current PHP/runtime/infra. A larger original
could also require async processing and independently bounded transform output.

## Open questions / required evidence before any [Resolved] proposal

1. **Sample matrix:** merchant images by type, large landscape/portrait,
   transparency, high-compression high-pixel inputs, animation/multiframe
   behavior, corrupt/oversized headers, and near-boundary sizes.
2. **Measured resource envelope:** peak RSS, CPU/time and concurrent requests
   for the exact current PHP processing path and available image libraries, plus
   URL fetch/HTTP transport timeouts and Livewire/FilePond size limits.
3. **Available native/OSS components (Research First):** official Filament,
   Laravel and image processor tools; license/commercial permission, activity,
   integration risk and maintenance. Prefer supported native configuration
   over writing a new image or billing engine.
4. **Cost model:** object-storage bytes, replicas/backups, history retention,
   derivatives, ingress/egress/CDN and CPU; tenancy-safe accounting,
   idempotence, and no double-charge for same-workspace SHA reuse.
5. **Product/UX policy:** warning before local upload for surprising dimensions,
   clear size-vs-resolution errors, paid quota display and upgrades only after
   explicit owner pricing approval, and importer-specific URL access errors.
6. **Adversarial tests:** reject dangerous and unsupported files fail-closed,
   prove no bypass via URL, replacement, bulk import or future connectors,
   no runaway decode, and deterministic cleanup under parallel uploads.

## Shortest safe path / decision gate

**NOW:** retain 20 MiB / 25 MP; leave billing, storage quotas, derivatives,
MediaAsset cardinality and production config unchanged. No implement/deploy work.

**NEXT:** gather reproducible workload data and official OSS/native fit matrix,
then present owner with evidence-based candidate(s), risk and cost. Only after
a separate explicit product decision may code and `[Resolved]` contracts be
changed in a dedicated campaign/PR.

**Stop:** do not assign percentage scores, invent tariff prices, or use channel
limits as universal DAM admission limits.
