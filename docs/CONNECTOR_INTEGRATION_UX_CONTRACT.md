# Connector Integration UX Contract

**Status:** Approved normative connector UX contract

**Approval date:** 2026-08-10

**Authority:** This document is the consolidated normative reference for connector-facing UX. Where `docs/06-UI_DESIGN_SYSTEM.md` or `docs/03-DOMAIN_MODEL.md` summarize connector UX or domain boundaries, they link here for full detail. Upon conflict between those summaries and this contract, this document wins for connector UX rules until the summaries are explicitly updated to match.

**Scope:** Every current and future connector-facing surface — Magento today; Shopify, Google Sheets, 1C, marketplaces, and any subsequent connector tomorrow. A connector UI is not acceptance-tested against "does it look like Magento's" — it is tested against this contract, §15.

**Non-goal:** This contract does not itself authorize new backend work. Every rule below is satisfiable with what already exists (`ConnectorCapability`, Discovery runtime, workspace isolation, authorization) plus UI wiring, or is explicitly flagged as a future decision requiring its own scoping pass.

**Existing-vs-future boundary:** This contract defines the *required UX* for synchronization, preview/dry-run, scheduling, mapping, issues, history, and bulk resolution *when those capabilities are implemented*. It does not assert that their backend/runtime contracts exist today beyond what has been directly confirmed elsewhere in this document (the `ConnectorCapability` enum, the Discovery/snapshot/field read path, the account-cardinality constraint). Any missing domain capability, persistence mechanism, or execution runtime — including, but not limited to, dry-run/preview computation, sync scheduling beyond what already exists for Discovery, issue aggregation, and bulk resolution — requires its own scoped architectural pass before the corresponding UI is implemented. A reader must not conclude from this document alone that any of these backend mechanisms already exist.

---

## 1. Audiences and the four layers

| Layer | Question it answers | Audience | Contains |
|---|---|---|---|
| **A — Щоденна робота** | "Is my integration okay right now?" | Merchant, daily | Status, last sync, count of items needing attention, one action |
| **B — Налаштування даних** | "How do I control what/how this syncs?" | Merchant, occasionally | Direction per data type, schedule, field mapping, ownership, available-fields reference |
| **C — Діагностика** | "What technically happened?" | **Platform support/operator** — never a workspace's own Admin/Director/Merchandiser role | Discovery runs, snapshots, technical status codes, redacted diagnostic data (see rule below) |
| **D — Каталог конекторів** | "How does our platform talk to X?" | Platform operator / developer | `ConnectorDefinition`, schema sources, endpoints, auth profiles, verification status |

**Rule:** "Admin" in this table never means a workspace's own Admin role — that role is a merchant role and reaches Layer A/B only, same as every other merchant role. Layer C requires a distinct platform-support identity/permission. If no such identity model exists yet at implementation time, Layer C is simply not merchant-accessible by any role — it does not default to "give it to workspace Admin since they're the closest thing we have." That is a separate architectural decision requiring its own scoping pass.

**Rule:** Layer assignment is a visibility ceiling, not an authorization grant. A role being eligible for Layer A/B does not automatically authorize every action within that layer. Existing policies/permissions remain authoritative and may further restrict individual pages/actions within a layer a role can otherwise reach. In particular, Merchandiser access to connection setup, synchronization settings, scheduling, ownership choices, credential changes, or destructive actions must be explicitly authorized through the existing policy contract; it must never be inferred merely from Layer B eligibility. This document does not widen any existing authorization boundary — it only says which layer a capability lives in, not who within that layer's audience may exercise it.

**Rule:** Layer C does not mean "raw payloads become visible." Reaching Layer C never lifts the project's existing secret-redaction, credential-encryption, or workspace-isolation rules. Credentials never become support-visible merely because a person has a Layer C-capable identity. "Diagnostic" means redacted technical detail (status codes, cause categories, timestamps, non-secret identifiers) — not unredacted raw request/response bodies or decrypted credentials.

**Rule:** The word **"Discovery"**, **"Знімок"**, **"Snapshot"**, and **"Schema source"** never appear in Layer A or Layer B UI text. They are Layer C vocabulary. A Layer B surface may show *derived* information (e.g. "коли ми востаннє перевіряли доступні поля") without naming the underlying mechanism.

---

## 2. Capability-driven rule — bound to the real enum, not a principle in the abstract

**Confirmed as already-built, not proposed:** `App\Enums\ConnectorCapability` currently has cases `ConnectionCheck` and `SchemaDiscovery`, each connector profile declares its supported set in `config/connectors.php` (`'capabilities' => [...]`), and `$definition->supports(ConnectorCapability $capability): bool` is already the real, callable check (`ConnectorProfileRegistry::requireCapability()` already throws when a profile lacks a capability it's asked to use).

**Rule:** Every connector-specific optional capability MUST have one domain-level source of truth. Where an appropriate `ConnectorCapability` case already exists (today: `ConnectionCheck`, `SchemaDiscovery`), UI MUST gate the corresponding surface on `supports()` for that case — no optional surface gated on that capability may render unconditionally. Where no appropriate case exists yet (e.g. a future two-way-sync toggle, a scheduling capability, a mapping capability), the feature MUST NOT invent a UI-only flag to fake capability-awareness in the meantime — the domain `ConnectorCapability` enum must first be extended, as part of that feature's own scoping/implementation pass, before its UI ships. This document does not pretend those cases exist today.

**Rule:** When a new `ConnectorCapability` case is introduced for a future connector-specific ability, the corresponding UI section must be written to be absent by default and to appear only when the connected profile's `supports()` returns true — never the reverse (present by default, manually hidden per connector).

**Rule:** Do not duplicate `ConnectorCapability` with a separate UI-layer flag system once the domain case exists — the UI reads the domain enum, it does not maintain a parallel one.

---

## 3. `Інтеграції` — universal landing surface

`Інтеграції` is the workspace/merchant surface for connecting and managing external systems. It is not the merchant surface for catalog work or for configuring/executing synchronization. The future merchant workflow for selection → direction → mapping → preview → schedule → run → results/errors will be defined separately by the Sync UX rebaseline. `Каталог і синхронізація` must not currently be represented as an established navigation group merely because it appears in the future roadmap; the current standalone top-level placement of `Інтеграції` is an intentional interim use of standard Filament ungrouped navigation behavior, not the final navigation IA.

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

Three steps, each ending in a plain confirmation, not a form dump.

1. **Підключення** — ask only the human-facing connection inputs required by that specific connector (a URL + token for Magento; OAuth + resource selection for Google Sheets; an API key for another connector type; file selection for yet another) — never schema source, auth profile, or endpoint path regardless of connector type. The exact input set is connector-specific by nature; what's constant is that it is always phrased in terms a human filling in a form understands, never internal connector configuration.
2. **Що синхронізувати** — per data type (Товари, Ціни, Залишки, ...), only for data types the connected profile's capabilities actually support (§2), phrased as directional sentences, never bare "Import/Export":
   ```
   Товари
   ☑ Отримувати товари з Magento
   ☑ Передавати зміни товарів у Magento
   ```
3. **Перша перевірка** — a categorized dry-run count before any real sync (`✓ готові · ⚠ потребує уваги · ⛔ неможливо`), never a silent first sync and never a bare "Sync? Yes/No."

**Rule:** Scheduling/automation is not offered until step 3 has succeeded at least once manually.

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

Contains, only for capabilities the connector supports (§2):

- Per-data-type direction toggles (§4.2), editable after initial setup.
- Schedule (frequency, next-run time).
- **Per-data-domain ownership**, asked in plain language, never as a global default and never using the term "ownership" or "source of truth" in merchant-facing copy:
  ```
  Де ви хочете керувати цінами?
  ○ У нашій платформі      ○ У Magento
  ```
  Repeated per data domain (Ціни, Описи, Залишки, ...) that is bidirectionally enabled. No connector ships a hardcoded default answer — this is a per-merchant, per-domain decision made explicit during setup, not inferred silently. (The underlying mechanism this answer configures — field-level write ownership rather than timestamp-based last-write-wins — is a backend decision requiring its own scoping pass before implementation; this contract fixes the *question asked to the merchant*, not yet the storage/enforcement design.)

---

## 7. Mapping — Layer B

The field-mapping matrix is the primary Layer B surface for data structure, built around the merchant's own catalog fields as rows:

```
Дані у вашому каталозі   Magento         Стан
Назва товару              Name            ✓
Артикул                   SKU             ✓
EAN / штрихкод             —               ⚠ Потрібно зіставити
Колір                     Color           ✓
```

**Rule:** The already-shipped Field Browser's persistence, query, security, and read-model architecture (`ViewConnectorSchemaSnapshot`, `ConnectorSchemaFieldPresenter`, the workspace/account/snapshot ownership chain) is retained entirely — no backend rework required by this contract. **Confirmed directly: its current merchant-facing copy does not yet satisfy §13** — `lang/uk.json` currently ships `connectors.ui.snapshot.title` = "Зведення знімка" and similar snapshot-worded strings on what is today a merchant-reachable page. This contract requires that copy to change (title, section labels, empty states — translation-file edits, not architecture) before this page qualifies as a compliant Layer B surface; it does not pretend the existing copy already complies. Layer C, if it ever gets its own distinct surface, may keep diagnostic snapshot terminology freely.

**Rule:** Once its copy is adapted, this surface is reachable only as a supporting reference from inside the mapping page — e.g. a `Переглянути всі доступні поля Magento` action — never as a landing destination or top-level nav item.

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

1. Every optional section is gated by a real `ConnectorCapability::supports()` check for the connected profile (§2) — verified by a test that asserts the section is absent for a profile lacking the capability.
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
- Does not change anything about what is already shipped (`ConnectorAccountResource`, Discovery runtime, the Field Browser, authorization boundaries). Every rule here is a navigation/labeling/gating change on top of existing capability, not a new backend contract, except where §6 explicitly flags the ownership-storage mechanism as a still-open future decision.
