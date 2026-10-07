# Master Brand Entity — 2026-10-07

> **STATUS: [Resolved — effective on merge with explicit Product Owner `OK merge`] — 2026-10-07**
>
> Base: `origin/develop @ 405738c1401ce7a450cda190218e3276a04f8636`.
>
> This decision implements the Brand normalization work explicitly deferred by
> `MASTER_PRODUCT_CARD_UX_CONVERGENCE_2026_10_05.md`.

## Goal

Replace free-text Product Brand with one workspace-owned Master Brand entity without
creating dual authority, without conflating Brand with manufacturer/provider vendor
labels, and without weakening source-owned Product rules.

## Research-first / integration-first gate

No drop-in free Brand module was found that fits BabyPark's Laravel/Filament domain
without importing a second e-commerce backend.

| Candidate | Functionality | License | Activity | Upgrade | Integration | Tenant isolation | Verdict |
|---|---|---|---|---|---|---|---|
| Existing BabyPark `TagManager` + Filament native relation actions | PARTIAL | PASS | PASS | PASS | PASS | PASS | ADAPT pattern |
| Lunar `lunarphp/filament` 2.0 alpha | PASS for ready Brand form/table, FAIL as isolated Brand dependency | PASS — MIT | PASS | PARTIAL — alpha | FAIL — requires PHP ^8.4 + `lunarphp/core` commerce domain | PARTIAL | REJECT as dependency; REFERENCE only |
| TomatoPHP `filament-ecommerce` | PASS as full commerce suite, FAIL as isolated Brand module | PASS — MIT | PASS | PARTIAL | FAIL — owns Products/Orders/CMS/settings and adds broad dependencies | PARTIAL | REJECT as dependency; REFERENCE only |
| Generic Filament/Laravel Brand package search | FAIL — no mature isolated Brand module found | UNKNOWN/PASS varies | FAIL/PARTIAL | UNKNOWN | FAIL | UNKNOWN | REJECT |

The shortest safe path is therefore a native Brand aggregate built from existing
BabyPark workspace/model/Filament patterns. No new production dependency is authorized.

## Resolved semantic boundary

- **Brand** is the commercial Master brand.
- **Manufacturer** remains a separate Platform Library concept.
- Shopify `vendor`, Magento `manufacturer`, marketplace party labels and similar
  provider fields are not automatically Brand.
- Similar names never establish provider identity/mapping.

## Cardinality and authority

- Workspace owns many Brands.
- Product owns `0..1 Brand`.
- `products.brand_id` is the only persisted Product→Brand authority after migration.
- The legacy `products.brand` string column is removed after deterministic backfill.
- No writable compatibility mirror is retained.

The canonical `brand` field remains text-semantic to channels/readers, but its storage
becomes `Relation / products.brand_id`; projection resolves the related Brand name.

## Brand v1 fields

- `id` UUID;
- `workspace_id`;
- `name`;
- optional `logo_media_asset_id` referencing an existing same-workspace Original image `MediaAsset`;
- optional `short_description`;
- `is_active`;
- timestamps.

Brand v1 does not create a second media subsystem. The Brand page may select an existing Original image as its logo; upload/edit/rendition/background-removal workflows remain owned by the existing Media capability. Banner, SEO and landing-page media remain out of scope.

## Identity / names

Brand identity is the internal UUID only. `name` is a merchant-visible label and is never used
as identity or as proof that two Brands are the same. Lunar's current Brand model provides an
independent reference for the same separation: Product stores `brand_id`, while Brand `name` is
not a unique identity key.

No fuzzy or normalized merge is authorized:

- no lowercase/case folding as identity;
- no edit distance;
- no aliases;
- no transliteration;
- no provider-name matching.

For merchant usability, creating a second Brand with the **exact same label** in one workspace is rejected by the governed writer. Brand creation is serialized by the existing workspace row lock, so concurrent creates cannot both pass the exact-label check. This is a duplicate guard, not identity: UUID remains the only identity, while case/accent variants remain separate UUID Brands.

Migration materializes one Brand per **exact legacy label** in a workspace. Distinct case/accent
labels remain distinct Brand IDs unless a merchant later performs an explicit governed merge.
Leading/trailing whitespace is treated as ambiguous legacy data and migration fails closed rather
than silently trimming it.

Production audit on the resolved base found 54 Products with non-empty Brand, 6 exact workspace
labels and 0 whitespace anomalies, so current production data is eligible for deterministic backfill.

## Migration contract

Migration order is governed:

1. preflight legacy Brand labels and field bindings;
2. create `brands`;
3. add nullable `products.brand_id`;
4. materialize one Brand per exact admitted legacy label;
5. backfill Product `brand_id`;
6. verify no non-empty legacy Brand remains unbound;
7. add the tenant-safe Product→Brand FK;
8. update the canonical system Brand FieldBinding from
   `Column/products.brand` to `Relation/products.brand_id`;
9. drop legacy `products.brand`.

If any extra/custom FieldBinding still references `products.brand`, migration stops
instead of dropping a column that another binding claims to own.

Rollback reconstructs the legacy Brand string from the current related Brand name before
dropping `brand_id` / `brands`.

## Source-owned Products

Existing source authority remains unchanged:

- a Product/Variant carrying 1C ownership cannot change Product→Brand assignment through
  Master UI;
- Brand management must not become a bypass around this rule;
- in v1, renaming a Brand referenced by any source-owned Product fails closed.

Deactivating a Brand does not rewrite source-owned Product data and does not remove
existing Product assignments.

A later explicit authority decision may introduce source-label ↔ Master-Brand mapping.
This v1 does not guess it.

## Active / inactive semantics

`is_active` controls availability for new Master assignments and inline creation/search.
New Product assignment rechecks the Brand as active under transaction lock order
`Workspace -> Brand` before Product insert; concurrent deactivation therefore wins or the
assignment wins, but no Product can be newly attached after the Brand has become inactive.

Deactivation:

- does not deactivate Products;
- does not clear existing Product assignments;
- does not hide Products from B2B by itself;
- does not issue provider writes;
- existing Products continue to display the Brand name.

## Product UX

Master Product card:

- replace free-text Brand with a searchable Brand select;
- active workspace Brands are selectable;
- inline Brand creation is available using the existing Filament
  `createOptionForm/createOptionUsing` pattern;
- source-owned Product Brand assignment stays disabled;
- create-page Brand selection works before the Product itself is persisted.

Brand management page:

- standard Filament resource/list/create/edit;
- name, short description, active state;
- optional reference to an existing same-workspace Original image as logo;
- Product count;
- activate/deactivate;
- no Brand-specific upload/rendition workflow, SEO or provider mapping in this slice.

Physical populated-Brand deletion/reassignment is deferred to a separate lifecycle
capability. This slice must not silently cascade Product assignments.

## Read / filter / export cutover

After migration no production consumer may read `products.brand`.

Consumers must project Brand through the relation, including:

- Product admin tables/infolists;
- B2B catalogue search/filter/sort;
- Sync Preview/Live worklist search;
- Preview identity presentation;
- completeness/readiness;
- channel field projection.

Brand filters use Brand UUIDs as their state/URL values and render Brand names only as labels.
No filter or assignment path uses Brand name as identity.

## Provider projection

The canonical field `brand` remains a text value for outbound field mapping. Relation
storage resolves to Brand `name`, never Brand UUID.

No provider Brand mapping/identity table is created in this campaign.

## Architecture invariants

> **BrandHasSingleMasterAuthority** — Product Brand authority is `brand_id`; no independently editable legacy string remains.

> **BrandIsNotManufacturerOrVendor** — provider party/manufacturer labels never become Brand without explicit governed mapping.

> **SourceOwnedBrandAssignmentIsReadOnly** — Brand entity normalization does not weaken 1C Product authority.

> **BrandRelationIsWorkspaceIsolated** — cross-workspace Product→Brand links are impossible at database and service boundaries.
>
> **NewBrandAssignmentRequiresActiveBrand** — new Product→Brand assignment is authorized only while the exact same-workspace Brand is active, rechecked under the governed transaction lock.
