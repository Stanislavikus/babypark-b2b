# Master Assets Lifecycle: Replace + Guarded Delete — 2026-10-09

> **STATUS: PROPOSED — Product Owner approval required before application code**
>
> Base: `origin/develop @ 407c6aac82e0f56c4efb13e0bab50daad0852b4e`.
>
> This campaign continues the frozen contracts in
> `MASTER_PRODUCT_MEDIA_PERSISTENCE_ALIGNMENT_2026_10_01.md`,
> `MASTER_ASSETS_V1_CORE_2026_10_08.md`,
> `BRAND_ASSETS_UX_CLOSURE_2026_10_08.md`, and
> `ASSETS_INTERACTION_STANDARD_2026_10_08.md`.
> It does not reopen MediaAsset identity, ProductMedia/VariantMedia ownership,
> Brand-logo ownership, image admission limits, or Magento media projection semantics.

## Goal

Allow a merchant to safely replace the Original bytes of one canonical Asset and to
physically delete an unused Asset without creating duplicate media authority or silently
breaking Product, Variant, Brand or derivative references.

Observable result:

- the full Asset page exposes `Замінити` and guarded `Видалити`;
- Replace preserves the canonical `MediaAsset.id`, so existing usages automatically see
  the new image after a successful commit;
- Delete never auto-detaches Product, Variant, Brand or derivative relationships;
- used Assets fail closed with a merchant-readable blocker;
- database state is authoritative before retired filesystem bytes are removed;
- cleanup is retryable/idempotent and an orphan-recovery path exists.

## Current state

- `MediaAsset` is the frozen reusable workspace-owned identity.
- `ProductMedia`, `VariantMedia`, `brands.logo_media_asset_id`, and
  `media_assets.parent_media_asset_id` are current authoritative references.
- All four reference families are protected by workspace-aware `RESTRICT` foreign keys.
- Assets library currently exposes read/inspect only; physical delete is intentionally
  denied and was explicitly deferred to this lifecycle campaign.
- Shared image admission already exists in `OriginalImageIngestService::prepare()`:
  20 MiB application limit, 25 MP maximum, diagnosed JPEG/PNG/GIF/WebP/AVIF, SVG rejected.
- Asset drawer is quick inspection. Deeper mutation belongs on the full Asset page.

## Research-first / integration-first gate

Existing Assets Core research already rejected a second media authority. This campaign
rechecked lifecycle-specific capability rather than repeating the whole media-manager study.

| Candidate | Functionality for Replace/Delete | License | Activity | Upgrade fit | Integration with frozen MediaAsset | Verdict |
|---|---|---|---|---|---|---|
| Filament 5 Actions + FileUpload | PARTIAL — confirmation/upload/action UI, no domain lifecycle | PASS — MIT | PASS | PASS — already installed | PASS | **ADAPT** |
| Laravel 13 DB transactions/locks + Filesystem + Queue `afterCommit()` | PARTIAL — safe primitives, no BabyPark reference policy | PASS — MIT | PASS | PASS — already installed | PASS | **ADAPT** |
| Spatie Laravel Media Library + official Filament plugin | PARTIAL/PASS for generic media delete/file cleanup | PASS — MIT | PASS | PASS — current PHP/Laravel/Filament supported | **FAIL** — requires Spatie `media` authority/table | REJECT dependency |
| awcodes/filament-curator 5.x | PASS as generic media manager/delete UX; no compatible BabyPark identity contract | PASS — MIT | PASS | PASS — Filament 4/5, PHP 8.2+ | **FAIL** — owns Curator media persistence/lifecycle | UX REFERENCE only |
| TomatoPHP Filament Media Manager 5.x | PASS as generic manager | PASS — MIT | PASS | PASS — Laravel 12/13 + Filament 5 | **FAIL** — Spatie media + manager pivots become parallel authority | REJECT dependency |

Decision: **ADAPT existing official Filament/Laravel primitives over the frozen BabyPark
MediaAsset domain. Do not install another media manager.**

Primary sources checked:

- Filament 5 Actions confirmation/modal patterns:
  https://filamentphp.com/docs/5.x/components/action/
- Laravel 13 transactions and pessimistic locking:
  https://laravel.com/framework/docs/database
  and https://laravel.com/framework/docs/13.x/queries
- Laravel 13 queued work after transaction commit:
  https://laravel.com/framework/docs/13.x/queues
- Spatie delete semantics:
  https://spatie.be/docs/laravel-medialibrary/v11/basic-usage/retrieving-media
- Curator 5.x:
  https://github.com/awcodes/filament-curator
- TomatoPHP Media Manager:
  https://github.com/tomatophp/filament-media-manager

## External product-pattern check

Pimcore exposes **Replace asset binary data** on the current Asset. Because object
references point to that Asset identity, the reusable Asset remains the object being
referenced while its binary content changes. Pimcore additionally versions previous
states, which BabyPark does not add in this v1 lifecycle slice.

Shopify also treats Replace and Delete as separate file actions. Shopify keeps the
filename but may change the file URL on replace. BabyPark does not copy Shopify URL
semantics because our canonical reference is the MediaAsset UUID, not a URL.

References:

- https://docs.pimcore.com/platform/Portal_Engine/User_Documentation_for_Portals/Asset_Features/Asset_Detail/
- https://help.shopify.com/en/manual/shopify-admin/productivity-tools/file-uploads

## Proposed Product Decision

### 1. Replace means same Asset identity, new Original content

`Замінити` preserves the existing immutable `MediaAsset.id`.

It does **not**:

- delete the Asset and create another UUID;
- mass-rebind ProductMedia/VariantMedia/Brand rows;
- create a parallel asset identity;
- silently merge two existing Assets.

All existing Product, Variant and Brand usages continue to reference the same UUID and
therefore see the replacement after commit.

A replacement upload reuses the existing Platform Image Admission Standard v1. The
new file is prepared/diagnosed/hashed before the mutation lock is acquired.

If an External Original is replaced by an uploaded file, that same MediaAsset identity
becomes a Managed Original: managed storage becomes authoritative and `source_url`
is cleared.
### 2. Dedupe and derivative behavior

- If uploaded bytes equal the current Asset's own `content_sha256`, Replace is an
  idempotent no-op; no new permanent file is written.
- If uploaded bytes equal **another** same-workspace MediaAsset, Replace fails closed
  with a merchant-readable message that the image already exists in Assets.
  v1 does not merge identities or rebind usages automatically.
- If the current Original has derivative children, Replace is blocked in v1.
  Replacing a parent without an approved derivative invalidation/regeneration lifecycle
  would make lineage false.
- Cross-workspace replacement always fails closed.

### 3. Guarded physical Delete

`Видалити` is allowed only when the target Original has zero current references from:

- `product_media.media_asset_id`;
- `variant_media.media_asset_id`;
- `brands.logo_media_asset_id`;
- child `media_assets.parent_media_asset_id`.

Delete never silently detaches, substitutes or rewrites any of those usages.

If any reference exists, the action remains visible but cannot complete; the merchant
is told where the Asset is used and must remove/reassign those usages explicitly first.

The existing database `RESTRICT` constraints remain the final integrity guard even if
an application preflight is stale or a concurrent write races the UI.

### 4. Mutation surface

The quick-view drawer remains inspection-only.

Lifecycle mutations live on the full Asset page opened through
`Відкрити повну картку`:

- `Замінити` — one uploaded image, explicit confirmation;
- `Видалити` — danger action with explicit confirmation, enabled only when the fresh
  guarded-delete precondition succeeds.

Replace confirmation must state that the image will change in every current usage of
that Asset. Delete confirmation must state that the Asset and its managed Original file
will be removed.

No bulk Replace/Delete in v1.

### 5. Authorization

No new permission is introduced.

Replace/Delete use the existing workspace-scoped
`WorkspacePermissions::MANAGE_PRODUCTS` mutation authority, matching current upload
and Brand media mutation. Record workspace ownership is revalidated server-side inside
the lifecycle service.

A later permission split remains a separate Product Decision.

## Transaction and filesystem contract

### Replace ordering

Required ordering:

```text
prepare/diagnose/hash upload
→ DB transaction
  → lock Workspace (same canonical hash serialization seam as ingest)
  → lock target MediaAsset
  → revalidate workspace + Original + derivative guard
  → recheck content-hash collision
  → write replacement bytes to a NEW managed path
  → update the SAME MediaAsset row to new storage/hash/metadata
  → enqueue retired-path cleanup with afterCommit semantics
→ commit
→ cleanup job removes only the retired path
```

The old managed path is never overwritten in place before the database commit.

If any database step fails after the new path was written:

- DB state rolls back to the old Asset state;
- the new path is synchronously removed by rollback cleanup;
- the old path remains untouched.

### Delete ordering

Required ordering:

```text
DB transaction
  → lock Workspace
  → lock target MediaAsset
  → fresh zero-reference / zero-derivative check
  → delete MediaAsset row
  → enqueue managed-path cleanup with afterCommit semantics
→ commit
→ cleanup job removes retired managed bytes
```

For External Originals with no managed path, successful Delete removes only the
MediaAsset row.

Filesystem deletion never happens before the authoritative DB mutation commits.
The safe failure mode after commit is an orphan file, not a live DB row pointing at
missing bytes.

### Cleanup retry/idempotency and orphan recovery

Retired-path cleanup is an idempotent queued job:

- dispatch after DB commit;
- safe to retry;
- before deleting, verify no current MediaAsset references the same
  `storage_disk + storage_path`;
- already-missing files count as successful cleanup;
- failures use normal queue retry/failed-job handling.

A narrow maintenance command provides crash-gap recovery for managed Originals under
the BabyPark `media/originals` namespace. It compares stored paths against current
MediaAsset rows and supports dry-run before deletion. It must never traverse or delete
outside that owned namespace.

No new persistence table is introduced for lifecycle v1.

## Architecture invariants

- **CanonicalMediaAssetIdentity** — Replace preserves `MediaAsset.id`.
- **NoSecondMediaAuthority** — no Spatie/Curator/file-manager table or filesystem path
  becomes identity.
- **WorkspaceIsolation** — every read/write/recheck is workspace-scoped.
- **NoSilentReferenceRewrite** — Delete never auto-detaches or substitutes usages.
- **DerivativeLineageTruth** — parent Replace/Delete cannot leave derivative lineage
  referring to content that no longer exists.
- **DatabaseBeforeFilesystemCleanup** — committed DB truth precedes retired-byte delete.
- **RollbackPreservesOldAsset** — failed Replace cannot destroy the previous valid
  bytes/state.
- **HashUniquenessFailClosed** — Replace does not merge two existing canonical identities.

## Expected implementation surfaces

Likely existing files touched:

- `app/Services/Media/OriginalImageIngestService.php` — reuse preparation contract only;
- new focused `app/Services/Media/MediaAssetLifecycleService.php`;
- new idempotent retired-path cleanup job;
- new narrow orphan-recovery Artisan command;
- `app/Filament/Resources/MediaAssetResource.php`;
- `app/Filament/Resources/MediaAssetResource/Pages/ViewMediaAsset.php`;
- focused lifecycle tests plus existing Assets/Product/Variant/Brand/Magento regression.

No schema migration is expected.

## Acceptance evidence

### Replace

- same MediaAsset UUID before/after replacement;
- ProductMedia, VariantMedia and Brand FK values remain byte-for-byte unchanged;
- all those usages resolve the new image after commit;
- current-file hash replacement is idempotent;
- same-workspace hash collision with another Asset fails closed without writes;
- cross-workspace replacement fails closed;
- derivative child blocks replacement;
- External Original → Managed Original replacement succeeds on same UUID;
- failed DB mutation preserves old row + old file and removes only newly written path;
- successful replacement schedules old managed path cleanup only after commit;
- cleanup job retry is idempotent and never deletes a currently referenced path.

### Delete

- unused Managed Original can be deleted and its file is retired post-commit;
- unused External Original can be deleted without filesystem mutation;
- Product usage blocks delete;
- Variant usage blocks delete;
- Brand usage blocks delete;
- derivative child blocks delete;
- DB FK regression proves direct/bypass delete still fails closed when referenced;
- cross-workspace delete fails closed;
- concurrent reference/delete race cannot produce a dangling FK on MySQL.

### UI

- drawer remains read-only quick inspection;
- full Asset page exposes Replace/Delete actions;
- Replace confirmation explains all usages will update;
- blocked Delete exposes a merchant-readable reason rather than an SQL/FK error;
- no generic framework `Submit` wording leaks into the action;
- no bulk lifecycle action appears in v1.

### Regression

- Assets library/upload/search/filters remain green;
- Brand upload/select/remove remains green;
- ProductMedia semantics remain green;
- VariantMedia semantics remain green;
- certified Magento Product media projection remains semantically unchanged.

## Routing Decision

- **Goal:** safe merchant Replace + guarded physical Delete for canonical Assets.
- **Risk:** ORANGE.
- **Why:** reusable identity, tenant isolation, concurrent references and
  DB/filesystem commit boundary.
- **Architecture:** existing/frozen; no new media authority and no expected schema change.
- **Executor after approval:** Codex; Composer 2.5 fallback if constrained.
- **Post-review:** Lead adversarial review focused on lock ordering, rollback,
  reference races and cleanup idempotency.
- **Escalation:** Opus only if a new identity/concurrency/security ambiguity appears or
  root cause remains unresolved after two fix attempts.
- **Cost rationale:** reuse frozen media architecture and official framework primitives;
  do not repeat broad media-manager evaluation already resolved by Assets Core.

## Approval gate

Application code is blocked until Product Owner approves the observable lifecycle
behavior in this document.

After approval, this document status becomes `[Resolved]` in the same campaign branch
before production implementation continues.
