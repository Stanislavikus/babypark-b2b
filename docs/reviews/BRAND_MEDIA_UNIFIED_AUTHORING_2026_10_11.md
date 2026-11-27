# Brand Media single-surface authoring — production UX correction (2026-10-11)

**Owner acceptance context:** After PR #290 deployed on `develop` SHA `17686dcfafbba6db8ebc0c0e57e7d946c202f2cc`, the merchant explicitly rejected the separate Brand `Обрати з Assets` form field and restated the already approved combined Media-card concept: local drag/drop/upload and existing-media picker in the same `Медіа` area. PR #290 implemented a better *picker* but did not deliver the approved *placement*.

## Goal and current state

A Brand edit/create card offers one discoverable `Медіа` section: existing-logo preview and metadata, native local drag/drop/upload, and a button opening the existing visual media library. Remove the standalone existing-assets field outside this area. One Brand logo at a time; Product is later a multi-image gallery, never a second source of truth.

The Brand UI presently has separate top-level `FileUpload` and `ModalTableSelect`; their state hooks already clear each other; actual persisted writes are governed by `BrandManager` and `OriginalImageIngestService`. Assets original SHA-256/workspace identity, optional shared references, 20 MiB/25 MP restrictions, guarded Asset lifecycle and save/close behavior are frozen and unaffected.

## Official / OSS research-first

| Candidate | Function | License / commercial | Activity / upgrades | Integration | Verdict |
|---|---|---|---|---|---|
| Installed Filament 5.7.6 `Section`, `Group`, `FileUpload` (native FilePond drag/drop/choose), `ModalTableSelect` (native table with thumbnail, search, selection) | PASS both sources in a single card | PASS MIT; already installed | PASS official maintained v5 | PASS existing BrandManager, no new storage or writer | **ADOPT** |
| Standalone `ModalTableSelect` outside upload card (PR #290) | PARTIAL selection works, discoverability/placement FAIL | PASS | PASS | PASS | **REJECT UX** |
| Media manager / custom JS-FilePond plugin / another storage table | PARTIAL or unnecessary | Varies | Varies | FAIL native MediaAsset authority and/or upgrade surface | **REJECT** |

Official merchant precedents: [Shopify product Media](https://help.shopify.com/en/manual/products/product-media/add-media) provides upload, drag and drop and `Select existing` from inside one Media section. [Akeneo product asset collection](https://help.akeneo.com/en_US/serenity-take-the-power-over-your-products/serenity-enrich-your-product) allows Upload asset and Add existing asset in the same product authoring context. Official installed Filament docs: `vendor/filament/forms/docs/09-file-upload.md`, `vendor/filament/forms/docs/03-select.md`.

## [Resolved] UX location and terminology, product-owner feedback

- **Within each Brand card:** one titled `Медіа` section containing current-logo preview/details and one grouped upload/drop/choose-existing area. Do not present a disconnected existing-assets form field. The current implementation uses official Filament controls; the media picker remains a native Filament library selection dialog (not a filesystem browser and not a second upload) because original IDs must be explicit. UI smoke must inspect actual rendered placement, not only test picker interaction.
- **Language:** `Медіа` is the item/authoring section; `Медіатека` is the reusable library, with RU `Медиа` / `Медиатека` and EN `Media` / `Media library`. This controls merchant UI wording, labels and notices; **not** model name `MediaAsset`, internal `Assets` refs in code, or `/admin/media-assets` route. Translation values are `lang/{uk,ru,en}/media_ui.php`.
- **Reused identical image on Brand upload:** truthful non-blocking `Зверніть увагу` with simpler Ukrainian `Зображення вже є в медіатеці. Використано файл з медіатеки.` (RU/EN localized). `MediaSaveNotice::unchanged` remains unchanged; the Save semantics are not modified.
- **Asset replacement collisions:** existing fail-closed policy remains, localized business message names media library rather than leaking technical `Assets`, no mutation on colliding original. Notification placement and other [Resolved] Asset lifecycle invariants unchanged.
- Brand uploader `storeFiles(false)` / `afterStateUpdated` hooks, modal picker form state and BrandManager validation stay the same, no migration or package. File size/type checks remain native and ingest requires full dimensions/hash check.

## Acceptance evidence to collect

1. Browser: visual `Медіа` section contains actual FilePond dropzone and same-card `Обрати з медіатеки` button; button opens native existing-images library with previews and search.
2. Select existing, Save, reload persists same `MediaAsset` FK; upload reused bytes yields concise in-form notice; brand unchanged Save/Close and Asset shared-image confirmation remain intact.
3. Ukrainian, Russian, English media terms and notices resolve correctly; internal IDs and paths unchanged.
4. PHP Brand/Assets regressions + real GitHub-hosted Chromium + final full MySQL suite and Pint on the same HEAD. GitHub-hosted synthetic data only; never browser against production.

Not part of this campaign: Product gallery actions and product existing-asset association writer, Brand/Products Delete, any new image AI processing. Next Product work is #287, Delete is #288. No merge/deploy without explicit separate owner approvals.
