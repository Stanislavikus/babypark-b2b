# Assets Interaction Standard — 2026-10-08

> **Status: [Resolved — Product Owner approved 2026-10-08]**
>
> Scope: merchant interaction/presentation only. No MediaAsset identity, storage,
> ProductMedia, VariantMedia, Brand logo authority, or physical-delete semantics
> are changed by this campaign.

## Goal

Make Assets and Brand media presentation predictable for ordinary merchants:

- every image aspect ratio stays inside a stable preview frame;
- quick inspection preserves list context through the approved right-side drawer;
- structured filters have an explicit completion action and close after apply;
- technical labels do not leak into merchant UI;
- the full Asset page remains available for deeper work.

## Current state

The canonical MediaAsset foundation and BrandLogo usage are already live.

The merchant smoke after PR #267 found interaction defects:

- Filament stock image components size the `<img>` but do not provide a stable
  frame, so placeholders and real wide/tall images can align differently;
- Assets neutral click navigates to the full View page instead of the project
  context-drawer pattern;
- `usage_items` leaks as the visible label `Usage items`;
- the Assets filter popover uses the project-wide legacy live-filter default,
  applies immediately, but remains open until the user clicks outside;
- the upload action uses Filament's generic `Відправити` submit label;
- quick details lack useful merchant metadata such as added date and megapixels.

Post-deploy visual acceptance after PR #268 found additional concrete presentation
facts, which are valid grounds to amend this [Resolved] document:

- the detail frame rendered non-square and Brand variants still used rectangular
  geometry;
- Asset detail metadata was visually scattered instead of grouped around the
  preview/file block;
- very small images rounded megapixels to `0,00 МП`, which is technically
  misleading as merchant information;
- Filament's default filter reset button used danger/red styling for a reversible
  view-state action;
- the full-card footer action did not preserve the explicitly desired parallel
  browser context;
- an unused Asset could render an empty `Використовується в` card.

## Research First result

No new dependency is needed.

| Area | Existing ready solution | Result |
|---|---|---|
| Quick view | Filament `ViewAction()->slideOver()`, already used by Product | PASS / ADAPT |
| Structured filter panel | Filament `FiltersLayout::Modal` + `slideOver()` + deferred apply | PASS / ADAPT |
| Upload submit wording | Filament `modalSubmitActionLabel()` | PASS |
| Image fit | Filament image primitives provide `object-fit`, but not the stable merchant frame | PARTIAL |
| Media domain | Existing canonical MediaAsset | PASS; no second media package |

The global `Table::deferFilters(false)` migration compatibility override is not
changed in this campaign. Assets opts into deferred filters locally. Other pages
migrate when materially touched, per `06-UI_DESIGN_SYSTEM.md`.

Post-deploy action-emphasis research also stays integration-first:

| Source | Relevant guidance | Result |
|---|---|---|
| Adobe Spectrum ActionButton | panel/task actions are un-emphasized by default so content keeps attention | PASS / reference |
| Microsoft Fluent 2 Button | avoid high-emphasis buttons in information-rich modal surfaces; keep one primary action prominent | PASS / reference |
| Shopify admin action hierarchy | at most one primary action; supporting actions remain secondary | PASS / reference |
| Filament native Actions | `color('gray')`, `outlined()`, `openUrlInNewTab()` already provide the needed behavior | PASS / implement |

Therefore no custom button system or third-party UI package is introduced.

## [Resolved] Platform UI decisions introduced/clarified

### Stable Media Preview Frame

Media preview is a shared presentation primitive.

A preview frame owns:

- a 1:1 square default geometry for ordinary single-image previews;
- bounded size appropriate to its context;
- neutral background and border;
- centered content;
- `object-fit: contain`;
- identical geometry for placeholder and real media;
- no crop solely to fill the frame.

Post-deploy visual acceptance on 2026-10-08 proved that merely bounding width and
height was still ambiguous: Asset detail and Brand variants diverged into
rectangular frames. A second production check proved that equal fixed width/height
was still insufficient inside a constrained drawer column because width could
shrink while height remained fixed. The square default is therefore implemented
responsively through width + `aspect-ratio: 1 / 1`, not independent fixed height.

Approved first variants:

- Assets card/list;
- Asset quick/full detail;
- Brand current logo form;
- Brands list.

The underlying MediaAsset is not resized or transformed merely to render this
frame.

### Entity quick review

The existing Product drawer pattern is promoted to the general default for
list-backed entity quick review:

- neutral row/card click opens a right-side slide-over on desktop;
- list context stays in place;
- the drawer contains compact, decision-useful data;
- a visible `Відкрити повну картку` action is provided when a deep page exists;
- `Відкрити повну картку` opens in a new browser tab so the original list/drawer
  context remains available;
- the modal close action remains explicit.

Assets adopts this pattern now. Brand list inspection also adopts it after
post-deploy acceptance showed that Brand still navigated directly to edit instead
of opening the approved drawer. In Filament lists the quick-view interaction uses
a dedicated forced-modal `inspect` action with `recordUrl(null)`; the visible
`Деталі` action and neutral row both mount the same drawer action. This avoids
resource view/edit routing from becoming the quick-review interaction.

This does not require every existing resource to be mass-migrated in the same PR.

### Assets filter interaction

Assets follows the existing structured-filter standard:

- native Filament table filters;
- `FiltersLayout::Modal`;
- filter trigger rendered as a right-side slide-over;
- local `deferFilters(true)`;
- visible `Застосувати` action;
- Filament's apply action closes the drawer after the filters are applied.

The global legacy live-filter default remains unchanged.

### Asset detail content

Quick/full Asset details show merchant-relevant data only and group related
information instead of scattering fields across the available space.

Top content block:

- square preview on the left;
- on the right: file name, dimensions, megapixels, byte size and MIME/format.

Technical metadata row below:

- storage source;
- technical state;
- added timestamp.

The concrete `Використовується в` section remains separate below. When there are
no usages, it explicitly says `Не використовується` instead of rendering an empty
card.

Internal UUID, SHA, filesystem path and other implementation details are not
ordinary merchant fields.

For External assets the UI must state that lack of an attention warning is not
proof that the remote URL was recently checked.

### Drawer action emphasis

Assets filter/inspection drawers use neutral utility actions by default. Applying or
resetting filters is reversible view-state work, so neither action uses danger
styling. Danger/red is reserved for the future genuinely destructive Asset delete
operation. This follows the platform-wide action-emphasis rule in
`06-UI_DESIGN_SYSTEM.md`.

### Upload wording

The Assets upload modal submit action is `Завантажити`, not the generic
`Відправити`.

A repo-level inventory confirmed other materially different modal flows still rely
on Filament's default submit wording, including Product media/add-variant actions,
workspace/access confirmations and mapping flows. Some are confirmations or
view-only actions rather than upload/save flows, so they must not be renamed
mechanically. `06-UI_DESIGN_SYSTEM.md` now freezes the rule that a materially
touched modal names the business action explicitly. This campaign changes Assets
only.

## Explicitly out of scope

- physical MediaAsset delete;
- replace-across-usages;
- mutation of bytes under an existing MediaAsset ID;
- derivative deletion rules;
- orphan cleanup/recovery;
- changing the global Filament deferred-filter default;
- mass-migrating Brand/Category/other resource filter panels.

These belong to Assets Lifecycle or later resource-alignment campaigns.

## Acceptance evidence

- wide, tall, square and placeholder previews stay inside the same square stable frame;
- Brand form placeholder and real logo use the same square geometry;
- Brands list placeholder and real logo use the same square geometry;
- Assets list/card preview does not crop the subject;
- neutral Asset click opens the slide-over quick view;
- drawer has explicit close semantics and `Відкрити повну картку`;
- `Відкрити повну картку` opens in a new browser tab;
- full Asset page remains directly addressable;
- `Usage items` is not visible;
- date and megapixels are visible in detail;
- External source caveat is visible only for External assets;
- Assets filters are locally deferred, use the slide-over, and expose
  `Застосувати`;
- upload modal submit label is `Завантажити`;
- existing Assets workspace isolation/upload/usage tests remain green;
- Brand media regression tests remain green;
- Pint, `git diff --check`, exact-head MySQL CI green.

## Routing Decision

- Goal: close merchant interaction defects before Assets Lifecycle.
- Risk: **YELLOW**.
- Why: shared UI primitive and Filament interaction configuration; no DB schema,
  identity, auth, or write-transaction architecture change.
- Architecture: existing/frozen.
- Executor: current implementation executor.
- Post-review: Lead adversarial review.
- Escalation: only if the work exposes new data-lifecycle, auth, identity or
  transaction ambiguity.
- Cost rationale: native Filament primitives plus one shared presentation seam are
  lower-risk than a custom media manager or one-off fixes per resource.


## 2026-10-10 production UX supersession note

Production acceptance after the lifecycle implementation proved the same-identity
replacement semantics correct but exposed unnecessary interaction duplication between
Asset and Brand full cards. The Product Owner requested full-card authoring alignment:
manager-capable Asset full cards now use native Filament EditRecord lifecycle and expose
the replacement FileUpload directly in the full card; read-only inspection drawers stay
read-only. This supersedes only the earlier presentation choice that replacement be
opened from a separate modal. MediaAsset identity, replace/delete guards, deduplication,
retention, Brand association semantics and the shared preview-frame rules are unchanged.
The drawer `Відкрити повну картку` action also closes the originating drawer through the
native Filament action close primitive after opening the new tab.


## 2026-10-10 production feedback amendment — warning visibility and internal note

Production smoke of the unified EditRecord full card confirmed the lifecycle and drawer flow,
but showed that the top-right transient replacement-conflict notification could be missed while
the merchant's attention remained on the image control. The accepted local UX uses Filament's
native warning Callout adjacent to the replacement field for unchanged business conflicts; it
does not globally reposition notifications and does not add a blocking modal. A nullable
MediaAsset `internal_note`, labelled `Коментар`, is added as internal merchant-team metadata and
is excluded from identity/dedup/provenance/channel semantics. Brand full cards expose the same
canonical logo-Asset storage/status/created metadata. Reuse of one Asset by multiple Brands is
intentional and remains allowed.


## 2026-10-10 final production polish — semantic close state and quiet success

Production smoke after the internal-note rollout exposed a browser-only false dirty warning
caused by comparing raw FileUpload serialization. Asset close confirmation now checks semantic
unsaved state: a real temporary UploadedFile and/or a changed internal note. Generic success
toasts are suppressed on Asset edit; lifecycle-required post-save guidance remains local inline.
The full-card media row is aligned with the quick-view pattern: heading above, image and primary
file metadata aligned, secondary technical metadata below the merchant text field.


## 2026-10-10 production-smoke follow-up — live visual state and close lifecycle

Production smoke found three interaction defects that unit-level lifecycle correctness did not
surface: a second browser-native `beforeunload` prompt after confirming the Filament close modal,
a stale persisted-image view after same-ID replacement, and stale listing thumbnails when a
full card was closed back to its originating tab. The accepted correction keeps Filament's
unsaved protection but unmounts the confirmed close action before browser navigation, keeps the
schema-bound model instance synchronized after Save, and refreshes the same-origin opener listing
on explicit close. Brand edit now stays on the card after Save and keeps the persisted logo visible
while a new upload is only pending. The existing Asset delete action moves from the header to a
visually separated danger action in the form footer; delete semantics themselves are unchanged.
