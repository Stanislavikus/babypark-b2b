# Delivery Roadmap

> **Status: Living delivery-control document**
>
> Last updated: 2026-10-08.
>
> This file records execution order, current campaign state, dependencies, and
> acceptance evidence. It does **not** replace Product Vision, Domain Model,
> Architecture Principles, connector contracts, GAP documents, or any later
> `[Resolved]` decision. When this roadmap conflicts with a `[Resolved]`
> authority document, the authority document wins and this roadmap must be updated.

## Why this file exists

BabyPark B2B now has many individually documented capabilities, but
`Project_Documentation_Map.md`, `IMPLEMENTATION_GAPS.md`, connector contracts,
and review documents are not a chronological delivery roadmap.

The project must therefore keep one thin execution map so that a chat/session
ending cannot erase the intended next steps.

This roadmap records only:

- Goal;
- Current state;
- Acceptance evidence;
- Known blockers;
- Shortest safe next path;
- dependency/order between campaigns.

Detailed architecture remains in the owning documents.

## Maintenance rule

- Every campaign that materially changes roadmap state must update this file in
  the same branch/PR.
- A capability is not marked `DONE` from chat memory; exact merged code/docs and
  required real-system evidence must exist.
- `[Resolved]` decisions are referenced, not rewritten here.
- No dates/percent-complete estimates are used as substitutes for evidence.
- One capability remains one campaign / one branch / one Draft PR.
- Research First / Integration First still applies before implementation of each
  new capability.
- The roadmap must remain understandable from `origin/develop` without relying
  on conversation history.

## Status vocabulary

| Status | Meaning |
|---|---|
| `ACTIVE` | Current delivery campaign. |
| `NEXT` | Next dependency-ready campaign once ACTIVE is accepted/merged/deployed as required. |
| `QUEUED` | Required future capability whose exact implementation campaign has not started. |
| `PARTIAL` | Real implementation/evidence exists, but the merchant capability is not complete. |
| `BLOCKED` | Cannot safely proceed until the named blocker is removed. |
| `DONE` | Merged, verified, and real-system evidence complete where required. |
| `DEFERRED` | Intentionally outside the current delivery horizon. |

---

# A. Current Product / Magento critical path

## A1 — Assets v1 Core

**Status:** `PARTIAL` — merged/deployed; merchant-browser visual/action smoke pending.

**Goal:** one reusable workspace-owned MediaAsset library and shared Original image
ingest for Product, Variant, and the next Brand-logo campaign.

**Current state:**

- Frozen contract: `docs/reviews/MASTER_ASSETS_V1_CORE_2026_10_08.md`.
- PR **#266 — feat: add Assets v1 Core** merged 2026-10-08.
- Verified pre-merge HEAD:
  `26009b35e51914c194e6817243cae4df454eef45`.
- Squash merge on `develop`:
  `da54452e49e0e98206c20bf9a843f4c580102516`.
- Exact-head MySQL CI **#590**: **3840 passed / 15 skipped / 85,382 assertions**;
  Pint **1636 files PASS**; `git diff --check` PASS.
- Production deployed at exact `develop` SHA
  `da54452e49e0e98206c20bf9a843f4c580102516`.
- Production transport envelope aligned to the frozen contract:
  nginx **26m**, PHP-FPM upload **25M**, PHP-FPM post **26M**, Livewire **25 MiB**.
- Live external HTTPS/Livewire smoke:
  exact **20 MiB** valid PNG traversed transport and passed application admission;
  **20 MiB + 1 byte** traversed transport and was rejected by application with the
  controlled merchant message rather than nginx/PHP/Livewire rejection.
- Smoke temporary uploads were deleted and created no permanent MediaAsset.
- Physical MediaAsset delete is intentionally not part of this campaign.

**Acceptance evidence required:**

- shared Original ingest;
- Product upload atomicity preserved;
- 20 MiB / 25 MP admission;
- same-workspace hash dedupe and cross-workspace isolation;
- Managed/External presentation;
- usage read model without N+1;
- Product/Variant/Magento media regressions;
- exact-head MySQL CI;
- production upload transport smoke after approved deployment/configuration.

**Known blocker:** no engineering blocker remains. Final closure evidence still
needs merchant-browser visual/action smoke of the deployed Assets surface.

**Shortest safe path:** Product Owner visually checks deployed Assets and performs a
normal merchant upload; record any UX findings into A2 Brand UX / BrandLogo campaign.

## A2 — Brand UX completion / BrandLogo usage

**Status:** `NEXT`

**Goal:** make Brand authoring complete without creating a separate Brand media
domain.

**Current state:**

- Master Brand entity is already merged.
- `brands.logo_media_asset_id` already points at canonical same-workspace
  `MediaAsset`.
- Brand form can currently choose an existing image asset, but direct merchant
  upload/reuse UX is incomplete.
- Brand logo must remain a MediaAsset usage, not a `BrandLogo` entity.

**Acceptance evidence:**

- correct Brand navigation icon;
- shared empty-image placeholder;
- `Завантажити новий`, `Обрати з Assets`, `Прибрати`;
- uploaded logo uses the shared Assets ingest owner;
- existing External/Managed Originals remain selectable under the approved
  validity rules;
- `Логотипи брендів` appears as Assets usage, not as a new asset type;
- no Brand-specific storage/upload implementation.

**Dependency:** A1.

## A3 — Merchant Master Product → Magento end-to-end publication

**Status:** `NEXT` after A2.

**Goal:** prove the complete merchant path, not only connector-core execution:

```text
Master Product UI
→ validate / Preview / diff
→ Live publish
→ Magento
→ read/reconciliation
→ edit in BabyPark
→ republish
→ idempotent repeat
```

for both **Simple** and **Configurable** products.

**Current state / evidence already proved:**

On 2026-10-08 the current `develop` connector core passed a fresh real Magento
round trip for both Simple and Configurable products:

- BabyPark → Magento standard REST V1 WRITE;
- Magento READ/reconciliation;
- second execution with zero unnecessary writes;
- exact remote cleanup and local test-fixture cleanup.

This proves the moduleless Magento V1 connector core, but does **not** by itself
prove the complete merchant UI/Preview/Live orchestration.

**Acceptance evidence still required:**

- create a new Simple Master Product through merchant UI;
- publish through actual Preview/Live merchant flow;
- verify exact Magento identity/state;
- change admitted fields in BabyPark and republish;
- repeat with zero unnecessary writes;
- same full path for Configurable family;
- no guessed SKU/entity identity;
- safe disabled/not-visible initial state where required by the frozen Magento
  CREATE contract;
- exact real-system cleanup or retained certified fixture by explicit decision.

**Dependency:** A1/A2 must not leave Product authoring/media incomplete.

## A4 — Smart file import into Master Product

**Status:** `QUEUED`

**Goal:** allow a merchant to populate Master Products from Excel/CSV instead of
manual entry.

**Current state:**

- Product list already exposes disabled `Імпортувати товари`.
- `workspace_import_aliases` persistence foundation exists.
- Product Vision / Attribute Dictionary already freeze header normalization,
  guided mapping, and workspace-specific mapping memory.
- GAP-021 remains open for alias CRUD/resolution integration.
- This is a **file/snapshot onboarding flow**, not Magento Receive and not a
  connector identity shortcut.

**Acceptance evidence:**

- Research First on free reusable import components/parsers before code;
- upload/parse preview before mutation;
- normalized header matching;
- exact FieldBinding resolution;
- workspace-specific confirmed mapping memory;
- fail-closed unknown/ambiguous columns;
- controlled Product/Variant writers only;
- no hardcoded BabyPark/1C column aliases;
- real file import of representative simple and variant/configurable rows;
- re-import behavior and duplicate identity explicitly proven.

**Dependency:** A3 gives a known-good Master Product → Magento destination so imported
products can immediately be exercised end-to-end.

## A5 — Product SEO capability

**Status:** `QUEUED`

**Goal:** make SEO enrichment a first-class Master Product workflow after authoring
and import are usable.

**Planned product scope already established by Product UX work:**

- SEO title;
- meta description;
- slug/search preview;
- canonical handling;
- structured-data/readiness hints;
- `Отримати ключові слова` before AI description;
- external keyword/SERP evidence (for example DataForSEO or an approved
  alternative) must be researched before integration;
- AI suggestions remain previewable/merchant-controlled.

**Separate later capability:** search-performance analysis/SEO Agent using
Search Console/Merchant/marketplace evidence. Do not mix that larger optimization
agent into the first Product SEO authoring slice.

**Dependency:** A4 is preferred so imported products can be enriched in the same
merchant workflow.

---

# B. Magento true Receive / two-way completion

## B1 — Magento → Master Product Receive completion

**Status:** `PARTIAL / QUEUED FOR COMPLETION`

**Goal:** reach the truthful merchant scenario:

```text
existing trusted Magento product
→ receive into BabyPark Master Product
→ merchant completes/changes admitted fields
→ Preview
→ publish back to the same verified Magento entity
```

without guessing identity or creating a weaker second trust model.

**Current state:**

- Public capability truth remains
  **Adobe Products / Import / Live = false**.
- Internal Receive foundation exists and is real-target certified for:
  - canonical Product `name`;
  - Dynamic single-value Select fields;
  - existing trusted `ExternalRecordLink` targets;
  - fresh remote reread + proposal + explicit Apply + governed local writer.
- Existing Receive does **not** create an arbitrary new Master Product from an
  arbitrary Magento SKU.
- Pricing, inventory, media, relations/categories, broader field types, and
  merchant Import UI are not public Receive capability merely because Export can
  write them.

**Acceptance evidence before declaring “Magento is bidirectional”:**

- explicit merchant Receive UI;
- trusted-link onboarding/link-existing flow;
- bounded creation/import policy for a remote product where approved;
- required Product/Variant fields routed through their real domain owners;
- Simple and Configurable family semantics;
- media/category/pricing/inventory breadth only after their own owner contracts;
- receive → edit → republish real-target cycle;
- conflict/staleness behavior with no silent last-write-wins;
- public support flag flips only after real certification.

**Ordering note:** B1 is mandatory before claiming full Magento bidirectionality.
Its exact scheduling relative to A4/A5 is a Product Owner priority decision; it
must not disappear merely because Export is already certified.

---

# C. SaaS Core revision before broad 1C rollout

## C1 — Workspace onboarding, users, memberships, and platform-wide RBAC

**Status:** `PARTIAL / QUEUED`

**Goal:** make the platform usable as a real multi-user SaaS without BabyPark-specific
legacy role assumptions before broad ERP/1C data flow is exposed.

**Current state:**

- GAP-026 workspace RBAC foundation and production cutover are DONE for the
  already-cut-over Connector/Tax/Mapping/Access domains.
- GAP-027 remains OPEN.
- New staff onboarding/invitation/membership creation is not complete.
- Several admin resources still rely on transitional fixed `UserRole` behavior.

**Acceptance evidence:**

- company/workspace registration/onboarding;
- add/invite staff into a Workspace;
- merchant-owned role/access-profile assignment;
- platform-wide resource/action permission vocabulary;
- Product/Customer/Order/Pricing/Inventory/User surfaces fail closed by permission,
  not legacy job-title role;
- anti-lockout preserved;
- future multi-workspace behavior does not require architecture replacement.

## C2 — Customer / B2B account model revision

**Status:** `PARTIAL / QUEUED`

**Goal:** ensure Customer, customer login/access, groups, price-list assignment and
manager relationships are ready before importing a real 1C customer base.

**Current state:**

- Customer and Order resources exist.
- GAP-017 Contractor → Customer migration/terminology work remains tracked.
- Existing Customer UI still contains legacy/transitional assumptions that require
  review before treating it as the universal SaaS customer model.

**Acceptance evidence:**

- exact Customer identity contract;
- B2B account/user relation;
- customer group / price-list semantics;
- manager/backup-manager relation migrated away from unsafe legacy role assumptions;
- duplicate/legal-ID/email rules explicitly defined;
- workspace isolation and authorization proven.

**Dependency:** C1 permission/onboarding foundation.

---

# D. 1C / ERP platform integration track

This track is a platform connector capability, not a one-off BabyPark database import.
No 1C-specific shortcut may bypass the canonical Product, Customer, Pricing,
Inventory, Order, Field Dictionary, or Sync-domain owners.

If a separate 1C system/repository produces a canonical snapshot, it may cross into
this repository only through an explicitly defined read-only source contract. Repo
rules are not merged implicitly.

## D1 — 1C discovery and canonical read-only snapshot

**Status:** `QUEUED`

**Goal:** establish verified external identity and source shape before any mutation.

**Required inventory:**

- products and variants;
- external IDs/SKUs;
- attributes;
- prices and tax basis;
- stock/availability;
- customers/contractors;
- customer groups / price-list references;
- orders and statuses;
- payments/debt references where present;
- users/managers only if 1C is actually authoritative for such data.

**Rules:**

- read-only first;
- no fuzzy identity;
- no direct writes into core tables;
- exact source IDs and provenance;
- capability matrix PASS/PARTIAL/FAIL;
- real sample snapshot before write design.

## D2 — 1C Product / Variant receive

**Status:** `QUEUED`

**Goal:** create/update Master Product data from verified 1C source identity through
governed Product/Variant writers.

**Acceptance:** exact identity, preview/diff, field mapping, configurable/variant
families, idempotent rerun, fail-closed ambiguity.

**Dependency:** D1 plus A4 import/mapping primitives where reusable.

## D3 — 1C Pricing and Inventory receive

**Status:** `QUEUED`

**Goal:** receive prices/price lists and stock/availability through their own domain
owners, not generic Product fields.

**Acceptance:** declared tax basis, price-list identity, stock owner/location rules,
idempotency, stale-source behavior, and real-system reconciliation.

## D4 — 1C Customer migration / synchronization

**Status:** `QUEUED`

**Goal:** bring the verified 1C customer/contractor base into canonical Customer
without guessed identity.

**Acceptance:** exact remote IDs, duplicate policy, legal identifiers, groups,
price-list references, account-manager relation, B2B access linkage, replay
idempotency, and cross-workspace isolation.

**Dependencies:** C1, C2, D1.

## D5 — Orders: BabyPark → 1C and status/payment return

**Status:** `QUEUED`

**Goal:** close the commercial loop after catalogue/customer foundations are stable.

```text
B2B order in BabyPark
→ durable immutable order snapshot
→ 1C write
→ verified 1C document identity/number
→ status/payment/debt updates back to BabyPark
```

**Required invariants:**

- platform order survives even if ERP sync fails;
- no duplicate 1C order on retry;
- exact remote order identity;
- transactional/idempotent write semantics;
- status and payment status remain distinct;
- no reconstruction of historical order lines from mutable current Product data;
- observable retry/remediation path.

This is a high-risk consequential-write campaign and requires independent review
and real-system certification.

---

# E. Later platform breadth

**Status:** `DEFERRED / SEQUENCED AFTER THE ABOVE`

Examples already present in Product Vision / Domain Model but not on the immediate
critical path:

- broader B2B storefront/cabinet revision;
- payments;
- shipping/delivery integrations;
- notifications;
- richer customer pricing rules/tiers;
- Google / marketplaces / additional commerce connectors;
- Shopify / BigCommerce / Amazon;
- search-performance SEO Agent;
- advanced Media improvements and channel renditions;
- full asset lifecycle/delete;
- other ERP/accounting systems.

These must enter the active roadmap only through a named campaign and Research First
gate.

---

# Current shortest safe path

As of 2026-10-08:

```text
A1 Assets v1 Core
→ A2 Brand UX / BrandLogo
→ A3 full merchant Master Product → Magento Simple + Configurable E2E
→ A4 Smart file import
→ A5 Product SEO

Mandatory connector follow-up:
B1 complete truthful Magento Receive / two-way merchant flow

Then SaaS/ERP foundation:
C1 workspace/users/RBAC
→ C2 Customer/B2B account revision
→ D1 1C read-only discovery/snapshot
→ D2 Product/Variant
→ D3 Pricing/Inventory
→ D4 Customers
→ D5 Orders round trip
```

The exact position of **B1 Magento Receive completion** relative to A4/A5 may be
reprioritized by the Product Owner, but it is explicitly retained as unfinished
work and cannot be silently treated as DONE.

## Merge/roadmap discipline

When a milestone completes:

1. replace status only with evidence;
2. link merged PR / certification artifact;
3. move the next dependency-ready milestone to `ACTIVE`;
4. record any newly discovered blocker;
5. keep deferred/future items visible rather than relying on chat memory.
