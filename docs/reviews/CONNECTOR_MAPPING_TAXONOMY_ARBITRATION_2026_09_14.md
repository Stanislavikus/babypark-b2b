# Connector Mapping / Taxonomy Architecture Arbitration — 2026-09-14

**Status:** ARBITRATED RESEARCH / pre-implementation gate

**Authoritative repo base:** `develop@91a4dde55d7c009cf972037e61cb8b29eb315cab`

**Inputs:** independent Sonnet 5 High research, independent GPT-5.4 adversarial research, current repository contracts/runtime, and lead verification against current Google/Shopify/Adobe primary documentation.

**Scope:** universal multi-tenant / multi-connector mapping boundaries before further connector runtime or AI work. BabyPark is a certification merchant, not the domain model.

## 1. Final lead verdict

1. The existing Field Foundation remains authoritative: `FieldDefinition`, `FieldBinding`, typed Product/Variant values, `FieldMapping`, and `FieldOptionMapping`. Do not create a second generic attribute/mapping system.
2. `FieldMapping` remains direction-neutral semantic correspondence between a `FieldBinding` and an external logical field. It is not direction, authority, execution policy, transformation, taxonomy mapping, or record identity.
3. `FieldOptionMapping` remains explicit option-value correspondence only.
4. `ExternalRecordLink` remains ConnectorAccount-scoped Product/Variant record identity and Entity Trust. It must not be represented as a Field Matrix row.
5. Merchant/Catalogue `Category`, future `Standard Category`, `ProductType`, `Merchant Type`, Tags, and provider taxonomy/type concepts remain distinct.
6. Pricing, Availability, Media, Category/taxonomy, and other non-`FieldBinding` concepts keep their own domain ownership. Do not force them into `field_mappings` for symmetry.
7. The repository already acknowledges the one real generic seam still missing: a connector-facing internal domain-target boundary for non-`FieldBinding` concepts. Its physical persistence shape is NOT frozen.
8. Do not create `TaxonomyMapping`, `GenericMapping`, `ConnectorStructureMapping`, or polymorphic target columns until a real target proves the minimum shape.
9. `Standard Category` is a real future concept, but must not become a speculative global prerequisite for every connector or every Readiness target. Concrete target rules decide whether taxonomy classification is required, optional, inferred, or irrelevant.
10. Readiness is target-specific and consumes real target requirements plus canonical data/domain owners. It must never become a generic score engine or second value store.
11. Connector runtime work proceeds one provider/operation at a time after this architecture gate. AI remains downstream of field/runtime/readiness truth.

## 2. Arbitration — where Sonnet and GPT agree

Both reviews correctly confirm the following existing repo truth:

- Product/Variant scalar/select semantics belong to Field Foundation and use `FieldMapping`; select vocabulary correspondence uses `FieldOptionMapping`.
- External Product/Variant identity belongs to `ExternalRecordLink`, not to Product fields or FieldMapping.
- Workspace Merchant Category is a navigation/classification relation, not a text field.
- Provider taxonomy/category identity is not the same concept as Merchant Category.
- Price, inventory/availability, media, provider structure, and publication configuration must not be smuggled into `FieldMapping` merely because a connector exposes them as payload fields.
- Existing `onec_guid` / `rozetka_category_id` columns are universality debt with an existing paper trail, not precedent for adding more provider-shaped core columns.
- A giant universal “everything mapper” is rejected.

These conclusions match `03-DOMAIN_MODEL.md` first-slice boundaries and the Canonical Product Field Registry.

## 3. Arbitration — taxonomy / category

**Accepted:** a category/taxonomy correspondence is semantically different from `FieldBinding ↔ external_field_key`.

**Accepted:** a connector-facing domain-target boundary is required before category/taxonomy correspondence is persisted or exposed as if it were ordinary FieldMapping.

**Rejected as premature:** GPT-5.4's wording that a dedicated taxonomy mapping contract/operator surface is already a P0 prerequisite for all Google readiness.

Current Google Merchant documentation states that Google normally assigns its own product category and `google_product_category` is generally an optional override, with category-specific requirements in particular cases. Therefore a taxonomy mapping cannot be globally mandatory merely because the target is Google Merchant.
**Rejected as too broad:** Sonnet's tendency to treat all external category references as approximately ExternalRecordLink-shaped account identity. Magento installation category IDs, Google Product Taxonomy nodes, Shopify Standard Product Taxonomy nodes, and other provider/category references can have materially different scope and lifecycle. The generic boundary is taxonomy/domain correspondence; its identity scope must be determined by the concrete target.

**Open until a concrete connector requires it:** persisted shape, versioning, external taxonomy-node identity scope, and merchant operator UX for category/taxonomy correspondence.

**Do not decide now:** whether connector import may auto-create Merchant Category nodes. Deterministic ERP import, commerce sync, and AI proposals are different workflows. If/when needed, creation/update policy must be an explicit merchant/domain policy, not an inferred global default.

## 4. Standard Category correction

The current repo has a closed conceptual distinction: Standard Category is separate from Merchant Category and intended for readiness/export/attribute suggestions.

However, the sentence that Standard Category eventually becomes mandatory for product readiness/channel-export/publishing is too broad if read as a universal prerequisite. Real providers differ. Example: Google can auto-classify products and generally treats `google_product_category` as optional, while category-specific requirements may still make an explicit category relevant for particular products/scenarios.

**Lead decision:** retain Standard Category as a future cross-channel normalization concept, but do not require it before the first concrete target Readiness unless that target rule set proves the requirement. This should receive a narrow documentation clarification before implementing Readiness.

## 5. Field Matrix boundary

The current Field Matrix is valid for what it physically represents today: confirmed Product/Variant `FieldBinding` correspondences.

It is not and must not pretend to be a universal matrix for:
- Product/Variant external record identity;
- taxonomy/category-node correspondence;
- Pricing domain values/policies;
- Availability/Inventory domain values/policies;
- Media relationships;
- provider structure such as Magento Attribute Sets or Amazon PTD;
- connector-only publication/execution configuration.

The merchant experience may still present these concerns inside one coherent setup journey, but the backend ownership and mutation boundaries remain distinct.
## 6. Real-target Readiness contract

Readiness remains separate from structural Completeness.

For each target/goal (for example Magento export or Google Merchant publication), Readiness may consume:
- ProductType structural completeness;
- canonical Product/Variant values;
- confirmed `FieldMapping` and `FieldOptionMapping` where those mappings are relevant;
- target-specific taxonomy/category state only when the concrete target rules require it;
- Pricing, Availability and Media domain projections;
- connector account/context and target-specific validation findings.

Readiness must not:
- store duplicate field values;
- make ProductType requiredness equal marketplace/channel requiredness;
- require every future generic mapping primitive before a concrete target can ship;
- expose one universal readiness score with no target rule set.

This preserves the frozen Slice E rule: specify at least one real target rule set first.

## 7. Corrections to research findings

### Sonnet

Accepted: architecture base is sound; no generic mapping table is justified; `Category.onec_guid` should be added to the GAP-007 debt inventory.

Not frozen: “external category = approximately ExternalRecordLink identity”; automatic Merchant Category creation policy; a mandatory Standard Category prerequisite for all publication readiness.

### GPT-5.4

Accepted: ordinary Field Matrix must not absorb taxonomy, identity, Pricing, Availability, Media, provider structure or execution config; physical connector leakage remains dangerous precedent.

Rejected/downgraded: taxonomy mapping/operator surface is not a universal P0 prerequisite for Google; real target rules must prove that need first.

Rejected: the claimed missing 2026-09-13 Product Structure synthesis/contract artifact is not a repo problem. Both files exist under `docs/reviews/` on the authoritative repository used by this arbitration.

Downgraded: first-class MediaAsset absence is real platform debt, but not automatically a P1 architecture blocker to every target. Current Product `images` persistence and Adobe media runtime already exist; the concrete target Readiness contract must prove what media semantics are insufficient before expanding the media domain.
## 8. Additional repo discrepancy found by lead arbitration

Both external reviews missed one stale documentation contradiction in GAP-007.

`docs/IMPLEMENTATION_GAPS.md` still says `ExternalRecordLink runtime remains absent`, while the current runtime atlas and code classify `ExternalRecordLink` as implemented Stage 3A persistence foundation and later Entity Trust runtime uses it.

This is documentation debt only; it does not reopen architecture. GAP-007 should be corrected when this arbitration is promoted:
- remove/update the stale “runtime remains absent” sentence;
- explicitly add `categories.onec_guid` to the connector/category-identity debt inventory;
- preserve the existing decision not to create FieldDefinitions for connector identities.

## 9. Magento merchant-journey finding from current production UI

Current Magento Products/Export preview page renders three separate concerns in sequence on one page:

1. Preview/readiness worklist — “can the current data be transferred?”
2. Live transfer section — “is consequential transfer currently available?”
3. Entity Trust working set — “which internal Product corresponds to which external Magento record?”

These concerns are semantically distinct. The architecture is not wrong merely because two tables exist.

The merchant journey is nevertheless unresolved because the page currently exposes them as parallel worklists without a clear causal sequence. Repeated global mapping blockers are rendered per Product, and Entity Trust can remain visible even while Live support is unavailable.

The mapping/taxonomy research did NOT answer the critical lifecycle questions:
- when Entity Trust is required for CREATE versus UPDATE/relink;
- whether Entity Trust should be hidden/disabled until Preview reaches the relevant readiness threshold;
- how configuration-level blockers should be aggregated instead of repeated per Product;
- what the one causal next action should be at each merchant state.

Therefore this is a separate narrow research gate, not an excuse to reopen mapping architecture.
## 10. Required next research gate

Do **not** run another broad connector/mapping study.

Run one narrow Magento Products/Export merchant-journey review against the actual Stage 3D/3E contracts and current UI/runtime. Its scope is only:
- Preview blockers and aggregation;
- CREATE vs UPDATE/relink entity-identity semantics;
- Entity Trust visibility/admission order;
- first-Live causal next action;
- current merchant copy and progressive disclosure.

No FieldMapping redesign, taxonomy design, Google research, or generic readiness design is allowed in that follow-up.

## 11. Execution order after this arbitration

1. Finish and review current Product Structure Slice B closure work.
2. Complete the narrow Magento merchant-journey research/review above before changing that connector UI.
3. Specify one real Magento target Readiness rule set from actual runtime/contracts and close Magento production runtime blockers.
4. Treat 1C and Google as separate connector tasks, one at a time, each with its own real runtime/readiness requirements. Do not assume taxonomy mapping is required until the target proves it.
5. Only after deterministic field/runtime/readiness truth is proven, proceed to AI proposal/bulk-review work for commerce platforms.

## 12. Documentation patch candidates — not yet normative

After review of this arbitration, a narrow docs-only patch should:
- correct stale GAP-007 `ExternalRecordLink` runtime status;
- add `categories.onec_guid` to GAP-007 debt inventory;
- clarify the Standard Category sentence so target-specific Readiness decides whether explicit taxonomy is required;
- optionally make the FieldMapping exclusion boundary explicit (taxonomy identity, external identity, Pricing, Availability, Media, connector publication/config are outside first-slice FieldMapping).

Do not create migrations/models/services as part of that documentation patch.

## 13. Final status

**Mapping architecture:** sufficient and not to be redesigned.

**Taxonomy/domain-target persistence:** deliberately unresolved until a concrete target proves the minimum shape.

**Real-target Readiness:** required before AI; target-specific, not generic.

**Magento current Preview + Entity Trust UX:** unresolved merchant-journey issue requiring one narrow follow-up research/review before implementation.
