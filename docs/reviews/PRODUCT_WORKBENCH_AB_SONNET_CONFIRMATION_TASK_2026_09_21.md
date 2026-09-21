# TASK FOR SONNET 5 HIGH — Final adversarial confirmation of Product Decisions A + B

**Project:** B2B Product Data Platform
**Repository:** `Stanislavikus/babypark-b2b`
**Branch:** `docs/product-workbench-ux-synthesis`
**Authoritative base:** `origin/develop @ ec47527ab1a71f65dbdce3b605bdc4f9f82d15f3`
**Task type:** final adversarial confirmation — NO implementation, NO broad redesign.

## Goal

Try to disprove, not merely agree with, the two proposed product/architecture decisions below
before they are frozen as [Resolved].

Do not re-review the whole Product Workbench concept.
Do not redesign Magento CREATE.
Do not propose alternative architecture unless you can show a concrete blocker or a materially
safer/shorter path with repo evidence.

## Mandatory reading

1. `docs/Project_Documentation_Map.md`
2. `docs/05-AI_WORKING_AGREEMENT.md`
3. `docs/PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md`
4. `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md`
5. `docs/reviews/PRODUCT_WORKBENCH_CHANNEL_UX_SYNTHESIS_2026_09_21.md`
6. `docs/reviews/PRODUCT_WORKBENCH_SONNET_ARBITRATION_2026_09_21.md`
7. `docs/reviews/PRODUCT_WORKBENCH_TWO_STRUCTURAL_DECISIONS_2026_09_21.md`
8. `docs/reviews/PRODUCT_WORKBENCH_DECISION_B_GEMINI_ARBITRATION_2026_09_21.md`

Inspect actual `origin/develop` code/tests for every factual claim.

---

# Decision A — Magento daily workspace opens on the actual Magento catalogue

Proposed freeze:

- `Magento → Огляд` row universe = current successful Magento Remote Catalogue snapshot;
- `Публікація` row universe = local Master Products selected/linked for outbound preparation;
- `Зв'язки` = correspondence/matching between remote Magento rows and Master Products;
- remote rows remain provider observation, not Master Product ownership;
- Master Product remains platform truth;
- ExternalRecordLink remains trusted identity correspondence;
- no default UNION table mixes remote-only Magento rows with local-not-yet-created Products.

This is an information-architecture Stop-and-Amend to the old contract ordering where the local
selected Product worklist was primary and Remote Catalogue was secondary.

## Challenge A

1. Is there any frozen runtime/data invariant that actually requires local selected Products to
   remain the first/default Magento table?
2. Does remote-catalogue-first create any merchant ambiguity around ownership, publication,
   or editing?
3. Is `Огляд / Публікація / Зв'язки` the cleanest split for a merchant managing an existing
   Magento store?
4. Does this remain coherent for:
   - newly connected existing store;
   - empty/new Magento store;
   - remote-only products;
   - linked products;
   - local products selected for future CREATE?
5. Identify the smallest exact contract amendment needed.
6. If you reject A, prove the superior alternative with concrete product/runtime evidence.

---

# Decision B — defaults + sparse overrides for Magento Category / Attribute Set

Proposed freeze:

## Category

Default:
`ConnectorAccount + Master Category -> one default target Magento category`
using existing `ConnectorCategoryMapping`.

Exception:
`ConnectorAccount + Product -> 1..N explicit target Magento category IDs`
as sparse normalized rows.

No Product override rows means inherit default.
Deleting overrides means “return to automatic/default”.

Existing provider-only remote categories remain protected by the certified category-relation
ownership runtime; desired categories do not mean “delete every other remote category”.

## Attribute Set

For Product that does **not yet exist** in Magento / future CREATE:
1. explicit Product/account Attribute Set override;
2. else account-scoped `ProductType -> Magento Attribute Set` default;
3. else NotReady blocker.

For an **existing trusted Magento Product**:
- current observed remote `attribute_set_id` is structural truth for readiness/field
  applicability;
- ProductType default may be advisory;
- mismatch must NOT silently change the remote Attribute Set;
- changing Attribute Set of an existing remote Product is a separate future consequential
  capability.

Classification persists at Product scope; ProductVariant inherits Product-level structure.

## Provider-structure reuse

Reuse current observed Adobe structure foundation:
- AdobeProductAttributeSet;
- AdobeProductAttributeGroup;
- AdobeProductAttributeSetMembership;
- attribute/option lineage.

Do not perform per-Product provider HTTP reads for Attribute Set applicability.

## Preview determinism

Use canonical classification snapshot payload + deterministic revision/hash + Live admission
comparison.

Do NOT introduce a stored ConnectorAccount classification counter merely for invalidation.

## Challenge B

1. Is ProductType the correct default owner for future-CREATE Attribute Set selection?
2. Is sparse Product override the correct exception model?
3. Is normalized multi-category override better than JSON or full effective-state persistence?
4. Does the existing-vs-future-CREATE Attribute Set distinction hold technically and
   conceptually?
5. Are Product-level overrides correct for configurable/simple families given current
   Product/ProductVariant model?
6. Can current Adobe Attribute Structure persistence actually support multi-Attribute-Set
   readiness without N+1 provider calls?
7. Is canonical snapshot/hash the right Preview/Live invalidation pattern, or is a different
   existing repo owner more correct?
8. Does the model preserve future Shopify/Amazon/Google compatibility without embedding Magento
   semantics into Product/ProductType?
9. Identify any missing invariant, FK/uniqueness requirement, or stale-state problem that would
   make this unsafe to freeze.
10. If you reject B, propose the smallest superior model and explain exactly which problem it
    solves better.

---

## Required output

### Executive verdict

For each decision separately:

- `A: CONFIRM / CONFIRM WITH CORRECTION / REJECT`
- `B: CONFIRM / CONFIRM WITH CORRECTION / REJECT`

### Findings table

| ID | Decision | Severity | Claim challenged | Evidence | Correction |
|---|---|---|---|---|---|

Severity:
- BLOCKER — unsafe or misleading to freeze;
- MAJOR — must correct before freeze;
- MINOR — can follow after freeze.

### Freeze wording

If confirmed, provide the shortest exact wording you recommend freezing for Decision A and B.

### Explicit non-findings

List the tempting concerns you checked but which are NOT blockers, so we do not reopen them
again later without new evidence.

### Final recommendation

State whether the two decisions are ready for [Resolved] freeze.

## Do not

- implement code;
- broaden into full Workbench UX review;
- redesign CREATE;
- reopen ExternalRecordLink trust;
- equate ProductType with Magento Attribute Set;
- store Magento fields on Product;
- suggest generic JSON/EAV classification merely for “universality”;
- add speculative workflow/provenance columns without an identified requirement.
