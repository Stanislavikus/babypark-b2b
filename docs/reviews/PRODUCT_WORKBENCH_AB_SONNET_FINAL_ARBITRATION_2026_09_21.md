# Product Workbench Decisions A+B — Final Sonnet Arbitration — 2026-09-21

**Status:** final Lead arbitration; decisions are ready for product-owner approval and [Resolved] freeze.
**Base:** `origin/develop @ ec47527ab1a71f65dbdce3b605bdc4f9f82d15f3`.

## Verdict

Sonnet final verdict is accepted:

- **A: CONFIRM WITH CORRECTION**
- **B: CONFIRM WITH CORRECTION**
- **No BLOCKER**.

The two corrections are factual implementation-boundary corrections; neither changes the
semantic model chosen for A or B.

## A — Remote-catalogue-first Magento Overview

### Accepted decision

`Magento → Огляд` uses the current successful Magento Remote Catalogue snapshot as its row
universe.

`Публікація` uses local Master Products selected/linked for outbound preparation.

`Зв'язки` presents correspondence/matching between remote Magento rows and Master Products.

Remote rows remain provider observation; Master Product remains platform truth; remote-only
rows are never silently merged into local Product ownership.

### Accepted correction A1 — initial columns are bounded by actual projection

Actual `remote_catalog_snapshot_items` currently persists:

- `remote_identifier`;
- `sku`;
- `name`;
- `remote_type`;
- `remote_status`;
- `remote_updated_at`;
- `thumbnail_locator`;
- `storefront_locator`.

It does **not** currently persist brand or category.

`RemoteCatalogItemCandidate` likewise has no brand/category fields.

Although `thumbnail_locator` exists structurally, current Adobe scanning does not supply the
merchant-facing thumbnail needed by the target Overview.

Therefore the frozen UX must not claim brand/category/thumbnail are immediately available.

First deliverable Overview can show what the current projection actually knows, plus derived
link state:

- SKU;
- name;
- remote type;
- remote/provider status;
- freshness;
- link/correspondence state.

A named **Remote Catalogue Projection V2** task must enrich the scalable remote projection
before thumbnail, brand and category become default-visible/filterable columns.

This correction does not change Decision A's row-universe choice.

## B — Category + Attribute Set defaults and sparse overrides

### Accepted decision

Category and Attribute Set classification uses reusable defaults plus sparse Product-level
exceptions, while provider-specific structural semantics remain connector-owned.

### Accepted correction B1 — planner rewiring is a named core task

Current `AdobeProductExportSemanticPlanner` resolves one
`connector_execution_configuration.attribute_set_id` before evaluating Products, then injects
that same value into simple/configurable desired-state operations.

Therefore moving to an effective Attribute Set per Product is **not** merely a persistence/UI
change.

The implementation contract must name and review a dedicated planner/metadata slice that:

1. resolves effective Attribute Set per Product according to the frozen classification rules;
2. supplies Attribute-Set-specific applicability/metadata to semantic evaluation;
3. removes the single-config Attribute Set as the final execution owner;
4. preserves existing Preview/Live snapshot determinism;
5. uses the persisted Adobe Attribute Structure catalogue instead of per-Product provider HTTP
   calls.

This planner work must not be discovered accidentally inside a UI sprint.

## Non-findings frozen against needless reopening

The following were explicitly rechecked and are not blockers:

- no frozen runtime invariant requires local-selected-Products-first presentation;
- separate row universes preserve the no-mixing ownership invariant;
- ProductType is suitable as the default owner for future-CREATE Attribute Set selection;
- Product-level override is correct because ProductVariant is a separate child entity;
- no reverse uniqueness is required on `ConnectorCategoryMapping.external_category_id`;
- `AdobeProductCategoryAssignment` remains correctly anchored through trusted
  `external_record_link_id`;
- connector-owned Magento Attribute Set semantics do not constrain future Shopify/Amazon/Google;
- no variant-specific structural override is required in first scope.

Do not reopen these without newer authoritative evidence or a proven implementation blocker.

## Lead recommendation

Freeze A+B exactly through
`PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md` after explicit
product-owner approval.
