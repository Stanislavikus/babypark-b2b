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

## [Resolved] Platform UI decisions introduced/clarified

### Stable Media Preview Frame

Media preview is a shared presentation primitive.

A preview frame owns:

- fixed or bounded width/height appropriate to its context;
- neutral background and border;
- centered content;
- `object-fit: contain`;
- identical geometry for placeholder and real media;
- no crop solely to fill the frame.

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
- the modal close action remains explicit.

Assets adopts this pattern now. This does not require every existing resource to
be mass-migrated in the same PR.

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

Quick/full Asset details show merchant-relevant data only:

- preview;
- file name;
- storage source;
- dimensions;
- megapixels;
- byte size;
- MIME/format;
- added timestamp;
- technical state;
- concrete `Використовується в` links.

Internal UUID, SHA, filesystem path and other implementation details are not
ordinary merchant fields.

For External assets the UI must state that lack of an attention warning is not
proof that the remote URL was recently checked.

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

- wide, tall, square and placeholder previews stay inside the same stable frame;
- Brand form placeholder and real logo align identically;
- Brands list placeholder and real logo align identically;
- Assets list/card preview does not crop the subject;
- neutral Asset click opens the slide-over quick view;
- drawer has explicit close semantics and `Відкрити повну картку`;
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
