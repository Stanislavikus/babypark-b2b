# Product Structure / UX / AI — Implementation Contract for Adversarial Review

**Date:** 2026-09-13
**Status:** implementation proposal; DO NOT CODE until Gemini 3.1 adversarial review is resolved
**Companion:** `PRODUCT_STRUCTURE_UX_AI_FINAL_SYNTHESIS_2026_09_13.md`

## Goal

Deliver a universal workspace-owned ProductType / AttributeGroup structure that makes large field catalogs usable, preserves the existing Field Foundation, enables structural completeness, and provides a safe target for later AI classification/enrichment and bulk review.

The implementation must not become Magento-specific, BabyPark-specific, or a second attribute/value system.

## Required pre-read before coding/review

- `docs/Project_Documentation_Map.md`
- `docs/05-AI_WORKING_AGREEMENT.md`
- `docs/02-ATTRIBUTE_DICTIONARY.md`
- relevant `docs/03-DOMAIN_MODEL.md` ProductType / Category / Field Foundation sections
- `docs/04-ARCHITECTURE_PRINCIPLES.md` and current Architecture Review Checklist
- `docs/reviews/PRODUCT_STRUCTURE_UX_AI_GPT54_ARBITRATION_2026_09_13.md`
- `docs/reviews/PRODUCT_STRUCTURE_UX_AI_FINAL_SYNTHESIS_2026_09_13.md`
- Magento Stage 2 structure/materialization and R4 Receive contracts only where connector evidence boundaries matter.

## Non-negotiable invariants

1. Existing `FieldDefinition` + `FieldBinding` + typed value tables remain the only field/value foundation.
2. Structure placements reference `FieldBinding`, never external field codes and never `FieldDefinition` alone.
3. `merchant_type`, Merchant Category, ProductType, future StandardCategory, and provider schema/type stay distinct.
4. Provider groups/sets/types are evidence; they never silently author internal ProductTypes/Groups.
5. No AI direct writes and no automatic schema creation.
6. No Product/Variant inheritance engine in this initiative.
7. ProductType change must never delete stored values.
8. Current Category cardinality is out of scope; retain existing nullable `category_id` behavior.
## Slice A — Product Structure foundation

### New workspace-owned entities

#### `product_types`

Minimum conceptual columns:
- UUID PK, `workspace_id`;
- stable `code` unique per workspace;
- localized labels, nullable description;
- status/lifecycle;
- protected/default identity for one `Basic Product` per workspace;
- structure revision/version suitable for stale-proposal / impact checks later;
- timestamps.

Rules:
- exactly one default Basic Product per workspace;
- active ProductType code unique inside workspace;
- merchant rename changes label, not stable code/identity;
- archive/delete must be rejected while Products reference the type unless they are first reassigned through the governed mutation flow.

#### `attribute_groups`

Minimum columns:
- UUID PK, `workspace_id`;
- stable code unique per workspace;
- localized labels + nullable description/help text;
- status/lifecycle;
- timestamps.

Group identity is workspace-owned presentation/structure, not provider identity.

#### `product_type_group_placements`

Minimum columns:
- UUID PK, `workspace_id`, `product_type_id`, `attribute_group_id`;
- `sort_order`;
- `is_optional`;
- `default_active` (meaningful only for optional groups);
- timestamps.

Constraints:
- unique `(product_type_id, attribute_group_id)`;
- workspace-safe composite parent FKs where existing MySQL conventions allow;
- required groups cannot resolve inactive.
#### `product_type_field_placements`

Minimum columns:
- UUID PK, `workspace_id`, `product_type_id`, `product_type_group_placement_id`;
- `field_binding_id`;
- `sort_order`;
- `required_for_completeness`;
- timestamps.

Constraints/invariants:
- unique `(product_type_id, field_binding_id)` in v1;
- group placement must belong to the same ProductType and workspace;
- referenced FieldBinding must be active and must target `Product` or `ProductVariant`;
- binding is usable only when `field_bindings.workspace_id IS NULL OR field_bindings.workspace_id = placement.workspace_id`.

Important physical-schema note: `FieldBinding` may be global (`workspace_id NULL`) or workspace-scoped. Do **not** invent an invalid `(field_binding_id, workspace_id)` composite FK that would exclude global bindings. Keep the simple FK to `field_bindings.id`, enforce global-or-same-workspace compatibility in the authoritative mutation service, and cover it with cross-workspace tests.

#### `product_active_optional_groups`

Minimum columns:
- UUID/appropriate PK, `workspace_id`, `product_id`, `product_type_group_placement_id`;
- explicit `is_active` override;
- timestamps.

Constraints:
- unique `(product_id, product_type_group_placement_id)`;
- placement must be optional and belong to Product's current ProductType;
- resolver defaults to placement `default_active` when no override exists.

### Product table

Add `product_type_id` referencing workspace ProductType.

Migration strategy:
1. create one Basic Product per workspace deterministically/idempotently;
2. temporarily add nullable FK;
3. backfill every existing Product to its workspace Basic Product;
4. verify no null/cross-workspace references;
5. make the target relation non-null if MySQL migration safety allows in the same reviewed slice; otherwise use an explicit two-step guarded migration and document the cutover;
6. new Product creation assigns Basic Product automatically without requiring UI selection.
### Basic Product bootstrap from current Field Foundation

Use existing `FieldBinding.field_group`, `sort_order`, object type, status, workspace/global ownership, and admin visibility only as bootstrap evidence.

For each workspace:
- inspect active Product/ProductVariant bindings currently eligible for admin product editing;
- create/reuse AttributeGroups from distinct legacy `field_group` codes using stable deterministic codes/known platform labels;
- create ProductTypeGroupPlacement rows for Basic Product;
- create one ProductTypeFieldPlacement per binding;
- preserve current sort order as initial order;
- do not infer groups from Magento Attribute Groups or any provider labels.

Do not drop or rewrite `field_bindings.field_group` in Slice A. New ProductType UX reads new placements; legacy field_group remains fallback/compatibility state until a later cleanup proves no consumer remains.

### ProductType change services

Implement separate read/decision and mutation seams, conceptually:
- `ProductTypeChangeImpactService` — read-only preview;
- `ProductTypeMutationService` — authoritative transaction.

Impact result must include:
- old/new type identity and structure revision;
- fields/groups added/removed;
- count/list summary of Product/Variant values whose bindings become out-of-type;
- optional-group overrides invalidated;
- required fields newly introduced;
- affected active variants;
- completeness before/after projection where calculable.

Mutation must:
- lock Product and relevant type/structure rows using deterministic order;
- revalidate the preview/type revisions or require a fresh preview;
- update Product type;
- delete/retire only invalid optional-group override rows, never field values;
- leave out-of-type values physically intact;
- invalidate/recompute derived completeness/readiness caches only if such caches exist;
- emit auditable result/finding according to repository conventions.
## Slice B — Structure admin UX + Product editor + Completeness

### ProductType administration

Merchant-facing UI must avoid technical Field Foundation terminology where possible.

ProductType editor:
- label/code/status;
- ordered AttributeGroups;
- required vs optional group state and optional default-active setting;
- within each group: searchable existing FieldBindings, clear Product vs Variant badge, order, and completeness-required toggle;
- adding an existing field only; field creation remains the existing field-management workflow;
- duplicate ProductType action may copy placements, but never duplicate FieldDefinitions/Bindings;
- diff/impact preview before archive or structural destructive edits that affect assigned Products.

### AttributeGroup administration

- create/rename/archive reusable groups;
- localized label/description/help;
- show which ProductTypes use the group;
- do not expose Magento/provider group IDs;
- deleting/archive must not silently remove ProductType placements without explicit impact handling.

### Product editor

Header:
- Product title/SKU/status;
- effective ProductType selector;
- Merchant Category selector;
- compact overall completeness;
- later readiness chips placeholder only if a real readiness service exists.

Left/sticky group rail:
- active required groups + active optional groups;
- completeness badge per group;
- optional group enable/disable action where allowed;
- `AI suggestions`, `Incomplete`, `Additional data` synthetic views.

Main area:
- group sections from ProductTypeFieldPlacement;
- typed existing field inputs, not a new renderer if current FieldDefinition UI/widget seams can be reused;
- filters/search: All, Empty, Required, Incomplete, AI only, Changed/Conflict when those states exist;
- Product fields rendered once;
- Variant fields shown through compact sibling-variant grid/bulk helper rather than duplicate group pages.
### Completeness service

Implement a read service/projection; do not store redundant percentages unless profiling later proves necessary.

Inputs:
- Product;
- effective ProductType structure revision;
- optional active-group resolution;
- optional locale context.

Output at minimum:
- overall required count / filled count / percentage;
- per-group required count / filled count / percentage;
- missing Product binding IDs;
- missing Variant cells `{variant_id, field_binding_id}`;
- active/inactive optional-group state.

Rules:
- only active groups and active placements participate;
- only `required_for_completeness=true` placements affect completeness;
- out-of-type values do not affect completeness;
- inactive optional groups do not affect completeness;
- Product binding is complete when its governed storage contains a valid present value;
- Variant binding is complete when every active Variant has a valid present value; no variants => variant requirement is non-applicable unless a later ProductType variant policy explicitly says otherwise;
- localizable value evaluation uses the requested locale/fallback contract already used by Field Foundation; do not invent connector locale semantics here.

### Deterministic bulk actions before AI

Before AI, ship safe deterministic bulk actions using the same segment/list infrastructure available in the product table:
- assign ProductType;
- activate/deactivate admitted optional group;
- apply one Variant value to selected sibling variants where the binding/value passes the governed writer.

Every ProductType bulk assignment requires impact preview and applies per product with partial-failure reporting.

## Slice C — AI proposal foundation + single-product UX

Do not couple persistence to one LLM vendor.

### `ai_proposal_runs`

Minimum conceptual state:
- UUID, workspace, initiating actor;
- trigger and selection/filter snapshot;
- proposal kinds requested;
- model/provider/model-version + prompt/config version;
- status (`pending|running|completed|completed_with_failures|failed|cancelled` as needed);
- aggregate counts / confidence bands; timestamps;
- optional token/cost/usage metadata, not business authority.
### `ai_proposals`

Minimum conceptual state:
- UUID, run/workspace/product and optional variant;
- proposal kind enum (`product_type`, `category`, `optional_group_activation`, `field_value`, future `standard_category`, `schema_gap`);
- stable target identity fields appropriate to the proposal kind;
- expected/current value snapshot and proposed value;
- confidence;
- structured evidence/provenance JSON;
- source fingerprint;
- target-schema fingerprint/revision;
- status (`pending|accepted|edited_and_accepted|rejected|stale|failed`);
- resolving actor/time;
- created/updated timestamps.

Do not store raw hidden chain-of-thought. Evidence must be concise, reviewable facts/references: source field IDs, excerpts, document/asset refs, connector evidence refs, or web source URLs when that mode is enabled.

### Proposal generation rules

ProductType:
- rank existing active workspace ProductTypes only;
- Basic Product is the fallback/unclassified state;
- use structured fields + title/description + allowed assets as evidence;
- return top candidate(s), confidence, alternatives/evidence; no automatic assignment.

Category:
- rank existing Merchant Categories only in this slice;
- category never silently sets ProductType and ProductType never silently sets Category.

Optional groups:
- rank only optional ProductTypeGroupPlacements admitted by the effective/proposed ProductType;
- do not attach arbitrary workspace groups to a Product.

Field values:
- target only active FieldBindings placed in active groups of the effective/proposed ProductType;
- default priority is empty/questionable fields, not overwriting good merchant data;
- Select/MultiSelect proposals must resolve to declared existing option codes;
- no evidence => no proposal;
- no acceptable structure/option => `schema_gap`, not new schema.

### Acceptance / mutation contract

Before any accept/edit-and-accept:
1. lock/reload current Product/Variant and target schema entities as appropriate;
2. verify proposal is still pending;
3. recompute/verify source fingerprint;
4. verify ProductType/group/FieldBinding/option target still exists and target-schema fingerprint matches;
5. verify expected current value via existing CAS/governed writer semantics;
6. call the existing domain mutation service; never write the value table directly;
7. atomically resolve the proposal with actor and final accepted value.

Any mismatch => mark/report stale/conflict; no write.
## Slice D — bulk AI review

Bulk generation always creates one AIProposalRun and individual AIProposal children.

Required review surface:
- original segment/filter description and frozen selection context;
- total products / generated proposals / no-proposal count / failures;
- candidate buckets (for ProductType/Category/group proposals);
- confidence histogram/bands;
- representative sample from each bucket;
- conflict/stale count;
- explicit exclusions;
- `Approve selected`, `Approve this candidate bucket`, and `Approve >= threshold` actions.

A threshold action is still an explicit merchant decision. Do not add background auto-accept in this initiative.

Application semantics:
- process proposal-by-proposal (or safe chunks) with CAS/revision checks;
- partial success is expected and reported;
- one stale proposal must not roll back unrelated products;
- keep run-level and proposal-level audit;
- no blind time-window undo. A future reverse operation must validate current state before applying an inverse.

## Slice E — Readiness

Do not implement a generic readiness engine until at least one real target rule set is specified from connector/channel contracts.

When implemented, Readiness must remain separate from ProductType structural completeness and be addressable by target/goal, for example:
- Magento export/publish;
- Amazon marketplace/listing context;
- Shopify channel;
- Google Merchant feed;
- B2B storefront.

A target readiness evaluator may consume structural completeness but also uses actual mapping/category/provider requirements, identifiers, media, pricing, availability, localization and connector validation issues.

Amazon PTD is a key design check: marketplace, seller configuration, requirements mode and parentage level change the external schema. Do not encode these dimensions into ProductTypeFieldPlacement requiredness.

## Slice F — connector structure onboarding (deferred from first build)

Do not add a generic mapping table in Slice A/B.

Before Slice F, specify concrete mapping semantics for at least:
- Magento Attribute Set / provider Groups;
- Shopify Standard Category / category metafields;
- Amazon Product Type Definitions / classifications;
- BigCommerce category assignments + custom fields/metafields/options.

Then reuse the existing connector pattern: discover immutable/provider-scoped evidence -> classify/propose -> merchant confirm -> persist typed authority. Never infer a missing provider relationship.
## Authorization / mutation boundaries

Do not introduce role-name checks.

ProductType/Group structural mutations must go through service-level authorization compatible with current workspace authorization direction. Reuse an existing product/field-management permission only if its current contract truly covers this authority. If no suitable atomic permission exists, STOP in implementation and document the smallest permission addition instead of smuggling authority through Admin/Director labels.

Every workspace-owned query/write must be explicitly workspace safe. New joins with Product, ProductType, Group, placement and optional overrides should use composite workspace guards/FKs where possible.

The FieldBinding reference is the deliberate exception where a simple FK plus authoritative service invariant is required because FieldBinding may be global or workspace-scoped. Tests must prove:
- global binding is assignable to any workspace ProductType;
- workspace binding is assignable only inside its own workspace;
- workspace A binding cannot be attached to workspace B ProductType;
- changing a binding's status cannot silently leave an active invalid placement without detection/readiness signaling.

## Deletion / lifecycle rules

Prefer archive/status over destructive delete for ProductTypes/Groups once referenced.

At minimum:
- ProductType referenced by Products: delete RESTRICT / service rejection;
- AttributeGroup referenced by ProductType placements: no silent cascade that erases structure;
- FieldBinding delete already has mapping guards; ProductType placements must add their own reference guard or RESTRICT FK behavior;
- ProductTypeGroupPlacement structural removal must run impact checks if Products actively use it/override it;
- AI proposals targeting archived/deleted/revised structure become stale, not retargeted by label.

## Testing / certification gates

### Schema / tenant tests
- exact DB uniqueness and FK constraints on MySQL, not SQLite only;
- Basic Product uniqueness per workspace;
- Product.product_type_id backfill + non-null target state;
- ProductType/Group cross-workspace isolation;
- global/workspace FieldBinding compatibility invariant;
- no silent cascade of referenced structure.

### ProductType behavior tests
- new Product receives Basic Product automatically;
- specific ProductType assignment through service;
- change impact lists added/removed/out-of-type bindings;
- stored Product/Variant values survive type change bit-for-bit;
- invalid optional-group overrides are removed while values survive;
- stale structure revision rejects apply;
- bulk type change reports per-product partial failure rather than all-or-nothing.
### Bootstrap / compatibility tests
- distinct legacy `field_group` values bootstrap deterministic AttributeGroups;
- Stage2-C workspace custom bindings currently using `characteristics` are placed without provider-specific special cases;
- global canonical Product/Variant bindings and workspace custom bindings can coexist in Basic Product;
- legacy `field_group` remains unchanged after bootstrap;
- no Magento AttributeGroup ID/name is promoted into platform AttributeGroup automatically.

### Completeness tests
- required Product binding present/missing;
- required Variant binding across 0/1/many active variants;
- optional field does not affect score;
- inactive optional group does not affect score;
- activating optional group adds its required fields to completeness;
- localizable field evaluation follows locale context;
- out-of-type retained value does not satisfy unrelated new type requirement.

### UI tests
- Product editor only renders fields admitted by effective ProductType + active groups by default;
- `Additional data` exposes retained out-of-type values without treating them as current structure;
- Product vs Variant ownership is visually distinguishable;
- search/filter works across groups;
- ProductType selector performs preview/confirm mutation path rather than direct model assignment;
- no flat 1000-field default form.

### AI foundation tests (Slice C+)
- proposal generation cannot invent ProductType/Category/Group/option IDs;
- source change marks/rejects stale proposal;
- ProductType/group/option structure change marks/rejects stale proposal;
- acceptance uses CAS and cannot overwrite a user edit made after proposal;
- rejected proposals write nothing;
- edited-and-accepted stores original proposal + merchant final value;
- web evidence remains source-linked and opt-in;
- AIProposalRun scopes all children to one workspace.

### Bulk tests (Slice D)
- filter/selection snapshot captured;
- threshold approval applies only eligible proposal bucket;
- exclusions preserved;
- one stale/conflicting proposal does not rollback successful unrelated proposals;
- final counts reconcile with child outcomes;
- cross-workspace batch leakage impossible.

## Mandatory regression gates

At every implementation slice run the focused suites plus all relevant existing Product/Field/Sync/Connector tests. Before merging a schema/domain slice run MySQL tests for new constraints/concurrency-sensitive paths. Do not claim runtime support based only on SQLite.

No live destructive connector write is required for ProductType/Group foundation because the structure is platform-owned. Connector-driven onboarding later receives its own provider-specific real-target certification.
## Implementation boundary after review

After Gemini arbitration, implementation begins with **Slice A only** unless the review finds that a Slice B concern changes the physical foundation.

Do not scaffold AI tables, Readiness, or generic connector structure mappings together with Slice A merely because they appear in this initiative contract.

Slice A is complete only when:
- schema/models/services exist;
- Basic Product bootstrap/backfill is deterministic and MySQL-safe;
- current products all have an effective ProductType;
- existing field values/mappings remain unchanged;
- ProductType change impact + safe mutation are proven;
- optional group activation foundation is proven;
- bootstrap placements include current global + workspace custom Product/Variant bindings without provider-specific code;
- broad regressions are green;
- docs/evidence updated.

Then implement Slice B and prove real merchant usability before adding AI persistence.

## Gemini 3.1 adversarial review targets

Do not repeat broad PIM research. Attack this implementation contract against the actual repo.

Specifically challenge:
1. Whether one effective default ProductType per Product causes any migration/runtime contradiction with current Product creation/import flows.
2. Whether `ProductTypeFieldPlacement -> FieldBinding` is the correct level and whether any existing code requires definition-level assignment instead.
3. Whether reusable AttributeGroup + contextual field placement avoids both Magento duplication and unwanted global group unions.
4. Whether one canonical placement per binding/type creates a real UX blocker that requires multi-placement in v1.
5. Whether optional groups + per-product activation are worth their persistence complexity and whether the proposed resolver is deterministic.
6. ProductType-change data preservation and `Additional data` semantics: identify any hidden writer/export/import behavior that would corrupt or accidentally publish out-of-type values.
7. Global-or-workspace FieldBinding tenant safety and MySQL FK/index feasibility.
8. Basic Product bootstrap correctness against all current global/workspace bindings, not only Magento materialized fields.
9. Completeness semantics for Variant bindings and localizable fields.
10. Whether AIProposalRun + AIProposal is the minimum safe persistence for bulk review or whether any proposed field is missing/duplicative.
11. Staleness coverage: source revision, target structure, option lifecycle, current value, ProductType change.
12. Whether the plan accidentally creates a second connector-discovery/mapping system.
13. Whether current `field_group` compatibility strategy creates two competing sources of truth.
14. Authorization gaps: identify exactly where current workspace permissions are insufficient rather than using role names.
15. Migration rollback/partial-failure hazards and required MySQL probes.

Return only severity-ranked concrete findings, the smallest correction preserving the goal, and a final verdict:
- `APPROVE PRODUCT STRUCTURE CONTRACT FOR SLICE A`
- or `BLOCK PRODUCT STRUCTURE CONTRACT`.
