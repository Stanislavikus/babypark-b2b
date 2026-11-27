# Magento Product Export Merchant Journey — Lead Arbitration

**Date:** 2026-09-14  
**Status:** research arbitration only; not normative implementation authorization  
**Authoritative repo baseline reviewed:** `develop @ 91a4dde55d7c009cf972037e61cb8b29eb315cab`

## Inputs

This arbitration compares:

- current repository contracts/runtime on `develop`;
- GPT-5.4 merchant-journey adversarial research supplied 2026-09-14;
- Sonnet High merchant-journey research supplied 2026-09-14;
- current Adobe Commerce primary documentation for Product create/update/SKU/ID behavior.

The earlier mapping/taxonomy boundary remains separate and is recorded in
`docs/reviews/CONNECTOR_MAPPING_TAXONOMY_ARBITRATION_2026_09_14.md` on this research branch.

## Executive verdict

The existing Magento V1 safety foundation is not disproven. The current merchant page is confusing because it exposes Preview remediation and Live/Entity-Trust preparation as parallel worklists without a causal hierarchy.

However, the research also exposed a larger product-capability decision that must not be hidden inside a UX patch: current Stage 3E deliberately makes Magento V1 **link-first / existing-entity mutation only** and explicitly excludes no-link automatic Product CREATE. Supporting true CREATE is therefore a separate Stop-and-Amend, not an additive enum change.
## 1. Findings accepted from both reviews

### A. Configuration blockers must aggregate in presentation

Per-item `SyncRunItem` evidence may remain historical and product-scoped, but merchant presentation must group repeated root causes. One missing FieldMapping affecting 54 Products is one configuration task with 54 affected Products, not 54 independent merchant tasks.

Do not change persistence merely to deduplicate UI. Add a derived root-cause summary above/detailing the product worklist.

### B. Remediation destination must follow the domain owner

- FieldMapping finding -> Mapping.
- FieldOptionMapping finding -> Option Mapping.
- Product value finding -> Product editor when supported.
- Variant value finding -> Variant editor/grid when supported.
- Pricing finding -> Pricing owner/surface.
- Identity finding -> Live/Entity Trust flow.
- Unsupported remediation -> honest unavailable explanation, never a generic dead-end message.

### C. Account/target scope must be explicit

`ExternalRecordLink` is ConnectorAccount-scoped. Merchant copy must never imply one global "Magento identity" when the workspace may contain multiple Magento accounts/targets. Preview, Live and identity review must identify the connected account and relevant target context in plain language.

### D. Entity Trust is not Preview FieldMapping

Keep domain separation:
`FieldMapping != Product data validity != Entity Trust != Live execution state`.
A unified merchant worklist is acceptable only as a presentation contract; do not merge the underlying enums/state machines.
## 2. Sonnet corrections

### S1 — `AdobeProductExportPreviewPlanOperation.operation` is not CREATE/UPDATE intent

**Reject.** Actual runtime uses semantic operation labels such as `simple_product`, `configurable_parent`, `configurable_attribute`, etc. Sonnet misread this field as per-item mutation mode.

Therefore current Preview does **not** already prove a frozen CREATE-vs-UPDATE decision contract merely because `AdobeProductExportPreviewPlanOperation` has an `operation` property.

This matters: if true CREATE is restored later, explicit mutation intent must be designed and evidenced rather than assumed to exist already.

### S2 — zero vocabulary overlap is not an architecture defect

**Reject classification; accept UX consequence.** `SyncPreviewFindingCode` and `EntityTrustReadinessStatus` are intentionally different vocabularies because they represent different truths. Zero enum overlap is not a reason to merge domain taxonomies.

The actual gap is merchant orchestration: two correct subsystems are rendered without one causal task hierarchy.

### S3 — `SystemConfirmedViaCreate` is not a cheap additive fix

**Reject as implementation advice under the current contract.** Stage 3E explicitly invalidated the historical no-link create assumption, requires merchant-confirmed ERL for current consequential V1 writes, and freezes automatic Product create out of V1.

A new trust-origin enum case is meaningful only after a safe CREATE execution path is separately authorized and proven.
## 3. Why CREATE is a separate safety problem

Adobe officially supports Product creation with `POST /V1/products`, while update routes address an existing Product by SKU. Adobe also distinguishes the internal numeric Product ID from seller-managed SKU, and SKU can be changed.

That proves CREATE is a real provider capability. It does **not** prove that CREATE can be added safely by persisting the response ID after POST.

A production create path must separately settle at least:

- pre-create absence/collision evidence and race handling;
- transport loss after Magento may have committed but before SaaS receives the response;
- duplicate-create prevention on retry/recovery;
- authoritative capture of Magento logical `entity_id` plus exact SKU postcondition;
- account/store context binding;
- stale Preview/configuration protection;
- configurable parent/child partial-create recovery and ordering;
- media/relationship follow-on failures after core Product creation;
- how a newly established ERL provenance is audited without fake merchant confirmation.

Current Stage 3E avoided this entire ambiguity class by making the standard V1 consequential path link-first and update-only. Reopening CREATE is therefore legitimate product work, but it must be researched/certified as its own mutation category.

## 4. Current frozen runtime truth

Current code/docs require trusted ERL for standard simple/child consequential write; absent trust returns `link_required`. Current stock simple writer performs fresh Product GET, verifies exact SKU + simple type + Magento `id` equals the trusted discriminator, then performs at most one PUT and read-only verification. Stock POST/create is intentionally unreachable on this path. Public Products/Export/Live support remains false.
## 5. Merchant UX decision independent of CREATE

The following corrections are justified even if Magento V1 remains link-first/update-only:

1. Aggregate configuration-level Preview blockers by root cause and affected count.
2. Keep per-item historical evidence available as detail, not as the only presentation.
3. Replace repeated identical remediation buttons with one causal action per root cause.
4. Route Product/Variant/Pricing/Mapping/Identity findings to their real owner.
5. Show account/target context consistently.
6. Give configurable relink-without-parent-SKU a distinct merchant-facing reason instead of overloading generic configuration-stale wording.
7. While public Live support is false, do not make Entity Trust look like required work in the default merchant journey. It may remain available for internal/certification purposes but should not compete with Preview remediation as the user's next task.
8. Once Live is supported, surface only actionable identity exceptions by default; already-confirmed/no-action rows belong in status/detail, not the primary task list.

A presentation-level unified worklist may combine task rows from Preview and Entity Trust only after each row preserves its underlying source/type. Do not create one generic domain status enum.

## 6. CREATE capability decision

Before calling Magento Product Export generally production-ready, Product Owner must explicitly choose the advertised scope:

- **Update/link-only V1:** only previously existing Magento Products that have been safely linked may be mutated. This is close to current Stage 3E and can ship honestly if product copy/capability truth says so.
- **Create + update V1:** missing remote Products can be created, authoritative remote identity established from the create outcome, and later updates use ERL. This is a broader and more useful commerce connector, but requires a separate CREATE Stop-and-Amend + real-target certification.

Do not smuggle the second scope into the first as a small UX fix.
## 7. Arbitration ledger

| Finding | Verdict |
|---|---|
| Aggregate repeated config blockers | ACCEPT |
| Keep per-item evidence underneath | ACCEPT |
| Explicit account/store target copy | ACCEPT |
| Distinct configurable relink reason | ACCEPT |
| Domain-specific remediation routing | ACCEPT |
| Unified merchant worklist at presentation only | ACCEPT WITH CONTRACT |
| Merge Preview/Entity Trust enums | REJECT |
| `AdobeProductExportPreviewPlanOperation` already decides CREATE/UPDATE | REJECT — misread semantic operation type |
| Add `SystemConfirmedViaCreate` now | REJECT — CREATE path is forbidden/absent today |
| Auto-trust after a future certified CREATE | PLAUSIBLE, requires separate CREATE contract |
| Hide/default-deprioritize Entity Trust while public Live=false | ACCEPT for merchant default surface |
| Entity Trust state machine itself is broadly fail-closed | ACCEPT, subject to existing certification gaps |
| First Live needs explicit create/update/blocked counts | ACCEPT only after mutation-intent model exists; current V1 has no general CREATE intent |

## 8. Required next research gate before changing CREATE semantics

Run one narrow Stop-and-Amend investigation only if Create+Update V1 is the desired product scope. It must reconstruct why Stage 3E invalidated no-link create and test whether a new create-specific protocol can satisfy current invariants without weakening update safety.

Required proof topics: POST response-loss ambiguity, duplicate prevention, candidate collision, entity-id capture, SKU exactness, configurable partial creation, account/store scope, retry/idempotency, and post-create ERL provenance.

Until that gate closes, merchant-journey UX corrections may proceed only if they preserve current link-first runtime truth and public Live=false.

## Final verdict

**MERCHANT JOURNEY NEEDS NARROW UX/CONTRACT CORRECTION, PLUS A SEPARATE PRODUCT-SCOPE DECISION FOR CREATE.**

Do not implement `SystemConfirmedViaCreate` or restore POST/create from these research reports alone.

## 9. Gemini final review + primary-source verification

Gemini's final recommendation is **ACCEPTED WITH CORRECTIONS**:

**FREEZE — LINK/UPDATE-ONLY MAGENTO V1.**

The decisive verification was performed independently against current Magento `2.4-develop` source, not against the report's intermediate reasoning:

- `POST /V1/products` and `PUT /V1/products/:sku` both dispatch to `ProductRepositoryInterface::save()`.
- `ProductRepository::save()` resolves an object without an ID by SKU first; when that SKU exists it loads the existing Product and continues through save semantics. Therefore stock POST cannot serve as a BabyPark "create-only, fail-if-exists" safety primitive.
- `catalog_product_entity.sku` has a normal BTREE index rather than a DB UNIQUE constraint in current declarative schema.
- current `Sku::beforeSave()` calls `_generateUniqueSku()` and may suffix a conflicting SKU; exact post-save SKU equality remains a load-bearing postcondition.
- Adobe documents product creation using `POST /V1/products`, but that public API label does not override the repository semantics above for our safety contract.

Gemini's own intermediate statement that POST is create-only is rejected; its final provider-safety conclusion is supported by current Magento source.

### Corrections to the Gemini report

1. `AdobeProductExportPreviewPlanOperation.operation` is semantic operation type (`simple_product`, `configurable_parent`, etc.), not CREATE/UPDATE intent. Keep this correction frozen.
2. Do not describe ordinary retry of an existing linked update as generically "safe". Current contract remains `tries = 1`, no blind retry, with ambiguity/reconciliation handled explicitly.
3. Simple↔Configurable transition is **unsupported in Magento V1 by platform contract** unless separately certified; do not overstate this as a universal provider prohibition without direct proof.
4. Configurable CREATE non-atomicity is an additional reason not to advertise CREATE in V1, but the decisive Simple CREATE problem is already sufficient: stock REST save semantics do not provide the create-only identity boundary our safety contract requires.
5. A future CREATE capability is not forbidden forever. It would require a new, separately certified provider-side create boundary (for example a first-party component/endpoint) that can prove exact creation, ownership/provenance, response-loss recovery, and configurable partial-failure semantics. It is explicitly outside Magento V1.

### Merchant UX freeze candidates retained

- aggregate repeated configuration blockers in presentation while preserving per-item evidence;
- one causal remediation action per root cause;
- route Mapping / Product / Variant / Pricing / Identity findings to their real owner;
- show Connector Account + external context clearly;
- hide already-confirmed/no-action identity rows from the primary task list;
- do not merge Preview and Entity Trust domain enums; unify only presentation rows if useful;
- while public Live support is false, Entity Trust must not compete with Preview remediation as mandatory default work. It may remain reachable as preparation/status, but the merchant's primary next action is the blocker that actually prevents progress.

## 10. Final freeze decision

**FREEZE — LINK/UPDATE-ONLY MAGENTO V1.**

Magento V1 Product Export means: safely update Products that already exist in the selected Magento Connector Account and have established trusted external identity. It does **not** create a missing Magento Product.

Merchant-facing capability truth must say this explicitly. A Product without trusted identity is not Live-ready; it requires safe matching/confirmation to an existing Magento Product. If no corresponding Magento Product exists, V1 cannot send that Product and must explain the limitation rather than imply an editor/mapping problem.

This is a product-scope freeze for Magento V1, not a universal multi-connector rule. Another provider may support a certifiable create-only primitive and may establish identity from a provider-authoritative create response under its own connector contract.

### Next gate

No additional broad research is required. Next work is:

1. docs-only Stop-and-Amend aligning normative Magento V1 + connector UX contracts with this capability truth and the accepted worklist/progressive-disclosure corrections;
2. narrow review of that docs patch only;
3. freeze;
4. implementation in ordered slices, preserving current fail-closed update safety.

Do not restore POST/create or add a system-confirmed-create trust origin in Magento V1 implementation.
