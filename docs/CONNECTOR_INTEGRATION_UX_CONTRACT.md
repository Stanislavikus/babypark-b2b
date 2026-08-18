# Connector Integration UX Contract

**Status:** Approved normative connector UX contract

**Approval date:** 2026-08-10

**Authority:** This document is the consolidated normative reference for connector-facing UX. Where `docs/06-UI_DESIGN_SYSTEM.md` or `docs/03-DOMAIN_MODEL.md` summarize connector UX or domain boundaries, they link here for full detail. Upon conflict between those summaries and this contract, this document wins for connector UX rules until the summaries are explicitly updated to match.

**Scope:** Every current and future connector-facing surface — Magento today; Shopify, Google Sheets, 1C, marketplaces, and any subsequent connector tomorrow. A connector UI is not acceptance-tested against "does it look like Magento's" — it is tested against this contract, §15.

**Non-goal:** This contract does not itself authorize arbitrary backend work and is not the workspace-permission implementation specification beyond the approved domain authorization contract in `docs/03-DOMAIN_MODEL.md` → **Workspace access model and authorization (Resolved — Task 4C-1c-2a)** and **Preview-first Sync Execution Foundation Contract (Resolved — Task 4C-2a)** / **Merchant Preview Authorization & Remediation Contract (Resolved — Stage 2-0)**. Some underlying mechanisms are already shipped (for example `ConnectorCapability`, Discovery runtime, snapshot persistence, workspace isolation guards, Layer-B Mapping UI on `ManageSyncFieldMappings`, Mapping → Available Fields supporting reference with workspace-scoped Mapping authorization, Stage 1 Preview Engine with `run_sync_preview` and persisted zero-mutation Preview runs, Stage 2A-1 `manage_sync_configurations` runtime permission and Adobe Products Export Layer-B setup, Stage 2A-2 merchant Preview work surface and remediation presentation, **Stage 2B Option Mapping remediation UI on `ManageSyncFieldOptionMappings`**). Missing backend/runtime/security prerequisites require their own scoped tasks. Specifically, Task **4C-1c-2b** Layer-B Mapping UI and its Mapping-side Available Fields supporting path are shipped (PR #139, merge `9a4be2f`). Task **4C-2a** freezes Preview execution architecture (docs only); **`run_sync_preview` runtime and `SyncRun` Preview execution are implemented in Stage 1** (PR #145). **Stage 2-0** freezes merchant Preview authorization/remediation contract (docs only). **Stage 2A** (2A-1 + 2A-2) is **shipped** — `manage_sync_configurations`, non-mutating existence lookup, Adobe Products Export setup, merchant Preview UI, and contextual remediation presentation. **Stage 2B is shipped** — Option Mapping remediation on `ManageSyncFieldOptionMappings` using existing `view_sync_mappings` / `manage_sync_mappings` permissions only. Mechanisms that explicitly remain future include scheduling, issue aggregation/bulk resolution, sync-run history, ownership persistence/enforcement, broader Layer-C platform-support identity/gating, and **Stage 3A–3E** Live Engine implementation slices (**Stage 3-0** Live Safety contract is **Done (docs)**). Do **not** claim that historical pre-B-2 fixed `User.role` authorization satisfies this UX contract — that transitional behavior is historical evidence only under **GAP-026** / PR #102.

**Existing-vs-future boundary:** This contract defines the *required UX* for synchronization, preview/dry-run, scheduling, mapping, issues, history, and bulk resolution *when those surfaces/concerns are implemented*. Normative sync domain shape is now settled in `docs/03-DOMAIN_MODEL.md` (Sync Domain Rebaseline: `SyncConfiguration` → `FieldMapping` + `SyncRun` → `SyncRunItem`, account-scoped `ExternalRecordLink`). **Preview computation/runtime is shipped** — Stage 1 Preview Engine delivers persisted zero-mutation Preview (`run_sync_preview`, admission, Preview `SyncRun` persistence). **Stage 2A-2 merchant Preview work surface and remediation presentation are shipped**; **Stage 2A is Done**. **Stage 2B Option Mapping remediation UI is shipped** on `ManageSyncFieldOptionMappings` (existing `view_sync_mappings` / `manage_sync_mappings` permissions only; authoritative persisted connector snapshot metadata on read with zero HTTP; `confirm`/`replace` retain connector external validation outside locked DB transaction; Preview findings remain historical after remediation; narrow stale/orphan option-mapping cleanup does **not** fix Product/Variant select value integrity). Live consequential sync execution (**Stage 3A–3E**; **Stage 3-0** docs contract **Done**), scheduling beyond Discovery, issue aggregation, bulk resolution, sync-run history, ownership persistence/enforcement, and broader merchant sync surfaces remain future implementation gaps requiring their own scoped passes before the corresponding UI ships. This contract does **not** assert that every entity or runtime mechanism exists beyond what is confirmed elsewhere in this document — but a reader must **not** conclude that dry-run/preview computation is still absent. Those platform-owned sync UX/orchestration concerns do **not** become `ConnectorCapability` cases merely because they are optional or future.

---

## 1. Audiences and the four layers

| Layer | Question it answers | Audience | Contains |
|---|---|---|---|
| **A — Щоденна робота** | "Is my integration okay right now?" | Workspace merchant users when authorized by workspace permissions | Status, last sync, count of items needing attention, one action |
| **B — Налаштування даних** | "How do I control what/how this syncs?" | Workspace merchant users when authorized by workspace permissions | Direction per data type, schedule, field mapping, ownership, available-fields reference |
| **C — Діагностика** | "What technically happened?" | **Platform support/operator** — separate identity; never any workspace merchant role/access profile | Discovery runs, snapshots, technical status codes, redacted diagnostic data (see rule below) |
| **D — Каталог конекторів** | "How does our platform talk to X?" | Platform operator / developer | `ConnectorDefinition`, schema sources, endpoints, auth profiles, verification status |

**Rule:** Layers A/B audience means workspace merchant **memberships** authorized by workspace-scoped atomic permissions defined in `docs/03-DOMAIN_MODEL.md` → **Workspace access model and authorization (Resolved — Task 4C-1c-2a)**. Business-owned role/access profile names (Admin, Director, Merchandiser, …) do **not** authorize connector, mapping, or Layer C access by themselves.

**Rule:** Layer C requires a distinct platform-support identity/permission. If no such identity model exists yet at implementation time, Layer C is unavailable to **all** workspace merchant role/access profiles — it does not default to any workspace merchant membership regardless of business-owned name. That is a separate architectural decision requiring its own scoping pass.

**Rule:** Layer assignment is a visibility ceiling, not an authorization grant. A surface living in Layer A/B does not automatically authorize every action within that layer. Workspace-scoped atomic permissions remain authoritative and may further restrict individual pages/actions. Connector setup, synchronization settings, scheduling, ownership choices, credential changes, mapping mutation, and destructive actions require explicit permission bundles — never inference from Layer B eligibility or from a business-owned role name. Normative authority: `docs/03-DOMAIN_MODEL.md` → **Workspace access model and authorization (Resolved — Task 4C-1c-2a)** and **ConnectorAccount authorization (Resolved — rebaselined Task 4C-1c-2a)**. **026B repository status (post-B-2):** connector authorization follows the frozen workspace-permission matrix via `ConnectorAuthorization` / `WorkspaceAuthorization`; historical pre-B-2 fixed `User.role` checks are transitional evidence only under **GAP-026** / PR #102.

**Rule (connector safe presentation — Resolved — GAP-026B-0, 2026-08-13):** Connector presentation is **capability-based**, never job-title-based. Safe-only projection must exclude sensitive/configuration state (`credentials`, `settings`, `base_url`, `store_code`, `tenant_context`, `auth_profile`, and management-only connection-check state) **before** merchant-facing Livewire/Filament record serialization — not merely through visual hiding. Legacy `User.role` labels must not widen or restrict presentation relative to effective workspace permissions. Normative detail: `docs/03-DOMAIN_MODEL.md` → **Workspace RBAC authority cutover (Resolved — GAP-026B-0, 2026-08-13)**.

**Rule:** Layer C does not mean "raw payloads become visible." Reaching Layer C never lifts the project's existing secret-redaction, credential-encryption, or workspace-isolation rules. Credentials never become support-visible merely because a person has a Layer C-capable identity. "Diagnostic" means redacted technical detail (status codes, cause categories, timestamps, non-secret identifiers) — not unredacted raw request/response bodies or decrypted credentials.

**Rule:** The word **"Discovery"**, **"Знімок"**, **"Snapshot"**, and **"Schema source"** never appear in Layer A or Layer B UI text. They are Layer C vocabulary. A Layer B surface may show *derived* information (e.g. "коли ми востаннє перевіряли доступні поля") without naming the underlying mechanism.

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

`Інтеграції` is the workspace/merchant surface for connecting and managing external systems. It answers conceptually: *is this external system connected?* It is not the merchant surface for catalog work and must not become the technical sync builder.

Sync configuration, mapping, preview, first manual live run, schedule, results, and remediation belong to merchant sync/data-management surfaces. Normative sync domain model and merchant journey are defined by the Sync UX / Domain Rebaseline in `docs/03-DOMAIN_MODEL.md` (and summarized in `docs/06-UI_DESIGN_SYSTEM.md`). `Каталог і синхронізація` must not currently be represented as an established navigation group merely because it appears in the future roadmap; the current standalone top-level placement of `Інтеграції` is an intentional interim use of standard Filament ungrouped navigation behavior, not the final navigation IA.

Replaces `Платформи та джерела` as the merchant's entry point. The platform is the entry point; the exact card composition must correctly represent however many `ConnectorAccount` rows actually exist for that platform in the workspace.

**Confirmed directly from the schema:** the real unique constraint on `connector_accounts` is `(workspace_id, connector_definition_id, active_name_uniqueness_key)` — a workspace can have **more than one** account for the same platform (e.g. two separate Magento stores), distinguished by name. This contract does not assume a singleton connection per platform.

**Settled by page-specific contract:** the 0/1/N card composition, adaptive destinations, health rollup, merchant-safe platform catalog, and related acceptance criteria for this landing page are defined in `docs/CONNECTOR_INTEGRATSII_PAGE_UX_CONTRACT.md` (platform-first cards; 0 → setup, 1 → account Overview, N → platform account list). Active-but-not-connectable definitions (no enabled `AccountSetup` profile) are excluded from the 0-account landing set — `Інтеграції` is not a roadmap catalog.

For the single-connection case, the composition follows the account-connection pattern (status + identity + single action — no connector internals):

```
Magento                          Google Sheets
🟢 Підключено                    ➕ Не підключено
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

The default landing tab for an opened integration. Answers exactly one question truthfully: *is everything okay right now?*

```
Magento          🟢 Підключено
Остання синхронізація: сьогодні, 14:32 · Наступна: 15:00
[Синхронізувати зараз]

987 синхронізовано · 31 пропущено · 6 помилок
⚠ 37 товарів потребують уваги →
```

**Rule:** If synchronization is currently failing in a way that could misrepresent price or stock, that fact is on this screen, not behind a click — progressive disclosure never hides an active, consequential problem (see §1's Layer boundary; this is the one place Layer A content is non-negotiable regardless of visual density preferences).

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
  Repeated per data domain (Ціни, Описи, Залишки, ...) that is bidirectionally enabled. No connector ships a hardcoded default answer — this is a per-merchant, per-domain product decision, not inferred silently. Do not introduce mandatory per-field authority before that product need exists. (Storage/enforcement mechanism remains a backend decision requiring its own scoping pass; this contract fixes the *question asked to the merchant*, not yet the storage/enforcement design.)

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

| Category | Merchant sees | Implies |
|---|---|---|
| Потрібно виправити товар | "У 18 товарів відсутній EAN" | Merchant edits source data |
| Потрібно зіставити значення | "Magento не знає значення кольору «Ocean Blue»" | Merchant maps the value once |
| Потрібно перевірити підключення | "Magento більше не дозволяє доступ" | Merchant reconnects |
| Тимчасова проблема | "Наступна спроба через 5 хвилин" | System retries automatically |

**Rule:** Adding a new backend error code requires assigning it to one of these four categories (or proposing a fifth, with the same category-not-code discipline) before it may be surfaced anywhere in Layer A/B.

---

## 11. Bulk resolution — mandatory once a category exceeds a handful

**Rule:** Any issue category with more than a small, fixed threshold of affected items (exact threshold to be set during the Issues page's own implementation pass, not fixed here) must offer a single bulk action, not force one-by-one resolution. A category whose fix genuinely cannot be batched (e.g. requires distinct manual mapping per item) must say so rather than silently omitting the bulk option.

---

## 12. Merchant/operator visibility boundary — explicit table

| Concept | A | B | C | D |
|---|---|---|---|---|
| Connection status, last sync | ✓ | ✓ | ✓ | ✓ |
| Sync direction, schedule | | ✓ | ✓ | ✓ |
| Field mapping matrix, available fields | | ✓ | ✓ | ✓ |
| Categorized issues, bulk fix | ✓ (summary) | ✓ (full) | ✓ | ✓ |
| Sync run history | | ✓ | ✓ | ✓ |
| Discovery run / snapshot identifiers | | | ✓ | ✓ |
| Canonical hash, technical summary, raw error code | | | ✓ | ✓ |
| Endpoint path, source kind, auth profile | | | | ✓ |
| Connector definition, schema source catalog | | | | ✓ |

This table is authoritative. A page proposal that puts a row's concept in a column to the left of its marked cell is non-compliant with this contract and must be corrected before merge, not merged with a note to fix later (consistent with the project's existing rule that known regressions may not cross a merge boundary).

---

## 13. Forbidden merchant-facing vocabulary

The following terms, and direct translations of them, must never appear in Layer A or Layer B UI text, error messages, empty states, or notifications, regardless of how accurately they'd describe the underlying mechanism:

`schema source` / `джерело схеми` · `snapshot` / `знімок` · `canonical hash` · `discovery run` · `account_api` / `live_fetch` / any `acquisition_mode` value · `schema_scope` value as a raw label (`global`/`website`/`store` must be translated to their §6/§7 human phrasing, never shown raw) · `endpoint path` · `auth profile` · raw HTTP status codes · raw backend error-code strings.

These remain exactly as-is in Layer C/D and in code — this rule constrains *rendering*, not the domain model or internal naming.

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
   - **Sensitive-data enforcement is layer-specific, per §12.** A/B tests assert that data §12 permits only in C/D (canonical hash, technical summary, raw error code, credentials, endpoint path, etc.) never enters merchant-rendered content *or* serialized Livewire snapshot/effects state on an A/B surface — the existing, stricter canary-test pattern already used for sensitive-field absence applies here unchanged. A future Layer C surface is tested the same way against whatever §12 permits only in D (credentials, raw request/response bodies). Secret-redaction, credential-encryption, and workspace-isolation rules apply at every layer without exception (§1) — canary tests target the specific data forbidden on the surface under test, not every technical attribute globally regardless of layer.
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
integrity). Stage 3 remains pending.

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

Pre-Preview setup failure (admission/readiness): *Потрібно завершити налаштування
перед перевіркою.* Without `manage_sync_configurations`: *У вас немає доступу до
цієї настройки.* Do not convert admission failures into fake Product-level
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

Show *[Виправити]* only when an authorized edit surface exists for the affected
value. `NO_EDIT_SURFACE` is the dominant case for Product/Variant-data findings
today. *[Відкрити товар]* may provide context without implying edit authority.

### Current vs historical rule

When configuration drift makes a historical finding's remediation target unsafe,
suppress the misleading action and recommend rerun: *Налаштування змінилися після
цієї перевірки. Запустіть перевірку ще раз.* Matching `configuration_revision`
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

**Implementation status:** Stage 3-0 is **docs-only**. No Live action, permission
row, or Adobe write ships in this slice. Runtime lands in Stage 3A–3E (merchant
first-Live action in Stage 3D).

### Smallest first-Live surface

First manual Live admission belongs on `ManageAdobeProductsExportPreview`. Do
**not** turn `Інтеграції` into an execution console.

### Live authority

`run_sync_live` (tenth normative permission; runtime pending Stage 3A) is
independent from `run_sync_preview`, `manage_sync_configurations`, Mapping
permissions, and connector-account permissions. Preview permission never implies
Live authority.

### Preview prerequisite

After a relevant Completed current-revision Preview (`products` / `export` /
Preview / same `SyncConfiguration`), a user with `run_sync_live` may receive an
explicit Live action. Preview summary may guide but must **not** be described as
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
outcomes; no fake percentage. Completed vocabulary: *Синхронізовано* / *Не
передано* / *Частково синхронізовано* / *Не вдалося підтвердити*. `AMBIGUOUS`:
*Не вдалося підтвердити результат для N товарів. Не повторюйте передачу, доки
їхній стан не буде перевірено.*

### Forbidden merchant exposure

Do **not** expose `ExternalRecordLink`, idempotency, reconciliation, transport
attempts, HTTP codes, Adobe entity IDs, raw payloads, or connector internals in
Layer A/B.

### No selective retry

"Retry failed only" is explicitly **out** of Stage 3 V1. Recovery path: remediate /
verify → Preview when required → new all-products Live execution.
