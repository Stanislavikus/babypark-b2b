# Master Variant Media Authoring implementation contract — 2026-10-03

STATUS: [Resolved — 2026-10-03] — PRODUCT OWNER DIRECTION

## Goal

Turn the existing Variant Media capability from a visible pending action into a working merchant workflow without changing Media ownership, introducing inheritance rules, or changing certified Magento Product media export.

Observable merchant result:

Product Media → select one or more Original assets → **Призначити варіантам** → choose concrete variants or all current variants matching one or more axis values → Add / Replace / Detach → Variant-specific gallery and coverage update immediately.

## Authoritative base

Implementation starts from exact `origin/develop`:

`dfa9a44bacc86b58f881063f242fa871bb399238`

Relevant frozen contracts remain authoritative:
- `MASTER_PRODUCT_WORKSPACE_IMPLEMENTATION_CONTRACT_2026_09_30.md`
- `MASTER_PRODUCT_MEDIA_PERSISTENCE_ALIGNMENT_2026_10_01.md`
- `MASTER_PRODUCT_WORKSPACE_DELIVERY_MATRIX_2026_10_01.md`
- `MASTER_PRODUCT_FULL_UI_EXERCISE_ALIGNMENT_2026_10_02.md`

This contract resolves only Variant Media authoring UX/runtime and one additive association-uniqueness invariant.

## Frozen domain boundaries

- `MediaAsset` is reusable workspace-owned asset identity.
- `ProductMedia` owns common Product gallery associations.
- `VariantMedia` owns Variant-specific gallery associations.
- Current gallery associations reference Originals only.
- Variant primary is explicit. Product primary never becomes Variant primary automatically.
- Product common gallery may be appended in presentation after Variant-specific media.
- Current Magento Product media export consumes ProductMedia only.
- Channel artifacts do not enter Master gallery.
- Assignment by axis value is a UI/read-model shorthand only. It is expanded immediately to concrete current VariantIDs and is never persisted as an inheritance/mapping rule.
- First authoring slice supports only common scope `locale = NULL`. Locale-specific creative remains outside merchant authoring for this campaign.

## Association uniqueness clarification

A MediaAsset may occur at most once for the same owner within the same normalized locale scope.

Add STORED generated `locale_scope_key` to both `product_media` and `variant_media`.

Normalization is exactly:

1. trim whitespace;
2. empty string => common scope;
3. replace underscore `_` with hyphen `-`;
4. lowercase;
5. common scope uses reserved key `#common`, which is not a valid BCP-47 locale tag.

Conceptual expression:

`COALESCE(NULLIF(LOWER(REPLACE(TRIM(locale), '_', '-')), ''), '#common')`

Add unique constraints:

- ProductMedia: `workspace_id + product_id + media_asset_id + locale_scope_key`
- VariantMedia: `workspace_id + variant_id + media_asset_id + locale_scope_key`

Existing primary/order constraints remain unchanged. This migration does not introduce per-locale primary or per-locale ordering semantics.

Before adding constraints, migration must fail closed if existing duplicate owner+asset+normalized-locale rows exist. It must never delete or merge rows automatically.

Service layer remains idempotent and must return the existing association for an exact retry. DB uniqueness is the final race/bypass guard.

## VariantMediaReadService

Provide:
- ordered common-scope VariantMedia;
- common ProductMedia separately;
- composed presentation: Variant-specific first, Product common gallery after;
- composed dedupe by `media_asset_id`;
- coverage states:
  - explicit primary;
  - specific media without explicit primary;
  - only common Product media;
  - no media.

No ProductMedia row is copied into VariantMedia.

## VariantMediaMutationService

Implement common-scope operations:
- assign existing Originals;
- bulk assign concrete VariantIDs;
- replace specific media;
- detach selected associations;
- reorder;
- make primary.

Rules:
- authorize `manage_products`;
- validate workspace/Product/Variant/Asset ownership;
- selected variants must belong to the current Product;
- assets must be Originals;
- lock affected Variants in stable ID order;
- bulk operation is atomic;
- exact duplicate assignment is successful idempotent no-op;
- retry does not silently change role or order;
- primary changes only through explicit merchant intent;
- do not use unconditional upsert.

### Explicit-primary rule

Detaching the current Variant primary **must not auto-promote another image**.

After primary detach:
- remaining specific associations are renumbered `0..n-1`;
- every remaining association keeps/receives role `gallery`;
- Variant is allowed to have specific media but no explicit primary;
- coverage must show that state;
- Product primary is never inherited or promoted silently.

If merchant wants a new primary, they choose it explicitly.

### Reorder rule

Because `(workspace_id, variant_id, sort_order)` is unique, reorder must not update rows one-by-one into occupied positions.

Use a safe two-phase reorder / temporary offset equivalent to the proven ProductMedia pattern, then write final `0..n-1` order.

### Bulk sort-order rule

Bulk assignment to N variants must calculate the next position independently per locked Variant and remain atomic. A naive shared `max+1` sequence across variants is invalid.

## Merchant UX

Do not create a permanent separate Variant Media page and do not create a persistent media×variant matrix.

Use one reusable assignment drawer with two entry points:

1. Media section: select assets → **Призначити варіантам**.
2. Variants section: click the compact Photo state for one Variant → open the same drawer scoped to that Variant.

### Drawer — asset side

Show selected Original assets and their order.

Allow explicit intent to make the first selected asset primary.

### Drawer — variant side

Provide:
- search by SKU and option labels;
- filter **Без власного фото**;
- concrete Variant selection;
- quick groups by axis value.

For products with multiple axes:
- show groups separately for every declared axis;
- preserve declaration order of axes;
- first axis group section expanded;
- later axis group sections collapsed by default;
- selecting `Color = Grey` means all **current** variants matching that value;
- selecting `RAM = 16 GB` is an independent selection gesture;
- no group becomes a saved inheritance rule.

### Operations

Expose three distinct operations:

- **Додати до власних фото**
- **Замінити власні фото**
- **Зняти вибрані призначення**

`Замінити власні фото` is destructive and bulk-capable.

Before execution, show a confirmation that includes the number of affected variants. No replace occurs without explicit confirmation.

Common ProductMedia is displayed separately as **Загальні фото товару** and is never replaced by a Variant bulk action.

## Scale behavior

### Simple Product

Hidden default Variant does not expose Variant Media authoring. Merchant uses Product gallery only.

### Small variant family

Variant list shows compact photo state:
- explicit primary thumbnail;
- `Лише загальні`;
- `Немає фото`;
- `+N` for additional specific media.

Click opens the same reusable drawer.

### 20+ variants

Primary workflow is media-first bulk assignment:
select assets → choose axis-value group(s) or concrete variants → Add/Replace/Detach.

No permanent media×variant matrix.

## Required persistence tests

- duplicate common ProductMedia owner+asset rejected by DB;
- duplicate common VariantMedia owner+asset rejected by DB;
- `NULL + NULL` duplicate rejected;
- `de-DE + de-de` duplicate rejected;
- `de_DE + de-DE` duplicate rejected;
- `NULL + de-DE` allowed;
- same asset on another Product/Variant allowed;
- same asset simultaneously in ProductMedia and VariantMedia allowed;
- migration duplicate preflight fails closed and does not repair/delete;
- cross-workspace association rejected;
- derivative association rejected.

## Required mutation tests

- one Original reused by many Variants without a new MediaAsset;
- assignment to 20 variants calculates valid independent sort_order per Variant in one transaction;
- exact retry is idempotent;
- one explicit primary maximum;
- primary must be sort position 0;
- detaching primary leaves remaining specific media with gallery roles and no explicit primary;
- reorder of four associations moving last to first succeeds without transient unique-order violation;
- replace requires explicit operation and replaces only specific VariantMedia;
- detach never deletes MediaAsset;
- ProductMedia removal does not remove VariantMedia;
- Variant deletion removes associations and preserves reusable MediaAsset;
- new Variant created after axis-group assignment receives no media automatically.

## MySQL concurrency proof

Service-level test with two independent workers/connections:
1. same Workspace, Variant and Original;
2. simultaneous assignment starts through a barrier;
3. one creates association;
4. second waits on owner lock and resolves existing association;
5. both calls complete successfully;
6. exactly one VariantMedia row exists.

DB-bypass proof:
1. two direct inserts into the same owner/asset/common scope;
2. one succeeds;
3. the other receives MySQL duplicate-key / SQLSTATE 23000;
4. exactly one row remains.

## UI verification

Test:
- action hidden for simple Product;
- Originals only in picker;
- assignment to one Variant;
- assignment by one axis-value group;
- multiple-axis groups shown separately in declaration order;
- first axis group expanded and subsequent groups collapsed;
- Add / Replace / Detach;
- Replace confirmation includes affected Variant count;
- coverage states including specific-media-without-primary;
- 20-variant bulk flow;
- common Product gallery visibly separated;
- no silent inheritance.

## Connector invariant

Adding, reordering or removing VariantMedia must not change current Magento `ProductExecutionImageInput`.

Run the existing Magento Stage3D/media regression.

When a real Magento target is available, bounded validation is:
- add VariantMedia locally;
- build/preview existing Magento Product image payload;
- payload remains semantically unchanged;
- no new remote VariantMedia write occurs in this campaign.

## Evidence rule

No test is accepted as PASS without literal Pest/PHPUnit/CI output.

Executor report must include:
- exact base SHA;
- exact final HEAD;
- changed files;
- literal focused test output;
- literal MySQL/concurrency output where applicable;
- literal relevant Magento regression output;
- `git diff --check`;
- clean working tree.

## Stop conditions

STOP only for:
- existing duplicate rows that block the fail-closed migration;
- inability to preserve current global primary/order semantics;
- a new unresolved locale ownership rule;
- a real concurrency failure requiring a different transaction design;
- final merge/deploy approval.

Otherwise continue:
implement → test → inspect diff → fix → continue.
