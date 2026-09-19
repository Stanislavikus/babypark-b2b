# Product Structure / UX / AI — Final Research Synthesis

**Date:** 2026-09-13
**Status:** pre-implementation architecture synthesis for adversarial review
**Inputs:** current repository contracts/runtime, GPT-5.4 research + arbitration, Sonnet 5 High research, current official vendor documentation, and an additional Amazon/BigCommerce connector-shape pass.

This is a universal multi-tenant / multi-connector SaaS design. BabyPark is only a certification merchant and must never become the domain model.

## 1. Final lead conclusions

1. Preserve the current Field Foundation. `FieldDefinition`, `FieldBinding`, typed values, `FieldMapping`, and `FieldOptionMapping` remain authoritative; no second attribute system is permitted.
2. `ProductType` becomes a real workspace-owned structural template. It remains distinct from `products.merchant_type`, merchant `Category`, future `StandardCategory`, and every provider product/schema type.
3. Every Product has exactly one **effective** ProductType. Each workspace owns a hidden/default `Basic Product`; existing and newly created products use it until a more specific type is explicitly selected/accepted.
4. ProductType changes are allowed, but only through an impact-aware governed mutation. A type change never deletes field values.
5. `AttributeGroup` becomes a first-class reusable workspace entity for product-editor organization. Magento Attribute Groups are provider evidence and never become these entities automatically.
6. Group reuse is workspace-wide, but **field placement is ProductType-contextual**. Do not create a global `AttributeGroupField` as the structural truth.
7. The structural placement target is `FieldBinding`, not `FieldDefinition`, because Product vs ProductVariant ownership is part of the binding contract.
8. Within one ProductType a FieldBinding has one canonical group placement in v1. The same binding may be placed differently in other ProductTypes. Duplicate editing of one value in several groups is intentionally rejected for v1.
9. ProductType-to-Group assignments are reusable placements with order and `required|optional` activation semantics. Optional groups may be activated per Product.
10. AI may propose activation of **existing optional groups** for a Product; it may not attach arbitrary workspace groups that are not admitted by that ProductType.
11. Merchant `Category` remains a separate classification/navigation concept. This initiative does not change its current nullable single-category physical model; multi-category support is separate future scope.
12. Structural Completeness and goal/channel Readiness remain distinct. ProductType controls what information is expected; connector/channel rules determine whether the product is publishable/usable for a target.
13. AI is proposal-first and reuse-first. It ranks existing ProductTypes, Categories, admitted Groups, FieldBindings, and existing Select/MultiSelect options before reporting a schema gap.
14. AI suggestions must be persistent, reviewable, auditable, stale-able, and applied only through existing governed mutation/CAS seams.
15. Bulk AI work is a first-class run/batch workflow, not thousands of unrelated transient suggestions.
16. Product/Variant UI uses existing ownership. Do not add a new inheritance engine in this initiative; use explicit Product fields plus fast apply-to-sibling-variants helpers for Variant fields.
17. Provider structure onboarding reuses discovery/classification/evidence patterns, but a generic `ConnectorStructureMapping` table is **not** frozen now. Magento, Shopify, Amazon, and BigCommerce expose materially different structural shapes.
18. Existing `onec_guid` / `rozetka_category_id` core columns are genuine universality debt. Record and defer their generic external-identity migration; do not expand the current initiative or add more provider-specific core columns.

## 2. Why the final model differs from both research reports

GPT-5.4 correctly preferred one canonical placement per field/type but initially rejected optional per-product groups. Sonnet supplied strong real-world evidence (especially Pimcore) that optional group activation is a useful escape hatch for products whose schema differs in a controlled way.

Sonnet, however, proposed `AttributeGroup <-> FieldDefinition`. That loses the existing Product/ProductVariant ownership invariant. The repository already defines semantic meaning at `FieldDefinition` and object/storage applicability at `FieldBinding`; ProductType structure therefore must place `FieldBinding`.

The synthesis accepts optional groups but rejects global group-field membership as structural truth. A reusable group is a reusable **section identity**; its concrete fields belong to the ProductType context.

## 3. Minimum conceptual model

```text
Workspace
  -> ProductType
       -> ProductTypeGroupPlacement -> AttributeGroup
       -> ProductTypeFieldPlacement -> FieldBinding -> FieldDefinition

Product
  -> exactly one effective ProductType
  -> zero/one Merchant Category (unchanged in this initiative)
  -> zero/many ProductActiveOptionalGroup

AIProposalRun
  -> AIProposal -> Product / ProductVariant / structural target
```
### ProductType

Workspace-owned, stable identity, localized label/description, active/archived lifecycle, revision/version, and one protected default key for `Basic Product`.

Every workspace receives `Basic Product`. Existing products are backfilled to it; new products receive it automatically. The merchant therefore never has to choose a type just to create a draft product.

### AttributeGroup

Workspace-owned reusable section identity with stable code, localized label/description, status, and lifecycle. It does **not** own provider IDs and does not globally own a fixed field list.

### ProductTypeGroupPlacement

Joins ProductType to AttributeGroup and carries type-local presentation/activation semantics:
- `sort_order`;
- `is_optional`;
- `default_active` for optional groups;
- status/revision as required by repository conventions.

Required groups are always active. Optional groups resolve from the placement default plus an explicit Product override.

### ProductTypeFieldPlacement

The structural source of truth for one FieldBinding inside one ProductType:
- ProductType;
- ProductTypeGroupPlacement;
- FieldBinding;
- sort order;
- `required_for_completeness`.

Enforce one canonical placement for `(ProductType, FieldBinding)` in v1. This avoids duplicate controls and double-counting while still allowing the same binding to appear in different groups across different ProductTypes.
Do not put channel/connector requiredness here. A localizable required field is evaluated for the locale requested by the completeness evaluator; channel/marketplace requirements belong to Readiness.

### ProductActiveOptionalGroup

Workspace/product-scoped override referencing a valid optional ProductTypeGroupPlacement. It cannot activate a group not admitted by the Product's effective ProductType.

Resolution:
- required group -> active;
- optional group with explicit override -> override value;
- optional group without override -> placement default.

## 4. ProductType change lifecycle — frozen behavior

Changing ProductType is a governed mutation, never a direct dropdown save.

Before apply, calculate and show an impact preview:
- fields/groups added by the new type;
- existing stored values whose FieldBindings are not placed in the new type;
- optional-group overrides that become invalid;
- expected completeness delta;
- affected variants;
- readiness targets likely to require recalculation.

Apply rules:
1. Never delete ProductFieldValue / VariantFieldValue rows because a type changed.
2. Values outside the new ProductType become derived **out-of-type data**: retained, excluded from default group UI and completeness, and available in an `Additional data` / reconciliation surface.
3. Invalid optional-group overrides are removed/retired transactionally; their field values remain untouched.
4. FieldMappings and provider structure mappings are not silently rewritten.
5. Pending AI proposals whose source or target-schema fingerprint no longer matches become stale.
6. Bulk ProductType changes use the same impact engine and apply per Product with partial-failure reporting; no giant all-products transaction.

## 5. `FieldBinding.field_group` coexistence

`field_group` remains legacy/fallback platform presentation metadata during this initiative. It is not the new ProductType grouping authority and is not deleted in the first migration.

Bootstrap strategy for each workspace's `Basic Product`:
- create/reuse AttributeGroup records from current effective admin-visible Product/ProductVariant binding `field_group` codes;
- create ProductTypeGroupPlacement rows in current group order;
- create ProductTypeFieldPlacement rows from the current bindings and their existing sort order;
- include global and workspace-scoped bindings that are currently eligible for admin product editing;
- never derive platform groups from Magento Attribute Groups.

After bootstrap, new ProductType/group UX reads the new placements. `field_group` stays compatibility metadata until a later cleanup proves no runtime dependency.
## 6. Completeness and Readiness

### Completeness

Completeness is structural and ProductType-owned: "is the information this product structure requires present?"

V1 rules:
- evaluate only active groups for the Product;
- denominator contains only `required_for_completeness` placements;
- Product binding -> required value must exist on Product;
- ProductVariant binding -> if the Product has active Variants, every active Variant must satisfy the required binding; report missing variant cells, not merely one aggregate boolean;
- localizable fields are evaluated for the requested locale; which locales matter is supplied by caller/workspace context, not stored as connector rules on the placement;
- compute per-group details plus overall completeness;
- optional inactive groups do not participate.

Do not store channel-specific requirements in structural completeness.

### Readiness

Readiness answers "can this product perform goal X?" and may consume completeness plus:
- provider ProductType/schema/category mapping;
- target marketplace required/conditional fields;
- identifiers;
- media;
- price/availability;
- localization;
- relation/variant rules;
- connector-specific validation issues.

Readiness is intentionally a later slice after real internal structure is working. Amazon strongly validates this separation: Product Type Definition schemas vary by marketplace, seller context, requirements mode, and parentage level, and may contain conditional requirements that change over time.

## 7. Product / Variant UX boundary

A ProductTypeFieldPlacement targets a FieldBinding, therefore ownership is explicit.

Editor behavior:
- Product bindings render once in the group section;
- Variant bindings render in a compact sibling-variant grid within/under the relevant group;
- filters include All, Empty, Required, Incomplete, AI suggestions, Changed/Conflicting;
- group rail shows completeness and optional activation state;
- search spans groups;
- source/provenance and AI evidence are available without permanently flooding the form.

Do **not** add parent->variant value inheritance in this initiative. For repeated Variant input provide `Apply to all variants` / selected-variants bulk helpers that perform explicit governed writes. A true inheritance engine requires a separate domain decision.
## 8. AI proposal architecture

AI never writes directly to Product, ProductVariant, Category, ProductType, group state, or typed field values.

Introduce a batch/run envelope plus individual proposals.

### AIProposalRun

Carries:
- workspace and initiating actor;
- trigger (`single_product`, `selected_products`, `saved_segment`, later workflow);
- frozen selection/filter snapshot;
- requested proposal kinds;
- model/provider/version and prompt/config revision;
- run state and aggregate counts/confidence distribution;
- timestamps and optional usage/cost metadata.

### AIProposal

Carries:
- run + workspace + Product (and Variant when relevant);
- proposal kind: `product_type`, `category`, `optional_group_activation`, `field_value`, future `standard_category`, or `schema_gap`;
- target stable identity (`ProductType`, Category, ProductTypeGroupPlacement, FieldBinding, option key as applicable);
- current/expected value and proposed value;
- confidence;
- structured evidence/provenance;
- source fingerprint;
- target-schema fingerprint/revision;
- status: pending, accepted, edited_and_accepted, rejected, stale, failed;
- resolving actor/time.

Acceptance always revalidates source/schema/current-value state and calls the appropriate governed mutation/CAS service. A stale proposal never writes.

## 9. AI evidence order and enrichment rules

Default evidence priority:
1. existing structured Product/Variant data;
2. technical/specification fields already present;
3. title and description;
4. workspace/supplier documents and assets (images/PDF) when explicitly available;
5. connector-side evidence already acquired by governed discovery/receive flows;
6. web enrichment only as an explicitly enabled last-resort source with source links.

For ProductType/Category classification, rank only existing workspace entities. For groups, rank only optional group placements admitted by the selected/effective ProductType. For Select/MultiSelect values, rank only options already declared by the FieldDefinition.

If no acceptable internal target exists, emit `schema_gap`; do not silently create ProductTypes, Categories, Groups, FieldDefinitions, or options.

Akeneo's current AI behavior supports this ordering: catalog data/assets/supplier documents are preferred and web enrichment is documented as a last line of defence; option-based enrichment maps to existing catalog options and is reviewed before saving.
## 10. Bulk review contract

Bulk work is review-first and segment-driven:
1. choose products through filters/saved segment/selection;
2. dry-run/generate proposals;
3. show product count, proposal count, candidate distribution, confidence buckets, conflicts, and a representative sample;
4. allow explicit approval of one candidate bucket and/or a confidence threshold with manual exclusions;
5. apply each proposal through its governed mutation with CAS/revision checks;
6. report per-item success/stale/conflict/failure; never wrap thousands of products in one database transaction;
7. retain per-product audit plus run-level summary.

The platform does not promise a blind time-based `Undo`. Future reversal must be current-state-aware (CAS/revision) or construct a reviewed inverse change set.

Required bulk scenarios:
- Basic/other ProductType -> AI proposes a more specific existing ProductType;
- deterministic bulk assign ProductType with type-change impact preview;
- activate existing optional group for a filtered segment;
- group completeness below threshold -> propose missing values;
- same brand/category/signature -> propose existing Category/ProductType/group;
- review one candidate/confidence bucket across thousands of products without opening each product.

## 11. Connector structural evidence after Amazon + BigCommerce check

Do not freeze a single provider-equivalence model.

- Magento: Attribute Set is a provider-side product-record template and groups organize its form. Existing REST evidence for attribute-to-group membership is incomplete, so group equivalence cannot be guessed.
- Shopify: Standard Product Category can unlock category metafields; this couples taxonomy and schema externally even though the platform keeps them separate internally.
- Amazon: Product Type Definitions are marketplace/seller/parentage-sensitive JSON schemas with required and conditional attributes; Amazon can recommend a product type from item name/keywords. Treat this as provider schema/readiness evidence, not internal ProductType truth.
- BigCommerce: Catalog APIs expose Products, Variants/Options, Custom Fields/Metafields, category assignments and category trees, but no equivalent universal ProductType template that should be assumed to map 1:1.

Therefore first implementation does **not** add a generic `ConnectorStructureMapping` table. Preserve current provider-specific discovery projections and define the internal ProductType/Group model first. A later connector-onboarding slice may introduce typed confirmed mappings only after internal usage and at least Magento + Shopify + Amazon + BigCommerce mapping semantics are specified.

## 12. Universality debt found during research

Current core Product/Category models still contain provider/legacy-specific identifiers (`onec_guid`, `rozetka_category_id`). This is real architectural debt, but it is orthogonal to ProductType/Group/AI UX.

Decision:
- record it as debt;
- do not block Product Structure work;
- do not add new provider-specific columns to core Product/Category;
- migrate those identifiers only in a later generic external-identity initiative.
## 13. Implementation sequence

### Slice A — Product Structure foundation

Implement only:
- ProductType;
- AttributeGroup;
- ProductTypeGroupPlacement;
- ProductTypeFieldPlacement;
- ProductActiveOptionalGroup;
- Product.product_type_id with Basic Product bootstrap/backfill;
- bootstrap from existing FieldBinding field_group metadata;
- governed ProductType-change impact + mutation services.

No AI and no connector-structure mapping in Slice A.

### Slice B — usable structure UX + Completeness

- ProductType / AttributeGroup admin surfaces;
- ProductType structure editor;
- grouped Product editor with Product/Variant ownership;
- optional group activation;
- completeness evaluator and group/overall badges;
- deterministic bulk type/group actions with impact preview.

### Slice C — single-product AI proposals

- AIProposalRun + AIProposal persistence;
- ProductType/Category/optional-group proposals;
- field-value enrichment against active ProductType structure;
- evidence/confidence/review/staleness;
- governed accept/edit/reject.

### Slice D — bulk AI review

- saved/filtered segments;
- dry-run and proposal generation;
- candidate/confidence aggregation;
- threshold approval + exclusions;
- per-item CAS application and audit.

### Slice E — Readiness

Add target-specific readiness only from real channel requirements and existing mappings; keep it separate from structural completeness.

### Slice F — connector-driven structure onboarding

Only after ProductType/Group UX has real usage. Reuse connector discovery/evidence patterns and define provider-specific external identity + typed confirmation semantics rather than assuming universal 1:1 provider structure.
## 14. Frozen decisions for Gemini review

Freeze for adversarial review:
- exactly one effective ProductType per Product via default Basic Product;
- ProductType change preserves all stored values and surfaces out-of-type data;
- first-class reusable AttributeGroup;
- ProductType-contextual Group + FieldBinding placement;
- one canonical group placement per FieldBinding within one ProductType in v1;
- optional ProductType groups with per-product activation override;
- Category remains separate and current cardinality is not expanded in this initiative;
- structural Completeness separate from target Readiness;
- no Product/Variant inheritance engine in this initiative;
- persistent AI run + proposal state with source and target-schema staleness checks;
- explicit review before AI writes; bulk threshold approval remains an explicit merchant action;
- no automatic schema creation from AI/provider evidence;
- no generic connector-structure mapping table in the first Product Structure slice.

Questions for Gemini are implementation/consistency attacks, not invitations to restart market research from zero.

## 15. Primary evidence used

- Adobe Commerce Attribute Sets / Product Workspace: https://experienceleague.adobe.com/en/docs/commerce-admin/catalog/product-attributes/create/attribute-sets
- Akeneo Families / Completeness: https://help.akeneo.com/en_US/serenity-build-your-catalog/30-serenity-manage-your-families-and-variant-families
- Akeneo AI Classification: https://help.akeneo.com/en_US/supplier-data-manager-how-the-modules-work/supplier-data-manager-classification-how-to-use-the-classification-module
- Akeneo Web Enrichment: https://help.akeneo.com/using-ai-in-the-pim/what-is-web-based-attribute-enrichment
- Pimcore Classification Store: https://docs.pimcore.com/platform/Pimcore/Objects/Object_Classes/Data_Types/Classification_Store/
- Plytix Product Families / Attribute Groups / Bulk Editing / Bulk AI: https://help.plytix.com/en/how-to-create-and-manage-product-families and https://help.plytix.com/en/bulk-edit-products and https://help.plytix.com/en/bulk-ai
- commercetools Product Types / Attribute Groups: https://docs.commercetools.com/merchant-center/product-types and https://docs.commercetools.com/api/projects/attribute-groups
- Shopify Category / Category Metafields: https://help.shopify.com/en/manual/products/details/product-category and https://help.shopify.com/en/manual/custom-data/metafields/category-metafields
- Amazon Product Type Definitions / listings workflow: https://developer-docs.amazon.com/sp-api/lang-en_EN/docs/retrieve-a-product-type-definition and https://developer-docs.amazon.com/sp-api/lang-en_EN/docs/building-listings-management-workflows-guide
- BigCommerce Catalog API structure: https://developer.bigcommerce.com/docs/rest-catalog/products/videos (navigation/reference surface exposing Products, Variants/Options, Custom Fields/Metafields, Category Assignments, Category Trees)
