# Product Workbench — Hybrid Visual UX Final Arbitration — 2026-09-21

> **Status: Lead final review — Hybrid is ready for product-owner approval.**
>
> Corrected prototype reviewed at `7df5fce9b08d20ad3f96f9b5c202fbd1016c8882`.
> Structural authority remains `PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md` [Resolved].

## Verdict

**HYBRID: ACCEPT. No remaining visual blocker.**

The corrected prototype closes V-01..V-08 from the prior Lead arbitration without changing runtime or resolved architecture.

## Verified corrections

- Publication has no row/select-all checkboxes in first scope.
- Publication Preview/Live is a configuration-level causal action above the table.
- Row `Наступна дія` is remediation-only; ready rows do not launch Live.
- `Ще немає в Magento` is a quiet state with no CREATE control.
- Overview has no generic Problems column that duplicates link state.
- Links shows factual trust only; no fuzzy similarity/confidence/candidate ranking is claimed.
- Product selection is represented as a searchable/filterable multi-select table.
- Basic drawer fields are read-only; Magento owns only target-classification controls.
- First-scope drawer tabs are `Основне / Magento` only.
- Hybrid is the default prototype; historical A/B remain comparison artifacts only.

## Final visual choice

Use the Hybrid as the implementation target:

- Variant-B-like page hierarchy and one-line View purpose;
- operational table density near 14px body / 42px row height;
- wider calm drawer;
- search visually primary;
- Filters/Columns secondary;
- configuration-level causal Publication action;
- row actions only for row-owned remediation/link/detail.

## Non-blocking implementation notes

- Prototype sidebar composition is illustrative; do not couple UX-1 to a new global navigation redesign.
- Projection V2-only fields remain future capability and must not appear in initial Overview.
- Decision-B Category/Attribute Set correction controls are target UX but become runtime-real only after the separate ORANGE classification/planner campaign.
- Existing pages/services may remain internally reusable during UX-1; visual navigation may change without deleting proven runtime.

## Lead recommendation

Approve Hybrid for visual freeze. After approval, mark the visual contract [Resolved], merge the docs/prototype branch, then start UX-1 on fresh `origin/develop`.