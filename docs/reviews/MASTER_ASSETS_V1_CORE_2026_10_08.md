# Master Assets v1 Core — 2026-10-08

> **STATUS: [Resolved — 2026-10-08] — PRODUCT OWNER APPROVED**
>
> Authoritative base: `origin/develop @ a2afbf50c383024eb318b3fd384b8329564da626`.
>
> This contract continues `MASTER_PRODUCT_MEDIA_PERSISTENCE_ALIGNMENT_2026_10_01.md`
> and `MASTER_VARIANT_MEDIA_AUTHORING_IMPLEMENTATION_CONTRACT_2026_10_03.md`.
> It does not reopen MediaAsset identity, ProductMedia/VariantMedia ownership, or
> certified Magento Product media semantics.

## Goal

Provide one workspace-owned Assets library where a merchant can upload an image once,
inspect it, find it again, and reuse the same MediaAsset from Product, Variant and the
next Brand-logo UX campaign without introducing a second media domain.

Observable result:

- `Каталог → Assets` exists;
- Original image upload creates or reuses the canonical `MediaAsset`;
- existing Product image upload consumes the same shared ingest owner;
- Managed and External Originals remain visible and distinguishable;
- usage is visible without creating another ownership table;
- Brand logo is a required downstream usage of the same MediaAsset identity;
- physical asset delete is intentionally not part of this campaign.

## Frozen authority

The canonical media authority remains:

```text
MediaAsset
  ↕
ProductMedia / VariantMedia / Brand.logo_media_asset_id
```

No Curator, Spatie `media`, file-manager table, filesystem path, connector record, or
channel rendition may become a competing asset identity.

`MediaAsset` remains workspace-owned, reusable, channel-neutral, and Original when
`parent_media_asset_id IS NULL`. Managed Originals use `storage_disk + storage_path`;
External Originals may use `source_url`. Content-hash dedupe is only within one Workspace.

## Research-first / integration-first gate

Current free Filament media-manager candidates were checked before code.

| Candidate | Functionality | License | Activity | Upgrade | Integration with frozen MediaAsset | Verdict |
|---|---|---|---|---|---|---|
| Filament Spatie Media Library plugin 5.x | PARTIAL | PASS — MIT | PASS | PASS | FAIL — requires Spatie media authority | REFERENCE only |
| awcodes/filament-curator 5.x | PASS | PASS — MIT | PASS | PASS | FAIL — owns Curator persistence/lifecycle | UX REFERENCE only |
| TomatoPHP Filament Media Manager 5 | PASS | PASS — MIT | PASS | PASS | FAIL — Spatie media + own pivots | REJECT dependency |
| Slimani Filament Media Manager | PASS | PASS — MIT | PASS | PASS | FAIL — own media domain + Spatie | REJECT dependency |
| VoodMedia | PASS | PASS — MIT | PASS | FAIL on current PHP 8.3 | FAIL — Spatie domain | REJECT |
| Outerweb Image Library | PARTIAL | PASS — MIT | PASS | FAIL on current PHP 8.3 | FAIL — own image/source-image domain | REJECT |
| MWGuerra FileManager | PASS as file manager | PASS — MIT | PASS | PASS | FAIL — filesystem/file authority bypasses MediaAsset | REJECT |

Decision: **ADAPT**, not install. Reuse Filament/Laravel primitives and the existing
BabyPark Media domain; use Curator/Shopify/Pimcore patterns as UX references only.

## Scope

### In scope

- `Каталог → Assets`;
- image-only Original merchant ingest;
- shared Original image ingest owner;
- grid/list library, upload, search and preview/details;
- Managed / External source distinction;
- read-only usage information and usage filters;
- reusable asset picker contract;
- bounded upload/file/pixel limits;
- safe filename presentation;
- existing Product upload cutover to the shared ingest owner;
- documentation truth sync.

### Explicitly out of scope

- physical MediaAsset delete;
- folders, tags or DAM taxonomy;
- crop, background removal, enhance/upscale or AI;
- channel-specific renditions or CDN redesign;
- video/PDF/document authoring;
- SVG ingest;
- new media permissions;
- Brand form changes themselves.

## Shared Original image ingest

The current Product-specific MediaAsset creation path is extracted into one Media-owned
service. The service owns admission, diagnosis, same-workspace dedupe/reuse, managed
Original persistence and provenance.

Conceptual sequence:

```text
UploadedFile
→ caller authorization
→ application file-size admission
→ image header / actual type diagnosis
→ width × height admission
→ SHA-256
→ enter caller-controlled DB transaction
→ lock Workspace only for canonical dedupe/create section
→ same-workspace hash lookup
→ reuse existing Original OR persist new managed Original
→ return MediaAsset + newly-written path evidence
```

The implementation must not claim full pixel decode validation. Current v1 diagnosis is
header/type inspection plus bounded file/pixel limits.

## Transaction ownership and Product atomicity

**ProductUploadRemainsAtomic** is a required invariant.

Today Product upload creates `MediaAsset` and `ProductMedia` in one caller transaction
and removes newly-written files if the transaction fails. Extracting shared ingest must
not weaken this.

Rules:

- shared ingest must be callable inside a transaction owned by the caller;
- Product upload keeps asset ingest + ProductMedia association in the same transaction;
- ingest returns enough evidence about newly-created storage paths for rollback cleanup;
- an already-reused MediaAsset is never deleted by rollback cleanup;
- a pure upload from Assets may own its own transaction;
- if ProductMedia persistence fails after new-asset ingest, neither the new MediaAsset
  row nor the new file may remain.

The shared service must not unconditionally commit an independent transaction when
invoked from Product upload.

## Lock scope

File inspection, dimensions, file-size calculation and SHA-256 computation happen
**before** the Workspace row lock.

The Workspace lock covers only the canonical same-workspace hash lookup/recheck and
creation section. Do not hold the Workspace lock while reading or hashing the upload
stream. The database unique constraint remains the final race/bypass guard.

## Platform Image Admission Standard v1

The platform defines a safety envelope for an Original image. It does not define one
universal commerce-channel image size.

| Rule | v1 value |
|---|---:|
| Application file limit | 20 MiB / 20,971,520 bytes |
| Maximum image area | 25,000,000 pixels |
| Minimum width/height | none |
| Required aspect ratio | none |
| JPEG | admitted |
| PNG | admitted |
| GIF | admitted |
| WebP | admitted |
| AVIF | admitted |
| SVG | not admitted in v1 |

The 25 MP test is `width × height <= 25,000,000` and must run before permanent managed
storage is written.

Small or non-square Originals may exist. Product, Brand, Google, Magento or other channel
quality/readiness requirements are downstream usage rules and must never mutate the
MediaAsset admission contract.

SVG rejection must be explicit and merchant-readable, for example:

> SVG поки не підтримується. Завантажте PNG, JPEG або WebP.

SVG sanitization/vector handling is a separate later security capability.

### Derivative-hash collision

If uploaded bytes match a same-workspace MediaAsset whose
`parent_media_asset_id IS NOT NULL`, ingest must fail closed rather than silently
promoting the derivative to Original. The merchant receives a human-readable message,
not an internal `invalidOriginal` exception.

## Upload transport envelope

The application business limit is 20 MiB. Infrastructure ceilings must be higher so
the application, not nginx/PHP/Livewire, owns the normal over-limit error.

| Layer | Minimum configured ceiling |
|---|---:|
| application business validation | 20 MiB |
| Livewire temporary upload | 25 MiB |
| PHP `upload_max_filesize` | 25M |
| PHP `post_max_size` | at least 26M |
| nginx `client_max_body_size` | at least 26m |

A PR merge does **not** close this campaign. Closure requires live-chain smoke evidence
after approved deployment/configuration:

- a valid boundary image traverses browser → nginx → PHP-FPM → Livewire → application;
- an image just above 20 MiB reaches application validation and receives a controlled
  merchant message;
- the over-limit case must not fail first as generic nginx 413 / blank server page.

Production configuration is an operational deployment concern. The Lead owns this step
through authorized server access; an executor task must not assume or silently change
production configuration.

## Managed and External Originals

`storage_path IS NULL` does not imply an invalid asset.

- **Managed** — byte content is controlled through `storage_disk + storage_path`;
- **External** — source authority is represented by `source_url`.

The library must display the distinction clearly.

Assets Core does not crawl or download every External asset. Existing diagnosis/evidence
may mark an asset as needing attention. A later critical use may perform bounded
validation through an explicitly approved SSRF-safe seam; this campaign does not create
a new network crawler.

## Assets UI

Navigation: `Каталог → Assets`.

Default surface:

- search;
- upload;
- grid/list toggle;
- image preview or placeholder;
- original filename;
- dimensions;
- byte size;
- Managed / External indicator;
- diagnosis/status indicator when meaningful.

Usage/source views:

- `Усі`;
- `Managed`;
- `External`;
- `Використовується`;
- `Не використовується`;
- `Товари`;
- `Варіанти`;
- `Логотипи брендів`;
- `Потребує уваги`.

`Логотипи брендів` is a usage view/filter, never a `BrandLogo` entity or asset type.

Technical UUID, SHA-256 and storage path stay out of the ordinary merchant view unless a
technical remediation surface explicitly needs them.

## Used in read model

Usage is derived from current authoritative relations, not persisted in another ownership
table.

Current owners/references to count:

- `product_media.media_asset_id`;
- `variant_media.media_asset_id`;
- `brands.logo_media_asset_id`;
- `media_assets.parent_media_asset_id` for derivative lineage.

Historical Preview/Sync snapshots do not become MediaAsset owners merely because they may
contain an old URL or representation.

Usage totals must be loaded with aggregates/eager read models and must not perform N+1
queries per asset. The page acceptance suite must exercise dozens of assets and assert a
bounded query count independent of the number of displayed rows.

## Read and mutation authorization

No new `manage_assets` permission is introduced in this campaign.

Read access mirrors the existing admin Catalog/Product surface:

- a user admitted to the current workspace admin catalogue/Product surface may view
  Assets for that current Workspace;
- the Assets query is always scoped to the current Workspace;
- no filter, URL parameter or record ID may expose another Workspace's MediaAsset.

Mutation/upload/reuse remains governed by
`WorkspacePermissions::MANAGE_PRODUCTS`.

A later Assets lifecycle/security campaign may split view/upload/edit/delete permissions
only through a new explicit Product Decision.

## Filename safety

`original_filename` is untrusted merchant input used as display metadata only.

Requirements:

- never use the client filename as managed storage identity/path;
- render filenames escaped;
- long names must truncate/wrap without breaking the grid;
- emoji and Unicode remain display-safe;
- HTML/script-like names render as text, never markup;
- storage extension comes from diagnosed image type, not the client filename.

## Physical delete is deferred

Assets v1 Core has no physical MediaAsset delete action.

Delete is a separate lifecycle campaign because it must define and prove reference checks,
derivative relationships, database transaction ordering, filesystem cleanup after
committed DB state, orphan-file recovery and retry/idempotency.

Brand logo UX must not wait for this lifecycle capability.

## Brand-logo downstream acceptance

Brand already stores optional `brands.logo_media_asset_id` referencing a same-workspace
Original image.

Assets Core must make the next Brand UX campaign mechanical:

- upload through the same shared Original ingest owner;
- choose an existing reusable MediaAsset;
- remove the Brand association without deleting the MediaAsset;
- show Brand-logo usage through `Used in`.

The Brand campaign may not create a Brand-specific upload/storage subsystem.

## Required acceptance evidence

### Ingest and safety

- valid JPEG/PNG/WebP upload PASS;
- supported GIF/AVIF header diagnosis remains compatible;
- SVG receives controlled merchant rejection;
- file over 20 MiB receives controlled application rejection;
- 25 MP image admitted;
- image over 25 MP rejected before permanent storage;
- cross-workspace asset reference/reuse fails closed;
- identical bytes uploaded twice in one Workspace resolve to one MediaAsset;
- identical bytes in two Workspaces remain independent MediaAssets;
- identical concurrent uploads resolve deterministically to one same-workspace MediaAsset;
- bytes matching an existing derivative fail closed with merchant-readable remediation.

### Product regression

- Product upload still creates/reuses the shared Original;
- ProductMedia creation failure after new ingest leaves no MediaAsset row and no new file;
- rollback never deletes an already-reused asset;
- ProductMedia primary/order semantics remain unchanged;
- VariantMedia semantics remain unchanged;
- existing Magento Stage3D/Product media regression remains semantically unchanged.

### UI/read model

- Managed and External Originals render distinctly;
- search does not escape the current Workspace;
- usage counts include Product, Variant, Brand logo and derivatives;
- unused view is correct;
- Brand-logo usage view is correct before Brand upload UI exists;
- dozens of assets do not cause per-row N+1 usage queries;
- HTML-like, emoji and very long original filenames are escaped and do not break the grid.

### Live transport gate

After explicit deployment approval and server configuration:

- boundary-valid upload traverses the real browser/nginx/PHP-FPM/Livewire/application chain;
- just-over-20-MiB upload reaches application validation and receives a controlled
  merchant message, not infrastructure 413;
- production runtime limits are captured as acceptance evidence.

## Documentation sync

This campaign must:

1. register this contract in `docs/Project_Documentation_Map.md`;
2. correct the stale current-state statement in `docs/03-DOMAIN_MODEL.md` that says
   MediaAsset/ProductMedia/VariantMedia runtime does not exist;
3. preserve the rule that Media must not become a full DAM in MVP;
4. record no new provider/channel-specific media fields in Product core.

## Routing Decision

- **Goal:** merchant-usable reusable Assets Core over the frozen MediaAsset domain.
- **Risk:** ORANGE.
- **Why:** tenant isolation, reusable identity, upload security and DB/filesystem rollback boundary.
- **Architecture:** existing/frozen.
- **Executor:** Codex; Composer 2.5 fallback if Codex is constrained.
- **Post-review:** Lead adversarial review; optional Grok blind challenge focused on ingest/rollback/read-model boundary.
- **Escalation:** Opus only for a new hard-to-reverse DB/security/identity decision or a root cause unresolved after two fix attempts.
- **Cost rationale:** do not repeat broad research or redesign already-frozen Media persistence.

## Merge and closure gate

Merge readiness requires exact base/HEAD, focused tests, MySQL/concurrency evidence where
applicable, Product/Variant/Magento media regressions, required CI, `git diff --check`,
clean tree and all BLOCKER findings resolved.

Merge always requires explicit Product Owner OK.

Campaign closure additionally requires approved production deployment/configuration and
the live upload-chain evidence above. Merge alone is not closure.
