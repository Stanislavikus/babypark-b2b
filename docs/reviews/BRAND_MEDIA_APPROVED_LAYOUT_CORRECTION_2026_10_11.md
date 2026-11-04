# Brand Media approved layout + canonical Media library selector (2026-10-11)

## Goal

The owner rejected the visually different Brand media block shipped by PR #291
(the native FilePond was displayed as a separate box and the picker action floated
below it), and rejected the picker table when the full Media library already has
an approved card-grid listing. Only an implementation matching the owner's two
provided visuals counts: one dashed upload/choose area and the existing library's
actual card grid, restricted to selection. No added upload header in the picker.

## Existing implementation and research-first

`MediaAssetResource::table()` already provides the approved cards through official
Filament `Split`, `Stack`, `ImageColumn`, `TextColumn`, `contentGrid` and the shared
`MediaPreviewFrame::ASSET_CARD`. `OriginalImagesTable` used a second three-column
row table instead. The official installed Filament 5.7.6 `ModalTableSelect` supports
any Table configuration with `contentGrid`; no new package, duplicate source of
truth, controller or JS media editor is needed. Native `FileUpload` (FilePond)
remains responsible for local uploads. The only form-specific presentation is a
bounded style wrapper around these native components. BrandManager remains the
exclusive Brand writer, with existing workspace/Original validation and
`OriginalImageIngestService` dedup.

| Option | Functional | License/commercial | Activity/update | Integration | Result |
|---|---|---|---|---|---|
| Reuse existing Filament library cards, `contentGrid` in official `ModalTableSelect` | PASS | PASS (installed MIT) | PASS (official Filament 5) | PASS, same columns and resolver | ADOPT |
| Hand-build another picker cards UI or full Media library route in a modal | PARTIAL | N/A | FAIL/unknown maintenance | FAIL duplication of UX logic | REJECT |
| New media plugin/storage table | FAIL unnecessary new authority | Unnecessary | Unknown | FAIL scope and upgrade safety | REJECT |

## [Resolved] Owner correction

1. Extract canonical library card layout once to `MediaAssetCardColumns`; use it in both the Media library listing and `OriginalImagesTable`. Picker only changes query to workspace-scoped Original images, applies grid breakpoints, and retains native single selection. Header upload action is absent. No labels and status semantics are duplicated.
2. Brand `Медіа` section has current-logo preview (if present), one dashed, bounded upload/select area and format notes. No separate filename heading/label before the select action; the saved image remains visible in the current preview above. The inner FilePond panel has no second competing panel background. The user may drag/drop/upload a new image or choose an existing one in one place.
3. No changes to brand logo FK, hashing, transactional writes, source, media lifecycle, permissions, 20MiB/25MP boundaries, or independent product media UX.

## Verification gate

- Real isolated Chromium: assert native FilePond input and select action are **inside the same dashed area**, not merely within the parent `Медіа` section; assert picker has actual `.fi-ta-content-grid` with shared library cards rather than table rows; select, save, reload and shared-asset behavior must pass.
- Targeted PHP regressions and `npm run build` then full MySQL/Pint plus relevant Playwright on the same frozen HEAD; no production browser tests. No merge/deploy without separate explicit owner authorization and, after deployment, owner visual acceptance.

## Post-deploy visual rejection and corrective campaign (2026-10-11)

Owner production screenshots of #292 are **FAIL** for visual acceptance even though
Playwright geometry/selection and MySQL suite passed. Do not treat #292 as accepted UX.

The third supplied owner image is the authority for composition:
- In a single `Медіа` section: image count in heading; current logo preview when present.
- Inside one dashed area: centered picture-plus icon, prominent centered line
  `Перетягніть зображення сюди`, secondary guidance, a **horizontal single row**
  of compact native upload and `Обрати з Assets` actions, and format hint below.
- Brand accepts exactly one logo, Product gallery remains a separate issue.
- A native FilePond nested oversized rectangle **inside** the dashed area is forbidden.
  An isolated picker button **below** FilePond fails acceptance even when their
  ancestor is the same dashed area.
- Picker must preserve native Filament `ModalTableSelect` and reuse the canonical
  `MediaAssetCardColumns`, but its grid is **two cards per row at desktop** within
  available modal width, **one on narrow screens**, never screen-wide three
  columns overflowing the dialog with horizontal scrolling.
- Real Chromium screenshots of both Brand section and opened picker must be uploaded
  as CI artifacts **on successful runs**, not only on failure. Layout assertions
  verify relative alignment, compact uploader and card containment. Owner still
  signs off the final visual result before acceptance.

No schema, media identity, dedup, new uploader implementation, Asset write
semantics, permissions or production browser automation is authorized here.

## Chromium evidence after correction

The original `ModalTableSelect` action invokes `slideOver()` by default in
official installed Filament 5.7.6. The full-height slide-over caused the
reported enormous empty region in the picker. Override only this native action
setting to `slideOver(false)` and keep a bounded native modal width. Desktop
layout is two cards across. Real Chromium screenshots #23 and #25 showed the
side-by-side uploader and the compact, centered picker respectively, while the
browser regression was tightened to take screenshots of the visible modal
window, not its invisible outer Alpine host. Final success screenshots remain
required on one frozen verification HEAD.
