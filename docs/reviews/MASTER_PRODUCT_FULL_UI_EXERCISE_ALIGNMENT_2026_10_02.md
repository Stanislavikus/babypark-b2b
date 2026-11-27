# Master Product full UI exposure + exercise alignment — 2026-10-02

STATUS: [Resolved — 2026-10-02] — PRODUCT OWNER DIRECTION

## Goal

Expose the approved Master Product capabilities in the merchant interface now, so the product can be visually reviewed and exercised end-to-end before the final SEO/AI module is connected.

The interface must distinguish three states:
- working now;
- working through an existing channel/runtime surface;
- visible but **«Чекає на підключення»**.

Do not hide an approved capability merely because its runtime is not connected yet.

## Product-owner sequencing decision

1. Surface and exercise the whole non-SEO Master Product workflow first.
2. Preserve standard Adobe/Magento REST V1 as the baseline channel runtime and test the merchant flow through it.
3. Keep SEO/Search Brief/AI content integration visible but disconnected for now.
4. Connect and certify the SEO/AI module last, after the core Workspace has been visually and operationally exercised.

PR #250 (AI/Search Brief) is intentionally deferred without merge; its research remains future input only.
## Current truth

Already working in Master Workspace:
- source-neutral Product creation;
- basic Product editing;
- Product Type / grouped governed fields;
- explicit variants;
- first-class Product media add/reorder/remove;
- classification/organization basics;
- physical/shipping fields;
- data-quality/completeness presentation;
- channel selection/presentation;
- Magento V1 Workbench / Preview / Live runtime.

Existing but not fully merchant-editable in Master Workspace:
- Offer editing;
- Inventory/location editing;
- richer category UX;
- variant-media authoring.

Approved but not runtime-connected:
- spreadsheet Smart Import runtime (although workspace_import_aliases persistence already exists);
- document/PDF-assisted field enrichment (AI evidence flow);
- media Improve/background/channel-preparation transforms;
- SEO/Search Brief/AI content module;
- generic Publication ChangeSet / unified target drawer.
## UI rule

Each relevant section must show its complete approved capability set.
If an action has no working runtime yet, invoking it must show a concise merchant-facing state:
**«Чекає на підключення»**
with a short explanation, never a fake success path.

Existing working actions must continue to use their real services and authorization.

SEO fields may display existing stored values for orientation, but editing/generation/keyword analysis is disabled in this campaign.

## File distinction

Two file concepts remain separate:
- Spreadsheet/CSV import: non-AI Smart Import using header normalization, FieldBinding matching and workspace_import_aliases memory. Runtime is not yet implemented; expose it as pending in this campaign.
- Supplier document/PDF/image enrichment: AI evidence source for proposals. Expose as pending and defer implementation with SEO/AI.

Do not collapse either into connector FieldMapping.
## Exercise scope

Lead verification must exercise:
- create Product;
- edit governed Product fields;
- Product Type / optional groups;
- simple -> variants / add axis / add variant;
- media add/reorder/remove;
- organization/category/brand/tags;
- physical facts;
- Product channel membership;
- Magento Workbench overview/open/link surfaces;
- Preview;
- existing certified Live behavior where safe and already covered by the V1 contract.

Pending actions require UI tests proving they are visible and cannot mutate data.

## Non-goals

- no SEO/Search Brief provider integration;
- no AI provider integration;
- no spreadsheet import write runtime in this visual-exposure slice;
- no new Product/connector identity semantics;
- no media transform pipeline;
- no generic Publication ChangeSet redesign;
- no Safe Sync dependency.

## Done for this campaign

- full approved capability inventory visible from Product Workspace;
- working capabilities remain genuinely functional;
- missing capabilities visibly identify themselves as pending;
- SEO is visibly deferred, not accidentally presented as production-ready;
- UI-level regression covers all active Master actions;
- Magento V1 merchant flow regression remains green;
- visual review can be performed against one coherent Workspace instead of hidden future features.
