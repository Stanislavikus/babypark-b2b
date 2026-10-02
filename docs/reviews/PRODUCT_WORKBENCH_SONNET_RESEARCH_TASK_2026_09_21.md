# TASK FOR SONNET 5 HIGH — Product Workbench / Channel UX challenge

**Project:** B2B Product Data Platform
**Repository:** `Stanislavikus/babypark-b2b`
**Authoritative base:** `origin/develop @ ec47527ab1a71f65dbdce3b605bdc4f9f82d15f3`
**Task type:** independent UX/product architecture challenge — NO implementation.

## Goal

Challenge the proposed universal Product Workbench / Channel UX before we freeze an
implementation contract.

The target product must let a normal merchant/content manager operate large catalogues,
channels, enrichment, SEO, AI, media, import/export and review without understanding connector
internals or requiring a programmer.

## Mandatory documents

Read first:

1. `docs/Project_Documentation_Map.md`
2. `docs/05-AI_WORKING_AGREEMENT.md`
3. `docs/00-WHY.md`
4. `docs/01-PRODUCT_VISION.md`
5. `docs/02-ATTRIBUTE_DICTIONARY.md`
6. `docs/03-DOMAIN_MODEL.md`
7. `docs/04-ARCHITECTURE_PRINCIPLES.md`
8. `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md`
9. `docs/PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md`
10. `docs/reviews/PRODUCT_STRUCTURE_UX_AI_FINAL_SYNTHESIS_2026_09_13.md`
11. `docs/reviews/PRODUCT_STRUCTURE_UX_AI_IMPLEMENTATION_CONTRACT_2026_09_13.md`
12. **`docs/reviews/PRODUCT_WORKBENCH_CHANNEL_UX_SYNTHESIS_2026_09_21.md`**
Inspect current relevant code/screens on `develop`, especially:

- Product Resource/list table;
- Magento channel page;
- Magento Remote Catalogue page;
- export setup;
- Preview/Live merchant page;
- Product/Field/Category/ProductType/AttributeGroup models;
- sync selection/link/readiness presentation services.

Do not assume the draft is correct merely because it is documented.

## Research references

Use current primary/help documentation and concrete UX patterns from mature systems where
useful, especially:

- Plytix — product overview, table Views, completeness, columns/filters/bulk actions;
- Akeneo — product grid, completeness, enrichment, review/workflows, AI proposals;
- ChannelEngine — product selections, categorization/mappings, listed-products validation,
  publication state/actions;
- optionally comparable mature PIM/channel SaaS if a materially better pattern exists.

Do not rank vendors. Extract transferable patterns and explain where they fit or do not fit our
product.

## NON-NEGOTIABLE PRODUCT TRUTH

- one governed Master Catalogue remains platform truth;
- remote provider catalogue is observation/evidence, not automatic Master ownership;
- ExternalRecordLink remains trust authority for consequential existing-record work;
- AI proposes; merchant/human review remains first-class unless a future explicit automation
  policy says otherwise;
- connector/runtime safety must remain hidden behind human causal UX;
- do not redesign frozen DB/runtime foundations merely because another SaaS models them
  differently.
## Questions you MUST answer

### A. Universal Workbench

1. Is “one universal Product Workbench + Views” the right information architecture?
2. Which Views should be system presets on day one?
3. Which columns are mandatory default-visible in Magento Overview?
4. Which columns should be configurable but hidden by default?
5. Does the proposed side drawer correctly separate scanning/bulk work from one-product editing?

### B. Status semantics

6. Separate clearly:
   - platform product lifecycle/status;
   - completeness/readiness;
   - provider state (e.g. Magento enabled/disabled);
   - connector publication/sync result;
   - trust/link state.
7. Recommend merchant labels that cannot be confused.
8. Challenge whether a new Product lifecycle enum is actually needed or whether current data
   can represent draft/active workflow without new persistence.

### C. Categories and provider structure

9. Challenge the category breadcrumb/L1-L2 proposal for large catalogues.
10. Define the clearest UX for automatic Category/Attribute Set recommendation with manual
    correction.
11. How should multiple Magento category assignments be shown without clutter?
12. What should a content manager be allowed to choose versus what should require an admin?

### D. Completeness / problems

13. Should completeness appear in Overview, Publication only, or both?
14. How should a merchant inspect missing required fields without opening 50 screens?
15. How should root-cause aggregation coexist with row-level product indicators?

### E. SEO / AI / Media

16. Is `Контент і SEO` one View or should SEO be separate?
17. How should current value vs evidence vs AI proposal vs approved value be shown?
18. How should stale ranking/keyword evidence be represented?
19. How should AI/manual workflows coexist without forcing AI on users?
20. Does the proposed Media View/drawer adequately replace the historical spreadsheet workflow?
### F. Settings

21. Challenge the three-level settings model:
    - platform/system owner;
    - workspace/client defaults;
    - per-job override.
22. Identify concrete examples that belong to each layer.
23. Identify settings that should NOT exist because the system can infer them.
24. Recommend where these settings are discoverable without creating a “95% buttons nobody
    understands” Zoho-style settings experience.

### G. Scale

25. Validate the UX for 10k–100k products.
26. Which filtering/saved-view/bulk-action patterns are mandatory for scale?
27. What should load lazily vs appear in the lightweight list?
28. Identify any proposal that would cause N+1 provider API calls or unusable tables.

### H. Capability boundaries

29. Identify every place where current UI implies a capability the runtime does not yet ship.
30. In particular separate:
    - current Magento UPDATE/link path;
    - future Product CREATE;
    - future remote-only Magento → Master Product import;
    - price/stock high-frequency sync;
    - AI/SEO enrichment.
31. Recommend how UX can remain coherent while these capabilities arrive incrementally.

## Required output format

Return:

### 1. Executive verdict
`ACCEPT / ACCEPT WITH CORRECTIONS / REWORK REQUIRED`

### 2. Strong points
Only genuinely supported strengths.

### 3. Findings table

For every finding:

| ID | Severity | Draft claim / seam | Problem | Evidence/reference | Recommended correction |
|---|---|---|---|---|---|
Severity:
- BLOCKER — would make the Workbench model misleading/unusable or conflict with frozen truth;
- MAJOR — material UX/product correction before implementation;
- MINOR — worthwhile but can follow first implementation.

### 4. Recommended system preset Views

For each View give:
- purpose;
- row universe;
- default columns;
- default filters;
- bulk actions;
- row actions;
- drawer entry;
- what must NOT appear.

### 5. Status vocabulary

Give an explicit merchant-facing vocabulary for:
- platform lifecycle;
- provider state;
- completeness;
- link/trust;
- publication result;
- problems.

### 6. Settings ownership table

`setting → platform owner / workspace / channel / job → rationale`

### 7. Missing capability warnings

List places where product design depends on runtime not yet implemented.

### 8. Shortest safe implementation sequence

Do NOT produce dozens of tiny PRs. Propose coherent campaigns ordered by product value and
dependency.

### 9. Explicit disagreements

If you disagree with the 2026-09-21 draft, quote the exact section and explain why.
Do not silently replace it.

## Do not do

- Do not implement code.
- Do not create a new domain model from scratch.
- Do not treat competitor screenshots as architecture authority.
- Do not expose SyncConfiguration/ERL/revision/reconciliation terms to normal merchants.
- Do not claim a capability exists without locating it in current `develop`.
- Do not mark uncertain items as resolved.
