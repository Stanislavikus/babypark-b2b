# Product Workbench / Channel UX — Sonnet 5 High Arbitration — 2026-09-21

**Status:** Lead arbitration of independent review; not a frozen implementation contract.
**Base:** `origin/develop @ ec47527ab1a71f65dbdce3b605bdc4f9f82d15f3`
**Draft under review:** `PRODUCT_WORKBENCH_CHANNEL_UX_SYNTHESIS_2026_09_21.md`

## 1. Lead verdict

Sonnet verdict `ACCEPT WITH CORRECTIONS` is accepted at the campaign level.

The central Workbench/View model remains viable. However, not all Sonnet corrections are
accepted. Two proposed changes would reintroduce ambiguity or contradict frozen repository
truth, and Sonnet missed one important product-owner-vs-frozen-UX tension around the default
Magento row universe.

## 2. Finding arbitration

| ID | Sonnet finding | Lead status | Result |
|---|---|---|---|
| S-01 | Current Publication View must not expose CREATE because production CREATE does not ship | **PARTIALLY ACCEPTED** | Current V1 UI must hide/forbid CREATE until capability support is certified. But CREATE is now a required product capability, so the target Workbench model must remain capability-gated and able to expose Create later without redesign. |
| S-02 | Collapse link state + Magento state into one ordered status chip | **REJECTED** | Frozen channel contract explicitly requires separate merchant-visible truth dimensions and forbids a mega-status merging identity/readiness/run truth. Compact presentation is allowed; semantic collapse is not. |
| S-03 | SEO/ranking provider belongs to platform/system settings | **ACCEPTED WITH REFINEMENT** | Provider implementation/credentials belong to platform/system. Workspace/job may choose merchant concepts such as target market/search engine/country and research depth, not raw provider integration. |
| S-04 | Category filter should use a tree-select interaction | **ACCEPTED** | Strong default for hierarchy filtering; breadcrumb remains row display. |
| S-05 | System Views first; design shape so custom/shared Views can follow cheaply | **ACCEPTED / DEFERRED** | First implementation should ship system presets. Custom/private/shared View persistence is follow-up unless repo evidence shows it is nearly free. Public/share-link semantics require normal authorization review. |
| S-06 | Freeze platform lifecycle to Draft / Active / Archived now | **REJECTED AS PREMATURE** | Current frozen canonical status is boolean `is_active`; richer lifecycle was explicitly deferred. User need for draft workflow is real, but persistence/status vocabulary remains OPEN pending repo study. |
| S-07 | Overview row universe = local channel Products; remote-only appears only in Links | **REJECTED FOR TARGET UX / REQUIRES STOP-AND-AMEND** | Product-owner direction is that Magento daily work opens on the actual Magento catalogue. Existing frozen contract still frames remote-only as a separate secondary surface and local selected Products as the primary table. This requires explicit contract amendment, not silent UI drift. |
| S-08 | One discoverable settings index even if actual settings live in separate owners | **ACCEPTED** | Keep ownership separation, but give support/admins one navigation map so settings are findable. Do not build settings early. |
| S-09 | Thumbnail extraction should precede a thumbnail-default Overview release | **ACCEPTED** | Backend projection must actually supply thumbnail evidence before making the column a default promise. |
| S-10 | Workbench shell can implement before structural open questions are resolved | **PARTIALLY REJECTED** | Visual shell work can be prototyped, but implementation contract must first resolve row universe, lifecycle truth, category/Attribute Set presentation, and scalable remote projection. |
## 3. Frozen-contract evidence affecting arbitration

`PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md` already freezes that the primary
local Product worklist must preserve separate merchant-visible dimensions for:

- remote correspondence;
- readiness/blocker/recommendation counts;
- one causal next action.

It explicitly says:

> Do not persist one generic mega-status that merges membership, identity, readiness, and run result.

Therefore S-02's proposed single ordered status value cannot become the new universal Product
row semantic. The UI may visually group several small indicators inside one compact cell or
popover, but the underlying meanings and filterability must stay distinct.

The same frozen contract currently says remote-only Magento records are shown separately from
the main local Product worklist. The product-owner's new requirement — Magento workspace
opens on the actual Magento catalogue — is a legitimate UX correction, but it changes that
information-architecture precedence and must be documented explicitly.

## 4. CREATE arbitration

Current repo evidence is unambiguous:

- production runtime has no caller of `postProduct()`;
- current certified Magento V1 is existing-record LINK/UPDATE only;
- architecture-closure tests mechanically guard against accidental CREATE wiring.

Therefore no current V1 merchant UI may imply that selecting an uncreated Master Product can
already create it in Magento.

However, the product owner has now made a new product decision: a real multi-platform hub must
be able to publish new Master Products to Magento. Therefore the correction is not “delete
Create from the target design forever”. The correction is:

1. current capability truth hides Create;
2. Product Workbench action model is capability-driven;
3. after dedicated CREATE architecture/runtime/real-target certification flips support,
   Publication may expose `Створити` for eligible rows;
4. CREATE itself remains a separate RED campaign and cannot ride inside a UX PR.

## 5. Lifecycle arbitration

Sonnet proposed `Чернетка / Активний / Архівний`.

Do **not** freeze this yet.

Existing repository truth is more specific:

- current canonical Product status is boolean `products.is_active`;
- Adobe maps that boolean deterministically to enabled/disabled;
- prior audits explicitly deferred a richer draft/active/archived lifecycle.

The merchant requirement is still real: 1C/import may create a minimally described Product
that should visibly wait for enrichment rather than silently live forever in an ambiguous
inactive state.

Targeted repo research must therefore answer whether:

- existing `is_active` + readiness/profile evidence is sufficient for the first merchant
  draft workflow; or
- a richer governed lifecycle is required.

Do not infer persistence from UX vocabulary before that answer.
## 6. Magento Overview row-universe correction

This is the most important unresolved IA question.

Product-owner direction after hands-on testing:

- opening the Magento daily workspace should immediately prove “this is my store” by showing
  the current successful Magento catalogue;
- remote Magento rows need SKU, thumbnail, name, brand, category and useful state/filter data;
- link state to Master Catalogue is visible and actionable from that catalogue;
- publication/preparation of Master Products is another View/workflow, not the first screen.

This differs from the frozen 2026-09-15 contract ordering where the main local selected-product
worklist is primary and remote-only catalogue is secondary.

No data-domain invariant requires that old presentation order. Remote projection remains
read-only observation and Master Product remains platform truth. Therefore this appears to be
an **information-architecture Stop-and-Amend**, not a core data architecture reversal.

GPT-5.4 must verify every downstream contract/test/code assumption that depends on the old
surface ordering before Lead freezes the amendment.

## 7. Status/display direction after arbitration

Keep dimensions separate even if visually compact:

- **Platform state/lifecycle** — current `Активний / Неактивний` truth until richer lifecycle
  is resolved;
- **Magento provider state** — provider-observed enabled/disabled or equivalent;
- **Link state** — linked / candidate / remote-only or the final approved vocabulary;
- **Readiness/completeness** — active profile percentage/problems;
- **Publication result** — last governed operation result;
- **Problems** — count/root-cause drill-down.

A user must be able to filter by these dimensions independently.

## 8. Settings correction

Accepted ownership direction:

- platform/system: concrete provider integrations, provider credentials, model availability,
  safety/cost ceilings;
- workspace: business defaults such as languages, content style, image preferences, target
  market defaults;
- channel/account: only settings that genuinely apply to the connected destination as a whole;
- job/batch: temporary overrides such as research depth, languages or image profile for one
  selected operation;
- per-product corrections such as Category/Attribute Set are product work, not Settings.

Also accept Sonnet's discoverability correction: one settings index/map may link to these
owners without collapsing them into one giant form.

## 9. External UX-reference checks

Current Plytix documentation confirms that a View is a saved combination of columns, filters,
sorting, Product Family and hierarchy levels, with private/shared forms and a copy-view-link
action. This supports the Workbench/View direction.

Plytix filtering also confirms category as a first-class system filter and supports hierarchy/
category selection patterns. These are UX reference patterns only; they do not define our
domain model.

## 10. Sonnet coverage gaps

Sonnet did not fully resolve several mandatory questions from the research task:

- 10k–100k scalable Magento read shape for thumbnail/brand/category/Attribute Set;
- price/stock high-frequency synchronization versus content-workbench workflows;
- exact readiness/completeness ownership and derivation;
- existing repo persistence that could support saved Views before inventing new tables;
- settings persistence/inheritance;
- the frozen-contract implications of making remote Magento catalogue the default daily View;
- whether current boolean `is_active` is enough for the requested draft workflow.

These become the scope of the targeted GPT-5.4 repo/architecture study.
