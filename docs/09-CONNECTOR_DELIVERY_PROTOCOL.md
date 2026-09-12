# Connector Delivery Protocol

## Purpose

This document defines the mandatory delivery method for Magento / Adobe Commerce and every future external connector.

The goal of connector work is not to complete stages, GAPs, PRs, endpoints, or individual fields.

The goal is an observable product result:

> Every connector-declared field and capability is inventoried, classified, represented by the platform, and proven on a real target in every direction that the external platform actually permits. For mutable fields, the default target is working change propagation in both directions: external system → platform and platform → external system.

A connector is not complete because one field works, one endpoint returns data, unit tests pass, or an architectural foundation exists.

Connector delivery is complete only when the field/capability inventory is exhausted and every item has a verified outcome.

This protocol is implementation process, not a replacement for the Domain Model, Architecture Principles, Attribute Dictionary, Connector/Sync contracts, or Runtime Atlas.

---

## 1. Authoritative External Inventory First

Before expanding connector runtime, establish the external platform's authoritative capability and field inventory from primary sources and, where available, the real target schema/runtime.

Use, in priority order:

1. official platform API/schema/reference documentation;
2. official reference connector or integration metadata when one exists;
3. the actual connected target's metadata/schema/capability responses;
4. verified runtime request/response evidence.

Do not derive the connector scope from whichever fields our code already happens to support.

Do not start with a hand-picked sequence such as `name → description → price` unless that sequence is justified by the inventory and cluster plan.

The inventory must capture, at minimum:

- external entity/object;
- external field/key/path;
- type and shape;
- required/optional/null/clear semantics where known;
- read capability;
- write capability;
- external restrictions or system ownership;
- version/edition/API scope where relevant.

Unknown fields are work to resolve. They must not silently disappear from the connector scope.

---

## 2. Classify All Fields Before Field-by-Field Implementation

After the external inventory exists, classify every field by transport and platform-domain mechanics.

Clusters should reflect shared behavior, for example:

- core Product scalar fields;
- core ProductVariant scalar fields;
- dynamic scalar attributes;
- select / multiselect attributes and option mapping;
- localized text;
- pricing;
- inventory / availability;
- media;
- categories / relations;
- configurable / variant-family structure;
- identity and system-controlled fields;
- other connector-specific structures proven by the external inventory.

The exact clusters are determined by evidence from the external platform and our domain owners. They are not frozen by the examples above.

Fields that use the same platform owner and transport semantics should normally share one implementation mechanism. Do not build duplicate one-field pipelines when a cluster-level mechanism is appropriate.

---

## 3. Map Every Field to the Platform

Maintain a connector master matrix with one row per external field/capability.

Minimum columns:

`External entity/field → cluster → external READ → external WRITE → platform domain owner → platform representation/binding → connector READ seam → connector WRITE seam → real validation → result/blocker`

For every row determine:

- which platform domain owns the data;
- whether the platform already has a canonical representation;
- whether FieldMapping / FieldOptionMapping is applicable;
- whether an existing connector runtime seam can transport it;
- what exact missing seam, if any, prevents end-to-end behavior.

Connector-specific payload structures must not become new core domain fields merely to mirror the external platform.

When the external field has no valid platform representation, treat that as a concrete blocker to resolve through the normal documentation/domain process. Do not silently skip the field.

### 3.1 Mandatory per-field runtime progress state

The connector master matrix may group fields when documenting shared transport/domain mechanics, but every active certification campaign must maintain a **machine-readable per-field progress source with exactly one explicit outcome per discovered external field**. Aggregated cluster rows never replace per-field state.

When a persisted runtime classification/projection exists, **runtime state is authoritative** and JSON/CSV ledgers are generated evidence/exports only. When a connector has not yet implemented such a runtime projection, a machine-readable per-field ledger remains the fallback authoritative source until the runtime projection replaces it. Never maintain two independently editable classification truths.

Minimum per-field facts are:

`external_field_key → normalization/type/scope → behavior_class → disposition/progress_bucket → owner or canonical_code (if any) → mapping strategy/state → certification evidence → next action/reason`

The top-level runtime disposition or fallback `progress_bucket` must make merchant/runtime intent obvious:

- `canonical_platform` — a neutral platform concept exists; automatic mapping is either verified or has an explicit mapping gap;
- `provider_standard` / connector-specific equivalent — a stable provider field or provider/domain mechanic that must not be forced into global canonical vocabulary;
- `workspace_custom` — a merchant/workspace-specific discovered attribute retained without global semantic promotion;
- `system_or_dedicated_owner`, `review_needed`, or `unsupported` — explicit non-generic terminal/attention states where the connector runtime supports them.

**Write-through rule:** discovery/classification/certification work is not complete until the authoritative runtime projection (or fallback ledger) records the resulting state and durable evidence/reason. “We checked it in chat” is not project state.

**Resume rule:** after a chat/session boundary, read the authoritative inventory, persisted runtime classification plus its latest generated evidence export (or the fallback ledger where no runtime projection exists), the pending-blocker queue, and the runtime Atlas. Continue from unresolved runtime/research/blocker state. Do not repeat provider research or re-inventory already-accounted fields unless the external version/snapshot materially changed or a concrete inconsistency is found.

### 3.2 Connector certification is not customer onboarding

Field-by-field connector certification is an engineering activity performed once per connector/version/behavior class. It must not become a manual onboarding requirement for every merchant catalogue.

At workspace discovery time:

1. exact/high-confidence canonical mappings reuse the already-approved canonical/provider mapping rules;
2. known provider-standard fields reuse the connector's existing owner/transport classification;
3. workspace custom fields are persisted as discovered fields and matched to an already-certified behavior class (for example data type + scope + requiredness + option/clear semantics);
4. a new engineering investigation is opened only for an unrecognized behavior class, ambiguous semantic promotion, or a concrete runtime failure.

A catalogue with hundreds or thousands of custom attributes must therefore scale primarily as data classification, not as hundreds or thousands of fresh manual vendor-manual studies. Individual rows still receive explicit progress/results, but proven cluster/behavior mechanics are reused automatically.

For installation-dependent `workspace_custom` attributes, **exact per-code WRITE certification is not an onboarding gate** when the discovered metadata fits an already real-target-certified behavior class and there is no field-specific restriction or runtime failure. The ledger must distinguish `exact field certified` from `behavior-class covered`; both may be onboarding-ready, but only the first may be counted as an exact field WRITE→READ→RESTORE proof. A field that introduces a new type/scope/option/clear/requiredness behavior, or fails at runtime, leaves the reusable fast path and becomes engineering work.

---

## 4. Implement Missing Seams as Blocker Removal, Not as New End Goals

Architecture foundations, Safe Sync seams, mapping services, mutation services, transport clients, identity checks, and run lifecycle components are means to the connector goal.

They are not independent campaign goals unless they are reusable product capabilities in their own right.

Every implementation slice must answer:

> Which observed or inventory-proven blocker prevents a field cluster from completing real end-to-end transport, and how does this slice remove that blocker?

If there is no concrete blocker, the next action should normally be a real probe or certification step, not another speculative architecture stage.

Do not create a new GAP, stage, table, service, abstraction, or persistence model merely because it may be useful later. Create or change architecture only when current authoritative docs require it or real evidence proves it is necessary.

---

## 5. Representative Real Probe for Every Cluster

Before certifying every field individually, select at least one representative field from every cluster and exercise the complete end-to-end path against a real target.

For every direction the external platform permits, execute the representative probe:

- external system → platform (Receive / Import / READ + governed local mutation);
- platform → external system (Send / Export / governed external WRITE).

The probe must use the same production-intended identity, mapping, authorization, safety, transformation, domain-owner, and run-evidence path that the connector will use.

Mocks and unit tests are useful but do not replace the real-target probe.

Capture literal evidence sufficient to identify the failure class: request/operation, response/status, connector/domain outcome, and the field/cluster being exercised. Do not expose secrets in committed evidence.

---

## 6. Error-Driven Correction Loop

After the first representative probes, observed failures become the implementation queue.

Use this loop:

1. define expected field behavior;
2. run the real or production-equivalent path;
3. capture the actual failure/error/evidence;
4. identify root cause;
5. fix the smallest correct seam;
6. rerun tests;
7. rerun the same real probe;
8. continue until the representative field passes in every supported direction;
9. move to the next unresolved cluster.

The purpose of each next technical step is to remove a demonstrated obstacle to the final connector goal.

Do not replace a real failure with theory about what might fail later when the target can be tested now.

A failure may expose a genuine new architecture or domain ambiguity. In that case use the normal Stop & Amend / routing rules. Otherwise fix the proven blocker and continue.

---

## 7. Field-by-Field Certification After Cluster Proof

Once a cluster's representative path works, run every provider-standard/canonical field in that cluster through the proven mechanism.

Do not assume that standard provider fields pass because one representative field passed.

Installation-dependent `workspace_custom` codes are different: their connector contract is the discovered behavior class, not the merchant's arbitrary attribute code. Each custom row still receives an explicit result, but it may terminate as `BEHAVIOR CLASS COVERED` without a dedicated destructive WRITE when its metadata matches a real-target-certified class and no field-specific blocker exists. Exact per-code certification remains required for new behavior classes, special semantics, and concrete failures.

Every row receives an explicit certification/classification result for each relevant direction.

Allowed final classifications are:

- `READ PASS`;
- `WRITE PASS`;
- `READ + WRITE PASS`;
- `BEHAVIOR CLASS COVERED` — workspace-custom only; exact representative real-target proof for the matching transport behavior exists and this row introduces no new behavior/failure;
- `INTENTIONALLY ONE-WAY` — only with a concrete external-platform or product-domain reason and evidence;
- `SYSTEM / PLATFORM OWNED` — mutation is not semantically valid, with owner/reason recorded;
- `BLOCKED` — concrete unresolved blocker recorded;
- `NOT APPLICABLE` — only with an explicit reason.

`INTENTIONALLY ONE-WAY`, `SYSTEM / PLATFORM OWNED`, and `NOT APPLICABLE` are not convenience escape hatches. If the external platform exposes a field as mutable and the platform has a valid domain representation, the connector target is bidirectional transport unless an approved product/domain decision says otherwise.

No field may remain silently `unknown` in a connector declared complete.

---

## 8. Connector Completion / Certification Gate

A connector V1 is not production-ready until all of the following are true:

1. authoritative external field/capability inventory is complete for the declared connector scope/version;
2. every field is assigned to a cluster and platform domain owner;
3. every field has a platform representation or an explicit documented blocker/classification;
4. at least one representative field from every cluster has been proven end-to-end on a real target in every supported direction;
5. errors found during representative probes have been root-caused and the required blockers fixed;
6. every canonical/provider-standard field has been individually certified through the working cluster mechanism, and every workspace-custom row is either exact-field certified or explicitly covered by a certified behavior class with no unresolved exception;
7. the master matrix contains no unexplained gaps or unknowns;
8. connector capability/support flags match actual runtime truth;
9. automated tests and CI pass for the production-intended paths;
10. real-target evidence exists where a real target is available;
11. affected `08-CONNECTOR_SYNC_RUNTIME_ATLAS.md` rows reflect the actual runtime in the same PR/campaign.

A unit-test-only or mock-only result does not satisfy connector production readiness when a real target exists.

---

## 9. Campaign and Task Discipline

For one coherent connector capability, prefer one implementation campaign, one working branch, and one Draft PR with multiple logical commits/slices.

Within the campaign:

`inventory → classify → map → implement missing seam → representative probe → capture errors → fix root cause → rerun → certify all fields`

Normal successful slices do not require user approval to continue.

Do not stop after each field to ask what to do next. The matrix and failed probes determine the next technical action.

Do not let historical Stage/GAP numbering become the product goal. Stages/GAPs may locate constraints or blockers; they are not the definition of connector completion.

---

## 10. Required Handoff State

Every connector campaign handoff must state:

- connector and external version/edition scope;
- authoritative inventory source(s);
- inventory completion status;
- clusters and representative fields;
- master matrix location;
- authoritative per-field runtime progress source (or fallback ledger) plus latest generated evidence location and disposition/bucket counts;
- which representative READ probes passed/failed;
- which representative WRITE probes passed/failed;
- literal current blockers/errors;
- fixes made and probes rerun;
- field-by-field certification progress;
- tests/CI status;
- real-target evidence status;
- next action selected from an unresolved blocker or uncertified field/cluster.

The handoff must not reduce the campaign to the last field that happened to be implemented.

---

## 11. Magento / Adobe Commerce First Application

Magento / Adobe Commerce is the first connector to follow this protocol end-to-end and should become the reference implementation for subsequent connectors.

The Magento campaign goal is therefore not `Product.name`, Receive R3, GAP-028, GAP-029, one REST endpoint, or any other individual seam.

The goal is:

> Inventory all Magento / Adobe Commerce fields and relevant product capabilities in the declared V1 scope; classify them into transport/domain clusters; represent them correctly in the platform; prove at least one field from every cluster in every permitted direction; fix the concrete failures discovered by those probes; then certify every field through the resulting working mechanisms until the Magento V1 matrix has no unexplained gaps.

Existing frozen safety/domain contracts remain mandatory. This protocol changes delivery sequencing and completion criteria; it does not weaken Entity Trust, Safe Sync, workspace isolation, authorization, transaction, domain-owner, or other `[Resolved]` invariants.

---

## 12. Optional Safe Sync — future comparative certification contract
[Resolved — Post-#168 rebaseline — 2026-09-03]

This section records how the first-party `B2BPlatform_MagentoSafeSync`
component is treated by the delivery protocol **after** the
Post-#168 moduleless-by-default rebaseline. It does **not** remove,
deprecate, or invalidate any existing Safe Sync contract; it binds any
**future** work on that component to a comparative, evidence-first
certification.

### 12.1 Classification

For the standard connector path, the first-party component is
re-classified as:

- **Optional "Enhanced Safety" candidate**, not a baseline connector
  prerequisite;
- an opt-in component, installed and certified separately only when
  Enhanced Safety is selected;
- an implementation-true, legitimate primitive whose entity-bound
  read + write boundary remains a durable repository artifact.

It is **not** required for connection, standard Product READ, field
discovery, mapping, Preview, or normal Magento V1 operation once the
stock public REST path is separately certified for the relevant
operation.

### 12.2 Future certification contract

Any future campaign that re-introduces, expands, or re-frames the
first-party component for any merchant-visible capability MUST, **before
shipping the merchant-facing change**, present a documented comparative
proof that:

1. identifies the exact capability the stock public REST API does
   not provide for the target operation;
2. demonstrates that the first-party component provides that capability
   in a way the stock API cannot match on the **same** real Adobe
   Commerce target;
3. measures the operational cost (install, upgrade, merchant trust,
   support surface, operational risk) of the first-party path against
   the stock path for the **same** operation;
4. shows that the differentiator materially affects the Product Goal
   and is not merely developer preference;
5. proposes the smallest possible deployment surface (for example:
   opt-in module, opt-in webhook, opt-in storefront event) and
   presents it to the merchant as **optional, never as baseline**.
   **Commercial packaging, pricing, and paid tiers are explicitly
   UNDECIDED and out of scope of this delivery protocol.**

A future campaign that cannot produce items 1–5 above MUST NOT
re-position the first-party component as a precondition for the
standard connector path. Re-positioning without that evidence
triggers the Stop and Amend Rule.

### 12.3 Composer / version envelope

This section does **not** widen, relax, or narrow the current
Composer compatibility of the first-party component. Any such change
remains a separate, narrowly-scoped decision and must not be
smuggled in via a docs change.

### 12.4 Runtime migration, when and if it happens

The current code may still consume the first-party component for
trusted simple Product WRITE in some internal seams. That is recorded
as **current runtime truth** in
`08-CONNECTOR_SYNC_RUNTIME_ATLAS.md`. A future runtime migration
that actually decouples the standard connector path from the
first-party component is a separate, separately-designed task. It is
**not** authorised by this delivery-protocol section and is **not**
part of this docs amendment.

---

## Final Rule

For connector work, the next task is chosen by the shortest path to field-complete real transport.

The default question is not:

> Which field or architecture stage should we implement next?

The default question is:

> What concrete missing capability, failed probe, or field certification result is preventing all declared connector data from working in every permitted direction, and what is the smallest safe change that removes that blocker?
