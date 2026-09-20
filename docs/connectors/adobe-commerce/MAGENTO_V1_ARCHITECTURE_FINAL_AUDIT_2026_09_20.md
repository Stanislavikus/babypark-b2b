# Magento V1 Architecture Final Audit — Aggregated Arbitration

**Status:** OPEN — architecture closure audit in progress
**Audit date:** 2026-09-20
**Authoritative base:** `develop @ d63ae74831fa9c1015655ac81ad9e86810922400`
**Scope:** Magento / Adobe Commerce V1 architecture and runtime only. Merchant UX/UI completeness is explicitly a separate closure campaign.
**Purpose:** collect independent final-audit findings, Lead arbitration, and one deduplicated correction backlog before declaring the Magento V1 architecture CLOSED.

> This file is an audit working record, not a new Product/Domain contract. Existing `[Resolved]` decisions remain authoritative until a correction is explicitly approved and merged.

---

## 1. Closure rule

Magento V1 architecture may be declared **CLOSED** only when all of the following are true:

1. current `develop` runtime matches the advertised bounded Magento V1 capability;
2. authoritative docs are internally consistent and do not advertise mutually exclusive support truth;
3. all accepted independent-audit findings are corrected or explicitly rejected with repository evidence;
4. architecture contract tests mechanically protect the final truth;
5. no accepted runtime/security/concurrency/identity/transaction blocker remains;
6. an independent final architect review (Opus 5 in this audit) has been arbitrated;
7. UI/UX completeness is **not** inferred from architecture closure and is audited separately.

Current architecture closure state: **NOT YET CLOSED**.

---

## 2. Current bounded production truth on the audit base

Verified on `develop @ d63ae74831fa9c1015655ac81ad9e86810922400`:

- Adobe Products / Export / Preview = **true**.
- Adobe Products / Export / Live = **true**.
- Adobe Products / Import / Live = **false**.
- Magento Product CREATE = **unsupported in V1**.
- Standard path = **moduleless stock REST linked UPDATE**, not mandatory Safe Sync.
- Safe Sync = implemented optional Enhanced Safety primitive, not a standard-path prerequisite.
- Existing linked Products only; entity trust remains anchored by MerchantConfirmed `ExternalRecordLink` + logical Magento `entity_id` discriminator + expected SKU precondition.
- Live remains Preview-first, configuration-revision-bound, runtime-readiness-gated, DB-fresh consequential-write-gated, fail-closed on identity ambiguity, and reconciled after consequential writes.
- Public Receive/Import is not enabled merely because internal R3/R4 Receive runtime is implemented and real-target certified.
- P-04 / P-05 / P-06 / P-09 are explicitly non-blocking for the advertised bounded Export Live scope.

Primary current truth anchors:

- `docs/03-DOMAIN_MODEL.md` → **Live support truth [Resolved — 2026-09-19]**.
- `docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md`.
- `docs/connectors/adobe-commerce/MAGENTO_V1_PENDING_CERTIFICATION_ITEMS.md`.
- `docs/connectors/adobe-commerce/magento_v1_products_export_live_certification_2026_09_19.json`.
- `app/Support/Connectors/AdobePaaS/AdobePaaSConnectorAdapter.php`.
- `app/Services/Sync/SyncLiveAdmissionService.php`.
- `app/Support/Connectors/AdobePaaS/Command/AdobeProductStockSimpleWriteExecutor.php`.
- `tests/Feature/Sync/MagentoV1ProductsExportLiveTruthFlipDocumentationContractTest.php`.

---

## 3. Independent review source A — GPT-5.4

**Reviewer:** GPT-5.4
**Reviewer base:** `origin/develop @ d63ae74831fa9c1015655ac81ad9e86810922400`
**Reviewer verdict:** “B. ARCHITECTURE CAN CLOSE AFTER DOC CORRECTION ONLY.”

### Lead arbitration of GPT-5.4 conclusions

| ID | GPT-5.4 conclusion | Lead status | Severity / closure impact | Lead arbitration |
|---|---|---|---|---|
| G54-01 | Current advertised Magento V1 runtime is coherent and bounded | **CONFIRMED** | Architecture evidence; no correction | Adapter advertises Export Preview+Live and rejects Import Live. Live admission requires current revision Preview evidence and current support. Stock writer verifies trusted logical entity before PUT and reconciles afterward. No runtime contradiction found in this pass. |
| G54-02 | Authoritative documentation has a support-truth precedence conflict | **CONFIRMED** | **MEDIUM / DOC-ONLY / BLOCKS ARCHITECTURE CLOSURE** | `CONNECTOR_INTEGRATION_UX_CONTRACT.md` declares itself the winning connector-UX authority on conflict, yet its current-tense boundary/Stage 3 sections still say Adobe Products/Export/Live remains false and merchant consequential Live remains non-actionable. This contradicts later resolved Domain/Atlas/runtime truth. |
| G54-03 | The later moduleless decision is sufficient runtime supersession of mandatory Safe Sync for the standard path | **CONFIRMED** | No runtime correction | Current runtime consumes the certified moduleless linked-update path. Safe Sync is not required by standard Live admission/readiness. The old Safe Sync-centric gate remains legitimate historical/optional Enhanced Safety evidence, not current standard-path support truth. |
| G54-04 | Product CREATE is not reachable in the advertised V1 shipping path | **CONFIRMED** | Safety invariant; no correction | Low-level `AdobeProductRemoteStateClient::postProduct()` still exists, but repository search finds no production call site; the moduleless command path is linked GET → at most one PUT → GET reconciliation. Current truth test explicitly protects CREATE=false. |
| G54-05 | P-04, P-05, P-06, P-09 do not block current advertised Export Live | **CONFIRMED** | No correction unless scope expands | The authoritative pending ledger explicitly marks all four as NON-BLOCKING for the certified/default-store advertised scope. They remain useful future evidence/scope-expansion items, not reasons to reopen Export Live. |
| G54-06 | 22-point architecture checklist is green/N/A | **PARTIALLY CONFIRMED / SCOPE-QUALIFIED** | No immediate correction | Magento-relevant items 1–3, 17, 18, 21, 22 have direct code/schema/test evidence. Items 4–10 are accepted here only as “no Magento regression against existing platform invariants,” not as a fresh full-platform re-certification. Items 11–16 and 19 are outside Magento connector scope. **Item 20 (Hidden Technical Complexity) is NOT evidence that the merchant UI is complete; it belongs to the separate UI/UX closure audit.** |
| G54-07 | Evidence quality is sufficient for the bounded architecture claim | **CONFIRMED** | Architecture evidence | Structural/schema tests, feature/unit tests, MySQL concurrency tests, real-target READ/WRITE/restore evidence, and merchant-path Preview→Live evidence exist for the advertised scope. This does not claim every possible negative-path or future scope is certified. |
| G54-08 | Architecture can close after documentation correction | **CONFIRMED WITH PROCESS CONDITION** | Closure condition | Technically plausible after the accepted doc corrections, but per project decision on 2026-09-20 final closure also requires arbitration of the independent Opus 5 audit. Architecture is therefore not yet declared CLOSED. |

---

## 4. Confirmed finding A-001 — authoritative UX support-truth conflict

**Status:** CONFIRMED
**Class:** documentation precedence / current-truth contradiction
**Severity:** MEDIUM
**Runtime defect:** NO
**Blocks architecture closure:** YES

### Conflicting current authority

`docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md` states that it is the consolidated normative connector-facing UX reference and wins over summary docs on connector UX conflict.

The same document still contains current-tense claims including:

- Existing-vs-future boundary: truthful flip of Adobe Products/Export/Live remains **false** and consequential Live stays non-actionable until real-target certification.
- Stage 3 implementation status: advertised Live support remains **false** and production enablement waits for Stage 3E real-target validation/truth flip.
- §18: “Truthful Adobe Products/Export/Live advertised support remains **false**.”
- §18: Magento tile keeps the **false** truth flag until certification.
- Link-first section: truth flip “waits for real-target certification”.

Those prerequisites have since been satisfied and the resolved 2026-09-19 truth flip is already merged.

### Contradicting current runtime / resolved truth

- `AdobePaaSConnectorAdapter::supports(Products, Export, Live)` = true.
- `AdobePaaSConnectorAdapter::supports(Products, Import, Live)` = false.
- `docs/03-DOMAIN_MODEL.md` → [Resolved — 2026-09-19] states Export Live=true, Import Live=false, CREATE unsupported.
- Runtime readiness, current-revision Preview evidence, entity trust, write gate and reconciliation are implemented.
- `magento_v1_products_export_live_certification_2026_09_19.json` records real merchant-path no-op/write/restore evidence.

### Required correction

Do **not** rewrite historical evidence as though it never existed. Update only current/normative UX truth so that it says, consistently:

- moduleless standard path;
- Safe Sync optional Enhanced Safety;
- Products / Export / Live = true for the certified bounded V1 scope;
- Products / Import / Live = false;
- Product CREATE unsupported;
- linked UPDATE-only;
- first consequential Live remains Preview/admission/readiness/trust gated, but is now actionable when those gates pass.

At minimum reconcile:

1. `CONNECTOR_INTEGRATION_UX_CONTRACT.md` Existing-vs-future boundary;
2. Stage 3 Live implementation-status paragraphs;
3. §18 current support-truth / false-tile paragraphs;
4. Link-first “truth flip waits” wording, which should become historical/completed prerequisite wording rather than a still-pending gate.

---

## 5. Lead finding A-002 — missing mechanical guard on the highest-precedence UX truth

**Status:** CONFIRMED
**Class:** documentation-contract test gap
**Severity:** MEDIUM (because it allowed A-001 to survive green CI)
**Runtime defect:** NO
**Blocks architecture closure:** YES, together with A-001

Current truth-flip contract tests mechanically protect `03-DOMAIN_MODEL.md`, the runtime adapter and Runtime Atlas, but do not assert the final current support truth in the precedence-owning `CONNECTOR_INTEGRATION_UX_CONTRACT.md`.

Existing moduleless UX tests protect important semantics (Safe Sync not mandatory, real-target certification requirement, first-Live gating), but they were not amended after certification to assert the completed truth flip.

### Required correction

Add section-scoped mechanical assertions that the current/normative UX sections state:

- Export / Live = true for the certified scope;
- Import / Live = false;
- Product CREATE unsupported;
- Safe Sync optional rather than mandatory;
- no current-tense statement in the authoritative current sections says that the completed truth flip is still pending.

Avoid a blanket “string must never exist anywhere” assertion because historical sections/evidence may legitimately retain old support=false statements.

---

## 6. Lead arbitration A-003 — historical Stage 3E wording in `03-DOMAIN_MODEL.md`

**Status:** PARTIALLY CONFIRMED
**Class:** documentation clarity / precedence hardening
**Severity:** LOW-to-MEDIUM
**Runtime defect:** NO
**Blocks architecture closure:** recommended to correct in the same doc-truth patch

GPT-5.4 proposed explicitly marking old `Live=false / Production Live not implemented / Safe Sync standard-path` sections as historical/superseded.

Lead finding:

- `03-DOMAIN_MODEL.md` already contains a later explicit **superseding** Moduleless-by-default decision.
- The 2026-09-19 **Live support truth** section explicitly labels the older Safe Sync validation gate historical/superseded for the standard moduleless path.
- Therefore `03` does not have the same unresolved precedence defect as the UX contract.

However, the older Stage 3E status blocks themselves still read in present tense (“support remains false”, “Production Live remains NOT IMPLEMENTED”) before the reader reaches the superseding sections. For final architecture closure, a short banner at the beginning of those historical status/amendment blocks should make the supersession immediately visible without deleting historical evidence.

### Recommended correction

Add an explicit historical/superseded notice to the old Stage 3E implementation-status / Post-#168 amendment region, pointing to:

- `Magento V1 Moduleless-by-default Stop-and-Amend`;
- `Live support truth [Resolved — 2026-09-19]`.

Do not rewrite historical decisions or certification sequencing.

---

## 7. GPT-5.4 architecture assertions accepted as non-findings

These are verified constraints/evidence, not correction items:

### 7.1 Support/readiness separation

Support truth and runtime readiness are distinct. Export Live support is public; runtime readiness still performs fresh account-specific read-only checks and does not imply Import support.

### 7.2 Linked UPDATE-only / no CREATE

The shipping V1 path requires trusted linkage and current identity. `postProduct()` remains a dormant low-level primitive with no production shipping call site. Current V1 is not allowed to create Products.

### 7.3 Entity trust / identity

MerchantConfirmed ERL + logical Magento entity discriminator is identity authority. SKU remains an equality/precondition, not identity authority. Standalone Simple uses Variant-subject trusted identity under the current resolved model.

### 7.4 Preview-first / configuration revision

Live requires completed Preview evidence for the current configuration revision; Preview is not a promise of an immutable payload but remains the merchant evidence/admission prerequisite.

### 7.5 Concurrency / stale recovery

Live admission and worker-side gates have MySQL concurrency coverage; stale queued/running recovery exists; writer execution does not use blind retry semantics.

### 7.6 SSRF / external destination safety

Connector transport disables redirects, enforces time/connect limits and response-size bounds, and applies destination/IP policy including non-globally-reachable ranges such as link-local/private space.

### 7.7 Secret handling

`ConnectorAccount.credentials` is hidden and cast as `encrypted:array`; tests protect UI/Livewire/serialization non-exposure and authorized credential mutation.

### 7.8 Open P-items

P-04 / P-05 / P-06 / P-09 remain explicitly bounded/non-blocking. They should become blockers only if advertised ownership/scope expands into their deferred semantics.

---

## 8. 22-point checklist — Lead scope qualification

The global checklist remains mandatory, but final Magento closure must distinguish **direct connector proof** from **unrelated platform invariants**.

| Checklist area | Magento audit classification |
|---|---|
| 1 Tenant Isolation | DIRECTLY VERIFIED / connector-owned schemas and scopes |
| 2 Automated Scoping | DIRECTLY VERIFIED / workspace scopes + explicit without-scope owners where required |
| 3 Authorization and RBAC | DIRECTLY VERIFIED / dedicated workspace authorization and connector/sync permissions |
| 4 Attribute Dictionary Integrity | NO MAGENTO REGRESSION FOUND; governed mapping/materialization uses platform field architecture |
| 5 Attribute Storage Split | NO MAGENTO REGRESSION FOUND |
| 6 JSON localization | NO MAGENTO REGRESSION FOUND; R4 explicitly excludes localizable Dynamic single-select |
| 7 Field duplication/import aliases | NO MAGENTO REGRESSION FOUND; not re-certified as a whole-platform import subsystem |
| 8 Clean domain separation | VERIFIED for connector ownership boundaries |
| 9 Variant cardinality | VERIFIED for Magento identity shape; standalone Simple uses platform Variant subject |
| 10 B2B channel projection | NO MAGENTO REGRESSION FOUND |
| 11–16 Orders / payments / inventory invariants | NOT APPLICABLE TO MAGENTO V1 CONNECTOR CLOSURE; no touched seam |
| 17 Connector Encapsulation | DIRECTLY VERIFIED |
| 18 No Hardcoded Clients | DIRECTLY VERIFIED for Magento runtime; no BabyPark-specific client ID/business branch accepted |
| 19 Payment Data Safety | NOT APPLICABLE |
| 20 Hidden Technical Complexity | **DEFER TO SEPARATE MAGENTO UX/UI AUDIT**. Architecture closure must not claim merchant UX completeness. |
| 21 External URL / SSRF Safety | DIRECTLY VERIFIED at connector transport policy level |
| 22 Connector Secret Handling | DIRECTLY VERIFIED |

This qualification supersedes any reading of GPT-5.4's checklist as proof that the Magento merchant interface itself is finished.

---

## 9. Consolidated correction backlog — before Opus 5 arbitration

| Correction ID | Source | Action | Risk | Required before architecture CLOSED? |
|---|---|---|---|---|
| C-001 | GPT-5.4 / Lead confirmed | Reconcile current/normative `CONNECTOR_INTEGRATION_UX_CONTRACT.md` with 2026-09-19 Export Live truth | GREEN docs, high truth importance | **YES** |
| C-002 | Lead addition | Add section-scoped contract tests tying UX current truth to adapter/Domain/Atlas truth | GREEN tests | **YES** |
| C-003 | GPT-5.4 / Lead partial | Add immediate historical/superseded banner to old Stage 3E status/amendment blocks in `03-DOMAIN_MODEL.md` without rewriting history | GREEN docs | **YES for clean closure** |
| C-004 | Process | Add Opus 5 findings below, deduplicate against C-001..C-003, then implement only accepted corrections | pending | **YES** |

No runtime/data-model/auth/concurrency/transaction correction is accepted from GPT-5.4 at this stage.

---

## 10. Independent review source B — Opus 5

**Status:** PENDING USER-SUPPLIED REVIEW

When the Opus 5 result arrives, record every materially distinct claim here with:

- exact claim;
- Lead status: `CONFIRMED / PARTIALLY CONFIRMED / REJECTED / OBSOLETE`;
- exact authoritative doc/code/test/evidence seam;
- overlap with GPT-5.4 finding/correction if any;
- resulting correction ID or explicit no-action reason.

Do not implement the consolidated correction backlog until Opus 5 arbitration is complete, unless an active safety/runtime blocker is discovered.

---

## 11. Current Lead verdict

**Runtime architecture verdict:** no architecture/runtime blocker was confirmed from GPT-5.4's audit for the currently advertised bounded Magento V1 Export Live scope.

**Formal architecture closure verdict:** **NOT CLOSED YET**.

Reason: confirmed documentation-precedence conflict + missing mechanical guard remain, and the user explicitly requires an independent Opus 5 audit before final closure.

**UI/UX verdict:** deliberately **NOT ASSESSED HERE**. Magento merchant-facing completeness is a separate campaign after architecture closure.
