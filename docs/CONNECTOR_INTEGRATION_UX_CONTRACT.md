# Connector Integration UX Contract

**Status:** Approved normative connector UX contract

**Approval date:** 2026-08-10

**Authority:** This document is the consolidated normative reference for connector-facing UX. Where `docs/06-UI_DESIGN_SYSTEM.md` or `docs/03-DOMAIN_MODEL.md` summarize connector UX or domain boundaries, they link here for full detail. Upon conflict between those summaries and this contract, this document wins for connector UX rules until the summaries are explicitly updated to match.

**Scope:** Every current and future connector-facing surface — Magento today; Shopify, Google Sheets, 1C, marketplaces, and any subsequent connector tomorrow. A connector UI is not acceptance-tested against "does it look like Magento's" — it is tested against this contract, §15.

**Non-goal:** This contract does not itself authorize arbitrary backend work and is not the workspace-permission implementation specification beyond the approved domain authorization contract in `docs/03-DOMAIN_MODEL.md` → **Workspace access model and authorization (Resolved — Task 4C-1c-2a)** and **Preview-first Sync Execution Foundation Contract (Resolved — Task 4C-2a)** / **Merchant Preview Authorization & Remediation Contract (Resolved — Stage 2-0)**. Some underlying mechanisms are already shipped (for example `ConnectorCapability`, Discovery runtime, snapshot persistence, workspace isolation guards, Layer-B Mapping UI on `ManageSyncFieldMappings`, Mapping → Available Fields supporting reference with workspace-scoped Mapping authorization, Stage 1 Preview Engine with `run_sync_preview` and persisted zero-mutation Preview runs, Stage 2A-1 `manage_sync_configurations` runtime permission and Adobe Products Export Layer-B setup, Stage 2A-2 merchant Preview work surface and remediation presentation, **Stage 2B Option Mapping remediation UI on `ManageSyncFieldOptionMappings`**). Missing backend/runtime/security prerequisites require their own scoped tasks. Specifically, Task **4C-1c-2b** Layer-B Mapping UI and its Mapping-side Available Fields supporting path are shipped (PR #139, merge `9a4be2f`). Task **4C-2a** freezes Preview execution architecture (docs only); **`run_sync_preview` runtime and `SyncRun` Preview execution are implemented in Stage 1** (PR #145). **Stage 2-0** freezes merchant Preview authorization/remediation contract (docs only). **Stage 2A** (2A-1 + 2A-2) is **shipped** — `manage_sync_configurations`, non-mutating existence lookup, Adobe Products Export setup, merchant Preview UI, and contextual remediation presentation. **Stage 2B is shipped** — Option Mapping remediation on `ManageSyncFieldOptionMappings` using existing `view_sync_mappings` / `manage_sync_mappings` permissions only. Mechanisms that explicitly remain future include scheduling, issue aggregation/bulk resolution, sync-run history, ownership persistence/enforcement, broader Layer-C platform-support identity/gating, and **Stage 3B–3E** Live Engine implementation slices (**Stage 3A** Live Safety foundation is **shipped** — `run_sync_live`, stale active-run recovery, `ExternalRecordLink` persistence, Live admission/shell; **Stage 3-0** Live Safety contract is **Done (docs)**). Do **not** claim that historical pre-B-2 fixed `User.role` authorization satisfies this UX contract — that transitional behavior is historical evidence only under **GAP-026** / PR #102.

**Existing-vs-future boundary:** This contract defines the _required UX_ for synchronization, preview/dry-run, scheduling, mapping, issues, history, and bulk resolution _when those surfaces/concerns are implemented_. Normative sync domain shape is now settled in `docs/03-DOMAIN_MODEL.md` (Sync Domain Rebaseline: `SyncConfiguration` → `FieldMapping` + `SyncRun` → `SyncRunItem`, account-scoped `ExternalRecordLink`). **Preview computation/runtime is shipped** — Stage 1 Preview Engine delivers persisted zero-mutation Preview (`run_sync_preview`, admission, Preview `SyncRun` persistence). **Stage 2A-2 merchant Preview work surface and remediation presentation are shipped**; **Stage 2A is Done**. **Stage 2B Option Mapping remediation UI is shipped** on `ManageSyncFieldOptionMappings` (existing `view_sync_mappings` / `manage_sync_mappings` permissions only; authoritative persisted connector snapshot metadata on read with zero HTTP; `confirm`/`replace` retain connector external validation outside locked DB transaction; Preview findings remain historical after remediation; narrow stale/orphan option-mapping cleanup does **not** fix Product/Variant select value integrity). **Stage 3A Live Safety foundation is shipped** — `run_sync_live` runtime permission, stale active-run recovery, `ExternalRecordLink` persistence foundation, `SyncLiveAdmissionService`, and fail-closed Live job shell (no Adobe write, no merchant consequential Live UI). Merchant consequential Live execution (**Stage 3B–3E**; **Stage 3-0** docs contract **Done**) — including **Stage 3E-R2a per-item ownership/ERL-provenance rewrite and Stage 3E-R2b-1 backend link-trust services (`AdobeProductEntityTrustReviewService`, `AdobeProductEntityTrustConfirmationService`, `AdobeProductEntityTrustLinkReadinessProjector`, `AdobeProductEntityTrustAuthorizationService` dual-permission enforcement, `EntityTrustReviewEnvelopeService` 15-minute TTL envelopes, and target-snapshot binding via `ConnectorAccountSettingsService`)** and **Stage 3E-R2b-2 merchant-confirmed Filament/Livewire confirmation UI on `ManageAdobeProductsExportPreview`** (per-item readiness/remediation, opaque server-side review-flow store, exhaustive 19-case `EntityTrustFailureReason` presentation, and dual-permission Confirm/Review/Renew actions over the Stage 2-0 contract) — are **shipped**, but truthful flip of Adobe Products/Export/Live advertised support remains **false** and still requires **real-target certification** of the **actual standard shipping implementation** for every advertised V1 consequential Live mutation category, proving all still-frozen safety and domain invariants. The current first-party Magento entity-bound Safe Sync implementation may remain current-runtime evidence and / or an optional Enhanced Safety primitive, but it is **not** a mandatory product prerequisite under the Post-#168 / Post-D6 moduleless-by-default decision. Until real-target certification is met, merchant consequential Live action remains non-actionable and the **Magento** tile keeps the **false** truth flag for Adobe Products/Export/Live. Scheduling beyond Discovery, issue aggregation, bulk resolution, sync-run history, ownership persistence/enforcement, and broader merchant sync surfaces remain future implementation gaps requiring their own scoped passes before the corresponding UI ships. This contract does **not** assert that every entity or runtime mechanism exists beyond what is confirmed elsewhere in this document — but a reader must **not** conclude that dry-run/preview computation is still absent. Those platform-owned sync UX/orchestration concerns do **not** become `ConnectorCapability` cases merely because they are optional or future.

---

## 1. Audiences and the four layers

| Layer                      | Question it answers                     | Audience                                                                                            | Contains                                                                                     |
| -------------------------- | --------------------------------------- | --------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| **A — Щоденна робота**     | "Is my integration okay right now?"     | Workspace merchant users when authorized by workspace permissions                                   | Status, last sync, count of items needing attention, one action                              |
| **B — Налаштування даних** | "How do I control what/how this syncs?" | Workspace merchant users when authorized by workspace permissions                                   | Direction per data type, schedule, field mapping, ownership, available-fields reference      |
| **C — Діагностика**        | "What technically happened?"            | **Platform support/operator** — separate identity; never any workspace merchant role/access profile | Discovery runs, snapshots, technical status codes, redacted diagnostic data (see rule below) |
| **D — Каталог конекторів** | "How does our platform talk to X?"      | Platform operator / developer                                                                       | `ConnectorDefinition`, schema sources, endpoints, auth profiles, verification status         |

**Rule:** Layers A/B audience means workspace merchant **memberships** authorized by workspace-scoped atomic permissions defined in `docs/03-DOMAIN_MODEL.md` → **Workspace access model and authorization (Resolved — Task 4C-1c-2a)**. Business-owned role/access profile names (Admin, Director, Merchandiser, …) do **not** authorize connector, mapping, or Layer C access by themselves.

**Rule:** Layer C requires a distinct platform-support identity/permission. If no such identity model exists yet at implementation time, Layer C is unavailable to **all** workspace merchant role/access profiles — it does not default to any workspace merchant membership regardless of business-owned name. That is a separate architectural decision requiring its own scoping pass.

**Rule:** Layer assignment is a visibility ceiling, not an authorization grant. A surface living in Layer A/B does not automatically authorize every action within that layer. Workspace-scoped atomic permissions remain authoritative and may further restrict individual pages/actions. Connector setup, synchronization settings, scheduling, ownership choices, credential changes, mapping mutation, and destructive actions require explicit permission bundles — never inference from Layer B eligibility or from a business-owned role name. Normative authority: `docs/03-DOMAIN_MODEL.md` → **Workspace access model and authorization (Resolved — Task 4C-1c-2a)** and **ConnectorAccount authorization (Resolved — rebaselined Task 4C-1c-2a)**. **026B repository status (post-B-2):** connector authorization follows the frozen workspace-permission matrix via `ConnectorAuthorization` / `WorkspaceAuthorization`; historical pre-B-2 fixed `User.role` checks are transitional evidence only under **GAP-026** / PR #102.

**Rule (connector safe presentation — Resolved — GAP-026B-0, 2026-08-13):** Connector presentation is **capability-based**, never job-title-based. Safe-only projection must exclude sensitive/configuration state (`credentials`, `settings`, `base_url`, `store_code`, `tenant_context`, `auth_profile`, and management-only connection-check state) **before** merchant-facing Livewire/Filament record serialization — not merely through visual hiding. Legacy `User.role` labels must not widen or restrict presentation relative to effective workspace permissions. Normative detail: `docs/03-DOMAIN_MODEL.md` → **Workspace RBAC authority cutover (Resolved — GAP-026B-0, 2026-08-13)**.

**Rule:** Layer C does not mean "raw payloads become visible." Reaching Layer C never lifts the project's existing secret-redaction, credential-encryption, or workspace-isolation rules. Credentials never become support-visible merely because a person has a Layer C-capable identity. "Diagnostic" means redacted technical detail (status codes, cause categories, timestamps, non-secret identifiers) — not unredacted raw request/response bodies or decrypted credentials.

**Rule:** The word **"Discovery"**, **"Знімок"**, **"Snapshot"**, and **"Schema source"** never appear in Layer A or Layer B UI text. They are Layer C vocabulary. A Layer B surface may show _derived_ information (e.g. "коли ми востаннє перевіряли доступні поля") without naming the underlying mechanism.

---

## 2. Capability-driven rule — bound to the real enum, not a principle in the abstract

**Confirmed as already-built, not proposed:** `App\Enums\ConnectorCapability` currently has cases `ConnectionCheck`, `SchemaDiscovery`, and `AccountSetup`; each connector profile declares its supported set in `config/connectors.php` (`'capabilities' => [...]`); and `$definition->supports(ConnectorCapability $capability): bool` is already the real, callable check (`ConnectorProfileRegistry::requireCapability()` already throws when a profile lacks a capability it's asked to use).

**Governing invariant:** a feature must become a `ConnectorCapability` only when its availability or semantics genuinely vary by connector/runtime support. Platform-owned functionality must **not** become a connector capability merely because it is optional, future, configurable, UI-driven, or not yet implemented. Examples of platform-owned concerns include scheduling, mapping UI, dry-run/preview orchestration, issue aggregation, bulk resolution, sync-run history, and similar platform workflow/UI/orchestration capabilities. Do not invent a large future connector capability taxonomy or DSL for those concerns.

**Rule:** Every **connector-specific** optional capability MUST have one domain-level source of truth. Where an appropriate `ConnectorCapability` case already exists (today: `ConnectionCheck`, `SchemaDiscovery`, `AccountSetup`), UI MUST gate the corresponding surface on `supports()` for that case — no surface gated on that connector capability may render unconditionally. Where a genuinely connector-dependent ability has no case yet, the feature MUST NOT invent a UI-only connector-capability flag to fake capability-awareness — the domain `ConnectorCapability` enum must first be extended, as part of that feature's own scoping/implementation pass, before UI that depends on that ability ships. This document does not pretend additional connector-capability cases exist today.

**Rule:** Platform-owned optional sections (schedule controls, mapping matrix, Preview, issue aggregation, bulk resolution, sync-run history, and similar) are governed by their appropriate Sync Domain / platform / authorization / product conditions — **not** by inventing a `ConnectorCapability` case solely because the section is optional or future.

**Rule:** When a new `ConnectorCapability` case is introduced for a future connector-specific ability, the corresponding UI section must be written to be absent by default and to appear only when the connected profile's `supports()` returns true — never the reverse (present by default, manually hidden per connector).

**Rule:** Do not duplicate `ConnectorCapability` with a separate UI-layer flag system once the domain case exists — the UI reads the domain enum, it does not maintain a parallel one.

---

## 3. `Інтеграції` — universal landing surface

`Інтеграції` is the workspace/merchant surface for connecting and managing external systems. It answers conceptually: _is this external system connected?_ It is not the merchant surface for catalog work and must not become the technical sync builder.

Sync configuration, mapping, preview, first manual live run, schedule, results, and remediation belong to merchant sync/data-management surfaces. Normative sync domain model and merchant journey are defined by the Sync UX / Domain Rebaseline in `docs/03-DOMAIN_MODEL.md` (and summarized in `docs/06-UI_DESIGN_SYSTEM.md`). `Каталог і синхронізація` must not currently be represented as an established navigation group merely because it appears in the future roadmap; the current standalone top-level placement of `Інтеграції` is an intentional interim use of standard Filament ungrouped navigation behavior, not the final navigation IA.

Replaces `Платформи та джерела` as the merchant's entry point. The platform is the entry point; the exact card composition must correctly represent however many `ConnectorAccount` rows actually exist for that platform in the workspace.

**Confirmed directly from the schema:** the real unique constraint on `connector_accounts` is `(workspace_id, connector_definition_id, active_name_uniqueness_key)` — a workspace can have **more than one** account for the same platform (e.g. two separate Magento stores), distinguished by name. This contract does not assume a singleton connection per platform.

**Settled by page-specific contract:** the 0/1/N card composition, adaptive destinations, health rollup, merchant-safe platform catalog, and related acceptance criteria for this landing page are defined in `docs/CONNECTOR_INTEGRATSII_PAGE_UX_CONTRACT.md` (platform-first cards; 0 → setup, 1 → account Overview, N → platform account list). Active-but-not-connectable definitions (no enabled `AccountSetup` profile) are excluded from the 0-account landing set — `Інтеграції` is not a roadmap catalog.

For the single-connection case, the composition follows the account-connection pattern (status + identity + single action — no connector internals):

```
Magento                          Google Sheets
🟢 Підключення перевірено        ➕ Не підключено
Синхронізовано сьогодні 14:32
⚠ 12 товарів потребують уваги

[Відкрити]                       [Підключити]
```

**Rule:** This page never renders `ConnectorDefinition` fields (`code`, `source_kind`, `endpoint_path`, `verification_status`, etc.). Platform display name, connection status, one honest status line, one action — nothing else.

---

## 4. Connection / setup journey

Connection onboarding remains human-friendly and ends in plain confirmation, not a form dump. Sync configuration steps below are merchant sync concerns; they must not collapse `Інтеграції` into a technical sync builder.

1. **Підключення** — ask only the human-facing connection inputs required by that specific connector (a URL + token for Magento; OAuth + resource selection for Google Sheets; an API key for another connector type; file selection for yet another) — never schema source, auth profile, or endpoint path regardless of connector type. The exact input set is connector-specific by nature; what's constant is that it is always phrased in terms a human filling in a form understands, never internal connector configuration. Connection verification is part of this onboarding.
2. **Що синхронізувати** — per data domain (Товари, Ціни, Залишки, ...), only for domains the connected runtime contract actually supports, phrased as directional sentences, never bare "Import/Export":
    ```
    Товари
    ☑ Отримувати товари з Magento
    ☑ Передавати зміни товарів у Magento
    ```
    These two checkboxes are **enabled semantic operations** on one
    domain/context `SyncConfiguration`. They must **not** be translated into
    two hidden SyncConfiguration records merely because import/export can be
    enabled independently. Semantic field correspondence may remain shared
    across the enabled operations. `ConnectorDefinition.direction` remains
    coarse platform metadata and is not configuration capability truth.
3. **Перша перевірка** — a categorized Preview / dry-run before any real sync (`✓ готові · ⚠ потребує уваги · ⛔ неможливо`), never a silent first sync and never a bare "Sync? Yes/No." Preview language must remain distinct from completed synchronization and must never imply an external write occurred.

**Rule:** Scheduling/automation is not offered until a successful Preview has been followed by at least one successful first manual live run path.

---

## 5. Integration Overview — Layer A

The default landing tab for an opened integration. Answers exactly one question truthfully: _is everything okay right now?_

```
Magento          🟢 Підключення перевірено
Остання синхронізація: сьогодні, 14:32 · Наступна: 15:00
[Синхронізувати зараз]

987 синхронізовано · 31 пропущено · 6 помилок
⚠ 37 товарів потребують уваги →
```

**Rule:** If synchronization is currently failing in a way that could misrepresent price or stock, that fact is on this screen, not behind a click — progressive disclosure never hides an active, consequential problem (see §1's Layer boundary; this is the one place Layer A content is non-negotiable regardless of visual density preferences).

### Connector Account Overview (before sync surfaces are present)

Some connectors may expose an account Overview before a full Sync Overview (runs, issues, scheduling) is present or relevant. In that case the default Overview is still Layer A and must remain merchant-safe and zero-training:

- answers only: **which external account/store**, **last-known connection truth**, **readiness of one concrete business operation when relevant**, and **one causal next action when attention is required**.
- **Connection truth and operation readiness MUST remain distinct.** A downstream readiness probe failure MUST NOT rewrite a successful baseline connection as connection failure.
- The default Overview MUST NOT show raw external schema/field counts as health/readiness KPIs and MUST NOT show "Refresh available fields" as a primary account action.
- Vague internal terminology (e.g. "store setup", "environment setup", connector component names) MUST NOT be the default merchant vocabulary. The default presentation speaks in concrete business operations (e.g. "Передача змін товарів у Magento").
- Available Fields and manual refresh belong to Mapping as a supporting Layer-B reference (see §7).

---

## 6. Synchronization setup — Layer B

Contains merchant sync-configuration concerns. Which data domains and semantic operations may be enabled is gated by the connected runtime-contract / Sync Domain capability-truth boundary (`03-DOMAIN_MODEL.md` and §2): only combinations the connected account authoritatively supports may be offered. Schedule, selection, mapping, Preview, ownership questions, issue surfaces, and history are platform-owned sync UX/orchestration concerns — they are **not** `ConnectorCapability` cases merely because they are optional or future.

- Data/domain + independently enabled semantic operations (§4.2), editable after initial setup, only for combinations the connected runtime contract supports. One domain/context SyncConfiguration may enable import, export, or both; UI checkboxes are not a reason to duplicate SyncConfiguration rows.
- Selection scope for the synchronization.
- Schedule (simple merchant-appropriate controls; exact presets are a Product Owner decision).
- `external_context` exposure (e.g. Magento website/store view) remains an open Product Owner MVP choice — architecture recognizes the concept; this contract does not silently settle one-default vs multi-context merchant UX.
- **Per-data-domain ownership**, asked in plain language only when bidirectional sync ships, never as a global silent default and never using the term "ownership" or "source of truth" in merchant-facing copy:
    ```
    Де ви хочете керувати цінами?
    ○ У нашій платформі      ○ У Magento
    ```
    Repeated per data domain (Ціни, Описи, Залишки, ...) that is bidirectionally enabled. No connector ships a hardcoded default answer — this is a per-merchant, per-domain product decision, not inferred silently. Do not introduce mandatory per-field authority before that product need exists. (Storage/enforcement mechanism remains a backend decision requiring its own scoping pass; this contract fixes the _question asked to the merchant_, not yet the storage/enforcement design.)

---

## 7. Mapping — Layer B

The field-mapping matrix is the primary Layer B surface for data structure. It is **concept-first**, built around the merchant's own catalog/domain concepts as rows — not around “map every discovered external field”:

```
Дані у вашому каталозі   Magento         Стан
Назва товару              Name            ✓
Артикул                   SKU             ✓
EAN / штрихкод             —               ⚠ Потрібно зіставити
Колір                     Color           ✓
```

Mapping flow:

1. merchant chooses which internal product/domain concepts matter;
2. platform prefills high-confidence mappings from platform-global canonical knowledge where available;
3. platform validates them against actual account discovery;
4. platform may suggest additional discovered matches;
5. merchant resolves only mappings that matter to the selected synchronization and remain uncertain/unresolved.

The canonical registry is sparse platform knowledge; live discovery is account reality. Neither is exposed as raw connector schema internals on Layer A/B.

**Rule:** The already-shipped Field Browser's **snapshot persistence**, **workspace/account/snapshot ownership-chain validation**, and **field query/read-model/presenter architecture** (`ViewConnectorSchemaSnapshot`, `ConnectorSchemaFieldPresenter`) may be reused — this contract does not require redesign of those read paths. **However:** true Layer-C diagnostic access requires the separately resolved platform-support identity. Do **not** interpret this as "security retained entirely" or "no backend rework required" for authorization/gating. **Partial migration (PR #139):** Mapping → Available Fields supporting path with workspace-scoped Mapping authorization and Layer-B title/copy (`sync_mappings.available_fields_*`). **GAP-025A cutover (Task GAP-025A):** ordinary workspace-merchant `ConnectorAccount` Overview now uses Layer A/B **Доступні поля** vocabulary; `DiscoveryRunsRelationManager` and `ConnectionChecksRelationManager` are removed from merchant Overview (backend classes preserved for future Layer C); manual discovery remains but is labeled **Оновити доступні поля**; `ViewConnectorSchemaSnapshot` requires `view_sync_mappings` / `manage_sync_mappings` — connector-only actors receive 403; Available Fields page always presents Layer B copy for authorized merchants; the only merchant entry to Available Fields is Mapping → Available Fields. Diagnostic snapshot translation keys (`connectors.ui.snapshot.*`) remain for Layer C / operator surfaces — do not delete them merely because they are forbidden in Layer B. **Remaining gap:** Layer C gating when platform-support identity exists; ConnectionChecks history on merchant Overview (intentionally withheld — current projection is conservative until Layer C identity ships).

**Rule:** This surface is reachable only as a supporting reference from inside the mapping page — e.g. a `Переглянути всі доступні поля Magento` action — never as a landing destination or top-level nav item. PR #139 introduced that Mapping-side entry path; no new top-level Available Fields navigation was added.

---

## 8. Issues — Layer A summary, Layer B/A boundary detail

A count and category summary lives on the Overview (§5). The full list, filterable by category, is one click away:

```
⚠ 37 товарів потребують уваги

18  Відсутній EAN                  [Виправити всі 18]
9   Невідоме значення кольору       [Зіставити]
6   Не визначена категорія          [Виправити всі 6]
4   Інші проблеми
```

**Rule:** See §10–§11 for vocabulary and bulk-resolution requirements.

---

## 9. History — Layer B

```
Сьогодні, 14:32   ⚠ Частково    987 успішно · 37 потребують уваги
Сьогодні, 13:30   ✓ Успішно     1017 успішно
```

Row click opens the run's own detail: affected items, per-item problem, per-item action. No separate "Discovery history" concept merges into this — this is sync-run history, a Layer B/merchant concept, distinct from Layer C `ConnectorDiscoveryRun` diagnostics even though they may share underlying data.

**Preview vs completed synchronization:** Preview runs may be retained for audit/reproducibility, but must not automatically appear as completed synchronizations. Whether Preview runs are visible in normal merchant history is a Product Owner decision. Transport attempts/batches/HTTP diagnostics remain Layer C and must not become the merchant's primary history model.

---

## 10. Human error vocabulary — mandatory categories

Every backend error family maps to exactly one of these categories before it may reach Layer A/B. Raw codes (`422 schema_validation_failed`, `HTTP 429`, `discovery_source_unavailable`, etc.) never render to a merchant under any circumstance.

| Category                        | Merchant sees                                   | Implies                      |
| ------------------------------- | ----------------------------------------------- | ---------------------------- |
| Потрібно виправити товар        | "У 18 товарів відсутній EAN"                    | Merchant edits source data   |
| Потрібно зіставити значення     | "Magento не знає значення кольору «Ocean Blue»" | Merchant maps the value once |
| Потрібно перевірити підключення | "Magento більше не дозволяє доступ"             | Merchant reconnects          |
| Тимчасова проблема              | "Наступна спроба через 5 хвилин"                | System retries automatically |

**Rule:** Adding a new backend error code requires assigning it to one of these four categories (or proposing a fifth, with the same category-not-code discipline) before it may be surfaced anywhere in Layer A/B.

---

## 11. Bulk resolution — mandatory once a category exceeds a handful

**Rule:** Any issue category with more than a small, fixed threshold of affected items (exact threshold to be set during the Issues page's own implementation pass, not fixed here) must offer a single bulk action, not force one-by-one resolution. A category whose fix genuinely cannot be batched (e.g. requires distinct manual mapping per item) must say so rather than silently omitting the bulk option.

---

## 12. Merchant/operator visibility boundary — explicit table

| Concept                                           | A           | B        | C   | D   |
| ------------------------------------------------- | ----------- | -------- | --- | --- |
| Connection status, last sync                      | ✓           | ✓        | ✓   | ✓   |
| Sync direction, schedule                          |             | ✓        | ✓   | ✓   |
| Field mapping matrix, available fields            |             | ✓        | ✓   | ✓   |
| Categorized issues, bulk fix                      | ✓ (summary) | ✓ (full) | ✓   | ✓   |
| Sync run history                                  |             | ✓        | ✓   | ✓   |
| Discovery run / snapshot identifiers              |             |          | ✓   | ✓   |
| Canonical hash, technical summary, raw error code |             |          | ✓   | ✓   |
| Endpoint path, source kind, auth profile          |             |          |     | ✓   |
| Connector definition, schema source catalog       |             |          |     | ✓   |

This table is authoritative. A page proposal that puts a row's concept in a column to the left of its marked cell is non-compliant with this contract and must be corrected before merge, not merged with a note to fix later (consistent with the project's existing rule that known regressions may not cross a merge boundary).

---

## 13. Forbidden merchant-facing vocabulary

The following terms, and direct translations of them, must never appear in Layer A or Layer B UI text, error messages, empty states, or notifications, regardless of how accurately they'd describe the underlying mechanism:

`schema source` / `джерело схеми` · `snapshot` / `знімок` · `canonical hash` · `discovery run` · `account_api` / `live_fetch` / any `acquisition_mode` value · `schema_scope` value as a raw label (`global`/`website`/`store` must be translated to their §6/§7 human phrasing, never shown raw) · `endpoint path` · `auth profile` · raw HTTP status codes · raw backend error-code strings.

These remain exactly as-is in Layer C/D and in code — this rule constrains _rendering_, not the domain model or internal naming.

---

## 14. Copy rules

- Every merchant-facing state (empty, error, disabled, success) follows: **what happened → how many affected → what will happen or what to do.** A bare status word alone ("Помилка") never ships without at least the second and third parts unless space genuinely forces a two-tap disclosure (label + one-click detail).
- Boolean/nullable presentation is always three-state where the underlying data is nullable (§ already established for `is_required`/`is_multi_value`/`is_localizable` in the Field Browser) — "так / ні / невідомо," never collapsed to two states.
- No hardcoded language strings in PHP/Blade; all merchant-facing copy goes through `lang/{en,uk,ru}.json`, consistent with existing project convention.

---

## 15. Acceptance criteria for every connector UI (including Magento's own rework)

Before a connector UI surface may be merged, it must satisfy all of the following:

1. Capability gating is scoped correctly (§2):
    - sections whose availability genuinely depends on a connector capability are gated by a real `ConnectorCapability::supports()` check for the connected profile — verified by a test that asserts the section is absent for a profile lacking that capability;
    - platform-owned optional sections (schedule, mapping UI, Preview, issue aggregation, bulk resolution, sync-run history, and similar) are governed by their appropriate Sync Domain / platform / authorization / product conditions instead;
    - no platform-owned optional section is forced to introduce a `ConnectorCapability` case solely because it is optional.
2. Two distinct checks, not one conflated check:
    - **Merchant vocabulary (§13):** no Layer A/B page renders any term from §13's forbidden list in user-visible rendered content — labels, accessible text, notifications, validation/empty/error messages. This does not extend to internal property/variable names (e.g. a `snapshotId` Livewire property) that are never rendered as content; those are implementation detail, not a vocabulary violation.
    - **Sensitive-data enforcement is layer-specific, per §12.** A/B tests assert that data §12 permits only in C/D (canonical hash, technical summary, raw error code, credentials, endpoint path, etc.) never enters merchant-rendered content _or_ serialized Livewire snapshot/effects state on an A/B surface — the existing, stricter canary-test pattern already used for sensitive-field absence applies here unchanged. A future Layer C surface is tested the same way against whatever §12 permits only in D (credentials, raw request/response bodies). Secret-redaction, credential-encryption, and workspace-isolation rules apply at every layer without exception (§1) — canary tests target the specific data forbidden on the surface under test, not every technical attribute globally regardless of layer.
3. Layer boundary (§12) is respected — a merchant role cannot reach Layer C/D content through any route, relation manager, or table action, verified by an authorization/workspace-isolation test following the existing project pattern.
4. Every error surfaced to Layer A/B maps to one of §10's categories — no raw code path reaches merchant-facing text.
5. Any issue category exceeding the bulk threshold offers a bulk action (§11), or explicitly documents why it cannot.
6. No consequential write/overwrite behavior is silently assumed. Where a connector supports more than one behavior that could change merchant data (which direction syncs, which side owns a field), the merchant makes an explicit choice — never a hardcoded default (§4, §6). A connector that supports only one such behavior does not present a fake choice merely for the appearance of consistency. Safe, non-destructive defaults are allowed and encouraged where they don't risk merchant data — most notably, **automation/scheduling defaults to off until the merchant explicitly enables it** — and must be clearly visible as the current state, not hidden. This criterion exists to prevent silent data-changing assumptions, not to turn setup into a mandatory questionnaire for every configurable value regardless of consequence.
7. First sync is never automatic/silent — the dry-run/preview step (§4.3) is not skippable.
8. Nullable boolean/tri-state fields render three states, not two (§14).

A connector UI PR that fails any of these must be corrected before merge, following the project's existing "known regressions cannot cross merge boundaries" discipline — not merged with a follow-up-task note.

---

## Relationship to prior work

- Final micro-pass before approval: Layer-as-ceiling-not-grant (§1); layer-specific sensitive-data test scoping resolving a §12/§15 conflict (§15); explicit existing-vs-future boundary for sync/preview/scheduling/history/bulk-resolution UX (intro); softened §15 criterion 6 to permit safe non-destructive defaults and avoid forcing a fake choice on single-behavior connectors.
- Corrected against direct code verification (real `ConnectorCapability` enum/config, real `connector_accounts` unique constraint, real `lang/uk.json` snapshot-worded strings) — see §1 (status/authority, Layer C audience, redaction), §2 (capability rule precision), §3 (cardinality), §4 (connector-agnostic setup inputs), §7 (Field Browser copy-vs-architecture split), §15 (test-type split).
- Supersedes `connector-ux-design-reference.md`'s three-level model (§2 there) with the four-layer model here (§1).
- Retains, unchanged, that document's evidence base (Akeneo, Shopify, Celigo, Zapier, QuickBooks Connector, NN Group progressive disclosure, bidirectional-sync conflict-resolution literature) — see that document's §8 for full citations.
- Adds, beyond that document: the explicit binding of "capability-driven" from principle to the real `ConnectorCapability` enum (§2); the fourth layer separating merchant-Layer-B from operator-Layer-C (§1); removal of the global "our platform is the default owner" assumption in favor of a per-domain question with no hardcoded default (§6); the explicit visibility table (§12) and forbidden-vocabulary list (§13) as directly testable acceptance criteria (§15).
- **Historical note (post-4C-1c-2a):** Connector runtime, snapshot persistence, and core Field Browser read architecture remain usable building blocks. Authorization boundaries were subsequently rebaselined by **Task 4C-1c-2a** (`docs/03-DOMAIN_MODEL.md` → Workspace access model and authorization). **Historical pre-B-2:** fixed `User.role` authorization and `ConnectorAccountMerchandiserPresentation` were transitional under **GAP-026** / PR #102 — superseded in the repository by **GAP-026B-2** workspace-RBAC matrix and `ConnectorAccountCapabilityPresentation`; reference-environment production **EXECUTE** completed 2026-08-14. Required authorization-foundation work was therefore **not** merely navigation/labeling/gating UI work — this historical bullet does not define a second normative authorization contract.

---

## 16. Merchant Preview authorization and remediation (Resolved — Stage 2-0)

Normative detail: `docs/03-DOMAIN_MODEL.md` → **Merchant Preview Authorization &
Remediation Contract (Resolved — Stage 2-0)**. Summary-level UI rules:
`docs/06-UI_DESIGN_SYSTEM.md` → Merchant Preview interaction rules.

**Implementation status:** Stage 2-0 is **docs-only**. Stage 1 Preview Engine
(`run_sync_preview`, admission, persisted Preview runs) is **shipped**. Stage
2A-1 is **shipped** (`manage_sync_configurations`, non-mutating existence lookup,
Adobe Products Export Layer-B setup on `ListSyncDataSetup` + `ManageAdobeProductsExportSetup`).
**Stage 2A-2 is shipped** — merchant Preview work surface on
`ManageAdobeProductsExportPreview`, evolved `ListSyncDataSetup` landing
(independent Setup/Preview actions), advisory read model, explicit start via
admission, lifecycle, completed summary, Needs-attention worklist, contextual
remediation presenters, and explicit no-bulk-action exception. **Stage 2B is
shipped** — Option Mapping remediation UI on `ManageSyncFieldOptionMappings`
(uses existing `view_sync_mappings` / `manage_sync_mappings` permissions only;
read from authoritative persisted connector snapshot metadata with zero HTTP on
read; `confirm`/`replace` retain connector external validation outside locked DB
transaction; Preview findings remain historical after remediation; narrow
stale/orphan option-mapping cleanup does **not** fix Product/Variant select value
integrity). Stage 3B–3E merchant Live execution remains pending.

### Layer-B SyncConfiguration management authority

`manage_sync_configurations` (ninth runtime permission; **implemented Stage 2A-1**) authorizes merchant-facing mutation of SyncConfiguration-owned setup —
including Adobe Products Export connector execution configuration (attribute set
selection on `ManageAdobeProductsExportSetup`). Merchant discoverability for
`manage_sync_configurations`-only actors uses `ListSyncDataSetup` navigation —
not `ViewConnectorAccount`. It is independent from
`manage_connector_accounts`, Mapping permissions, and `run_sync_preview`. Do not
expose `attribute_set_id` as merchant terminology. Setup reachability uses
`ConnectorAccount` + Products + Export + default context — not a pre-existing
`SyncConfiguration` UUID.

### Merchant Preview safe-read authority

`run_sync_preview` authorizes Preview execution and the minimum merchant-safe
run-relevant setup **read** projection. It does **not** authorize hidden
SyncConfiguration creation or mutation. Preview-only actors who lack setup must
see a setup-required state without triggering `ensure*()` mutators.

### Setup-required UX

Pre-Preview setup failure (admission/readiness): _Потрібно завершити налаштування
перед перевіркою._ Without `manage_sync_configurations`: _У вас немає доступу до
цієї настройки._ Do not convert admission failures into fake Product-level
findings. Completed Preview findings (e.g. `AttributeSetInvalid`) are a separate
layer — route to connector setup remediation when authorized.

### Remediation and actionability

Remediation uses **historical** `SyncRunItem.findings` + run
`configuration_snapshot` as cause, and **current** authorization + destination
existence for actionability. Do not persist remediation classification; do not
create `SyncIssue`.

Presentation dimensions (no DB enum):

- **Remediation area:** `PRODUCT_DATA`, `VARIANT_DATA`, `FIELD_MAPPING`,
  `OPTION_MAPPING`, `CONNECTOR_SETUP`, `PRICING`.
- **Current actionability:** `ACTION_AVAILABLE`, `VIEW_ONLY`, `PERMISSION_REQUIRED`,
  `NO_EDIT_SURFACE`, `CURRENT_CONFIGURATION_CHANGED`.

### No fake Fix

Show _[Виправити]_ only when an authorized edit surface exists for the affected
value. `NO_EDIT_SURFACE` is the dominant case for Product/Variant-data findings
today. _[Відкрити товар]_ may provide context without implying edit authority.

### Current vs historical rule

When configuration drift makes a historical finding's remediation target unsafe,
suppress the misleading action and recommend rerun: _Налаштування змінилися після
цієї перевірки. Запустіть перевірку ще раз._ Matching `configuration_revision`
does not prove Product data is unchanged — explicit rerun required after Product
edits.

### Preview outcomes

Merchant Preview surfaces use exactly three outcome buckets: ready / warning /
blocked. Current Adobe implementation may show zero warnings — that is not a
permanent contract. Do not depict nonzero warnings as the typical case in Stage
2A examples when they cannot currently occur.

---

## 17. Merchant First-Live UX (Resolved — Stage 3-0)

Normative detail: `docs/03-DOMAIN_MODEL.md` → **Live Safety, Identity & First-Live
Contract (Resolved — Stage 3-0)**. Summary-level UI rules:
`docs/06-UI_DESIGN_SYSTEM.md` → Merchant First-Live interaction rules.

**Implementation status:** Stage 3-0 is **docs-only**. No merchant Live action,
Adobe write, or support-truth flip ships in the Stage 3-0 slice. **Stage 3A Live
Safety foundation is shipped** — `run_sync_live` runtime permission, stale
active-run recovery, `ExternalRecordLink` persistence foundation,
`SyncLiveAdmissionService`, and fail-closed `SyncLiveRunJob` shell (no Adobe
write). **Stage 3D merchant first-Live UI/read model is shipped (internal)** on
`ManageAdobeProductsExportPreview` — dual-authority page presence, Live read
model, worklist/result presentation, and dormant `startLive()` admission path;
merchant consequential Live action remains **non-actionable** while
`ConnectorSyncOperationSupport(Products, Export, Live) === false`. Adobe Product
and media Live write/reconciliation runtime is **implemented internally** through
normative Stage 3D (3B/3C/3D-1/3D-2); advertised Live support remains **false**.
Merchant consequential exposure and production enablement wait for Stage 3E
real-target validation and truthful support flip.

### Smallest first-Live surface

First manual Live admission belongs on `ManageAdobeProductsExportPreview`. Do
**not** turn `Інтеграції` into an execution console.

### Live authority

`run_sync_live` (tenth normative permission; **implemented Stage 3A**) is
independent from `run_sync_preview`, `manage_sync_configurations`, Mapping
permissions, and connector-account permissions. Preview permission never implies
Live authority. `run_sync_live` is **authority only** — it does **not** mean
connector/runtime currently supports Live. Possession of `run_sync_live` does
**not** imply `ConnectorSyncOperationSupport(Products, Export, Live) === true`.
Merchant consequential Live execution remains gated by that support-truth check
until the Stage 3E flip.

### Merchant consequential Live admission gates

All gates are mandatory before actionable merchant consequential Live
admission/exposure:

- fresh `run_sync_live`;
- relevant Completed current-revision Preview (`products` / `export` / Preview /
  same `SyncConfiguration`);
- current configuration/account readiness;
- `ConnectorSyncOperationSupport(Products, Export, Live) === true`.

Preview prerequisite is **trust/readiness** — not executable Live support.
Stage 3D must not bypass `ConnectorSyncOperationSupport` or expose a consequential
Live action while Live support remains **false**. Actionable merchant exposure
happens only after Stage 3E real-Adobe validation and truthful support flip.

### Preview prerequisite

When **all** admission gates are satisfied (including truthful Live support),
Preview summary may guide merchant confirmation but must **not** be described as
the frozen payload Live will send.

### Merchant confirmation copy

Concept:

> Передати товари в Adobe Commerce?
>
> Це реальна дія — дані будуть передані у ваш магазин. Перед передачею ми ще
> раз перевіримо актуальні дані товарів.

If Preview contained blocked Products, explain that Products still not ready
during the fresh Live check will not be changed externally.

### Running and completed states

Running: honest queued/running; optional processed Product count from persisted
outcomes; no fake percentage. Completed vocabulary: _Синхронізовано_ / _Не
передано_ / _Частково синхронізовано_ / _Не вдалося підтвердити_. `AMBIGUOUS`:
_Не вдалося підтвердити результат для N товарів. Не повторюйте передачу, доки
їхній стан не буде перевірено._

### Forbidden merchant exposure

Do **not** expose `ExternalRecordLink`, idempotency, reconciliation, transport
attempts, HTTP codes, Adobe entity IDs, raw payloads, or connector internals in
Layer A/B.

### No selective retry

"Retry failed only" is explicitly **out** of Stage 3 V1. Recovery path: remediate /
verify → Preview when required → new all-products Live execution.

---

## 18. Per-item Live linking (Resolved — Stage 3E docs contract)

Normative detail: `docs/03-DOMAIN_MODEL.md` → **Stage 3E Stop-and-Amend — Magento
ownership and entity-bound Safe Sync runtime contract**. Summary-level UI rules:
`docs/06-UI_DESIGN_SYSTEM.md` → Per-item Live linking.

**Implementation status:** Per-item Live linking is split across two shipped
slices plus remaining truth-flip prerequisites.

- **Stage 3E-R2a (shipped)** — per-item ownership/ERL-provenance rewrite
  (foundation prerequisite for per-item link lifecycle and ownership
  assertions).
- **Stage 3E-R2b-1 (shipped — backend only)** —
  `AdobeProductEntityTrustReviewService`,
  `AdobeProductEntityTrustConfirmationService`,
  `AdobeProductEntityTrustLinkReadinessProjector`, and
  `AdobeProductEntityTrustAuthorizationService` enforce the dual-permission
  contract (`manage_sync_configurations` **and** `run_sync_live`) with fresh
  checks before remote HTTP and again under Workspace row lock before ERL
  persistence. Review envelopes authenticate `explicit_relink` intent,
  per-subject Magento `logical_entity_id` + controlled-field fingerprint, and
  the configured Adobe target snapshot (`base_url + store_code`).
  `ConnectorAccountSettingsService` rejects target-defining account changes
  while trusted merchant-confirmed ERLs exist. Merchant Filament/Livewire
  confirmation UI is delivered as **Stage 3E-R2b-2** (below).
- **Stage 3E-R2b-2 (shipped — merchant confirmation UI)** — per-item
  link-trust presentation and action surface on
  `ManageAdobeProductsExportPreview` (Live worklist / item context). The
  surface renders the `AdobeProductEntityTrustLinkReadinessProjector` output
  (`initial_link_required` / `reconfirmation_required` /
  `relink_review_required` / `already_confirmed` / `no_action`) and exposes
  the dual-permission `Confirm link`, `Re-review`, and explicit `Renew link`
  (relink) actions over the Stage 2-0 contract. All 19
  `EntityTrustFailureReason` cases are exhaustively mapped to merchant-safe
  copy via `EntityTrustFailureReasonPresenter`. The `reviewToken` is **never**
  a Livewire public property: review state lives in a server-side
  `EntityTrustReviewFlowStore` keyed by an opaque short-lived
  `EntityTrustReviewFlowId` (10-minute TTL, single-consume on Confirm).
  Hydrated working-set rows, family flags, and parent-SKU fields are
  presentation/merchant-input only; product eligibility, readiness, family
  routing, and confirm binding remain server-derived or flow-bound authority.
  `existingParentSkuHint` merchant input is wired through
  `EntityTrustConfirmationMode::ConfigurableExistingParent`. Comprehensive
  R2b-2 feature tests cover authorization, readiness, review-flow security,
  confirm, stale-flow fail-closed, conflict handling, vocabulary, and
  configurable-family support.

Truthful Adobe Products/Export/Live advertised support remains **false**.
Both R2b slices ship the _necessary_ link-trust mechanism, but they are
**not sufficient** for the exemplary consequential Live truth flip. The flip
still requires:

- **real-target certification** of the **actual standard shipping
  implementation** for every advertised V1 consequential Live mutation
  category, proving all still-frozen safety and domain invariants
  (entity trust; `ExternalRecordLink` / `entity_id` identity authority; SKU
  equality/precondition; no blind ambiguous retry; Preview-first; no
  automatic Product create V1; post-write verification; fail-closed
  identity uncertainty);
- the still-frozen authorization, verification, and merchant consent
  contracts (Stage 2-0 / Stage 3-0 / Stage 3A admission, dual-permission
  enforcement, opaque review-flow store, exhaustive `EntityTrustFailureReason`
  presentation, post-write verification, and fail-closed Live
  authorization) remaining effective against that certified implementation.

The current first-party Magento entity-bound Safe Sync implementation may
remain current-runtime evidence and / or an optional Enhanced Safety
primitive, but it is **not** a mandatory product prerequisite under the
Post-#168 / Post-D6 moduleless-by-default decision.

Until real-target certification is met, merchant consequential Live action
remains non-actionable and the **Magento** tile keeps the **false** truth
flag for Adobe Products/Export/Live.

### Presentation boundary

Linking belongs to **per-item Live readiness/remediation** on
`ManageAdobeProductsExportPreview` (Live worklist / item context). It is **not**:

- `SyncLiveMerchantSetupBarrier`;
- a Preview finding or Preview remediation surface;
- a Preview HTTP lookup or existence-check mutation.

Preview readiness does **not** imply Live applicability.

### Unlinked Product in Live surface

When a Product lacks sufficient trust for consequential Live:

- outcome remains `SyncLiveOutcome::NotApplied` (no fifth Live outcome);
- merchant-safe linking reason is distinguishable from other `NotApplied` cases;
- item appears in the Live worklist;
- contextual link/reconfirmation action is offered when authorized.

Do **not** add a per-product case to the run-level setup barrier for linking.

### Link confirmation authorization

Link-confirmation mutation authority requires **both**, fresh for the current
Workspace:

- `manage_sync_configurations` — setup authority to assert synchronization
  ownership/configuration;
- `run_sync_live` — Live authority because the assertion authorizes future
  consequential external mutation.

Neither permission alone is sufficient. Do **not** create a new permission in
Stage 3E. Revocation of either before confirmation must fail closed.

**Stage 3E-R2b-1 (shipped — backend) and Stage 3E-R2b-2 (shipped — merchant
UI):** `AdobeProductEntityTrustReviewService` and
`AdobeProductEntityTrustConfirmationService` enforce this dual-authority
contract with fresh checks before remote HTTP and again under Workspace row
lock before ERL persistence. Review envelopes authenticate `explicit_relink`
intent, per-subject Magento `logical_entity_id` + controlled-field
fingerprint, and the configured Adobe target snapshot
(`base_url + store_code`). `ConnectorAccountSettingsService` rejects
target-defining account changes while trusted merchant-confirmed ERLs exist.
`AdobeProductEntityTrustLinkReadinessProjector` exposes current/prospective
link readiness without historical `SyncRunItem` rewriting. The
`ManageAdobeProductsExportPreview` Live area renders that readiness and
exposes dual-permission `Confirm link` / `Re-review` / `Renew link` actions
backed by a server-side opaque review-flow store (see
`§18 Implementation status` above).

### Informed merchant confirmation

Before confirmation the merchant must see a concise controlled-field comparison:

- platform value vs current Magento value for fields the connector will own/update.

Merchant action concept:

> Пов’язати з цим товаром Magento

with clear explanation that subsequent synchronization may update those fields. A
bare checkbox alone is insufficient.

Confirmation flow must include a fresh read-only Magento GET, remote record
classified Found, workspace + `ConnectorAccount` verification, collision checks,
remote type compatibility, exact SKU match for simple/child subjects, explicit
merchant confirmation, and capture of remote logical discriminator — per domain
contract. Stale cached discovery alone may not establish trust.

### Forbidden merchant exposure

Do **not** expose `ExternalRecordLink`, Magento `entity_id`, discriminator,
ownership policy, reconciliation, transport attempts, HTTP codes, or raw payloads
in Layer A/B.

### Link-first necessary but not sufficient

Merchant-confirmed linking establishes ENTITY TRUST (logical Magento Product
identity via stored `entity_id` discriminator), not SKU trust. Expected SKU remains
a mandatory equality precondition but is not identity authority. After trust exists,
stock SKU GET must not prove verification/reconciliation/applied state.

Pre-trust candidate discovery may use bounded stock SKU lookup; final confirmation
must freshly verify exact logical entity + expected SKU.

Therefore link-first + entity trust + informed confirmation are **required** but
**not sufficient** for exemplary consequential Live. Truth flip waits for
**real-target certification** of the **actual consequential WRITE
implementation** against all still-frozen safety invariants (entity trust;
`ExternalRecordLink` / `entity_id` identity authority; SKU
equality/precondition; no blind ambiguous retry; Preview-first; no
automatic Product create V1; post-write verification; fail-closed identity
uncertainty) for every advertised V1 Live mutation category.

The current first-party Magento entity-bound Safe Sync implementation may
remain current-runtime evidence and / or an optional Enhanced Safety
primitive, but it is **not** a mandatory product prerequisite under the
Post-#168 / Post-D6 moduleless-by-default decision.

---

## 19. Connector Account Overview — connection confidence and one next step

[Resolved — production-validation rebaseline — 2026-09-05]

The normal Magento Connector Account Overview (Layer A) has one product purpose:
**connection confidence + one next step**. This decision supersedes the earlier
verify-to-preview Overview reference wherever it conflicts.
It does not claim that the underlying runtime migration is already complete and does not
change connector capability truth.

### 19.1 Normal healthy presentation

The healthy Overview presents, in this order:

```
Magento · [merchant/account name]

🟢 Підключено
Перевірено [human-friendly time]

[Перевірити ще раз]
Перевірка не змінює дані в Magento.

НАСТУПНИЙ КРОК
[one lifecycle action, or a calm authorization explanation]
```

The account name remains the account-owned dynamic value. Overview must not expose the
internal `ConnectorAccount` enum wording when it differs from the approved merchant copy.
Re-check remains a secondary action under existing authorization and active-check rules and
is read-only: it must never mutate Magento.

Connection truth is independent from downstream capability truth. A field-metadata,
mapping, Preview, Live, or other downstream capability problem must not turn an otherwise
successful baseline connection red. In particular, a Magento ACL denial for optional field
metadata is not bad-credentials evidence and must not be presented as such.

### 19.2 Evidence and surface ownership

Catalogue, product-field, and media probes remain authoritative runtime, support, and
certification evidence. Their collection, safe persistence, Discovery, schema snapshots,
Available Fields, and Mapping behavior remain intact. They are **not** permanent rows on a
normal healthy merchant Overview, and catalogue/field/image counts are not health KPIs.

Surface ownership is:

- field inventory and mapping details → **Mapping / Available Fields**;
- concrete Product/data findings and remediation → **Preview/worklists**;
- transport, schema incompatibility, and other technical connector failures → internal
  runtime/support diagnostics.

Overview does not add a generic “fields need attention” warning or arbitrary field-error
count. A future merchant field warning is permitted only when the condition is genuinely
merchant-actionable, cannot be resolved safely by the system, has an authorized concrete
remediation destination, and explains the decision the merchant must make. That behavior is
not implemented by this campaign; Preventive Field Mapping is separate work.

### 19.3 Exactly one next step

For the Products/default lifecycle, Overview renders exactly one outcome:

1. no Products/default `SyncConfiguration` + setup-authorized and eligible actor →
   **`Налаштувати синхронізацію`**;
2. valid Products/default configuration + Preview-authorized actor →
   **`Створити пробну синхронізацію`**;
3. an actor without the required authority receives a calm, non-actionable explanation that
   an authorized workspace administrator must perform setup; the `НАСТУПНИЙ КРОК` container
   is never empty.

An existing Products/default configuration follows the same distinction for Preview: an
actor without `run_sync_preview` receives a permission/access explanation, while an actor
with Preview authority whose target/profile eligibility cannot currently be resolved receives
a technical-support/unavailable explanation. Technical unavailability must not be
misclassified as an administrator-permission problem.

An unrelated configuration must not advance this lifecycle. Overview exposes no first-Live
CTA while `ConnectorSyncOperationSupport(Products, Export, Live)` is false and never claims
`Синхронізація працює` before persisted, successful real Live evidence. Preview remains
read-only and Preview-first contracts remain authoritative.

The merchant Preview surface must retain the reassurance **`Пробна синхронізація не змінює
дані в Magento`**. Preview never implies an external write. The first consequential Live
operation is available only after qualifying Preview evidence and every existing Live support,
authorization, and admission gate permits it; this Overview contract neither bypasses nor
redefines those owners.

Layer A/B continue to forbid HTTP status codes, OAuth/ACL identifiers, endpoint paths,
schema/snapshot/Discovery/canonical-hash vocabulary, Safe Sync internals, framework or module
versions, raw payloads, and internal probe/handshake/readiness names. Such technical evidence
belongs to authorized support/runtime diagnostics.

### 19.4 Acceptance anchors

Mechanical protection must prove the approved connected and checked copy; safe secondary
re-check; absence of catalogue/field/image rows, catalogue counts as KPIs, generic field
warnings, and unsupported Live actions; exact setup/Preview CTA cardinality; unrelated
configuration isolation; non-empty unauthorized state; stale/missing-profile resilience;
preserved probe/Discovery/schema-snapshot behavior; merchandiser no-runtime-query behavior;
and complete English, Ukrainian, and Russian localization.

## 20. Connector Account Overview implementation reference

[Resolved — production-validation rebaseline — 2026-09-05]

The Magento implementation follows §19. The account Overview consumes connection state,
human-friendly last-successful-check time, the existing safe re-check action, Products/default
configuration identity, and the existing setup/Preview authorization and profile-support seams.
It does not read persisted catalogue/media evidence merely to render the healthy Overview.

The underlying connection check may continue recording bounded `catalog_total_count` and
media-readability evidence, and Discovery may continue persisting field/schema snapshots.
Removal from Layer A is presentation-only and must not weaken those runtime/support contracts.
Mapping and Available Fields remain unchanged. No new authorization permission, role default,
or broad grant is introduced: absence of `manage_sync_configurations` explains the
non-actionable setup state instead of hiding an empty card.

This implementation-specific copy does not rebaseline the separate `Інтеграції` landing
surface: its page contract and evidence-scoped `Підключення перевірено` status remain intact.
