# docs/IMPLEMENTATION_GAPS.md

## Purpose

This document records known, verified gaps between approved project documentation
(00–08) and the actual state of the codebase on `develop`.

Entries here are NOT open product questions. The architectural decision is already
**Resolved** in the referenced document — the gap is purely that the code has not
caught up yet.

Rules for using this document:

- A gap listed here must not be re-litigated as if it were an open design question.
- A temporary workaround built around a gap (e.g. a placeholder, a simplified
  presenter) must be explicitly linked to its GAP entry, both in code comments and
  in the relevant PR/task description.
- When a gap is closed, update its Status and keep the entry for history — do not
  delete it.
- No named-customer or pilot-specific hardcoding is permitted as a "solution" to any gap (per
  `04-ARCHITECTURE_PRINCIPLES.md`, Configuration Over Custom Code mandate).
  Reference clients validate the platform; they do not define the platform.

Verified against `develop` as of the GAP-016 Field Foundation migration (PR pending):
`app/Models/` contains `Category, Customer,
DeliverySetting, FieldBinding, FieldDefinition, InventoryLocation, InventoryRecord, Order, OrderItem, Price,
PriceList, PriceListItem, Product, ProductFieldValue, ProductTag,
ProductVariant, Reservation, Stock, SyncLog, Tag, User, VariantFieldValue,
Workspace, WorkspaceImportAlias` (24 models). `workspace_id` appears in 9
migrations and via `BelongsToWorkspace`/`BelongsToWorkspaceOrGlobal` on 14
models — see GAP-004 for the caveat that this is a sampling check, not a full
audit. Field Foundation naming is implemented — see GAP-016 (closed).

---

## GAP-001 — Pricing model mismatch

**Approved docs:**
- `02-ATTRIBUTE_DICTIONARY.md`: `price` is a Variant-Level System Attribute —
  "Public / base price in workspace currency", distinct from `sale_price` and
  `cost_price`.
- `03-DOMAIN_MODEL.md`, Pricing Context: pricing must be resolved through
  `PriceList` → `PriceListItem` → `PriceResolver`, with a closed, **Resolved**
  priority order ending in "Cached variant base price on ProductVariant as a final
  fallback."
- `03-DOMAIN_MODEL.md`, MVP Domain Scope: `PriceList`, `PriceListItem or simple
  ProductPrice`, and cached variant prices are explicitly part of MVP scope, not
  future scope.

**Current code (historical — see Status below for resolution):**
- `app/Models/Price.php` was a flat model where `contractor_id` was a mandatory foreign key,
  with no `PriceList`, no `PriceListItem`, no `is_default` concept, and no cached base price on
  `ProductVariant`. The legacy `prices` table and `Price` model are retained, read-only, for
  historical/compatibility reasons (not deleted).

**Impact (historical):**
- There was no way to answer "what is this product's price" without already knowing which
  contractor was asking, and the admin product table had no source for a neutral `Ціна` column.

**Decision (still applies):**
- `РРЦ` (recommended_retail_price_cache) and resolved sale price remain distinct concepts — do
  not conflate them.

**Status:** Closed in code. Implemented via Pricing MVP Foundation (PR #44 — schema,
`PriceList`/`PriceListItem`, `PriceResolver`, MySQL-safe default-list constraint, safe legacy
data migration; PR #45 — replacement of all legacy pricing call sites, `products.cost_price`
finally dropped in favor of variant-level `cost_price`, order-creation price snapshot
integration via `OrderCreator`). The admin `Ціна`/РРЦ/margin columns are now populated from
`PriceResolver`/`ProductPricingSummary` rather than rendering `—`. `CustomerGroup`/`PricingRule`
remain deferred — see GAP-010.

---

## GAP-002 — Availability model mismatch

**Approved docs:**
- `03-DOMAIN_MODEL.md`, Availability source of truth (**Resolved**): net sellable
  stock must be computed via `AvailabilityResolver`, using
  `available_quantity_cache` minus active, unexpired `InventoryReservation`
  entries. Explicitly called "mandatory MVP architecture."
- `03-DOMAIN_MODEL.md`, MVP Domain Scope: `inventory_records`,
  `inventory_reservations` are explicit MVP-scope tables.

**Current code:**
- `app/Models/Stock.php` and `app/Models/Reservation.php` are a simpler, ad-hoc
  pair — not the documented `InventoryRecord` (ledger) / `InventoryReservation`
  (TTL-based, order-linked) shape.
- No class named `AvailabilityResolver` exists anywhere in `app/`.
- `app/Support/AdminAvailabilityPresenter.php` (added during the admin product
  table task) computes net quantity directly from `stocks.quantity -
  stocks.reserved`, with no TTL expiry logic and no ledger. It was explicitly
  built as "a small local presenter, not a full AvailabilityResolver" and
  documented as needing migration later.

**Impact:**
- Availability shown in admin table/filter/infolist does not go through a real
  reservation-expiry-aware resolver. It is a reasonable short-term approximation,
  not the architecturally intended source of truth.

**Decision:**
- Keep `AdminAvailabilityPresenter` as the single, unified source for admin-side
  availability display (already de-duplicated across column/filter/infolist —
  see PR #29/#30/#32) until this gap is closed.
- Do not build a second, different availability calculation anywhere else in the
  meantime.

**Next task:** None — closed. (Note: this entry previously referenced "proposed
task order below", a section that does not exist in this document — removed as
part of this Documentation Truth Reset pass.)

**Status:** Closed in code. Implemented via Availability Foundation and follow-up fixes
(PRs #39, #40, #41, #42 — schema, `AvailabilityResolver`,
`ReservationCreator`/`Confirmer`/`Releaser`, `inventory_records` ledger, scheduler registration,
MySQL-safe migration recovery, and UI delegation away from direct `stocks.reserved`
calculations). Two intentionally deferred product items remain open separately — see GAP-008
and GAP-009.

---

## GAP-003 — Attribute Dictionary not implemented

**Approved docs at the time of the original GAP — now superseded in
naming/shape by GAP-016 (Field Foundation); kept verbatim below for
historical record, not as a description of current approved docs:**
- `03-DOMAIN_MODEL.md`, Attribute value storage (**Resolved**): the platform must
  use separate `product_attribute_values` and `variant_attribute_values` tables.
  "A unified polymorphic attribute value table is strictly forbidden."
- `03-DOMAIN_MODEL.md`, MVP Domain Scope: `AttributeDefinition`,
  `ProductAttributeValue / VariantAttributeValue` are explicit MVP-scope entities.
- `02-ATTRIBUTE_DICTIONARY.md`: defines System Attributes (Level 1) and Platform
  Attribute Library (Level 2), each with Product-Level / Variant-Level /
  Both assignment rules.

**Current code (re-verified against `develop`):**
- `AttributeDefinition`, `ProductAttributeValue`, and `VariantAttributeValue`
  models and tables all exist, with `workspace_id`, foreign keys, and unique
  constraints. `AttributeDefinitionResource` exists in Filament
  (`canCreate(): false`; `canDelete()` only for `workspace_custom` scope).
- System Attributes remain first-class typed columns on `products` /
  `product_variants` (e.g. `brand`, `sku`, `cost_price`); only Platform Attribute
  Library / workspace-custom fields use `product_attribute_values` /
  `variant_attribute_values`, per the "Attribute storage model" Domain Decision.
- A workspace **can** add a custom product field today via this mechanism.

**Impact:**
- The original impact (no foundation for import mapping / connector work) no
  longer applies for the Product/Variant domain.
- This GAP's original scope was explicitly Product/Variant-only (see
  "Approved docs" above). Extending the same governance to `Customer` fields is
  **new scope**, not a reopening of this GAP — see the "Field Foundation
  (cross-object fields)" Domain Decision in `03-DOMAIN_MODEL.md` (that Domain
  Decision, not a separate ADR file, is the canonical record — no separate ADR
  document is checked into `docs/`).

**Decision:**
- Do not build a one-off custom-field mechanism anywhere as a stopgap.
- Do not let any connector/import work hardcode column-to-field mapping outside
  a proper `FieldMapping` mechanism once it exists.
- Do not treat this GAP's closure as covering `Customer`/other future entities —
  that is tracked separately (see `03-DOMAIN_MODEL.md`, "Field Foundation").

**Next task:** None for the original Product/Variant scope. Cross-object
extension is tracked as its own Field Foundation migration (GAP-016), sequenced
after the Contractor → Customer terminology migration (GAP-017) and before
GAP-006. **Not** blocked by GAP-004's full coverage audit — that audit is a
separate prerequisite for onboarding a second workspace only, not for this
migration (see "Field Foundation", Workspace isolation note, in
`03-DOMAIN_MODEL.md`).

**Status:** Closed for original (Product/Variant) scope.

---

## GAP-004 — Workspace isolation absent

**Approved docs:**
- `00-WHY.md`: "Each business should have its own isolated workspace... No
  company-specific logic should be hardcoded into the core."
- `03-DOMAIN_MODEL.md`, Company vs Workspace naming (**Resolved**): the technical
  SaaS boundary is `workspace_id`; every workspace-owned table must include it.
- `04-ARCHITECTURE_PRINCIPLES.md`, Mandate 1: workspace isolation is described as
  a critical, non-negotiable requirement — cross-tenant data leaks are a critical
  system failure.

**Current code (re-verified against `develop`):**
- `workspace_id` now appears in 9 migrations; `BelongsToWorkspace` /
  `BelongsToWorkspaceOrGlobal` traits are applied to 14 models, including
  `Product`, `AttributeDefinition`, `Customer`, `PriceList`, `Category`.
- This check was a **sampling audit**, not a full inventory of every
  workspace-owned table, model, background job, and raw query in the codebase.

**Impact:**
- Broad workspace isolation is demonstrably implemented, contrary to the
  previous "zero occurrences" note. However, the previous note's caution about
  not onboarding a second workspace before full verification still applies —
  a sampling audit finding isolation everywhere it looked is not the same as
  proof of no gaps anywhere.

**Decision:**
- Do not onboard a second workspace before a full audit (not sampling) is
  completed: every workspace-owned table, every Eloquent query path, every
  background job, plus a cross-workspace-leakage test suite.
- Any new table created for Field Foundation / Connector Foundation work must
  include `workspace_id` from its first migration.

**Next task:** Full workspace-isolation coverage audit (inventory + tests), not
a rewrite — the mechanism already exists broadly, this is a verification task.

**Status:** Partially closed — broad workspace isolation implemented; full
table/model/query/job coverage audit still required before onboarding a second
workspace. Do not mark this Closed on the basis of a sampling check.

---

## GAP-005 — Order / payment status not separated

**Approved docs:**
- `03-DOMAIN_MODEL.md`, Payment status automation (**Resolved**): "Payment updates
  `payment_status` only. Any resulting change to `order_status` is determined
  exclusively by `payment_triggers_json` inside `WorkspaceOrderStatusMatrix`.
  Hardcoded controller-level status changes triggered by payment events are
  strictly forbidden."
- `01-PRODUCT_VISION.md`, MVP Scope: "the order model should include payment
  status so that payment gateway integration can be added later without
  rewriting the order model."

**Current code:**
- `app/Models/Order.php` has a single `status` field (cast to `OrderStatus` enum).
  There is no separate `payment_status` field and no `WorkspaceOrderStatusMatrix`.

**Impact:**
- Adding payment gateway integration later, as `01-PRODUCT_VISION.md` assumes will
  be possible "without rewriting the order model," is not actually possible yet —
  the model would need to change.

**Decision:**
- Do not add any payment-triggered status-change logic directly in
  controllers/actions as a shortcut.

**Next task:** Not urgent for the current pilot phase (no live payment gateway
yet), but should be scheduled before any payment gateway integration work starts.

**Status:** Open, low urgency.

---

## GAP-006 — Connector / Import / FieldMapping infrastructure absent

**Approved docs:**
- `00-WHY.md`: platform must be connector-independent; "no connector should
  define the core product model."
- `01-PRODUCT_VISION.md`, Reference Clients Do Not Define the Platform: ERP / 1C
  and Google Sheets may be needed earlier than a generic SaaS MVP; they remain
  reusable connector families, not a named-customer architecture target.
- `03-DOMAIN_MODEL.md`, MVP Domain Scope + Sync Domain Rebaseline:
  `ConnectorDefinition`, `ConnectorAccount`, `SyncConfiguration`,
  `FieldMapping`, `SyncRun` / `SyncRunItem`, and `ExternalRecordLink` are
  explicit MVP-scope sync entities. Earlier draft name `ImportJob` /
  `ExportJob` / `SyncJob` is superseded and is not the current normative sync
  model.

**Current code:**
- `ConnectorDefinition` and `ConnectorSchemaSource` exist from Task 4A.
- Task 4B-1 / PR #85 added `ConnectorAccount` plus the six
  connection-check/discovery/snapshot/diff models and seven workspace-owned
  tables.
- The foundation includes 14 composite workspace FK guards, encrypted credential
  storage, generated-column active-name uniqueness, factories, translation-keyed
  enums, SQLite coverage, and MySQL 8 CI verification.
- `SyncLog` remains the legacy global summary log and is not reused for connector
  operational history.
- Tasks 4B-2a-1 through 4B-2a-2c merged the Adobe PaaS adapter, OAuth signing,
  outbound transport (SSRF/response-size), connection-check execution, queue
  lifecycle, retry/stale recovery, and dispatch service — deployed with worker
  confirmed running.
- Task 4B-2a-3 adds the first read/status/check operational admin surface
  (`ConnectorAccountResource` list/detail/history) on top of that backend chain.
- Tasks 4B-2b-0 through 4B-2b (PRs #96, #98–#102, correction PR #105, Discovery
  Overview UI PR #114) merged the `database_connectors` / `connectors` discovery
  lane, queued discovery execution (`ConnectorDiscoveryRunJob`), Adobe discovery
  execution and normalization, canonical field/snapshot hashing, snapshot
  publication and persistence, discovery-run received/normalized accounting (106
  received / 102 normalized on the committed Magento pilot regression fixture),
  account projection updates after successful discovery, and Discovery Overview UI.
- **Historical (PR #102, pre-B-2):** `ConnectorAccountMerchandiserPresentation` plus
  transitional `ConnectorAccountPolicy` fixed-role updates closed the Merchandiser
  `viewAny()`/`view()` and rendered management-field security gap — `store_code`,
  `tenant_context`, credentials, and other management-only connector details were
  not rendered to Merchandiser under pre-B-2 role semantics. **Superseded in
  repository runtime by GAP-026B-2** (`ConnectorAccountCapabilityPresentation` +
  workspace-RBAC matrix).
- Connector-account **creation UI** is implemented
  (`ConnectPlatformIntegration` on the Integrations path). **Credential-management /
  settings edit UI** remains absent (update service exists;
  `ConnectorAccountSettingsService::update()` has no Filament page).
- Task 4B-2b Discovery Overview UI (PR #114) delivers Connector Account list
  last-successful-discovery projection, account-detail Discovery summary,
  Discovery history, minimal snapshot summary, and manual Discovery action
  behind the existing deployment flag and authorization contract; Merchandiser
  receives the documented safe Discovery surface with workspace isolation and
  sensitive-field presentation gates.
- Diff computation, retention jobs, and broader operational UI beyond the
  current connector-account list/detail/discovery surface remain absent.
- Successful discovery persists `ConnectorSchemaSnapshot` and
  `ConnectorSchemaSnapshotField`; `ConnectorSchemaDiff` /
  `ConnectorSchemaDiffItem` remain model/schema scaffolding without a write
  path or consumer (dormant — do not infer a working schema-diff runtime).
- Sync Domain foundation (Task 4C-0): `SyncConfiguration` persistence and
  domain write path (`sync_configurations` table, workspace/account composite
  integrity, canonical operation-set semantics, opaque `external_context` with
  deterministic default-context uniqueness, semantic `configuration_revision`
  hashing, enabled/paused operational state) plus the authoritative runtime
  `(data_domain, semantic_operation)` support boundary
  (`ConnectorSyncOperationSupport` / `ConnectorSyncSupportResolver`, fail-closed
  when a profile adapter does not declare support). Vocabulary implemented:
  `products` + `import`/`export`. Adobe Commerce production profile remains
  fail-closed for executable sync pairs.
- Sync Domain entities still absent: `ExternalRecordLink`, preview/live execution,
  scheduling, sync history/issues, and merchant sync UX beyond connector
  connection management and mapping. `SyncRun` / `SyncRunItem` persistence and revision v3
  rebaseline are implemented (Task 4C-2b-1, Done). `FieldMapping`
  persistence/manual confirmation and authoritative-discovery validation are
  implemented (Task 4C-1b, Done). Canonical suggestion/read-model and
  UI-prefill work is Task 4C-1c (4C-1c-0 docs contract frozen; 4C-1c-1
  provider/read-model Done; 4C-1c-2a authorization contract Done; 4C-1c-2b
  Layer B mapping UI Done, PR #139). Preview-first execution foundation
  contract is frozen (Task 4C-2a, docs only). Magento Product Export V1
  execution contract is frozen (Platform Product Scope Rebaseline, docs only):
  `run_sync_preview` normative eighth permission (runtime catalogue still seven
  permissions until Stage 1), Product execution aggregate, mode-aware support,
  Live permission `run_sync_live`, ExternalRecordLink, and Live mechanics
  revalidation. Preview admission/execution runtime remains Stage 1.

**Task sequence (GAP-006 remains Open until implementation lands):**

| Task | Scope |
|---|---|
| **4B-0** | Stop-and-Amend: architecture docs, six-surface visual contract, documentation tests — Done |
| **4B-1** | Generic `ConnectorAccount` persistence/domain foundation — Done, PR #85 |
| **4B-2-0** | Runtime Stop-and-Amend: deployment-family capabilities, adapter/auth, authorization, queue, transaction, retry and SSRF decisions — Done |
| **4B-2a** | Adobe PaaS adapter/OAuth signing, SSRF-safe transport, connection-check execution and queue lifecycle, plus list/detail/history admin UI and current projection — Done, PRs #87, #89–#94 |
| **4B-2b** | Discovery backend runtime plus Discovery Overview UI — Done, PRs #96, #98–#102, #105, #114 |
| **4B-2c** | Discovered schema fields and change inspection: field list, filters, and field inspection from persisted snapshots; `ConnectorSchemaDiff` computation (models exist, no write path yet) |
| **4B-2d** | Activity history, retention/pruning service, recovery states, and operational polish |
| **4C-0** | `SyncConfiguration` foundation + authoritative `(data_domain, semantic_operation)` runtime support boundary — Done |
| **4C-1a** | FieldMapping persistence contract (docs-only Stop-and-Amend) — Done |
| **4C-1b** | `field_mappings` persistence + manual confirmation service + authoritative-discovery validation + revision v2 + graceful fail-closed handling when mapped `FieldBinding` or parent `FieldDefinition` physical deletion is attempted (archive remains valid lifecycle path; no raw FK errors) — Done |
| **4C-1c-0** | Docs-only suggestion/read-model Stop-and-Amend — canonical qualification, confidence semantics, registry→discovery boundary — Done |
| **4C-1c-1** | Canonical deterministic suggestion provider + transient registry/discovery/effective-mapping read-model (no DB/migration scope) — Done |
| **4C-1c-2a** | Workspace access / authorization contract — docs-only Stop-and-Amend — Done |
| **4C-1c-2b** | Layer B mapping UI: explicit `SyncConfiguration` Mapping page; deterministic high-confidence suggestion presentation; manual choose/change/remove; explicit confirmation through `FieldMappingMutationService`; stale-safe exact remove; workspace-scoped fresh Mapping authorization; Layer-B Available Fields supporting reference — Done, PR #139 |
| **4C-2a** | Docs-only Preview-first Sync Execution Foundation Stop-and-Amend — `SyncRun`/`SyncRunItem` physical contract, revision v3 + fixed `all_products` selection, `run_sync_preview` normative permission (runtime pending Stage 1 / historical 4C-2b-2), admission/concurrency/snapshot/planner boundaries, Adobe support-truth gate — Done |
| **4C-2b-1** | SyncRun Persistence & Revision Foundation — revision v3 runtime hasher + rebaseline, `SyncRun`/`SyncRunItem` persistence, enums/casts, workspace-aware integrity, Product composite FK support — Done |
| **4C-2b-2** | Historical tracking label — Preview Authorization & Admission Foundation. Not a mandatory future PR boundary. Absorbed by **Stage 1 — Preview Engine**. |
| **4C-2b-3** | Historical tracking label — Product Export Projection + Adobe Pure Planner. Not a mandatory future PR boundary. Absorbed by **Stage 1 — Preview Engine** (Magento V1 contract now freezes Product+Variant execution input; no separate Product Export Projection Stop-and-Amend is required before Stage 1). |
| **4C-2b** | Historical umbrella label for Preview foundation slices. 4C-2b-1 Done. Remaining Preview runtime is **Stage 1**. Must **not** ship: merchant Preview UI (that is Stage 2); Live mutation (Stage 3); automatic `ConnectorSyncOperationSupport` flip |
| **Stage 1 — Preview Engine** | Current coherent outcome: persisted zero-mutation Adobe Products Export Preview against the full platform Product/Variant model. Includes `run_sync_preview`, SyncConfiguration reachability, snapshot/admission, Product execution aggregate, Adobe simple + configurable planners, truthful Preview support, and E5 connector execution configuration persistence plus revision/snapshot rebaseline if required (current revision v3 has no connector execution-configuration input). Next implementation grouping. |
| **Stage 2 — Merchant Preview** | Authorized non-technical merchant reaches Preview through Integrations/Data Setup and understands Product-level ready/warning/blocked outcomes. No Sync History product, SyncIssue, scheduling, or analytics merely for this stage. |
| **Stage 3 — Live Engine** | After E11 revalidation: Live permission, ExternalRecordLink, simple + configurable Live execution, reconciliation, merchant-safe result, real Adobe create/update validation. |
| **4C** | Remaining sync domain after Stage 3: scheduling, sync history/issues, merchant sync UX beyond mapping/Preview/Live |

Visual contract prototype: `docs/prototypes/task-4b0-connector-account/`.

**Impact:**
- Do not build a one-off, hardcoded 1C-to-database field mapping as a shortcut —
  this is explicitly the named-customer hardcoded logic that
  `04-ARCHITECTURE_PRINCIPLES.md` Mandate 9 forbids.
- **`ImportedPriceTaxBasis`** (whether an imported row is net or gross) must be
  captured during connector import design — see GAP-018 cross-reference.

**Status:** Open. Task 4B-2a is complete. Task 4B-2b is complete (PRs #96,
#98–#102, correction PR #105, Discovery Overview UI PR #114). Task 4C-0 landed
`SyncConfiguration` foundation and the fail-closed runtime sync-support boundary.
Task 4C-1a settled the FieldMapping first persistence contract (docs only).
`field_mappings` persistence, confirmation service, revision v2 integration,
and graceful fail-closed handling for mapped binding / parent definition
physical-delete attempts (archive remains valid) are implemented (Task 4C-1b).
Canonical suggestion/read-model contract is frozen (Task 4C-1c-0, docs only).
Canonical deterministic suggestion provider/read-model (4C-1c-1) is implemented.
Workspace access / authorization contract is frozen (4C-1c-2a, docs only).
Layer B mapping UI (4C-1c-2b) is implemented (PR #139, merge
`9a4be2f29f239753151e33f0a107c33597a10f85`). Preview-first execution
foundation contract is frozen (Task 4C-2a, docs only). Connector Discovery
is production-operational on the Babypark pilot (dedicated
`babypark-connector-queue` worker verified `RUNNING` and one successful manual UI
Discovery completed 2026-08-15). `SyncRun` / `SyncRunItem` persistence and
revision v3 rebaseline are implemented (Task 4C-2b-1). Preview admission,
execution, schedule, history, and `ExternalRecordLink` remain unimplemented.
`run_sync_preview` runtime permission remains pending Stage 1 (historical label
4C-2b-2). Adobe `(products, export)` support remains fail-closed. No merchant
Preview UI exists. Connector-account **creation UI is implemented**
(`ConnectPlatformIntegration`); credential-management/settings **edit** UI
remains absent. Task 4B-2c (discovered schema fields / change inspection) and
retention jobs remain unimplemented.

**Current coherent Magento execution stages** (historical `4C-2b-*` labels are
not mandatory PR boundaries): Stage 1 Preview Engine → Stage 2 Merchant Preview
→ Stage 3 Live Engine. See `docs/03-DOMAIN_MODEL.md` → Magento Product Export
V1 Execution Contract.

**ConnectorAccount authorization/rendered-view sub-gap (closed PR #102 /
Task 4B-2b-1e+1f; historical pre-B-2 — superseded by GAP-026B-2 repository
runtime):** transitional pre-B-2 `ConnectorAccountPolicy` granted Merchandiser
`viewAny()`/`view()` (safe fields only) and `runDiscovery()` (enabled accounts
only) via fixed `User.role` checks. Management abilities remained denied to
Merchandiser. Field-level restrictions were enforced via historical
`ConnectorAccountMerchandiserPresentation`: query column selection, hidden
attributes on Livewire serialization, and Filament table/infolist visibility for
`store_code`/`tenant_context`. Merchandiser detail pages omitted connection-check
header actions and relation managers; `connectionChecks` presentation relations
were not loaded. Sensitive fields excluded: `credentials`, `settings`,
`base_url`, `store_code`, `tenant_context`, `auth_profile`. **026B repository
status (post-B-2):** `ConnectorAccountCapabilityPresentation` applies
capability-based safe projection from effective workspace permissions; connection-
check overlay and management surfaces are management-only (`manage_connector_accounts`).
Production EXECUTE activation completed on Babypark pilot 2026-08-14 (see GAP-026B).

**Connector-worker production activation sub-gap (closed 2026-08-15):** permanent
Supervisor program `babypark-connector-queue` installed and verified `RUNNING` on
the Babypark pilot host; dedicated command
`/usr/bin/php /var/www/babypark-b2b/artisan queue:work database_connectors --queue=connectors --sleep=3 --tries=3 --timeout=900 --max-time=3600`;
`babypark-queue` remains separately `RUNNING`; `php artisan queue:restart`
verified both workers exit/restart with new PIDs; manual Discovery enabled only
after worker verification (`CONNECTOR_DISCOVERY_MANUAL_TRIGGER_ENABLED=true`);
one real merchant UI manual Adobe Commerce Discovery succeeded
(`RUN=a281b181-f478-4b0b-8c7d-295c26265020`, `SNAPSHOT=a281b185-7618-4ce1-9c48-9ba5c8c87fca`,
`STATUS=succeeded`, `CONNECTOR_JOBS=0`, `FAILED_JOBS=0`); worker log recorded
`ConnectorDiscoveryRunJob ... DONE`. **Historical:** worker verified absent
2026-08-14 before installation. See `DEPLOY.md` → Connector-worker production
activation (completed 2026-08-15). **GAP-006 overall remains Open** — this
closure records only the Babypark pilot production worker activation gate.

**Historical shipped role matrix (pre-4C-1c-2a / pre-B-2 transitional
implementation; not normative target authorization; superseded by GAP-026B-2
repository workspace-RBAC matrix):**

(confirmed against `App\Enums\UserRole`):

| Role | `viewAny`/`view` (safe fields only) | `runDiscovery` | Management (settings/credentials/disable) |
|---|---|---|---|
| Admin | Yes | Yes (enabled accounts) | Yes |
| Director | Yes | Yes (enabled accounts) | Yes |
| Manager, Warehouse, Programmer with `manage_connector_accounts` | Yes | Yes (enabled accounts) | Yes |
| Manager, Warehouse, Programmer without `manage_connector_accounts` | No | No | No |
| Merchandiser | Yes | Yes (enabled accounts) | No |
| Any role, cross-workspace account | No (404) | No (404) | No |
| Disabled account | Per role matrix (unaffected by disabled state) | No | Per role matrix |

**GAP-006 overall remains Open.** Remaining scope: Task 4B-2c (discovered
schema fields / change inspection), retention/pruning (4B-2d), Magento Stages
1–3 (Preview Engine, Merchant Preview, Live Engine), `ExternalRecordLink`,
connector-account credential-management/settings **edit** UI (create UI shipped).
Workspace-scoped authorization foundation (GAP-026) repository runtime is
**Implemented** (GAP-026B-2) and production-activated on Babypark pilot
(2026-08-14). Layer B mapping UI (4C-1c-2b) shipped in PR #139.

### Classification after Sync UX / Domain Rebaseline (documentation pass)

Distinguish carefully — do not treat every future possibility as an active GAP:

| Class | Item | Blocks Sync domain work now? |
|---|---|---|
| **A. Architecture blockers** | None identified against current `origin/develop` for the approved Sync Domain Rebaseline | No |
| **B. Implementation gaps** | Preview/Live execution (Stage 1–3); `ExternalRecordLink`; merchant sync UX beyond connection create + mapping; ConnectorSchemaDiff write path/consumer; connector-account **settings edit** UI; remaining Connector UX migration / Layer C gating (GAP-025). `SyncRun`/`SyncRunItem` persistence is implemented (4C-2b-1). Connector-account **create** UI is implemented. | Yes for shipping sync; docs are settled |
| **C. Connector-specific future verification (deferred Variant #2 / profile)** | What external contract `adobe_commerce_paas_oauth1_integration` intentionally covers; PaaS-only vs broader Magento REST-family; post-bootstrap runtime-contract/version/capability verification; Magento Open Source setup/auth compatibility; whether AccountSetup and final runtime contract must later split; whether exactly-one AccountSetup-profile invariant must ever change | **No** — deferred; not a blocker for generic Sync domain rebaseline |

Do not add generic `edition` / `deployment_model` / `api_family` fields to
generic sync entities for symmetry while Class C remains unresolved.

**Task 4A note (added 2026-07-16):** Task 4A implements the first concrete
schema for `ConnectorDefinition` and introduces `ConnectorSchemaSource`, plus
a read-only admin surface over the existing Canonical Registry. It does not
implement `ConnectorAccount`, credentials, live discovery, or `FieldMapping`
runtime behaviour — those remain blocked by this GAP until Task 4B/4C.
GAP-006 stays Open.

**Task 4B-0 note (added 2026-07-21):** Docs-only Stop-and-Amend for
`ConnectorAccount`, connection-check/discovery history, immutable snapshots,
separate diffs, dual-axis errors, Adobe normalization contract, and fixture-backed
visual prototype. No migrations/models in 4B-0 PR.

**Task 4B-1 note (added 2026-07-22):** PR #85 merged seven
workspace-owned ConnectorAccount/history/snapshot/diff tables, fourteen composite
workspace FK guards, generated-column soft-delete uniqueness, seven Eloquent
models, eight translation-keyed enums, and seven factories. The migration was
verified on MySQL 8 through `.github/workflows/mysql-tests.yml`.
No HTTP adapter, credential-management UI, connection-check execution, discovery
execution, snapshot/diff computation, or pruning service was added.
`ConnectorDefinitionStatus`'s pre-existing hardcoded-label debt remains tracked
under GAP-019.

**Task 4B-2-0 note (added 2026-07-22):** Runtime decisions for connector
runtime Tasks 4B-2a–4B-2d were researched and approved in
`docs/proposals/task-4b2-0-runtime-decisions.md` (B1–B15) — covering not only
the Task 4B-2a connection vertical slice but also discovery execution,
diff computation, retention/pruning, and operational recovery states across
4B-2b–4B-2d. Approved patches promoted into `03-DOMAIN_MODEL.md`,
`04-ARCHITECTURE_PRINCIPLES.md`, `05-AI_WORKING_AGREEMENT.md`,
`06-UI_DESIGN_SYSTEM.md`, and `07-TECH_STACK.md` in this same PR. One runtime-design item remains explicitly open and non-blocking for
the completed 4B-2a scope:
- SaaS `Store`-header vs `store_code` reuse (B3) — deferred to future SaaS work;

The B9 repository implementation and host-prerequisite verification are
complete: this PR adds `php artisan queue:restart` to `deploy.sh`, and the
pilot host was verified on 2026-07-31 to use the `database` cache store
with a running Supervisor-managed default worker and `autorestart=true`.

Historical state at the time of this verification: the amended `deploy.sh`
had not yet been executed on the production host because the PR had not yet
been merged or deployed. Its future first execution was not evidence that the
dedicated connector worker had been installed.

Verified on 2026-07-31 (pilot host):
- existing `babypark-queue:babypark-queue_00` confirmed `RUNNING`;
- PHP path confirmed as `/usr/bin/php`;
- `pcntl` confirmed installed;
- active cache store confirmed as `database`;
- `lock_connection=null` resolves to the cache store's default DB connection (confirmed directly from the installed `laravel/framework` v11.54.0 source, `Illuminate\Cache\CacheManager::createDatabaseDriver`);
- `lock_table=null` resolves to `cache_locks` (same source);
- `cache` and `cache_locks` tables confirmed present with their expected structures (`key`/`value`/`expiration` and `key`/`owner`/`expiration`);
- **Historical (verified absent 2026-08-14; closed 2026-08-15):** dedicated
  `babypark-connector-queue` was intentionally uninstalled on the Babypark pilot
  host before permanent Supervisor activation. Discovery job/runtime and
  `database_connectors` / `connectors` lane exist in application code (PR #102).
  Permanent production Supervisor activation completed on the Babypark pilot
  2026-08-15 — see connector-worker production activation sub-gap above and
  `DEPLOY.md`.

**Historical (Task 4B-2-0, 2026-07-22):** At promotion time, connector
production-readiness also depended on **GAP-024** (framework upgrade). GAP-024
is now **closed** on `develop` — see the GAP-024 entry for the final stack.
Closing the B9 host-verification item did not, by itself, close GAP-024.

**Historical (Task 4B-2-0 sequencing, pre-connector-worker activation):** Next task at that time was Task 4B-2c — discovered schema fields / change inspection. Task 4B-2c remains open future scope within GAP-006. **Historical (pre-PR #139):** Task **4C-1c-2b** (Layer B Mapping UI) was then the current next repository implementation task; shipped in PR #139 (merge `9a4be2f`).

**Task 4B-2b note (added 2026-08-07):** PR #102 merged queued discovery
execution (`ConnectorDiscoveryRunJob`), the dispatch/persistence execution
chain, Adobe discovery execution, account projection updates, and historical
pre-B-2 Merchandiser authorization/presentation closure
(`ConnectorAccountMerchandiserPresentation` — superseded by GAP-026B-2).
Earlier PRs #98–#101 delivered discovery runtime Stop-and-Amend, normalization,
error vocabulary, and canonical hashing groundwork; PR #96/#4B-2b-0 added the
`database_connectors` lane. PR #105 corrected received-vs-normalized discovery
accounting and added the committed Magento pilot payload regression fixture
(106 received attributes, 102 normalized attributes, 102 persisted normalized
snapshot fields) with deterministic canonical hashing coverage in tests.
Discovery Overview UI merged in PR #114 (Task 4B-2b complete).
**Historical (verified absent 2026-08-14):** permanent production
`babypark-connector-queue` Supervisor activation was a separate operational gate
before Babypark pilot installation completed 2026-08-15 (see connector-worker
production activation sub-gap above).

**Task 4B-2b UI note (added 2026-08-09):** PR #114 merged Discovery Overview UI
on Connector Account — list last-successful-discovery projection, account-detail
Discovery summary, Discovery history, minimal snapshot summary page, and manual
Discovery action behind the existing deployment flag and authorization
contract; Merchandiser safe surface, workspace isolation, and sensitive-field
presentation gates covered. Successful discovery already persists
`ConnectorSchemaSnapshot` and `ConnectorSchemaSnapshotField`; field-level
inspection builds on that data. `ConnectorSchemaDiff` / `ConnectorSchemaDiffItem`
remain scaffolding without a write path — diff computation is Task 4B-2c scope,
not yet implemented.

**Task 4B-2b-0 note (added 2026-07-29):** Runtime alignment PR — adds
`database_connectors` / `connectors` queue lane (`retry_after` 1200s), dedicated
connector worker config (docker-compose + deferred permanent Supervisor
installation for the pilot host), and `deploy.sh` `queue:restart`. Connection-check lane unchanged.
B9 host-prerequisite verification completed 2026-07-31 (default worker `RUNNING`,
`database` cache/lock store confirmed). **Historical (verified absent
2026-08-14; closed 2026-08-15):** dedicated `babypark-connector-queue` on the
Babypark pilot production host was a separate permanent-activation gate before
installation completed 2026-08-15. `ConnectorDiscoveryRunJob` and the
`database_connectors` / `connectors` lane exist in application code (PR #102).
Discovery Overview UI is complete. **Historical:** GAP-024 was open at
2026-07-31 verification; it is now **closed** (see GAP-024).

**Task 4B UI handoff:**

- `ConnectorDefinition` remains global platform metadata and schema-source
  administration.
- Workspace API credentials, store/account base URLs, connection status,
  last sync and live account discovery belong to workspace-owned
  `ConnectorAccount`; do not store them on `ConnectorDefinition`.
- Task 4B must align all Eloquent list filters it materially touches with
  `06-UI_DESIGN_SYSTEM.md`:
  native Filament Table filters, visible desktop label, right-side
  slide-over presentation, approved badge semantics, active indicators,
  and mobile behavior.
- Automatic field-match suggestions, confidence handling, persistence of
  confirmed mappings and manual resolution for unmatched fields belong
  to the subsequent FieldMapping workflow (Task 4C), not to
  ConnectorDefinition metadata.

---

## GAP-007 — Connector-specific columns leaked into core `products` table

**Approved docs:**
- `02-ATTRIBUTE_DICTIONARY.md`, Channel Mappings Protection: "Core tables must never contain
  temporary attributes like google_title, rozetka_price, or prom_description."
- Canonical Product Field Registry DEC-011: `onec_guid` is `connector_only`, not a Product/System Attribute.
- Canonical registry: `meta_title` / `meta_description` are platform SEO `core_model_property` Product concepts; `rozetka_category_id` is `connector_only`.

**Current code:**
- Connector-specific leakage (must eventually leave Product core):
  - `products.rozetka_category_id` — Rozetka marketplace category reference;
  - `products.onec_guid` and `product_variants.onec_guid` — 1C external identity columns.
  These are vendor-instance identity/mapping leftovers. Do not create FieldDefinitions for them.
  Destination for identity is the account-scoped external identity boundary, not Product core.
- Valid platform Product core that happens to be physical columns (not equivalent leakage):
  - `products.meta_title`;
  - `products.meta_description`.
  Canonical registry classifies both as Product SEO `core_model_property` with `keep_as_is`.
  Do not treat generic reusable SEO fields as connector-specific leakage merely because they
  are columns on `products`.

**Impact:** connector-specific columns in Product core block a clean Connector Foundation
(GAP-006) identity/mapping split if left unaddressed. SEO columns do not belong in that same
debt inventory.

**Decision:** do not register `rozetka_category_id` or `onec_guid` as System Attributes or
FieldDefinitions. No further connector-specific columns should be added to core Product tables.
Physical `onec_guid` / `rozetka_category_id` columns are not deleted in a docs-only PR.

**Next task:** migrate connector-specific identity/mapping columns behind ConnectorAccount-scoped
external identity / mapping layers and deprecate the raw columns. SEO fields remain Product core
unless a later localization/store-view DEC says otherwise.

**Status:** Open, low priority for column migration (no active Rozetka export; 1C identity remains
legacy runtime). Canonical classification of `onec_guid` corrected by DEC-011.

---

## GAP-008 — Per-location pickup/checkout allocation not implemented

**Approved docs:**
- `03-DOMAIN_MODEL.md`, "Location-ready inventory foundation" (**Resolved**, added by
  Availability Foundation): `inventory_locations` exists as a foundation entity, but explicitly
  states "Pickup-point selection, per-location checkout allocation, per-location reservation,
  and location-aware delivery rules are explicitly future, separate work."

**Current code:**
- `inventory_locations` exists and `stocks` are linked to it, but `AvailabilityResolver` and
  `InventoryReservation` both operate at the variant level only, aggregated across all
  locations. There is no UI anywhere (admin or B2B cabinet) for a customer to choose a specific
  pickup location, and no reservation ever allocates against a specific location.

**Impact:**
- A merchant with a showroom and a separate warehouse (or multiple physical locations) cannot
  yet offer "choose your pickup point" to B2B customers, even though the underlying data model
  is already location-aware. This is a real, deliberately deferred product feature, not a bug.

**Decision:**
- Do not build ad-hoc per-location logic anywhere as a stopgap. When this is prioritized, it
  needs its own domain design pass (per-location availability formula, checkout UI, staff
  fulfillment workflow, delivery-setting interaction) — not a quick patch on top of the current
  variant-level resolver.

**Next task:** Not scheduled. Revisit when a merchant with multiple pickup-capable locations is
onboarded, or when explicitly prioritized in product planning.

**Status:** Open, low urgency (foundation exists, feature does not).

---

## GAP-009 — `low_stock` / `pre_order` availability thresholds not defined

**Approved docs:**
- `03-DOMAIN_MODEL.md`, "Operational Inventory Cache": `availability_status` is documented as
  an enum with four values — `in_stock`, `low_stock`, `out_of_stock`, `pre_order`.

**Current code:**
- `product_variants.availability_status` (added by Availability Foundation) is only ever
  backfilled/set to `in_stock` or `out_of_stock` — a simple `available_quantity_cache > 0` check.
  `low_stock` and `pre_order` are valid enum values that no code path ever assigns, by explicit
  decision during Availability Foundation, to avoid inventing an un-approved business threshold
  (e.g. "what quantity counts as running low?") or pre-order policy.

**Impact:**
- The UI cannot yet show a "Закінчується" ("running low") badge or support pre-order workflows,
  even though the enum already has room for both. This is intentional, not an oversight — the
  actual threshold/policy is a business decision that hasn't been made yet.

**Decision:**
- Do not invent a `low_stock` threshold or `pre_order` policy in code without an explicit
  documentation-level decision first (e.g. "low_stock = quantity below N" or "below N% of a
  typical restock level," and whatever `pre_order` should mean operationally for this business).

**Next task:** Not scheduled. Revisit when the business defines what "running low" and
"pre-order" should concretely mean for a merchant catalogue.

**Related finding (does not close this gap):** Shopify's `Variant Inventory Policy`
(`deny`/`continue` — whether a variant can still be ordered at zero stock) is the standard
mechanism that would populate `availability_status = pre_order`. This clarifies *how* pre-order
would work mechanically once a business decision is made on *when* to allow it — it does not
by itself decide the business threshold, which remains open per this gap.

**Status:** Open, low urgency.

---

## GAP-010 — CustomerGroup / PricingRule not implemented

**Approved docs:**
- `03-DOMAIN_MODEL.md`, "CustomerGroup" and "PricingRule" sections (descriptive, not yet
  formally Resolved): customer groups may connect to a price list, discount rule, visibility
  rules, and access mode; `PricingRule` represents a pricing adjustment layered on top of a
  resolved `PriceListItem` tier.

**Current code:**
- `Contractor` has no group/segment concept at all. Pricing MVP Foundation implements direct
  `Contractor.default_price_list_id` assignment only — many contractors can share one
  `PriceList`, which covers simple grouping-by-price, but there is no entity for bundling
  additional segment-level rules (catalog visibility, payment terms) together.

**Impact:**
- A merchant whose B2B customers need more than shared pricing cannot yet configure that as a
  single reusable "profile."

**Decision:**
- Do not build ad-hoc segment logic anywhere as a stopgap. Design `CustomerGroup` as its own
  entity that composes with the existing `PriceList` assignment, when actually needed.

**Next task:** Not scheduled.

**Status:** Open, low urgency.

---

## GAP-011 — Product classification structure: `Merchant Type`/`Tags` schema, and tracking of a future Standard Category concept

**Approved docs:**
- `03-DOMAIN_MODEL.md`, "Product classification model — Merchant Category / Standard Category /
  Merchant Type / Tags" (Patch 1 above, Resolved): four distinct concepts. Merchant/Catalogue
  `Category` (existing `categories` table) is unchanged. This document's existing `ProductType`
  template concept (internal field/variant structure control, hidden in MVP) is also unchanged
  and unrelated to the new `Merchant Type` concept. `Merchant Type` and `Tags` do not exist as
  schema yet and are ready to implement. Standard Category (standardized public taxonomy) is a
  tracked future concept, deliberately not built now, consistent with the existing "no global
  taxonomy in MVP" decision.

**Current code:**
- `categories` table/relationship already exists and is used for storefront navigation — no
  change needed here. Schema for `Merchant Type` and `Tags` is implemented (Task 5).
- **Implemented (Task 6A):** `TagResource` (standalone admin CRUD with guarded delete);
  `Merchant Type` and `Tags` in a dedicated `"Класифікація"` section on `ProductResource`
  form and infolist; internal admin table columns (`merchant_type`, `tags.name`) and filters;
  eager loading of `tags` on the product list query; `TagManager` for shared validation across
  standalone and inline tag creation; atomic locked delete guard preventing silent cascade when
  a tag is still attached to products.
- **Still deferred:** Standard Category (tracked alongside GAP-006, unchanged); B2B/cabinet
  exposure of `merchant_type` or `Tags` (not decided, not built).

**Implemented (as of Task 6B):**
- Bulk "Додати теги" and "Видалити теги" operations, with preview/apply metrics distinguishing
  products from links.
- Selected-rows and all-matching-filter support for bulk tag operations.

**Still deferred:**
- Standard Category (tracked alongside GAP-006, unchanged).
- B2B/cabinet exposure of Merchant Type/Tags (not decided, not built).

**Impact:**
- Managers can now assign the free internal organizational label (`Merchant Type`) and
  filtering tags (`Tags`) on products in the admin panel. Merchant/Catalogue `Category`
  alone was already functional and is unchanged. Standard Category's absence has no MVP impact
  — it becomes relevant once channel/marketplace export (GAP-006) is actually built.

**Decision:**
- Implement `Merchant Type` (nullable string, e.g. `products.merchant_type` — not a generic
  `type` column, to stay unambiguous relative to the existing `ProductType` concept) and `Tags`
  (separate table, many-to-many with `Product`) as their own small schema task now — this does
  not require re-touching `Category` (stays workspace-owned) or `ProductType` (stays hidden,
  unrelated).
- Standard Category is explicitly **not** part of this task's scope — revisit only alongside
  GAP-006 (connector/channel-mapping infrastructure), not as a core catalog change.

**Next task:** Product classification structure implementation (schema task, separate from the
Phase 2 field backlog in GAP-013).

**Status:** Partially closed in code. Implemented: `products.merchant_type` (nullable string column),
its column-backed `AttributeDefinition`, `tags` table, `product_tag` pivot with `workspace_id`
consistency enforcement (Eloquent `ProductTag` pivot guard + MySQL composite foreign keys),
`Tag` model, and `Product`/`Tag` `belongsToMany` relations; admin UI for assigning
`Merchant Type` and `Tags` to products (`ProductResource` `"Класифікація"` section, table columns,
filters, eager loading); standalone `TagResource` with guarded delete via `TagManager`;
bulk add/remove tag operations with preview/apply metrics (`TagBulkAssignmentService`).
Standard Category remains explicitly deferred/tracked alongside GAP-006 — this GAP is not fully
closed until that future concept is built. B2B/cabinet exposure remains open.

---

## GAP-012 — Multi-currency pricing not implemented

**Approved docs:**
- `03-DOMAIN_MODEL.md`, Pricing Foundation blocks: `price_lists.currency` field exists in the
  schema (Task 3C-1), defaulting to `'UAH'`, but no conversion/exchange-rate/multi-currency
  display logic was built — deliberately deferred at the time.

**Current code:**
- `PriceList.currency` is a UAH-only select in the admin UI (Task 3D-1); `PriceResolver` assumes
  a single currency throughout.

**Impact:**
- This SaaS is intended to be sellable beyond a single Ukrainian pilot merchant. A merchant
  selling in EUR/USD/other currencies cannot be onboarded without this. Given the realistic
  commercial ambition (a global-capable product, not a Ukraine-only tool), this must not be
  silently forgotten — it is tracked here explicitly so it surfaces again when the first
  non-UAH merchant scenario becomes real, rather than being rediscovered under time pressure.

**Decision:**
- Do not build ad-hoc currency conversion as a side effect of some other task. When a real
  multi-currency need appears, it needs its own domain design pass (exchange rate source,
  rounding rules, display format per locale, whether `PriceListItem` needs per-currency rows or
  a conversion layer).

**Next task:** Not scheduled. Revisit when the first non-UAH merchant scenario is real.

**Status:** Open, tracked (not urgent, but must not be dropped from this document).

---

## GAP-013 — Product Fields Phase 2: remaining standard fields not yet registered

**Approved docs:**
- `02-ATTRIBUTE_DICTIONARY.md`'s Phase 1 seed scope explicitly deferred a Phase 2 list.
- Cross-referenced against Shopify's real product CSV template and Magento's product
  attribute/CSV documentation (compiled reference, not reproduced verbatim per copyright — see
  the comparison table already shared with the project owner).

**Current code:**
- Phase 1 fields (name, brand, category, description, status, url, sku, gtin, price, RRP,
  cost_price, availability, color, size) are registered and working (Tasks 1-2, 3B, 3C).
- Phase 2 field registrations implemented (Task 5): `net_weight`, `gross_weight`, `volume_m3`,
  `shipping_required`, `backorder_policy`, `technical_characteristics`, `instructions` — all
  registered as `AttributeDefinition` records via `AttributeDefinitionSeeder`.
- Not yet registered: gift card flag (deferred).
  `image_alt_text` remains deferred to future Media entities (`MediaAsset`/`ProductMedia`/`VariantMedia`
  — alt text is a per-image property, not a product/variant-level attribute). Tags is tracked
  separately under GAP-011 (classification structure), not here.

**Previous decision:** Tax class deferred until a real product need appeared.
**Reopened:** The need is now confirmed by workspace tax defaults (this
task), and by the anticipated product-card inheritance, bulk assignment
and import mapping requirements that follow from it.
**Status:** Open — near-term prerequisite for full product card and
import UX.

**Impact:**
- These are ordinary Phase 2 registrations — no architectural blocker, unlike the Category/Type/
  Tags model (GAP-011) which needs its own schema work first.

**Decision:**
- Register these via the normal `AttributeDefinition` seeding mechanism already established
  (Task 1/2), grouped into existing `attribute_group` codes where they fit
  (`characteristics`, `logistics`, `images_media`, `b2b`) — no new mechanism needed.
- "Технічні характеристики" and "Інструкція" are **mandatory for B2B-ready/customer-facing
  publication readiness, not necessarily required at initial draft creation** — consistent with
  progressive product onboarding (start with a name, enrich later). Do not treat them as
  skippable nice-to-haves when a product is being prepared for publishing, but also do not make
  them a hard database-level requirement that blocks creating a draft product row.
- Tax class remains explicitly deferred as a **registered field** (not yet in
  `AttributeDefinition` seed) — revisit via GAP-013 reopening above. Gift card flag
  remain explicitly deferred (not registered now) per product owner decision — revisit
  only if a real need appears.

**Next task:** Product Fields Phase 2 implementation (schema/seed task, separate from the
Merchant Category/Standard Category/Merchant Type/Tags structural task in GAP-011).

**Status:** Partially closed in code. Phase 2 `AttributeDefinition` registrations are
implemented for `net_weight`, `gross_weight`, `volume_m3`, `shipping_required`,
`backorder_policy`, `technical_characteristics`, and `instructions`. Tax class reopened
(see above). Gift card flag remains explicitly deferred (unchanged). `image_alt_text` remains
deferred to future Media entities (unchanged). `backorder_policy` registration does not change
`AvailabilityResolver` behavior and does not close GAP-009. Publication-readiness enforcement
for `technical_characteristics` and `instructions` remains the future responsibility of
`B2BPublicationChecker` (not yet built).

Foundation Seed Sync v5 (Branch A) added eight Platform Library fields via
`FieldDefinitionSeeder`: `condition`, `short_description`, `material`,
`country_of_origin`, `manufacturer`, `model`, `compatibility`, `battery_type`
(each with a matching `FieldBinding`). See `FieldDefinitionSeederFoundationSeedV5Test`.

---

## GAP-020 — Canonical registry fields blocked by missing verified select options

**Approved docs:**
- Canonical Product Field Registry (CSV v7): `age_group` and `gender` are
  registered Platform Library select fields in the registry.

**Current code:**
- Canonical Registry now contains verified option sets for `age_group`
  and `gender` under DEC-006 and DEC-007.
- Neither field is seeded in `FieldDefinitionSeeder`.

**Impact:**
- The missing-options blocker tracked by GAP-020 is resolved.
- Seeding remains blocked by GAP-022 because product-vs-variant binding
  is not yet resolved.

**Decision:**
- Close the option-registry part of this gap.
- Do not proceed to seed solely because verified options now exist.

**Next task:** Resolve GAP-022, then prepare a separate seed task.

**Status:** Closed as a missing-options registry gap. Future seed activation
remains blocked by GAP-022.

---

## GAP-022 — age_group/gender product-vs-variant binding unresolved

**Approved docs:**
- Canonical Product Field Registry: age_group and gender registered with
  `binding_strategy: product` (DEC-006, DEC-007).

**Current code:**
- Verified option sets for `age_group` and `gender` exist in the Canonical
  Registry under DEC-006 and DEC-007.
- Neither field is seeded in `FieldDefinitionSeeder`.
- GAP-020 is closed as a missing-options registry gap; future seeding remains
  blocked by this GAP.

**Impact:**
- Google Merchant documentation confirms both age_group and gender may be
  variant-distinguishing properties (products varying "shoes for 0-3
  months" vs "shoes for 1-5 years"; "mens black tennis shoes" vs "womens
  black tennis shoes"). The registry's current product-level binding does
  not reflect this possibility.

**Decision:**
- Not resolved by this PR. `binding_strategy` remains `product` unchanged.
  A separate Stop-and-Amend decision must evaluate
  `product_and_variant_two_bindings` before any seed of these fields.

**Next task:** Evaluate variant-level binding need against a real merchant
catalogue and Magento configurable option dimensions — not against a named
customer as architecture authority — before seeding age_group/gender.

**Status:** Open, blocking dependency for future seed of age_group/gender —
seeding must not proceed automatically just because options now exist.

---

## GAP-023 — Explicit measurement value/unit storage is unresolved

**Approved docs:**
- DEC-009 defines `net_weight` and `gross_weight` as canonical physical-mass
  concepts.
- Canonical Registry classifies both fields with `value_shape: measurement`
  and `unit_family: mass`.

**Current code:**
- `products.net_weight` and `products.gross_weight` store nullable decimal
  values.
- No explicit mass-unit column or Measurement value object exists.
- `products.unit` represents the selling unit and must not be reused as the
  physical mass unit.

**Impact:**
- Stored legacy values are preserved, but their physical unit is not encoded
  explicitly beside each value.
- Automatic Adobe Commerce, Shopify, Google Merchant, Amazon or other
  weight mappings may be semantically incorrect without a unit and packaging
  policy.

**Decision:**
- Naming is corrected now.
- This PR does not introduce unit columns, conversion services or a
  Measurement value object.
- Weight connector mappings remain blocked until this GAP is resolved.

**Next task:** Design the measurement storage contract: value/unit physical
storage, supported unit codes, canonical conversion policy, packaging level
and migration of existing values.

**Status:** Open, blocking dependency for weight-related connector mappings.
It does not block mappings for unrelated fields.

---

## GAP-024 — Framework upgrade to supported Laravel / Filament / Livewire / Tailwind stack

**Approved docs:**
- `07-TECH_STACK.md` — current application stack guardrails.

**Original gap:**
- `composer.lock` pinned Laravel 11.x past security support; Filament 3,
  Livewire 3, and Tailwind 3 were below the target production stack.

**Final target reached:**
- Laravel 13
- Filament 5
- Livewire 4
- Tailwind CSS 4
- Vite 6
- Node 22 project contract (`.nvmrc` = 22; npm engines `>=22 <23`)

**Completed migration sequence:**
- **PR1** — runtime/toolchain/visual prerequisites
- **PR2** — Laravel 11 → 13 bridge
- **PR3** — Filament 3 → 4 + Tailwind 4 bridge
- **PR4** — Filament 4 → 5 + Livewire 3 → 4
- **PR5** — deliberately skipped after post-PR4 closure assessment found no
  mandatory hardening blocker
- **PR6** — truth-sync / documentation closure (this entry)

**Verification evidence (high level):**
- MySQL CI green on final PR4
- Full SQLite suite green
- Explicit MySQL `migrate:fresh --seed` green
- Frontend build green
- Filament authorization bridge green
- `novalidate` bridge green
- Live-filter bridge green
- Customer/cabinet auth regression green
- Livewire 4 quantity/cart/margin regression gates green
- Browser smoke green
- Visual regression matrix + corrected supplemental like-for-like evidence green

Historical audit reports, visual baselines, and migration research remain under
`docs/audits/` for evidence — they are not rewritten as current stack truth.

**Verified closure base:** `41dbb97094df13df93e72e3eaab3a4c46976fc34` on
`develop`.

**Status:** Closed in code. Framework migration complete; connector
production-readiness is no longer blocked by an unsupported Laravel release.
Remaining connector gaps are tracked separately under GAP-006.

---

## GAP-025 — Connector Integration UX contract not yet migrated in shipped UI

**Approved docs:**
- `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md` — approved normative connector UX
  contract (2026-08-10).
- `docs/CONNECTOR_INTEGRATSII_PAGE_UX_CONTRACT.md` — approved page-specific
  contract for the `Інтеграції` landing (platform-first cards, adaptive
  destinations, rollup, merchant-safe catalog).
- `docs/06-UI_DESIGN_SYSTEM.md` — Connector Integration UX summary and known
  mismatch section.
- `docs/03-DOMAIN_MODEL.md` — `ConnectorCapability` UI source of truth,
  0/1/N account cardinality, Layer C audience, ownership future decision.

**Current code:**
- `Інтеграції` merchant landing ships at `/admin/integrations` (platform-first
  cards, adaptive 0/1/N destinations, §5 health rollup, merchant-safe
  `EligibleConnectorPlatformCatalog`). `ConnectorAccountResource` remains the
  account Overview destination but is no longer a top-level nav entry.
- Layer B mapping UI ships on `ManageSyncFieldMappings` (Task 4C-1c-2b, PR #139):
  explicit `SyncConfiguration` Mapping page; deterministic high-confidence
  suggestion presentation; manual choose/change/remove; explicit confirmation
  through `FieldMappingMutationService`; stale-safe exact remove; workspace-scoped
  fresh Mapping authorization.
- Available Fields supporting reference from Mapping: `ViewConnectorSchemaSnapshot`
  is reachable from Mapping via `availableFieldsUrl`; mapping-authorized merchants
  with `view_sync_mappings` / `manage_sync_mappings` receive Layer-B title/copy
  (`sync_mappings.available_fields_*`); no other merchant entry path remains after
  GAP-025A.
- **GAP-025A cutover (shipped on branch `cursor/gap-025a-merchant-connector-cutover-a9b7`):**
  merchant `ConnectorAccount` Overview rebaselined to Layer A/B **Доступні поля**
  vocabulary; `DiscoveryRunsRelationManager` and `ConnectionChecksRelationManager`
  removed from merchant Overview (backend classes preserved); manual discovery
  action relabeled **Оновити доступні поля**; `ViewConnectorSchemaSnapshot`
  requires Mapping read permission (`canLayerBExternalFieldReference` is
  mapping-only); connector-only actors get 403 on snapshot route.
- **ConnectionChecks history on merchant Overview:** intentionally not restored —
  current merchant projection stays conservative until Layer C platform-support
  identity exists; backend `ConnectionChecksRelationManager` remains for future
  Layer C.
- **Shipped runtime/read architecture (not a regression):** `ConnectorCapability`,
  Discovery execution, snapshot persistence, and Field Browser read-model
  architecture are shipped.
- **026B repository authorization (post-B-2):** `ConnectorAccountPolicy` +
  `ConnectorAuthorization` evaluate the frozen workspace-permission matrix via
  `WorkspaceAuthorization` — not fixed `User.role` semantics. Historical pre-B-2
  fixed-role behavior is transitional evidence under **GAP-026** / PR #102 only.
- **Remaining GAP-025 UX work:** Layer C gating when platform-support identity
  exists; Layer B setup surfaces beyond connection create and mapping (sync
  execution, preview, scheduling, issue aggregation).
- **Shipped (Task 4C-1c-2b, PR #139):** Layer-B Mapping page and Mapping →
  Available Fields supporting reference with workspace-scoped Mapping authorization
  and Layer-B merchant copy on that path.
- **Shipped (GAP-025A):** merchant Connector Account Overview / Available Fields
  Layer A/B cutover for ordinary workspace merchants.

**Implemented sync-domain backend (verified on `develop`; not a GAP-025 UX claim):**
- `SyncConfiguration` persistence and domain write path (Task 4C-0).
- `FieldMapping` persistence, manual confirmation, authoritative-discovery
  validation, and revision v3 integration (Task 4C-1b + 4C-2b-1).
- Canonical deterministic suggestion/read-model provider (Task 4C-1c-1).
- `SyncRun` / `SyncRunItem` persistence foundation and revision v3 rebaseline
  (Task 4C-2b-1).

**Still absent in code (docs settled; runtime or UI missing):**
- Preview admission/execution runtime (`configuration_snapshot` builder,
  one-active-run admission, planner — **Stage 1**; historical labels 4C-2b-2+);
- normative eighth permission `run_sync_preview` (runtime catalogue still seven permissions until 4C-2b-2 / Stage 1);
- generic Product execution aggregate and Adobe simple + configurable planners
  (**Stage 1**; Magento V1 contract frozen — no separate Product Export
  Projection Stop-and-Amend is required before Stage 1);
- SyncConfiguration-owned connector execution configuration (E5 Adobe
  attribute-set context/default) plus revision-version rebaseline and
  snapshot inclusion if the hasher input set grows — **Stage 1** (current
  revision v3 has no connector execution-configuration input);
- mode-aware execution support (Preview vs Live independent; current binary
  `ConnectorSyncOperationSupport` remains fail-closed for Adobe);
- SyncConfiguration merchant reachability (lazy ensure; no UUID);
- `run_sync_live` (Stage 3);
- `ExternalRecordLink` (Stage 3);
- Live execution after E11 revalidation (Stage 3);
- sync execution runtime for merchant "Синхронізувати зараз";
- Preview before first live sync (Stage 2 merchant exposure);
- scheduling beyond Discovery;
- ownership persistence/enforcement;
- issue aggregation and bulk resolution;
- sync-run history as a merchant Layer B surface.

**Platform capability vs connector-V1-specific (do not conflate):**
- first-class MediaAsset / ProductMedia / VariantMedia runtime — platform
  capability gap; current media is `products.images` JSON;
- video / documents / instructions as Product assets — platform capability;
  Magento V1 marks them `PLATFORM CAPABILITY — NOT IN THIS CONNECTOR V1`;
- bundle/kit composition persistence — platform future capability, not Magento V1;
- Magento bundle/grouped/virtual/downloadable/gift-card types — connector-V1-out.

**Technical debranding residue (docs neutralized; runtime identifiers frozen):**

- **SAFE TEXTUAL/UI NEUTRALIZATION** (runtime PHP; compact cleanup gap; **not** this docs PR;
  **not** hash/worker migration debt):
  - `app/Filament/Resources/ProductResource.php` URL placeholder `https://babypark.ua/product/...`;
  - `ProductResource` section title `Основне (з 1С)` (create/edit and view).
  Neutralize these literals in a later runtime/UI cleanup PR. Do not hide them under revision-hash
  or Supervisor rename work.
- **MIGRATION/REBASELINE REQUIRED:** hash prefixes
  `babypark.sync-configuration-revision.v3`,
  `babypark.sync-run-input.v1`,
  `babypark.sync-external-context.v1`,
  `babypark.connector-schema-field.v1`,
  `babypark.connector-schema-snapshot.v1`;
- **OPERATIONAL INFRASTRUCTURE RENAME:** Supervisor `babypark-queue` /
  `babypark-connector-queue`; `CACHE_PREFIX=babypark_`;
- **EXTERNAL INFRASTRUCTURE IDENTITY:** host path `/var/www/babypark-b2b`,
  repository name, `APP_NAME`, fixture emails;
- **LEGITIMATE CONNECTOR FAMILY NAME:** 1C, Adobe Commerce, Magento, Shopify, BigCommerce,
  Google Merchant, Rozetka, Google Sheets, CSV — these names are vendor families, not a named
  customer defining Product architecture.

Historical production evidence in `DEPLOY.md` / GAP-026B may retain the literal old environment
identifier where needed for audit provenance. That is operational history, not architecture
authority.

Do not rename runtime hashes or worker names without the corresponding
migration. No new customer-specific runtime identifier may be introduced.

Each remains an implementation gap. Sync domain architecture is settled; do
not reopen it as an open research task unless current repository truth
contradicts an approved invariant.

**Impact:**
- GAP-025A rebaselined merchant `ConnectorAccount` Overview to Layer A/B
  **Доступні поля** vocabulary; Discovery Overview / history relation managers are
  no longer merchant destinations on that surface (backend preserved for future
  Layer C).
- Remaining non-compliance is Layer C platform-support gating and future sync
  surfaces (execution, preview, scheduling, issues, history) — not the legacy
  Discovery Overview merchant path removed by GAP-025A.
- Do not widen any workspace merchant membership / role-access profile to Layer C
  as a workaround — Layer C requires the separately resolved platform-support
  identity.

**Next task:** Remaining Connector UX migration — Layer C gating when
platform-support identity exists; Layer B setup surfaces beyond connection create
and mapping (sync execution, preview, scheduling). Backend mechanisms above remain
separate scoped tasks.

**Status:** Open — partial (`Інтеграції` landing shipped; SyncConfiguration,
FieldMapping persistence, canonical suggestion read-model, Layer B mapping UI
shipped — PR #139 for 4C-1c-2b; GAP-025A merchant Connector Account Overview /
Available Fields cutover shipped); remaining Connector UX migration / Layer C
boundary work.

---

## GAP-026 — Workspace-scoped RBAC foundation implemented; GAP-026B production cutover complete

**Approved docs:**
- `docs/03-DOMAIN_MODEL.md` — **Workspace access model and authorization
  (Resolved — Task 4C-1c-2a, 2026-08-13)** — atomic permissions as source of
  truth; workspace-owned role/access profiles; User × Workspace evaluation;
  additive workspace roles; transactional anti-lockout; no job-title authorization
  semantics.
- `docs/03-DOMAIN_MODEL.md` — **Workspace RBAC physical architecture (Resolved —
  GAP-026-0, 2026-08-13)** — WorkspaceUser-centric custom RBAC; five physical
  tables; composite workspace guards; explicit `WorkspaceAuthorization`; RESTRICT
  delete semantics; Spatie preflight; anti-lockout coordinator; 026A/026B split.
- `docs/03-DOMAIN_MODEL.md` — **ConnectorAccount authorization (Resolved —
  rebaselined Task 4C-1c-2a, 2026-08-13)** — capability/permission evaluation
  table for connector operations.
- `docs/04-ARCHITECTURE_PRINCIPLES.md` — authorization through policies/gates;
  workspace-scoped authorization must receive explicit `Workspace`; workspace
  isolation (cross-reference **GAP-004** — broad isolation exists, but this gap
  is **not** a claim that general workspace isolation is fully solved).

**Frozen minimum permission vocabulary (implemented in GAP-026A-1):**

- `view_connector_accounts`
- `run_connector_discovery`
- `manage_connector_accounts`
- `view_sync_mappings`
- `manage_sync_mappings`
- `manage_workspace_access`
- `manage_workspace_tax_settings`

Physical persistence is **resolved** in GAP-026-0 (custom WorkspaceUser-centric
RBAC, not Spatie Teams): five tables; `UNIQUE (workspace_id, template_key)` on
`workspace_roles`; parent FKs to `workspaces`/`users` with ON DELETE RESTRICT.
**GAP-026A-1** implemented the five-table foundation, global `workspace_permissions`
catalogue seeder, models/relationships, composite workspace guards/RESTRICT, and
explicit read-only `WorkspaceAuthorization` service with regression tests.

**Implementation staging:**

| Slice | Scope |
|---|---|
| **GAP-026A-1 — Schema, catalogue & explicit read authorization** | **Done.** Five custom RBAC tables; seven-permission global `workspace_permissions` catalogue (`WorkspaceRbacPermissionSeeder`); models/relationships without `BelongsToWorkspace`; composite workspace guards/RESTRICT; explicit `WorkspaceAuthorization` read boundary (`allows`, `effectivePermissions`, `activeMembership`); SQLite + MySQL foundation/authorization regression tests. Legacy Spatie `WorkspacePermissionSeeder` unchanged. No RBAC membership/role assignments seeded. **Explicitly not in 026A-1:** legacy preflight/backfill machinery; anti-lockout coordinator; production legacy assignment; policy/gate cutover. |
| **GAP-026A-2 — Preflight/backfill machinery & anti-lockout coordinator** | **Done.** `WorkspaceRbacLegacyPreflight` / result DTO; deterministic/idempotent `WorkspaceRbacLegacyBackfill`; frozen legacy template keys and bundles; `WorkspaceAccessMutationCoordinator` with fresh anti-lockout query; SQLite + MySQL regression tests including real MySQL 8 concurrent-process proof. Machinery + tests only — not production execution. |
| **GAP-026A (overall)** | **Done** — 026A-1 and 026A-2 foundation slices complete per original staging. |
| **GAP-026B-0 — Workspace RBAC authority cutover contract** | **Done (docs/tests).** Frozen cutover boundaries for Connector/Tax/Mapping/Access; capability-based connector presentation; existing-memberships-only Access; User lifecycle transition rules; one-time maintenance cutover sequence; CHECK-ONLY (B-1) / EXECUTE (B-2) slice ownership; 026B-1/026B-2 split. See `03-DOMAIN_MODEL.md` → Workspace RBAC authority cutover (Resolved — GAP-026B-0). |
| **GAP-026B-1 — Access & Cutover Machinery** | **Done.** Part 1 runtime core + Part 2 merchant Access/Roles UI; CHECK-ONLY `workspace-rbac:cutover-check`. EXECUTE ships with B-2 (see below). **Explicitly no** connector/tax policy authority switch in B-1-only runtime. |
| **GAP-026B-2 — Authority & Presentation Cutover** | **Done / production-activated (2026-08-14).** Babypark pilot maintenance-window cutover completed successfully against `fb2c5a7a3f8a521a2bfca7583e57d1ae83e95bc9`. EXECUTE command `workspace-rbac:cutover-execute`; `ConnectorAccountPolicy` + `ConnectorAuthorization` workspace-RBAC matrix; `ConnectorAccountCapabilityPresentation` three-tier safe projection; Integrations/ListPlatformConnections runtime-overlay gating; `WorkspaceTaxSettingsAuthorization` + write-time reauthorization; `FieldMappingAuthorizationService` outer seam; DB-fresh `WorkspaceAuthorization`; Connector post-lock dispatch authorization freshness; MySQL concurrency proofs for post-lock revocation. | Historical: first production deployment containing B-2 was the maintenance-window cutover deployment. Layer B mapping UI (4C-1c-2b) shipped in PR #139 after B-2 production EXECUTE. |
| **GAP-026B (overall)** | **Done** — production cutover completed 2026-08-14 on Babypark pilot. B-0 contract Done; B-1 Done; B-2 production-activated.

**Legacy membership / role backfill matrix (026B production execution — GAP-026B-2 EXECUTE only):**

Production backfill runs at **EXECUTE** during the maintenance-window cutover (GAP-026B-2
release — not 026A, not a B-1-only release), only after Spatie preflight and
legacy workspace/Admin preflight succeed, and only from **current** legacy state
at cutover time.

Before automatic backfill (026B step 2): fail-closed preflight requires exactly
one `workspaces` row, that row alone has `is_default = true`, and at least one
active staff Admin/Director (`customer_id IS NULL`, `users.is_active = true`).
Multi-workspace legacy state, zero active Admin/Director, or failed counts → STOP;
no inference, auto-promotion, reactivation, or assign-all-users-to-all-workspaces.
Failure halts authorization cutover — no partial RBAC.

Resolve default workspace by `is_default = true` (never hardcode UUID). Create
`WorkspaceUser` for each staff `User` (`customer_id IS NULL`) with
`workspace_users.is_active = true` regardless of `users.is_active`. Backfilled
capabilities flow through deterministic bootstrap `WorkspaceRole` bundle(s) and
`WorkspaceUserRole` assignment — not direct membership permission grants.
Stable non-null `template_key` is bootstrap role identity; merchant `name` is not.

**Legacy User lifecycle (026B cutover compatibility):**

Current `UserResource` still allows staff `User` create, legacy `role` / `is_active`
mutation, and hard-delete. Once `WorkspaceUser` rows exist, `users` →
`workspace_users` ON DELETE RESTRICT changes delete behavior. 026B must ship
`User` lifecycle integrity protection in the same cutover as legacy assignment
materialization and authorization activation. Do not weaken RESTRICT FKs.

| Legacy role | Backfilled permissions (via bootstrap roles) |
|---|---|
| Admin / Director | `view_connector_accounts`, `run_connector_discovery`, `manage_connector_accounts`, `manage_workspace_tax_settings`, `manage_workspace_access` |
| Merchandiser | `view_connector_accounts`, `run_connector_discovery` |
| Manager / Programmer / Warehouse | none of the seven permissions |
| `view_sync_mappings` | nobody |
| `manage_sync_mappings` | nobody |

**Spatie preflight / deferred removal (026B step 1):**

Before production backfill/cutover, audit `roles`, `model_has_roles`,
`model_has_permissions`, `role_has_permissions`. Unexpected rows → STOP and
reconcile explicitly. Spatie preflight must complete before legacy backfill.
Do not remove Spatie package/tables in 026A or 026B.

**Anti-lockout:**

Serialize authoritative access mutations on `SELECT workspace FOR UPDATE`; apply
mutation; post-mutation recheck for at least one active membership with effective
`manage_workspace_access`; rollback otherwise. Global User deactivation/deletion
must lock affected workspaces in deterministic `workspace_id` order. 026A does
not alone make every legacy User mutation anti-lockout-safe.

**Platform / cabinet boundaries:**

`PlatformAdminAuthorization` and `/cabinet` (`Customer` principal) remain outside
GAP-026 workspace RBAC.

**Verified production post-cutover state (Babypark pilot, 2026-08-14, commit
`fb2c5a7a3f8a521a2bfca7583e57d1ae83e95bc9`):**
- canonical workspace permission catalogue: **7**;
- default workspace: exactly **1**;
- effective `manage_workspace_access` holders: **1**;
- legacy Spatie role/permission assignments: **0**;
- Admin legacy account received `legacy_workspace_access_manager`;
- Manager received `WorkspaceUser` membership but no bootstrap `WorkspaceRole`;
- mapping permissions remained unassigned by legacy backfill;
- `workspace-rbac:cutover-check` returned safe / exit 0 after EXECUTE;
- production environment corrected to `APP_ENV=production`, `APP_DEBUG=false`,
  `APP_URL=https://b2b.babypark.ua`;
- HTTPS smoke: `/admin/login` → 200; unauthenticated `/admin` → 302 to
  `/admin/login`; `/cabinet/login` → 200;
- maintenance mode OFF at completion;
- `babypark-queue` RUNNING after restart.

**Verified current-code state (post GAP-026B-2 repository implementation):**
- `App\Services\Workspace\WorkspaceAuthorization` implements DB-backed effective-permission
  projection (`allows`, `effectivePermissions`, `activeMembership`) without trusting
  hydrated `User` state for authority inputs.
- Five RBAC tables exist with composite workspace guards and RESTRICT semantics;
  `WorkspaceUser`, `WorkspaceRole`, `WorkspacePermission` models do **not** use
  `BelongsToWorkspace`.
- `WorkspaceRbacPermissionSeeder` idempotently seeds all seven atomic permissions
  into `workspace_permissions`. Legacy `WorkspacePermissionSeeder` still seeds
  Spatie `web`-guard permissions for transitional production authorization outside
  cut-over domains.
- No production RBAC membership/role assignments are seeded by `DatabaseSeeder`.
- **Cut-over domains in repository runtime (B-2):** `ConnectorAccountPolicy` +
  `ConnectorAuthorization`; `ConnectorAccountCapabilityPresentation`; Integrations and
  `ListPlatformConnections` runtime-overlay gating; `WorkspaceTaxSettingsAuthorization`
  + write-time tax reauthorization; `FieldMappingAuthorizationService` outer seam;
  redundant Connector `WorkspaceMembership` gates removed in B-2 scope.
- `workspace-rbac:cutover-execute` command implemented; **production EXECUTE
  performed** on Babypark pilot 2026-08-14.
- `User.role` remains transitional for GAP-027 / platform surfaces outside cut-over
  domains; it has **no** connector/tax/mapping/access authorization effect in B-2 paths.
- Spatie Teams remains **disabled** — `config/permission.php` → `'teams' => false`.

**Impact:**
- Do not treat the current global Spatie configuration as satisfying the
  workspace-scoped RBAC contract.
- Layer B mapping UI (4C-1c-2b) shipped in PR #139 on top of the GAP-026B
  authorization foundation.
- Do not add more fixed `User.role` policy branches as a workaround for mapping
  or connector authorization.
- Target role-name-free authorization remains partially transitional outside scopes
  cut over in 026B until GAP-027.

**Decision:**
- Cross-reference **GAP-004** for workspace data isolation — GAP-004 tracks
  table/query coverage audit, not permission semantics.

**Next task:** GAP-027 — platform-wide admin Resource RBAC and new-staff onboarding.

**Status:** GAP-026A foundation **Done**; GAP-026B production cutover **Done** (2026-08-14 on Babypark pilot). physical architecture frozen (GAP-026-0). GAP-026B-0 cutover contract **Done**; GAP-026B-1 **Done**; GAP-026B-2 production-activated. Layer B mapping UI (4C-1c-2b) shipped in PR #139. GAP-027 (platform-wide admin RBAC and new-staff onboarding) remains Open — newly created staff without `WorkspaceUser` still fail closed in Connector/Tax/Mapping/Access until GAP-027 ships.

---

## GAP-027 — Platform-wide admin Resource RBAC

**Approved docs:**
- `docs/03-DOMAIN_MODEL.md` — Workspace RBAC physical architecture (GAP-026-0) —
  workspace permissions do not cover platform-global governance or unrelated admin
  catalogue/order/customer/user/pricing Resources.
- Current `User::canAccessPanel()` and many Filament Resources still use fixed
  legacy `UserRole` values.

**Reason:**

Current admin panel admission is still based on fixed legacy `UserRole` values.
The approved workspace permission vocabulary does not yet define authorization
semantics for all catalogue/order/customer/user/pricing/etc admin Resources.

**Scope (later — not GAP-026):**

- permission vocabulary for remaining admin domains;
- policies/gates for remaining Filament Resources / Pages / RelationManagers /
  actions;
- authorization-coverage CI guard;
- `strictAuthorization()` decision/enablement;
- membership-based `/admin` admission;
- complete removal of `User.role` from workspace authorization semantics;
- **new staff membership onboarding** — creating/attaching `WorkspaceUser` rows,
  invitations, attaching an existing `User` to a `Workspace`, future multi-workspace
  membership onboarding;
- **concurrency/locking contract** required when the membership set itself may grow;
- **removal of the temporary existing-memberships-only limitation** introduced by
  GAP-026B (Access UI must not expose Add/Invite until this ships).

**Transitional state after GAP-026B cutover (until GAP-027 onboarding):**

A new staff `User` created through transitional legacy `UserResource` after the 026B
cutover receives **no** `WorkspaceUser` automatically. Connector/tax/mapping/access
surfaces fail closed for that `User` until onboarding is implemented. This does **not**
authorize adding a role-based fallback. Current `canAccessPanel()` may still admit such
a `User` to unrelated legacy areas — transitional, not completed RBAC.

**Until GAP-027:**

- do not invent these permissions inside GAP-026;
- do not broaden `canAccessPanel()` as a workaround;
- do not enable global Filament strict authorization prematurely.

**Status:** Open — tracked; depends on GAP-026A/026B foundation.

---

## GAP-021 — Workspace import alias infrastructure incomplete

**Approved docs:**
- `03-DOMAIN_MODEL.md`, Field Foundation: `workspace_import_aliases` maps
  external import column names to `field_binding_id` per workspace.

**Current code:**
- `workspace_import_aliases` table and `WorkspaceImportAlias` model exist
  (with `field_binding_id` FK to `field_bindings`).
- No Filament admin UI, seeder, service, or connector integration reads or
  writes alias rows yet. Model relationships exist on `FieldDefinition` and
  `FieldBinding` only.

**Impact:**
- Import/connector field mapping cannot use workspace-specific aliases until
  CRUD and resolution logic are built (tracked under GAP-006).

**Decision:**
- Do not hardcode import column aliases in connector code. Wait for alias
  management UI and resolution service alongside Connector Foundation.

**Next task:** Connector Foundation (GAP-006) — alias CRUD and resolution layer.

**Status:** Open, schema-only foundation present.

---

## GAP-018 — Multi-jurisdiction Tax Engine

Jurisdiction за адресою клієнта; кілька податкових реєстрацій; reverse
charge; Stripe Tax/Avalara; автоматичне оновлення міжнародних ставок.

**Cross-references:**
- `ImportedPriceTaxBasis` — доповнення до **GAP-006** (Connector Foundation):
  1С already may send prices with or without tax; import mapping must record the
  declared tax basis per row.
- `ChannelPricePolicy` — доповнення до channel mapping/export work (see GAP-007
  and future connector channel-mapping GAP): e.g. Google Merchant feed requires
  explicit tax-inclusive vs tax-exclusive semantics per channel.

**Status:** Open, tracked (not urgent) — same pattern as GAP-012.

---

## GAP-019 — Application-wide UI Localization

Price Inspector fully localized (uk/ru/en) as of this task; rest of
admin panel (Filament resources, other pages) still Ukrainian-only.

Governance navigation/title/group and page-specific controls now use
uk/ru/en Laravel translation files and respect the current application
locale.

Deferred: inventory of hardcoded UI strings across the rest of the
admin panel; Filament/vendor package translations; user/workspace
locale preference; locale-selection middleware; language switcher UI;
translation completeness checks.

- `ConnectorDefinitionStatus::label()`/`options()` hardcode Ukrainian
  user-facing strings despite the application-wide localization rule
  (`06-UI_DESIGN_SYSTEM.md`, "Internationalization and Localization Rules").
  Do not copy this pattern into new connector enums (see Task 4B-1); migrate
  the existing enum during a dedicated localization pass under this GAP.

**Status:** Open, tracked (not urgent) — same pattern as GAP-012/GAP-018.

---

## GAP-014 — `sale_price >= regular price` data-integrity gap on non-Filament write paths

**Approved docs:**
- `03-DOMAIN_MODEL.md`, VAT handling in `PriceListItem` (**Resolved**): `effective_net_price`
  is the actual net price used for charge/display calculations — `PriceListItem.sale_price`
  overrides `PriceListItem.price` when present; otherwise the regular tier price is used.

**Current code:**
- `ResolvedPrice::fromListItem()` uses any non-null `sale_price` as `effectiveNetPrice`,
  regardless of whether it is lower than the regular `price`. Filament's admin form prevents
  entering `sale_price >= price` via `->lt('price')`, but non-Filament write paths (e.g. a
  future import/connector) can still persist such values.
- Task 3D-2B adds `isOnSale` metadata (`salePrice < regularNetPrice`) that correctly reports
  `false` for this data-error case, but does **not** change `effectiveNetPrice`/`grossPrice`
  algorithm behavior — that remains a separate pricing-integrity concern.

**Impact:**
- A `sale_price` that is not actually lower than the regular price could still be charged as the
  effective price, while provenance metadata would correctly show the item is not "on sale."
  Future admin tooling that shows struck-through regular vs effective prices must not assume
  `isOnSale` and `effectiveNetPrice` are always consistent until this gap is closed.

**Decision:**
- Do not silently alter `PriceResolver`'s effective-price selection in metadata-only work.
- When a real non-Filament write path exists (import/connector), add validation or normalization
  there, and/or teach `PriceResolver` to ignore non-discount `sale_price` values — as an explicit
  pricing-integrity task, not as a side effect of provenance metadata.

**Next task:** Pricing integrity pass — scheduled when import/connector write paths for
`PriceListItem` are built, or when product requirements demand stricter sale-price enforcement.

**Status:** Open, tracked.

---

## GAP-015 — Bulk tag operations have no undo/operation history

**Approved docs:** Task 6B implements bulk add/remove tag operations with an accurate
pre-application preview (per-product and per-link counts), which substantially reduces the risk
of an unintended bulk change — but this is prevention, not recovery.

**Current code:** No activity-log/audit package exists in this project (confirmed absent from
`composer.json`). Bulk tag operations have no way to be reversed after the fact beyond a manual,
mirror-image bulk operation performed by hand.

**Impact:** A genuine "undo my last bulk tag operation" capability requires storing the exact
pivot delta per operation (which specific product-tag links were actually added/removed, not
just the operation's inputs), an operation identifier, a retention policy, and a restore UI —
this is real, additional scope, not a simple flag to add later.

**Decision:** Do not build a partial/fake undo (e.g. "just re-run the opposite operation" is not
equivalent to a true undo, since it would also affect any links that existed before the original
operation for unrelated reasons). When this is prioritized, design it as its own feature — likely
alongside introducing a proper activity-log foundation for the platform generally, not just for
tags.

**Next task:** Not scheduled.

**Status:** Open, low urgency (mitigated in practice by the accurate preview from Task 6B).

---

## GAP-016 — Field Foundation code migration not yet done

**Approved docs:**
- `03-DOMAIN_MODEL.md`, "Field Dictionary Context" and "Field Foundation
  (cross-object fields)" Domain Decision (**Resolved**): the canonical entity
  names are `FieldDefinition`, `FieldBinding`, `product_field_values`,
  `variant_field_values`, `customer_field_values`, and
  `workspace_import_aliases.field_binding_id`.

**Current code:**
- `FieldDefinition`, `FieldBinding`, `ProductFieldValue`, `VariantFieldValue`,
  `CustomerFieldValue` models and tables exist. `FieldDefinitionResource` manages
  product/variant fields. `FieldDefinitionSeeder` is idempotent. Legacy
  `product-fields:migrate-legacy-attributes` command updated to target
  `variant_field_values` (deletion deferred until production-representative
  dry-run per §L).

**Impact:**
- Do not read `03-DOMAIN_MODEL.md`'s Field Foundation naming as a description
  of current code — it is the target. Any Cursor task that touches this area
  must check actual current code (this GAP), not assume the renamed entities
  already exist.
- `GAP-006` (Connector Foundation) is blocked on this migration landing first.

**Decision:**
- Do not build any new feature (e.g. Customer Fields UI, Connector Foundation)
  against the old `AttributeDefinition`/`value_level` shape — it would need
  immediate rework once this migration lands.
- This is a schema + model + Filament resource + service rename/restructure,
  not a pure find-and-replace — see "Field Dictionary Context" for the full
  target shape, including the new `FieldBinding` entity and the
  one-binding-per-object_type rule replacing `value_level`.

**Next task:** Field Foundation migration — GAP-017 prerequisite blocker removed;
sequenced before GAP-006 (Connector Foundation) resumes.

**Status:** Closed in code. Implemented via Field Foundation migration (`FieldDefinition`/`FieldBinding`, `product_field_values`/`variant_field_values`/`customer_field_values`, `workspace_import_aliases.field_binding_id`, idempotent `FieldDefinitionSeeder`, `FieldDefinitionResource` with product/variant query filter). GAP-006 (Connector Foundation) is unblocked.

---

## GAP-017 — Contractor → Customer terminology/auth migration not yet done

**Approved docs:**
- `03-DOMAIN_MODEL.md`, Customers Context (**Resolved**): `Customer`/`Клієнти`
  is the only acceptable user-facing and domain term; `contractor` may appear
  only inside a connector adapter that itself uses that external term (e.g.
  the 1C connector).

**Current code (historical — state before this migration landed):**
- The codebase still names the model, table, Filament resource, pages, and
  related services/tests after `Contractor`, not `Customer`
  (`app/Models/Contractor.php`, `ContractorResource`, `ListContractors`,
  `ContractorPriceListAssignmentService`, etc. — 45 files reference
  `Contractor` as of this writing).
- `config/auth.php` defines a `contractor` guard and `contractors` provider;
  `routes/web.php` uses `guest:contractor` and `Auth::guard('contractor')`;
  `ContractorAuthenticated` middleware exists. These are part of the B2B
  cabinet's live authentication path, not just naming — renaming the model
  without updating these would break `/cabinet` login silently.

**Impact:**
- Every new task in this area currently has to reconcile `Customer` in docs/UI
  with `Contractor` in code, which is a standing source of confusion for both
  developers and AI-assisted sessions.

**Decision:**
- Pre-launch, one-time terminology migration, not a permanent compatibility
  alias — SaaS is not yet launched, so there is no external integration
  depending on the old names today.
- Must be its own self-contained migration task (model, table, FK, Filament
  resource + pages, services, exceptions, tests, `config/auth.php`
  guard/provider, routes, middleware) — not folded into the Field Foundation
  migration (GAP-016), and not left as a side effect of some other task.

**Next task:** None — closed. GAP-016 (Field Foundation migration) is now
unblocked as the next sequenced task.

**Status:** Closed in code.

### Post-mortem: production deploy incident

- **Date / commit:** deploy of PR #55 (`432b7c6`), `migrate-safe.sh` on production.
- **Symptom:** `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'type'`
  at `migrateSyncLogType()` during the Contractor → Customer migration.
- **Root cause:** operation order in `migrateSyncLogType()` — `UPDATE` ran before
  `ALTER TABLE ... MODIFY COLUMN type ENUM(...)`, so MySQL strict mode rejected
  writing `'customers'` into a column whose ENUM still allowed only the old
  value set (`..., 'contractors', ...`).
- **Why the test missed it:** `CustomerRenameMigrationTest` had no fixture row
  in `sync_logs` with `type='contractors'` before rollback; production had such
  a row.
- **Manual recovery on production:** the confirmed 3-step
  ALTER→UPDATE→ALTER sequence applied manually via `mysql` CLI, followed by a
  clean `php artisan migrate` (which marked the migration `Ran`, since `up()`
  begins with `if (! Schema::hasTable('contractors')) return;`).
- **Fix:** correct operation order in the migration file plus a regression
  fixture in `CustomerRenameMigrationTest` (this task).
- **Lesson:** MySQL migration tests must cover **data** that actually exists on
  production — including "rare" lookup tables like `sync_logs` — not only the
  primary entities under test.

---

## Pending Minor Documentation Fixes

Small, low-effort textual corrections identified during review but not
yet applied — too small to be a GAP, too easy to forget if only
mentioned in conversation. Each entry: what's stale, where, found in
which PR review. Remove the entry once applied (do not leave stale
entries after fixing).

(none currently — see git history for resolved entries)
