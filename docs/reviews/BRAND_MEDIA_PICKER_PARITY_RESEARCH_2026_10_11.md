# Brand Media picker parity research — 2026-10-11

Status: **Research evidence + Draft UX variant, pending owner visual acceptance.**
Scope: media **selection only** inside Brand Media, no new upload/editor authority.

## Goal and baseline

The owner accepted the Brand Media form layout from PR #293 Chromium visuals, then
requested the picker to behave as a familiar entry into the existing Media library:
same search, same `Зберігання`/`Використання`/`Потребує уваги` filters,
and switch `Сітка` ↔ `Список`. The picker still selects exactly one
workspace-owned Original image. The current main listing:
- `MediaAssetResource::table()` owns canonical `MediaAssetCardColumns`, filters,
  search and dynamic `contentGrid`;
- `ListMediaAssets::getHeaderActions()` owns the `assetLayout` toggle;
- `OriginalImageAssetPicker` uses official Filament 5.7.6 `ModalTableSelect`
  with `OriginalImagesTable`.

## GitHub-first and official API verification

| Candidate | Functionality | License/commercial | Activity/updates | Integration into installed picker | Verdict |
| --- | --- | --- | --- | --- | --- |
| **Existing Filament 5.7.6 `ModalTableSelect`**, native `SelectFilter`, native `contentGrid` and shared `MediaAssetResource::libraryFilters()` | PASS for same filters and two render modes; PARTIAL for one-click header toggle | PASS installed official dependency | PASS official maintained dependency | PASS native configuration, but mode selector is inside `Фільтри` (not independent header button) | **ADOPT only as testable native draft; UX owner acceptance required** |
| **`hydrat/filament-table-layout-toggle`** | PASS for normal tables, unknown in nested selector | PASS MIT, commercial use | PASS project lists Filament 5 support | FAIL critical embedded-context requirement: official `TableSelectLivewireComponent::table()` explicitly resets `headerActions([])` and `toolbarActions([])`; plugin requires `HasToggleableTable` on the Livewire component, not present in Filament's nested component | REJECT for this picker; not a way around native limitation |
| **`dvarilek/filament-table-select`** | PARTIAL selection table | PASS MIT | FAIL outdated for project | FAIL only Filament 3; official Filament 5 already includes `ModalTableSelect` | REJECT |
| Custom Livewire picker / JavaScript toolbar imitation | Technically possible but unproven | N/A | N/A | FAIL duplicates official selection flow and raises upgrade risk | REJECT; needs explicit new GAP arbitration |

Official exact source:
- https://github.com/filamentphp/filament/blob/v5.7.6/packages/forms/src/Components/TableSelect/Livewire/TableSelectLivewireComponent.php
  resets header, toolbar, record and empty-state actions after `::configure()`,
  while preserving configured native filters and `contentGrid`.
- https://github.com/filamentphp/filament/blob/v5.7.6/packages/tables/src/Concerns/HasFilters.php
  provides `getTableFilterState()`.
- https://github.com/filamentphp/filament/blob/v5.7.6/packages/tables/src/Table/Concerns/HasContent.php
  supports `contentGrid(Closure | array | null)`.
- https://github.com/Hydrat-Agency/filament-table-layout-toggle
- https://filamentphp.com/plugins/david-varilek-table-select

## Native draft in PR #293 — not frozen final UX

1. Extract existing native filter instances to `MediaAssetResource::libraryFilters()`;
   use in full listing and picker. Filter semantics and query services unchanged.
2. Picker uses a native `SelectFilter::make('picker_view')`, choices `grid/list`,
   query no-op. Its current state controls native `contentGrid` without a new
   Livewire component, custom media toolbar or JavaScript. Unlike full listing,
   mode is chosen in native `Фільтри` drawer; this is **not identical header UX**.
3. Picker query reuses `MediaAssetLibraryReadService::originalsQuery()` for accurate
   usage counters, and additionally gates `asset_type=image`.
4. Keep selection across filter/view changes; verify real Chromium tests on
   GitHub-hosted synthetic DB and upload screenshots. Do not assume switching
   or filtering works without actual Playwright evidence.
5. If owner requires the **same independent one-click toggle at top** as main
   listing rather than a native filter choice, **STOP and arbitrate GAP**;
   do not silently replace Filament selector with custom UI.

No new production dependency, DB migration, Asset model, WRITE mutation, or changes
to authorization and deduplication.

## Acceptance still pending

- Full listing filters visible/usable in picker.
- Search preserved in both modes; layout switching works with selection and save.
- Native filter UI does not cause nested modal/picker selection conflicts.
- No horizontal overflow; screenshots compared visually with owner expectation.
- Full MySQL/Pint and Chrome tests at frozen HEAD, owner visual signoff.
