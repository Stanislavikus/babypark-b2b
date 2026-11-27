# TASK FOR GEMINI 3.1 — Challenge Decision B: Magento publication classification

**Project:** B2B Product Data Platform
**Repository:** `Stanislavikus/babypark-b2b`
**Authoritative base:** `origin/develop @ ec47527ab1a71f65dbdce3b605bdc4f9f82d15f3`
**Task type:** independent architecture challenge — NO implementation.

## Goal

Challenge only **Decision B** from:

`docs/reviews/PRODUCT_WORKBENCH_TWO_STRUCTURAL_DECISIONS_2026_09_21.md`

Do not re-review the whole Workbench UX.

The proposed decision is:

- existing account-level `ConnectorCategoryMapping` provides the default
  `Master Category -> Magento leaf category`;
- a new connector-owned default maps
  `ConnectorAccount + ProductType -> Magento attribute_set_id`;
- sparse per-Product/account overrides replace those defaults only for exceptions;
- Product override can select one Attribute Set and zero/one/many Magento categories;
- AI suggestions are not effective until merchant confirmation;
- effective changes participate in Preview/revision invalidation;
- Magento-specific semantics remain connector-owned rather than being put on Product or forced
  into a generic stringly-typed classification DSL.

## Mandatory reading

1. `docs/Project_Documentation_Map.md`
2. `docs/05-AI_WORKING_AGREEMENT.md`
3. `docs/02-ATTRIBUTE_DICTIONARY.md`
4. relevant ProductType / AttributeGroup / category / sync sections of
   `docs/03-DOMAIN_MODEL.md`
5. `docs/PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md`
6. `docs/reviews/PRODUCT_WORKBENCH_CHANNEL_UX_SYNTHESIS_2026_09_21.md`
7. `docs/reviews/PRODUCT_WORKBENCH_SONNET_ARBITRATION_2026_09_21.md`
8. `docs/reviews/PRODUCT_WORKBENCH_TWO_STRUCTURAL_DECISIONS_2026_09_21.md`

Inspect actual `origin/develop` code/migrations/tests for:

- Product / ProductType / AttributeGroup;
- ConnectorCategoryMapping;
- Magento category relation runtime;
- AdobeProductExportExecutionConfiguration / setup service / semantic planner;
- Preview configuration snapshot + revision/admission;
- FieldMapping / FieldOptionMapping;
- Adobe attribute structure discovery.

## Questions

1. Is `ProductType -> Magento Attribute Set` the best reusable default seam?
   - If yes, explain why Category or ProductType+Category is not better.
   - If no, propose the smallest safer owner.

2. Is sparse override superior to persisting effective classification for every Product?
   Challenge stale-state, query/read complexity, bulk editing and preview determinism.

3. Category semantics:
   - keep one default target leaf per Master Category?
   - allow product override to replace with a set of target category IDs?
   - should default mapping itself support multiple target categories now?

4. Persistence:
   - should Attribute Set mapping and per-product override be Magento-specific tables?
   - is there an existing repo owner that can be reused safely?
   - explicitly reject any tempting reuse that would violate current semantics.

5. Preview/revision:
   identify the exact mutation/snapshot/revision seam that should own these changes.
   Do not allow Preview/Live to read mutable latest classification after admission.

6. Configurable families:
   should the classification live on Product (family/root) and flow to variants, or are
   variant-specific overrides required for the first correct Magento scope?

7. Manual correction:
   what minimum provenance/audit fields are necessary for a merchant-confirmed override?
   Avoid speculative workflow columns.

8. Multi-platform consequence:
   does this design preserve a clean future for Shopify/Amazon/Google, or does it accidentally
   freeze Magento concepts into the universal domain?

## Required output

### Verdict
`ACCEPT / ACCEPT WITH CORRECTIONS / REWORK REQUIRED`

### Findings

| ID | Severity | Proposed seam | Finding | Repo evidence | Correction |
|---|---|---|---|---|---|

### Preferred effective-resolution algorithm

Write exact precedence from Product/Category/ProductType inputs to effective target Category(s)
and Attribute Set.

### Minimal persistence

List only tables/keys/invariants actually required. Distinguish existing vs new.

### Revision integration

State exactly what change must invalidate what Preview/Live evidence.

### Future-platform compatibility

Explain which pieces are universal and which remain Magento-owned.

### Explicit no-go alternatives

List designs that look convenient but should be rejected.

## Do not

- implement code;
- redesign ProductType;
- equate ProductType with Adobe Attribute Set;
- store Magento IDs directly on Product;
- weaken workspace/account isolation;
- reuse ExternalRecordLink for pre-create classification;
- add generic JSON/DSL persistence merely to look multi-platform;
- design Magento CREATE itself in this task.
