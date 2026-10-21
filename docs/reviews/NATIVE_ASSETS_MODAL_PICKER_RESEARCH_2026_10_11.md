# Native Assets Picker — Brand-first Research / 2026-10-11

> Research record for #287, capability 1: **Brand logo selection**. Architecture is unchanged. Not a new product-level [Resolved] authority.
>
> Base: `origin/develop @ ec3eadd872aa373d51151cebae7f65692d7cd810`.

## Goal and verified state

Merchant currently sees a filename-only dropdown limited to 50 results in `OriginalImageAssetPicker::make()`, and needs thumbnails, real Assets search and selection without additional storage or custom manager. `BrandResource::form()` already has upload, selection, current preview and guarded `BrandManager::create/update`; the `logo_media_asset_id` is a direct, workspace-scoped FK to an Original `MediaAsset`.

`Filament 5.7.6` is installed. Its **official** `ModalTableSelect` (`vendor/filament/forms/src/Components/ModalTableSelect.php`, `vendor/filament/forms/docs/03-select.md`) opens a native Filament table with searchable columns, `ImageColumn`, action modal and table pagination. **Do not install another media manager.** Product gallery selection is a separate capability: `ProductMediaMutationService` has `addUploadedImages`, `reorder`, `makePrimary`, `remove` but no governed `addExistingAssets`; direct auto relationship saving would skip ordering, legacy projection, tenancy and locks.

## Verified alternative scan (GitHub/official-first)

| Candidate | Functionality | License/free commercial | Activity / version | Upgrade | Canonical MediaAsset integration | Verdict |
|---|---|---|---|---|---|---|
| Filament 5 `ModalTableSelect` + native ImageColumn/Table/Actions | PASS — image, search, single/multiple table selection | PASS — existing MIT dependency | PASS — installed 5.7.6 | PASS — official | PASS — table config only; governed BrandManager still writes | **ADOPT** |
| Existing `Select::searchable()->allowHtml()` | PARTIAL — thumbnails in dropdown; no full table, paging/filter | PASS — MIT, installed | PASS | PASS | PASS | Reject: merchant rejects filename dropdown interaction |
| `ralphjsmit/filament-record-finder-pro` | PASS — table picker, Filament 5 | FAIL free — €59 paid plugin with private composer auth | PASS as advertised | PARTIAL — external package | PARTIAL — extra plugin for an already-native capability | Reject |
| Spatie / Curator / Tomato media managers | PARTIAL generic media UI | MIT (verify each exact tag before install) | Versions vary | PARTIAL | **FAIL — second media authority / tables** | Reject, previously frozen |

Official primary documentation: `vendor/filament/forms/docs/03-select.md` sections “Selecting options from a table in a modal” and “Passing additional arguments…”. External merchant UX reference: https://help.shopify.com/en/manual/products/product-media/add-media (product Media: upload, drag-and-drop, select existing). Paid plugin: https://filamentphp.com/plugins/ralphjsmit-record-finder-pro .

## Implementation and security boundaries

- Replace **only** Brand's text picker with official `ModalTableSelect` backed by an `OriginalImagesTable` configuration (table query returns current workspace, `asset_type=image`, `parent_media_asset_id IS NULL`), existing `MediaPreviewFrame` and `MediaAssetSourceResolver`, searchable filenames/URLs.
- Keep it as **form-state-only** field, not `->relationship('logo', ...)`. Native relationship auto-save is prohibited because the current `BrandManager` is the authorized mutation boundary. No raw Eloquent attach/save in picker.
- An independently scoped label resolver and **BrandManager::validatedLogoMediaAssetId** reject foreign workspace, derivative or wrong-type tampering at actual SAVE. Table filtering alone is not a security boundary (explicit native Filament warning).
- No new persistence or production dependencies. Create and Edit both remain governed by same writer; upload clears picker and picker clears pending upload as before.
- Acceptance: real GitHub-hosted Chromium shows modal, thumbnail, search and selection; Create/Edit saved logo points to the existing canonical MediaAsset, no copy; PHP ensures scope/invalid selection; targeted tests then full MySQL and browser on frozen HEAD.

**Follow-on**: Product multiple selection requires an explicit addition to existing `ProductMediaMutationService` with workspace locks, Originals validation, idempotence, primary/order, legacy projection and regression. No quick Filament auto-attach without approved existing invariants.
