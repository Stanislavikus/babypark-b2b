# Magento V1 Stage 2 — Structure / Custom Attribute Research Synthesis

**Status:** frozen implementation basis
**Base:** Stage 1 closure `0dcc4be329667bfeb04e855429160f24f5bafc94`
**Scope:** provider lineage, Product Attribute Sets, Attribute Groups, set membership, option identity, and workspace-custom materialization boundaries.

## Evidence

- Live Product attribute registry: 106/106 rows expose unique numeric `attribute_id` and unique `attribute_code`.
- Live Product Attribute Sets: 4 sets (`4`, `9`, `10`, `11`) with 60 / 84 / 81 / 59 members.
- Live `/V1/products/attribute-sets/groups/list`: 52 groups; it also returns groups for non-Product set IDs, so rows MUST be filtered through the authoritative Product-set catalogue.
- Live set-membership responses identify attributes but do not expose current `attribute_group_id` membership.
- Live select options expose stable provider option values such as `902`, `903`, `904`; labels vary by store view while option identity stays constant.
- Adobe documentation: attribute sets determine the fields available to products; groups organize fields inside a set; dropdown/multiselect options may have per-store-view labels.

## Frozen identity decisions

1. Magento Product custom-field lineage identity is `(workspace, account, schema_source, attribute_id)`, not `attribute_code`.
2. `attribute_code` is mutable current provider key. Rename keeps lineage when `attribute_id` is unchanged.
3. Delete + re-add with the same code but a new `attribute_id` creates a new lineage. No silent resurrection or merge.
4. Cross-account custom fields are never auto-merged by equal code/label. Semantic merge remains an explicit future suggestion/review operation.
5. Stage 1 v2 snapshot/hash contract remains frozen; Stage 2 reads `attribute_id` through a separate read-only structure projection instead of changing v2 payload semantics.
## Provider structure decisions

6. Attribute sets are provider applicability context, not canonical platform taxonomy.
7. One provider attribute may belong to zero, one, or many sets. Live workspace-custom distribution is 43/47 assigned: 38 in one set, 5 in two sets, 4 in no Product set.
8. A field present in the global Magento attribute registry but absent from all Product sets is discovered but not product-applicable; readiness must reflect that explicitly.
9. Magento Attribute Groups are provider presentation metadata. `FieldBinding.field_group` remains BabyPark platform UI taxonomy and MUST NOT be populated from Magento group names.
10. Standard REST read-side does not provide reliable existing attribute→group membership. Do not infer it from labels/order. Persist groups themselves, but leave field→group membership unknown until an authoritative read seam exists.
11. Group rows returned for set IDs outside the Product Attribute Set catalogue are ignored for Product schema purposes.

## Option decisions

12. Provider option identity is Magento option `value`/ID, not label.
13. Option labels are presentation metadata keyed by store code; label changes do not create a new option identity.
14. Blank sentinel option values are not materialized as normal business options.
15. Internal option codes may initially reuse the stable provider option value inside an account-local materialized FieldDefinition; cross-account semantic unification requires explicit option mapping/review.
16. Existing `FieldOptionMapping` remains the connector correspondence owner; no second mapping framework is introduced.
16a. Provider option lineage may be persisted for every selectable Product attribute because the global registry already returns those IDs/labels in the same paginated read; this does not materialize platform options. Workspace materialization still filters by runtime disposition and binding eligibility.

## Workspace materialization boundary

17. Materialized definitions use `FieldDefinition.scope = workspace_custom`; Magento `global|website|store` scope is provider behavior metadata and MUST NOT be written into platform ownership scope.
18. Source lineage and set applicability are persisted before automatic FieldDefinition creation.
19. `FieldDefinition.code` is not provider identity. If an equal code already belongs to another lineage/account, automatic merge is forbidden; materialization must use a deterministic collision-safe internal code or require explicit review.
20. Object-level binding (`product` vs `product_variant`) is NOT inferred from the attribute label or set name. It requires a separate entity-level resolver before automatic FieldBinding/FieldMapping creation.
## First implementation slices

A. **Persistence foundation**
- stable provider field lineage;
- Product Attribute Set projection;
- provider Attribute Group projection;
- field↔set applicability;
- provider option lineage.

B. **Read-only structure reconciler**
- fetch global Product attributes with `attribute_id`;
- fetch Product Attribute Sets and set membership;
- filter groups by authoritative Product set IDs;
- reuse inline provider option identity/labels from the global Product attribute registry for selectable attributes; call the per-attribute options endpoint only when that inline structure is absent, then let materialization consume the `workspace_custom` subset;
- reconcile rename, disappear/reappear, membership and option changes idempotently.

C. **Workspace materializer**
- create/reuse workspace FieldDefinition from lineage only after entity-level binding decision;
- create Dynamic FieldBinding through existing field invariants;
- create exact FieldMapping/FieldOptionMapping through existing mutation services;
- derive readiness; never persist a parallel readiness state.

D. **Custom Receive/Export**
- reuse `GovernedDynamicFieldValueWriter` for supported types;
- keep Money/Image/Computed and any unsupported localization/type combination fail-closed until their existing writer gaps are resolved.

## Explicit open questions / non-blocking provider gaps

- Existing Magento attribute→Attribute Group membership is not available from the standard read contract used here; no guess is permitted.
- Product vs ProductVariant ownership for arbitrary merchant attributes needs a separate evidence-based resolver.
- Store-scope localized select/multiselect remains constrained by the current governed writer contract.
- P-10 Product PUT store-scope inheritance risk remains orthogonal; this Stage 2 foundation is read-only until write admission is separately proven.
