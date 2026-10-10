# TASK FOR EXECUTOR — Remote Catalogue Projection V2

> **Status: DRAFT implementation task.**
> Execute from fresh `origin/develop` only after the 2026-09-22 Workbench visual/data-state freeze is merged.

## ROUTING DECISION

**Goal:** enrich the immutable Magento Remote Catalogue projection so the default `Огляд` can show a genuinely useful store catalogue without per-Product HTTP N+1 calls.
**Risk:** YELLOW
**Why:** substantial READ-side connector + projection persistence change on frozen architecture; no external writes, auth redesign or trust mutation.
**Architecture:** existing/frozen keyset/bounded Remote Catalogue scanner + immutable successful snapshot pattern.
**Executor:** Codex / Composer 2.5.
**Post-review:** Lead AI + real-target READ validation.
**Escalation:** only if Category/Brand/media semantics require a new identity/workspace/domain decision.
**Cost rationale:** no Opus/frontier architecture review required.

## Goal

On a real Magento account, one successful bounded catalogue scan populates the compact fields needed for the target Overview:

- thumbnail/media locator;
- SKU;
- name;
- Attribute Set ID/context;
- provider Category IDs/path support;
- provider Product type/status/freshness;
- provider brand-like source only after its connector semantics are resolved.

No per-Product detail request may be introduced.

## Authoritative base

Fresh `origin/develop`; record exact SHA.

## Mandatory reading

1. `docs/Project_Documentation_Map.md`
2. `docs/05-AI_WORKING_AGREEMENT.md`
3. `docs/PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md`
4. `docs/reviews/PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md`
5. `docs/reviews/PRODUCT_WORKBENCH_VISUAL_UX_CONTRACT_2026_09_22.md`
6. `docs/reviews/PRODUCT_WORKBENCH_REMOTE_CATALOGUE_PROJECTION_V2_REAL_MAGENTO_STUDY_2026_09_22.md`
7. current Remote Catalogue scanner/request/client/persistence/tests;
8. Adobe observed Attribute Set/option lineage models;
9. current category relation/target semantics.

## NON-NEGOTIABLE

1. Fetch/record base SHA.
2. One campaign branch + Draft PR.
3. Preserve bounded keyset enumeration and latest-successful-snapshot semantics.
4. Failed/incomplete/target-changed scan must never replace the current successful snapshot.
5. No per-Product HTTP request.
6. Do not persist arbitrary full Product JSON.
7. Do not hardcode `manufacturer == canonical brand`.
8. No external writes.
9. No changes to ExternalRecordLink trust semantics.
10. No Magento CREATE/import in this campaign.
11. Keep response-body safety limits fail-closed.
12. Test each slice and inspect diff before continuing.

## Real-target evidence already established

READ-only 2026-09-22 probe on the certified test account proved the normal Product list response carries:

- `attribute_set_id`;
- `extension_attributes.category_links`;
- `media_gallery_entries`;
- `custom_attributes` including image/thumbnail fields, `manufacturer`, SEO fields and Product-specific attributes.

All 19 target Products in one full page:
- body 144,484 bytes;
- average ~7,604 bytes/Product.

A selective shape that still included `custom_attributes`:
- body 133,631 bytes;
- average ~7,033 bytes/Product.

Therefore a richer scanner is viable, but pageSize=200 is too close to the existing 2 MB safety ceiling for unknown larger catalogues.

## Expected implementation

### A. Richer page request

Keep the existing keyset boundary.

Use a conservative richer-page size, initially around 100 unless tests/evidence justify another value.

Request only needed top-level/nested collections.

Because Magento returns the whole custom-attribute collection when requested, do not assume the `fields` query alone solves payload size.

### B. Compact persisted projection

Add only the normalized fields required by Workbench/filtering.

Exact physical schema is executor-owned within existing conventions, but semantics should support:

- remote Product identity;
- SKU/name/type/status/updated;
- external Attribute Set ID;
- thumbnail/media locator;
- zero/one/many Category IDs or a normalized/reference representation suitable for filtering;
- optional connector-owned brand-like raw/reference value when resolved.

Do not persist full arbitrary attributes as a JSON dumping ground.

### C. Attribute Set label

Resolve through existing observed `AdobeProductAttributeSet` data where current/non-missing.

Missing label must degrade gracefully to ID/unknown, not fail the scan.

### D. Category dictionary/path

Product payload gives Category IDs.

Add/reuse one account/target-scoped provider Category dictionary/tree read so IDs can be rendered/filterable as breadcrumbs without per-Product Category GET calls.

Do not convert remote categories into Master Categories just for display.

### E. Thumbnail policy

Choose/test one deterministic locator source from:
- image/small_image/thumbnail custom attributes;
- media gallery entries/types.

Handle missing/disabled/multiple media safely.

No binary media download during catalogue scan.

### F. Brand-like provider field

Do not hardcode canonical brand semantics.

Use connector-specific schema/field evidence to resolve the display source.

If the target only proves provider `manufacturer`, display provider label/semantics until a canonical Brand mapping is explicitly frozen.

Reuse persisted option metadata for ID→label resolution when applicable.

### G. Projection query

Extend the current merchant query projection/filtering without introducing hidden remote reads.

Overview reads remain DB-only after scan publication.

## Required tests

At minimum prove:

- richer request shape signs/reads correctly;
- keyset ordering/boundary invariants unchanged;
- response-size exceed fails scan safely;
- failed scan does not replace current snapshot;
- target change still fails publication;
- missing media/category/brand/attribute-set values are tolerated;
- no per-item transport call count regression;
- compact fields persist into snapshot items;
- current-snapshot merchant query returns the new fields;
- workspace/account/target scoping remains intact;
- MySQL constraints/migrations pass.

## Real validation

After automated tests:

1. run real READ-only scan on the certified Magento test account;
2. confirm all current target Products are received;
3. record page count/body-size evidence;
4. verify at least representative rows expose:
   - thumbnail/media locator when provider has one;
   - Category IDs/path;
   - Attribute Set;
   - brand-like provider value if resolved;
5. verify the target is unchanged and no writes occurred.

## STOP conditions

STOP only for new unresolved isolation/identity/domain semantics, missing target access, or a blocker not resolved after meaningful correction attempts.

Do not STOP merely because optional provider values are absent.

## Executor report

Report:
- base SHA;
- final HEAD;
- changed files;
- migration/schema choice;
- literal tests;
- real-target evidence;
- transport call counts/page/body-size evidence;
- unresolved provider field semantics if any;
- clean tree;
- Draft PR.
