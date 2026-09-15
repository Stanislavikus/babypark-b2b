# Product Structure / UX / AI — GPT-5.4 Research Arbitration

**Date:** 2026-09-13
**Repository branch:** `campaign/magento-custom-attributes-stage2`
**Reviewed HEAD:** `06001857ccd01394490ec6af9fb0ef521bb72333`
**Purpose:** preserve accepted conclusions, corrections, and unresolved architecture questions before Sonnet research and final implementation-contract synthesis.

This document is **not** an implementation contract. It is an arbitration record over the GPT-5.4 research supplied by the user on 2026-09-13.

## Overall verdict

GPT-5.4 reached the correct high-level direction and is useful as a strong research input. Its market pattern is consistent with current primary documentation from Adobe Commerce, Akeneo, Pimcore, Plytix, commercetools, and Shopify: structural template, catalogue/category classification, provider schema, completeness/readiness, and editing UX are distinct concerns.

However, several recommendations were frozen too early or stated more strongly than the repository and market evidence justify. Those points must remain open for Sonnet attack and final arbitration before schema work.

## Accepted as strong baseline

1. Keep platform `ProductType`, `products.merchant_type`, merchant `Category`, future `StandardCategory`, and connector/provider structures separate.
2. Do not create a second field/value architecture. `FieldDefinition` + `FieldBinding` + typed values remain authoritative.
3. Product structure should reference `FieldBinding`, not raw external codes and not only `FieldDefinition`, because object ownership (Product vs ProductVariant) is load-bearing.
4. A product should have at most one structural ProductType at a time; allowing `NULL` for draft/unclassified products is compatible with the current product lifecycle.
5. A first-class workspace AttributeGroup concept is justified for operator UX; the existing `FieldBinding.field_group` string is not sufficient as the future structural grouping authority.
6. Structural inclusion, completeness requirements, and channel/business-goal readiness are distinct semantics and must not be collapsed into one `is_required` flag.
7. Merchant Category remains classification/navigation, not structural schema.
8. Provider structures such as Magento Attribute Set / Group are evidence and mapping inputs, never automatic platform truth.
9. AI must be proposal-first: rank/reuse existing ProductTypes, Categories, groups, fields, and options before surfacing schema gaps.
10. AI proposals need persisted confidence, provenance/evidence, review state, actor/audit data, and stale invalidation.
11. Product editing should be group-based, searchable, filterable (`empty`, `required`, `AI`, `conflict`, etc.) and must expose Product vs Variant ownership clearly.
12. Bulk AI review must work on filtered/saved segments with dry-run/impact preview, confidence distribution, exclusions, explicit approval and per-product audit rather than forcing users to open every product.
13. Connector onboarding should suggest reuse of existing internal structure first and require review before creating or confirming new internal structure.

## Corrections to the GPT-5.4 report

### 1. `FieldDefinition` ownership was oversimplified

The report labels `FieldDefinition` as platform-owned. Current runtime explicitly uses `BelongsToWorkspaceOrGlobal`: definitions may be global/platform references **or workspace-scoped custom definitions**. The final model must preserve that dual ownership rather than move all definitions into one ownership class.

### 2. Merchant Category must not be frozen as mandatory

The report's textual cardinality says `Product 1 -> Category`, while current `products.category_id` is nullable and the domain contract does not require a category for draft-product existence. At minimum the current cardinality is `Product 0..1 -> Merchant Category`.

Whether the long-term merchant catalogue should remain single-category or evolve to multiple category memberships is **not frozen by this review** and should be challenged by Sonnet before implementation.

### 3. Reusable groups are plausible; one-placement-per-type is not yet frozen

The report recommends reusable `AttributeGroup` plus one canonical group placement for a binding inside one ProductType. This is a good minimum candidate, but market evidence is mixed: Pimcore explicitly permits a key in several groups; Plytix permits attributes to be added to multiple groups; Akeneo allows attributes to be reused across families and added by groups.

Therefore: first-class/reusable groups are accepted; the exact `FieldBinding <-> AttributeGroup` cardinality **inside one ProductType** remains open for Sonnet arbitration.

### 4. Group ordering must be contextual

If one AttributeGroup is reused across several ProductTypes, its display order cannot safely live only on the group itself. Group order belongs on the ProductType-to-Group placement. Likewise field order inside a group belongs to the ProductType field placement/rule, not only to global `FieldBinding.sort_order`.
### 5. ProductType change lifecycle is missing and is architecture-critical

The report recommends one ProductType per product but does not define what happens when a merchant or AI later changes the ProductType. This cannot be deferred as cosmetic behavior because bulk classification and AI classification require it.

Sonnet must attack at least:
- values for bindings present in old type but absent in new type;
- whether such values remain stored but hidden, become "extra fields", or block the change;
- completeness/readiness recalculation;
- variant implications;
- connector mappings tied to the old type;
- dry-run/impact preview for individual and bulk type changes.

Do **not** copy commercetools' immutable-after-selection ProductType rule automatically; treat it as evidence of lifecycle difficulty, not a platform requirement.

### 6. Per-product optional group activation is still genuinely unresolved

GPT-5.4 recommends no per-product ad-hoc groups in v1. That simplifies the model, but the target product experience explicitly wants AI to recommend existing relevant groups from product description/technical evidence.

There are at least two valid interpretations:
1. AI recommends a ProductType, and ProductType deterministically supplies all groups; or
2. ProductType supplies baseline groups and a product may activate optional existing groups when evidence warrants them.

This is a core UX/domain decision and must remain open until Sonnet compares real PIM practice and the expected bulk workflow. Do not freeze "no optional groups" yet.

### 7. Completeness dimensions need tighter definition

The report correctly separates completeness from readiness but underspecifies locale/channel behavior. Akeneo calculates completeness from family + required attributes + locale + channel. Our final model must explicitly decide which dimensions belong to platform structural completeness and which belong only to readiness.

Preferred question for arbitration: can v1 structural completeness be `ProductType + relevant locale` while connector/channel-specific requirements live entirely in Readiness, or do some workspace channels require completeness profiles too?
### 8. Generic `ConnectorStructureMapping` is a concept, not yet an approved table

The report proposes one generic structure-mapping entity for ProductType/group/category/provider schema mappings. That may be correct, but the semantics differ materially:
- provider product schema/type mapping;
- category/taxonomy mapping;
- provider group mapping;
- provider group observability may be partial or unavailable (already true for Magento attribute-to-group membership).

Freeze only the need for explicit non-field structure mapping/provenance. Do not freeze one generic table or one shared status vocabulary until Sonnet attacks the lifecycle and type-specific invariants.

### 9. `AIProposal` persistence needs a batch/run parent for the target bulk UX

A single proposal record is not enough to support the requested workflow for hundreds or thousands of products. The final design should evaluate an `AIProposalRun` / `EnrichmentRun` / batch envelope containing:
- selection/filter snapshot;
- model/prompt/version;
- requested proposal kinds;
- counts and confidence distribution;
- execution state;
- cost/usage metadata where useful;
- per-product proposal children.

Exact names are not frozen, but bulk proposals must be groupable/reviewable as one operation.

### 10. Staleness must include target-schema revisions, not only source evidence

Source fingerprinting is correct but incomplete. A pending value proposal can become stale because:
- source product data changed;
- ProductType changed;
- group/field assignment changed;
- allowed Select/MultiSelect options changed;
- the target Category/Group/ProductType was renamed/archived/deleted;
- mapping authority changed.

The proposal fingerprint/version contract must cover the evidence used **and the target schema/references required to apply it safely**.

### 11. Generic "undo window" is not yet safe to promise

Bulk review absolutely needs audit and safe correction, but a blind rollback window can overwrite legitimate edits made after the batch. If undo is implemented, it must be compare-and-set / revision-aware or produce an inverse reviewed change set. Do not freeze generic time-based rollback semantics.
### 12. Exact persistence names/cardinalities are not frozen yet

Names such as `ProductTypeGroup`, `ProductTypeFieldRule`, `ConnectorStructureMapping`, and `AIProposal` are useful conceptual placeholders, not approved migrations. In particular, avoid duplicating the same relationship in both a ProductType-group pivot and a field-rule table without proving why both are required.

### 13. Existing `FieldBinding.field_group` needs an explicit coexistence/migration rule

Accepted direction: it should not become the new structural ProductType grouping authority. But the final implementation contract must say what it remains responsible for (for example fallback/platform presentation taxonomy) and how current bindings bootstrap or coexist with new ProductType group placement without conflicting sources of truth.

## Sonnet questions that must remain open

1. Is `Product 0..1 ProductType` the correct platform contract, and what is the safe ProductType-change lifecycle?
2. Should merchant Category remain `0..1` long-term or eventually allow multiple catalogue placements?
3. Are AttributeGroups globally reusable within a workspace, ProductType-owned, or reusable templates with ProductType-local placement metadata?
4. Can one FieldBinding appear in multiple groups inside one ProductType, or should v1 enforce one canonical placement?
5. Should ProductType baseline groups be the only active groups, or may a product activate optional existing groups?
6. What exact completeness dimensions belong to ProductType versus Readiness?
7. What minimum batch/run persistence is required for safe review of thousands of AI proposals?
8. Should non-field connector structure mappings use one typed generic model or separate mapping aggregates?
9. How should ProductType/group/schema revisions invalidate pending AI proposals?
10. What is the smallest useful Product/Variant inheritance UX without creating a second inheritance engine?

## Primary-source checks used in this arbitration

- Adobe Commerce: Attribute Set is the product-record template; groups determine where attributes appear in the record. https://experienceleague.adobe.com/en/docs/commerce-admin/catalog/product-attributes/create/attribute-sets
- Akeneo: families collect common attributes and define completeness; completeness is family + locale + channel. https://help.akeneo.com/en_US/serenity-build-your-catalog/30-serenity-manage-your-families-and-variant-families
- Pimcore: Classification Store keys may belong to several groups; groups have ordering and required-key configuration. https://docs.pimcore.com/platform/Pimcore/Objects/Object_Classes/Data_Types/Classification_Store/
- Plytix: attribute groups organize large attribute catalogues; attributes can be added to multiple groups; bulk product/category editing is first-class. https://help.plytix.com/en/product-attribute-groups
- commercetools: Product Type is a product attribute template; a Product is based on one Product Type, but their immutable type-change rule should not be copied without arbitration. https://docs.commercetools.com/learning-model-your-product-catalog/product-modeling/product-types
- Shopify: Product Category and merchant Product Type are distinct and each product has one of each. https://help.shopify.com/en/manual/products/details/product-type
## Decision status before Sonnet

### Carry forward as presumptive baseline

- separate ProductType / merchant_type / Category / StandardCategory / provider schema;
- preserve Field Foundation;
- ProductType structure targets FieldBinding;
- first-class AttributeGroup likely required;
- completeness != readiness;
- AI is proposal/review first;
- reuse existing workspace structure before schema-gap workflow;
- bulk review is a first-class requirement;
- provider structure is evidence, not platform truth.

### Do not freeze before Sonnet + final lead arbitration

- exact AttributeGroup cardinalities;
- optional per-product group activation;
- long-term Category cardinality;
- ProductType-change lifecycle;
- exact completeness dimensions;
- exact new table names and relation decomposition;
- generic vs typed connector structure mappings;
- exact AI proposal/batch persistence shape;
- undo semantics;
- Product/Variant inheritance UX.

After Sonnet research arrives, compare it against this arbitration record and the current repository, then produce one final implementation contract for Gemini 3.1 adversarial review. Do not restart broad market research from zero unless Sonnet introduces unsupported or contradictory claims.
