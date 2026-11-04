# TASK FOR EXECUTOR — UX-1 Product Workbench shell after Projection V2

> **Status: DRAFT — execute only after the visual/data-state freeze is merged and Remote Catalogue Projection V2 is implemented/verified.**

## ROUTING DECISION

**Goal:** Opening the Magento channel lands on the useful real remote catalogue and exposes stable `Огляд / Публікація` Views without mixing row universes or inventing unsupported capabilities.
**Risk:** YELLOW
**Why:** substantial Filament/Livewire navigation/runtime composition on frozen architecture; no new persistence/auth/isolation/transaction semantics in this slice.
**Architecture:** existing/frozen. Reuse Projection V2, Product selection, Entity Trust and Preview/Live services.
**Executor:** Codex / Composer 2.5.
**Post-review:** Lead AI verifies actual HEAD/diff/tests and merchant flow.
**Escalation:** only for a new unresolved DB/workspace/auth/security/concurrency/identity/transaction decision or proven blocker.
**Cost rationale:** no frontier architecture model needed.

## Goal

Implement the first Product Workbench shell so the Magento daily-work entry follows the resolved structural/visual contracts on top of a verified useful Remote Catalogue Projection V2.

## Authoritative Base

Fresh `origin/develop` **after** Projection V2 is merged. Record exact base SHA.

## Mandatory reading

1. `docs/Project_Documentation_Map.md`
2. `docs/05-AI_WORKING_AGREEMENT.md`
3. `docs/PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md`
4. `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md`
5. `docs/reviews/PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md`
6. `docs/reviews/PRODUCT_WORKBENCH_VISUAL_UX_CONTRACT_2026_09_22.md`
7. Projection V2 implementation contract/evidence.

Inspect current:

- `ManageAdobeProductsChannel`;
- `ManageAdobeRemoteCatalog`;
- Projection V2 query/read services;
- Entity Trust merchant flow;
- ProductChannelSelectionService/ProductResource channel context;
- Preview/Live merchant read services/pages.

## NON-NEGOTIABLE

1. `git fetch origin develop`; record base SHA.
2. One campaign branch + one Draft PR.
3. Reuse proven runtime/services; do not rewrite Remote Catalogue, Entity Trust or Preview/Live merely to fit the shell.
4. No new DB architecture in UX-1.
5. No Magento CREATE/import capability.
6. No fuzzy similarity/confidence UI.
7. No per-Product Live action.
8. Do not implement Decision-B persistence/planner migration here.
9. `Зв'язки` is not a permanent third top-level tab in first scope.
10. After each slice: test, inspect diff, fix, continue.
11. No Fake Tests.
12. Do not merge/deploy.

## Expected behavior

### A. Focus shell

- Magento daily-work entry opens Workbench.
- Top-level Views: `Огляд / Публікація`.
- Global SaaS navigation collapses/reduces to a compact back/menu affordance in Workbench Focus Mode.
- Workbench-local collapsible filter rail uses freed horizontal space.
- Search remains primary; Filters/Columns secondary.
- Header shows account/store, connection/catalogue freshness, remote count, secondary refresh.

### B. Огляд

- Default View.
- Row universe = current successful Remote Catalogue snapshot.
- Use Projection V2 fields that are actually verified: thumbnail/media locator, SKU, name, resolved provider Category path, Attribute Set context, provider state, link state, freshness, resolved brand-like field only when semantics are proved.
- Action column is explicit when text actions are rendered.
- linked row -> open/details action; unlinked row -> `Пов'язати`.
- Correspondence workload is filter/system-view state inside Overview.
- Do not duplicate unlinked state as a generic Problem.
- Data state may be displayed only when its derived profile inputs/runtime exist; otherwise omit rather than fake a percentage.

### C. Публікація

- Row universe = selected/linked local Master Products.
- `Вибрати товари` remains the real membership operation.
- Scalable selector or current ProductResource channel-context grid as shortest safe fallback.
- No decorative row checkboxes without a real bulk action.
- Preview/Live causal action is configuration-level.
- Row `Дія` is remediation/detail only.
- Do not expose Decision-B Category/Attribute Set correction controls as editable until runtime exists.
- `Ще немає в Magento` is quiet state; no CREATE action.

### D. Linking inside Overview

- Reuse Remote Catalogue + ExternalRecordLink truth.
- Filter `Зв'язок = Не пов'язано` / system view `Потребують зв'язку`.
- Row `Пов'язати` opens current governed review/confirmation flow.
- No automatic similarity/ranking/bulk trust.

### E. Drawer/detail

- Reuse slide-over pattern to preserve table context.
- First scope: `Основне / Magento`.
- Core source-owned fields remain read-only where current authority says so.
- Do not fake future AI/SEO/Decision-B edits.

## Acceptance evidence

Must include literal test output proving at least:

- opening Magento lands on `Огляд` and Projection V2 remote rows render;
- only `Огляд / Публікація` are permanent top-level Workbench Views;
- link filtering/action works inside Overview and preserves Entity Trust confirmation;
- remote-only rows never appear as Master Products in Publication;
- Publication membership stays local-selection truth;
- refresh keeps latest successful snapshot while scan is running;
- no unsupported CREATE/import/fuzzy matching action is rendered;
- Preview/Live stays configuration-level and delegates admission to existing services;
- revoked permissions/target-change guards remain covered where touched.

Run focused suites during development, then the appropriate broader MySQL/CI gate.

## STOP conditions

STOP only for:

- new unresolved DB/workspace/pricing/auth/security/concurrency/identity/transaction decision;
- missing external access;
- proven conflict with [Resolved] contracts that cannot be solved by composition/reuse;
- critical blocker after meaningful correction attempts;
- final merge/deploy approval.

Do not STOP for normal Filament composition/refactoring choices.

## Executor report

Report base SHA, final HEAD, changed files, literal tests/output, any retained legacy page and why, clean tree, Draft PR.
