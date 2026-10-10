# Magento V1 Stage 2 Handoff — Workspace Custom Attributes + Attribute Sets/Groups

**Status:** ready to research/implement after Stage 1 closure
**Stage 1 input:** persisted v2 Product attribute snapshots + `connector_schema_field_classifications`
**Real-target evidence:** `magento_v1_stage1_real_target_evidence_2026_09_13.json`

## Goal

Turn Stage 1 `workspace_custom` classifications into safe, reusable workspace fields/mappings and model Magento attribute-set/group applicability without returning to per-literal-key engineering.

## Must reuse

- `FieldDefinition` / `FieldBinding` / `FieldMapping` / `FieldOptionMapping`;
- `FieldBinding.storage_type` (`Column|Relation|Dynamic`);
- `GovernedDynamicFieldValueWriter` for supported dynamic types;
- `ConnectorSchemaDiff` for schema reconciliation;
- Stage 1 provider metadata (`frontend_input`, `backend_type`, `source_model`, `backend_model`, `apply_to`, options, scope, etc.);
- Stage 1 behavior classes and dispositions.

## Research/implementation questions

1. Authoritative Magento APIs and semantics for attribute sets, groups, and set membership.
2. Stable identity for merchant custom attributes across rename, delete/re-add, and type changes.
3. Idempotent workspace `FieldDefinition` materialization keyed by provider lineage, with no fuzzy silent merges.
4. Select/multiselect option-domain creation and reconciliation.
5. Attribute-set/group applicability and how it should affect readiness/UI without becoming platform canonical taxonomy.
6. Cross-connector custom semantic suggestions: suggestions only, never authoritative merge by label/name similarity.
7. Custom Receive through the existing governed dynamic writer, including operation-specific safety and P-10 boundaries.
8. Explicit gaps for `Money`, `Image`, and `Computed`, which the current dynamic writer fails closed on.
9. Reconciliation when behavior changes incompatibly (`select→multiselect`, source-model change, scope change, removal/re-add).
10. Merchant review UX for proposed custom fields, mappings, options, and group/set context.

## Non-goals

Do not redesign the connector framework, canonical field library, mapping framework, or Product model. Do not make attribute set/group names into universal canonical groups. Do not infer semantic equivalence from labels alone.

## Entry condition

Stage 2 consumes Stage 1 runtime truth. The current real target has 47 `workspace_custom` attributes; four other provider identities remain `review_needed` in the research queue and are not to be auto-materialized until evidence resolves them.
