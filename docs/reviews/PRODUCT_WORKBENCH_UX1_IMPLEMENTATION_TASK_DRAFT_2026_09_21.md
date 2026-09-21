# TASK FOR EXECUTOR — UX-1 Product Workbench shell + Remote Overview

> **Status: DRAFT until visual UX freeze is [Resolved] and merged.**

## ROUTING DECISION

**Goal:** Opening the Magento channel lands on the real current remote catalogue and exposes stable `Огляд / Публікація / Зв'язки` Views without mixing row universes or inventing unsupported capabilities.
**Risk:** YELLOW
**Why:** substantial Filament/Livewire navigation/runtime composition on frozen architecture; no new persistence, auth, isolation or transaction semantics.
**Architecture:** existing/frozen. Reuse current Remote Catalogue, Product selection, Entity Trust and Preview/Live services.
**Executor:** Codex or Composer 2.5.
**Post-review:** Lead AI verifies actual HEAD/diff/tests and merchant flow.
**Escalation:** only for a new unresolved DB/workspace/auth/security/concurrency/identity/transaction decision or proven blocker.
**Cost rationale:** no frontier architecture model needed for this slice.

## Goal

Implement the first Product Workbench shell so the Magento daily-work entry follows the resolved structural/visual contracts using only capabilities that exist today.

## Authoritative Base

`origin/develop` **after** the visual UX freeze PR is merged. Record exact base SHA before work.

## Mandatory reading

1. `docs/Project_Documentation_Map.md`
2. `docs/05-AI_WORKING_AGREEMENT.md`
3. `docs/PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md`
4. `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md`
5. `docs/reviews/PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md`
6. final `[Resolved]` Product Workbench Visual UX Contract
7. `docs/reviews/PRODUCT_WORKBENCH_VISUAL_UX_FINAL_ARBITRATION_2026_09_21.md`

Inspect current:

- `ManageAdobeProductsChannel`;
- `ManageAdobeRemoteCatalog`;
- Remote Catalogue projection/query services;
- current Entity Trust merchant flow;
- ProductChannelSelectionService/ProductResource channel context;
- Preview/Live merchant read services and pages.

## NON-NEGOTIABLE

1. `git fetch origin develop`; record base SHA.
2. Work in one feature/campaign branch and one Draft PR.
3. Reuse proven runtime/services; do not rewrite Remote Catalogue, Entity Trust or Preview/Live just to fit the new shell.
4. No new DB tables/migrations in UX-1.
5. No Magento CREATE/import capability.
6. No Projection V2 fields in initial Overview.
7. No fuzzy similarity/confidence UI.
8. No per-Product Live action.
9. Do not implement Decision-B persistence/planner migration in this slice.
10. After each slice: run tests, inspect diff, fix and continue without user approval.
11. No Fake Tests.
12. Do not merge/deploy.

## Expected behavior

### A. Channel entry / shell

- Existing Magento daily-work entry opens the Workbench shell.
- Stable Views: `Огляд / Публікація / Зв'язки`.
- View state is URL/deep-linkable where practical using existing Filament/Livewire patterns; no Saved View persistence.
- Header shows account identity, connection/catalogue freshness, secondary refresh action.
- Remove the current duplicate top-level action maze from the merchant path; services/pages may remain internally reachable/reused.

### B. Огляд

- Default View.
- Row universe = current successful Remote Catalogue snapshot.
- Reuse current remote query/search/link-state/status/freshness behavior.
- Initial columns only: SKU, name, type, provider state, trusted-link state, updated/freshness and current safe row action.
- Preserve current successful snapshot while a refresh job is running.
- Empty/no-snapshot states follow visual contract.

### C. Публікація

- Row universe = current selected/linked local Master Products for the account/configuration.
- No first-scope row checkboxes unless an actually implemented bulk action is visible.
- Keep current selection membership operation through `Вибрати товари`.
- Use the existing ProductResource channel-context grid as fallback if building an in-shell selector would enlarge scope materially.
- Do not expose Decision-B Category/Attribute Set correction controls as editable until runtime exists; legacy setup remediation may remain behind current safe path where required by runtime.
- Show current causal Preview/Live entry at configuration level using existing merchant read models; do not duplicate admission logic in the UI.

### D. Зв'язки

- Reuse Remote Catalogue + ExternalRecordLink truth.
- Show factual linked/unlinked state.
- Row action opens current Entity Trust merchant review/confirmation flow.
- No similarity scoring/ranking.

### E. Drawer/detail

- Reuse ProductResource slide-over pattern where it reduces navigation churn.
- First scope may provide read-only Product identity + current Magento/link context.
- Do not fake classification edit controls before Decision-B runtime.

## Acceptance evidence

Must include literal automated test output proving at least:

- Magento channel opens to `Огляд` and remote snapshot rows are visible;
- remote-only rows do not appear as local Publication rows;
- Publication selected local rows remain the local row universe;
- Links View keeps current Entity Trust confirmation semantics;
- refresh preserves/uses current-successful snapshot behavior;
- no unsupported CREATE/import action is rendered;
- no Projection V2-only fields are rendered in first-scope Overview;
- Preview/Live action remains configuration-level and authorization/admission is delegated to existing services;
- revoked permissions/target-change guards remain covered where touched;
- old direct pages/routes, if retained, do not create conflicting merchant truth.

Run the smallest relevant focused suite during development, then the appropriate broader MySQL/CI gate before merge readiness.

## STOP conditions

STOP only for:

- new unresolved DB/workspace/pricing/auth/security/concurrency/identity/transaction decision;
- missing required external access;
- a proven conflict between the [Resolved] structural/visual contract and existing runtime that cannot be solved by composition/reuse;
- critical blocker after meaningful correction attempts;
- final merge/deploy approval.

Do not STOP for normal Filament composition/refactoring choices.

## Executor report

Report:

- base SHA;
- final HEAD;
- changed files;
- exact tests/output;
- any runtime behavior intentionally left behind a legacy page and why;
- clean tree;
- Draft PR.