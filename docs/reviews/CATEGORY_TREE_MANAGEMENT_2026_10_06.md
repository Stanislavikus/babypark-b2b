# Category Tree Management — 2026-10-06

> **STATUS: [Resolved — 2026-10-06] — PRODUCT OWNER APPROVED**
>
> Base: `origin/develop @ 4b60ab163a6bc426b0bf29c9af32b395fc0d57f2`.
>
> Reopen only for conflict with a newer authoritative document, a proven runtime blocker,
> or material new evidence.

## Goal

Make the existing workspace-owned Merchant/Catalogue Category usable as a real hierarchy
without inventing a second category domain or writing our own drag/drop tree.

## Research-first decision

Use `solution-forest/filament-nestable-tree` as an **ADAPT** UI dependency.

Verified for the campaign:
- package `1.0.2`;
- MIT license;
- Filament `^4|^5`;
- plain Eloquent `parent_id` supported without `NodeTrait`;
- custom `saveOrderUsing()` is the only persistence path used by BabyPark;
- exact project stack resolves: PHP `^8.3`, Laravel `13.24.0`, Filament `5.7.6`.

| Area | `filament-nestable-tree` | `solution-forest/filament-tree` | Decision evidence |
| --- | --- | --- | --- |
| Functionality | PASS | PASS | Both provide tree UI; selected package provides search, drag/drop, node actions and a custom save hook without requiring its model trait. |
| License | PASS | PASS | MIT; commercially reusable with no product/user free-tier limit. |
| Activity | PARTIAL | PASS | Selected package is newer/younger; the older tree package has a longer adoption history. |
| Upgrade | PARTIAL | PASS | Selected package supports Filament 4/5 but brings `kalnoy/nestedset` transitively even though BabyPark does not use it. |
| Integration | PASS | PARTIAL | Selected package works with the existing nullable `parent_id` model and BabyPark-owned writer; the older package is more opinionated around its tree model/root conventions. |
| Workspace/RBAC/write isolation | PASS | PARTIAL | Selected package can be presentation-only and route all writes through BabyPark authorization/invariants. |

**Verdict: ADAPT `solution-forest/filament-nestable-tree`.** Its weaker maturity/extra transitive dependency are acceptable because the integration boundary is deliberately replaceable and the package does not own Category persistence.

The package owns tree presentation and browser interaction only. It is not a domain writer.
All Category create/edit/activate/reparent/reorder writes remain governed by BabyPark code.

### Replaceability / rollback contract

`solution-forest/filament-nestable-tree` is an optional presentation adapter, not a Category
domain dependency. BabyPark does not adopt its `NodeTrait`, nested-set schema or automatic
persistence. Package-specific PHP references are confined to the Filament tree page; package
assets/theme source and Composer metadata are the other integration points.

If the adapter proves unsuitable, rollback requires no Category data migration and no rewrite of
workspace/RBAC, hierarchy, mutation or B2B filtering logic. Remove the Composer dependency and
published assets/theme source, replace `ManageCategoryTree` with another Filament presentation,
and retain the existing `categories.parent_id`, `sort_order`, `is_active` and BabyPark services.

## Resolved Category management contract

- Category remains one workspace-owned entity with nullable `parent_id`.
- Any practical hierarchy depth is represented by the same entity; there is no separate
  Subcategory model.
- Admin management is a collapsible/searchable tree with:
  - `Додати категорію`;
  - `Додати підкатегорію`;
  - Edit;
  - Activate/Deactivate;
  - drag/drop reorder and reparent.
- `sort_order` is persisted but not manually typed by the merchant.
- `is_active` is explicit. A node is effectively selectable/visible only when it and all
  ancestors are active.
- Deactivating a Category does not deactivate Products and does not rewrite Magento mappings.
- Physical delete remains disabled in this campaign.
- No Category image, description, SEO page or slug is added in this campaign.
- Existing `stock_display_threshold` remains editable.
- `categories.onec_guid` remains legacy connector/category identity debt. Its mere presence
  is not promoted into a new source-ownership contract without separate evidence.
- Reorder/reparent must fail closed if the submitted tree omits/adds/duplicates nodes,
  crosses workspace boundaries or would persist invalid hierarchy.
- Database and writer protection prevent cross-workspace parent relationships.
- B2B parent-category filtering includes effectively active descendants.

## Explicit non-goals

- Standard Category / Google or Shopify taxonomy;
- category-to-provider heuristic mapping;
- physical delete;
- category landing-page CMS;
- media/SEO fields;
- multi-category Product cardinality.
