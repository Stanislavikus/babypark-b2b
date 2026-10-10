# `Інтеграції` — Page-Specific UX Contract

**Status:** Approved page-specific contract, subordinate to the
approved `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md`. Ready for a
visual mock and/or implementation task.

**Grounding:** Every domain/authorization/status claim below was
verified directly against `origin/develop` (base `4020ff4`), not
inferred from prior documents. Exact files/methods are cited inline so
this contract can be re-verified quickly if `develop` moves before
implementation starts.

---

## 1. Information architecture — platform-first, adaptive destination

The landing page shows **one card per eligible platform**, never one
card per `ConnectorAccount`. A workspace with five Magento stores sees
one Magento card, not five.

**Eligible platforms for `Інтеграції`** (this exact definition is
authoritative for both this section and §9's merchant-safe projection):

- `Active` + existing **non-deleted** `ConnectorAccount` → visible
  (even when no merchant AccountSetup profile exists for that
  definition — existing connections must not disappear);
- `Active` + zero accounts → visible **only** when
  `ConnectorProfileRegistry` exposes exactly one enabled profile that
  advertises `ConnectorCapability::AccountSetup` for that definition's
  `code` (via required profile config key `connector_definition_code`).
  `ConnectorDefinitionStatus::Active` alone does **not** imply merchant
  setup exists — Layer D catalog status and merchant-connectable status
  are distinct. Unconnected Active definitions without AccountSetup
  (e.g. Shopify / Google Merchant today) must **not** render a card and
  must **not** invent Coming Soon / setup-unavailable UX on this page;
- `Deprecated` + existing **non-deleted** `ConnectorAccount` → visible
  (§14) — soft-delete scope must be respected (`deleted_at`); never
  `withTrashed()`, or a platform whose last account was deleted long ago
  would incorrectly resurface;
- `Deprecated` + zero accounts → never visible;
- `Draft` never appears, under any condition.

**Amendment (PR #118 stop-and-amend):** merchant setup availability is
sourced only from the connector profile registry
(`connector_definition_code` + `AccountSetup` capability) — never from a
UI-local code→profile map and never from Active status alone.

**The card's primary action destination is adaptive to account count,
not fixed:**

| Account count | Primary action | Destination |
|---|---|---|
| 0 | Підключити | Straight into connection setup (§4) |
| 1 | Відкрити | Straight into that single account's own Overview — no intermediate one-row list |
| N > 1 | Відкрити | That platform's own account list, each row with its own state/action |

This is a deliberate refinement over a plain "always open a list"
pattern: a single-store SMB merchant — the primary target user — never
pays an extra click for the platform's own architectural ability to
support N accounts. The visual model (Magento-as-platform) stays
constant regardless of count; only the destination adapts.

**Confirmed real-world precedent, not a first-party model:** Shopify's
own native product does not solve "one hub, multiple accounts of the
same platform" — every Shopify store is a fully independent account.
The actual precedent for platform-first, multi-account hub UX comes
from third-party multi-store management tools built on top of
single-platform products (e.g. tools explicitly marketing "manage 2 to
50+ stores from one dashboard"), which is the correct analogy for our
own product (we are the hub), not Shopify's own single-store admin.

---

## 2. Two-tier state model — not one flat vocabulary

**Confirmed distinct concepts, must not be merged into one enum or one
UI-only pseudo-status:**

### Tier 1 — Platform connection state (existence)

Whether *any* `ConnectorAccount` exists for this platform in this
workspace at all.

```
Ще не підключено   — 0 accounts
(connected)          — 1+ accounts exist; state comes from Tier 2
```

`Ще не підключено` is not a health state and must never be represented
as a value of `ConnectorAccountConnectionStatus` — no `not_connected`
pseudo-case should be invented in code. It is the absence of a row,
represented in the UI layer only.

### Tier 2 — Connection health (of existing accounts)

**Confirmed directly from `App\Enums\ConnectorAccountConnectionStatus`:**
five real cases — `Untested`, `Connected`, `AttentionRequired`,
`TemporarilyUnavailable`, `Disabled`. This contract's merchant
vocabulary maps close to 1:1, not collapsed into four states:

| Enum case | Merchant label | Meaning |
|---|---|---|
| `Untested` | **Не перевірено** | Configured, no check has completed yet — *not* "checking now" (see §3) |
| `Connected` | **Підключення перевірено** | Last check succeeded (a historical fact — see §7, no freshness threshold exists or should be invented) |
| `AttentionRequired` | **Потребує уваги** | Last check failed in a way needing merchant action |
| `TemporarilyUnavailable` | **Тимчасова проблема** | Last check failed in a way the system retries automatically — no action button, no elevated alarm styling |
| `Disabled` | **Вимкнено** | See §8 — currently unreachable in the real application; the vocabulary is defined now so no future rework is needed once a disable mechanism ships |

---

## 3. Active-check overlay — already implemented, reuse it exactly

**Confirmed:** `ConnectorAccountUiState` already separates
`runtimeStatusLabel()` (transient, derived from a live
`ConnectorConnectionCheck` row's own `Queued`/`Running` status) from
`stableStatusLabel()` (the persisted `ConnectorAccountConnectionStatus`).
`Інтеграції` reuses `activeConnectionCheck()` + `runtimeStatusLabel()`
for the active-check overlay on single-account cards and per-connection
rows. Page-specific stable labels/colors come from
`IntegrationsStatusVocabulary` (including the deliberately calmer
`TemporarilyUnavailable` presentation) — not from a discarded
`stableStatusLabel()` call.

**Rule:** An active check is always presented as an overlay on the
last-known stable state, never as a state substitution:

```
Підключення перевірено
Виконується перевірка…
```

not:

```
Перевіряємо…
```

alone, which would discard the true last-known state while a routine
re-check runs. `Untested`'s own label ("Не перевірено") is the
no-check-has-ever-completed case; an *active* first check on a brand
new account is `Не перевірено` + the same active overlay, not a sixth
label.

**Scope rule — single account vs. N-account aggregation:**
`ConnectorAccountUiState` describes one `ConnectorAccount`'s own
runtime state; it is not an aggregator across accounts. For a
**single-account platform**, the landing card may show this overlay
directly, exactly as built. For **N > 1**, the platform card's health
is derived only from the stable rollup (§5) — v1 does not invent a new
aggregate "something is currently being checked" indicator across
multiple accounts. Transient per-account check activity remains
visible only after `Відкрити`, on that platform's own account list,
where each row uses the existing single-account overlay unmodified.
This keeps Layer A calm and requires no new aggregation mechanism.

---

## 4. Setup entry — new accounts, no fabricated technical choice

For the 0-account case, `Підключити` leads directly into the
connection-input step already specified in the approved contract §4
(human-facing inputs only, connector-specific by nature, never schema
source/auth profile/endpoint path).

**Confirmed:** `ConnectorAccount.name` is a required, freely-typed
string with no current auto-derivation from remote-store metadata.

**Settled decision (product-owner, not re-opened here):** default the
name to the platform's own display name for the first account
("Magento"); for a subsequent one, choose the first available
deterministic suffix ("Magento — 2", "Magento — 3", …) against the
real `active_name_uniqueness_key` constraint (§3 of the promoted
contract), not a naive "account count + 1" — deletions or renames of
earlier connections must not produce an unexpected collision. Editable
any time. Do not prompt an unrelated naming question during first-time
setup. Where a connector's own setup response can supply a natural
identity (e.g. a resolved store domain), prefer that over the generic
fallback — this is a per-connector enhancement, not a blocking
requirement for
v1.

---

## 5. Aggregation rollup for N > 1 — corrected worst-wins

**Confirmed defect in the naive "worst status wins across all
accounts" approach:** a workspace with two healthy accounts and one
*intentionally* `Disabled` account must not show the platform card as
"Вимкнено" — that would misrepresent two working connections as if the
whole platform were off.

**Rule — exact rollup algorithm:**

```text
0 accounts
  → Ще не підключено

accounts exist, but ALL are disabled (is_enabled = false)
  → Вимкнено

otherwise, evaluate ENABLED accounts only:
  any AttentionRequired          → Потребує уваги
  else any TemporarilyUnavailable → Тимчасова проблема
  else any Untested                → Не перевірено
  else all Connected                → Підключення перевірено
```

Disabled accounts are excluded from the health evaluation entirely and
surfaced only in the secondary line:

```
Magento — Потребує уваги
3 підключення · 1 потребує уваги

Magento — Підключення перевірено
2 активні · 1 вимкнено
```

---

## 6. Discovery health stays separate — confirmed, not a proposal

**Confirmed:** `last_discovery_at`/`last_successful_discovery_at` and
Discovery's own error fields are tracked independently of
`connection_status` — a Discovery failure does not write
`connection_status`. **Primary card health = connection health only.**
"Доступні поля востаннє перевірено…" belongs one click deeper (the
single account's Overview), never on the landing card, per the
approved contract's instruction not to conflate "we couldn't refresh
available fields" with "the store connection is broken." When a real
sync runtime exists in the future, how *its* health relates to
connection health is a separate, later decision — not designed here.

---

## 7. "Connected" has no freshness threshold — confirmed, do not invent one

**Confirmed:** `isStale()` (`ConnectorConnectionCheckPersistence.php`,
`ConnectorDiscoveryRunPersistence.php`) applies only to `Queued`/
`Running` rows for orphan/recovery detection and explicitly returns
`false` for any terminal state. **No freshness/staleness concept
exists for a successful `Connected` result.** "Підключення перевірено" means "the last
check succeeded," a historical fact, not "currently proven reachable
this second." This contract does not introduce a staleness threshold —
doing so would be new product/runtime behavior requiring its own
scoping pass, not a landing-page UX decision.

---

## 8. Settled decision — `Інтеграції` v1 does not build enable/disable

**Confirmed directly, not assumed:** `is_enabled` is written only at
account creation (`ConnectorAccountSettingsService.php`, always
`true`) with no toggle mechanism anywhere in the codebase.
`ConnectorAccountConnectionStatus::Disabled` is referenced only in
presentation code (`ConnectorAccountUiState.php`'s badge-color
mapping) with no write path setting it. **No current UI action, no
current service method, disables an account.**

**Settled decision (Option B):** `Інтеграції` v1 does not add an
enable/disable action or any new write path. `Landing`'s existing
`Disabled`/`is_enabled=false` presentation semantics (§2's `Disabled`
row, §5's rollup exclusion) remain defined defensively for existing or
future data — they cost nothing to keep specified and make the rollup
correct if the state is ever reached — but no UI on this page creates
that state. Building a real enable/disable feature is explicitly
out of scope here and requires its own later, separately scoped
domain/authorization pass. This page must not become the vehicle for
inventing a new mutating domain feature.

---

## 9. Merchant visibility of not-yet-connected platforms — merchant-safe catalog projection

**Confirmed:** `ConnectorDefinitionResource::canAccess()` gates on
`PlatformAdminAuthorization::canManage($user)` — a distinct,
platform-operator-level check, unrelated to workspace-scoped connector
authorization for *existing* accounts.

**026B repository status (post-B-2):** merchant-safe eligible-platform
visibility is implemented via `EligibleConnectorPlatformCatalog` — a deliberate,
merchant-safe projection reading only `name`, `code` (internal mapping key,
never rendered), and `status` filtered per §1's exact eligibility definition
(not a bare `status = Active` filter — it must also surface a `Deprecated`
definition when this workspace already holds a non-deleted account against it).
This projection is **separate** from `PlatformAdminAuthorization` (which remains
the correct, unwidened gate for the real `ConnectorDefinitionResource` /
`Платформи та джерела` CRUD surface) and is **not** inferred from
`ConnectorAccountPolicy` eligibility alone (which only concerns accounts that
already exist). Every merchant workspace membership that can reach `Інтеграції`
at all sees the full eligible-platform catalog defined in §1 — visibility here
does not need per-role restriction beyond that, since a platform card with no
account carries no sensitive data (see §11's action-boundary rule for what *does*
still require management capability).

**Historical pre-B-2:** before GAP-026B-2, no merchant-safe read path existed;
that gap motivated this projection and is closed in repository runtime.

---

## 10. Card states — exact copy

### 0-account

```
Magento
Ще не підключено

[Підключити]
```

### 1-account, Connected

```
Magento          Підключення перевірено
Останню перевірку виконано: сьогодні, 14:20

[Відкрити]
```

### 1-account, Untested, no active check

```
Magento          Не перевірено
Підключення ще не перевіряли

[Відкрити]
```

### 1-account, active check running (§3 overlay)

```
Magento          Підключення перевірено
Виконується перевірка…

[Відкрити]
```

### N-account, mixed health (§5 rollup)

```
Magento          Потребує уваги
3 підключення · 1 потребує уваги

[Відкрити]
```

### N-account, all disabled (§5/§8 — currently unreachable in practice)

```
Magento          Вимкнено
2 підключення · обидва вимкнено

[Відкрити]
```

---

## 11. Actions and authorization — frozen workspace permission matrix

**026B repository status (post-B-2):** connector authorization on this page
follows the frozen workspace-permission matrix — not Merchandiser/job-title
semantics. Effective tiers:

| Tier | Required workspace permission(s) | Landing behavior |
|---|---|---|
| Safe read / discovery-only | `view_connector_accounts` and/or `run_connector_discovery` without `manage_connector_accounts` | Full eligible-platform catalog; true health states; **no** active connection-check/runtime overlay loaded or serialized; **no** management actions |
| Management | `manage_connector_accounts` | Full catalog; management actions (`Підключити`, settings/reconfiguration); active connection-check/runtime overlay via `ConnectorAccountUiState` where applicable |

`ConnectorAccountPolicy` + `ConnectorAuthorization` evaluate these tiers through
`WorkspaceAuthorization` — legacy `User.role` labels authorize nothing by
themselves.

**Connection-check / runtime overlay (management-only):** active connection-check
state and runtime overlay presentation are **management-only** (`manage_connector_accounts`).
Safe-read and discovery-only actors must not load or serialize connection-check
overlay state on the landing surface or per-connection rows.

**Rule:** Actors with safe-read or discovery-only capability see every platform
card and its true health state. `Підключити` (new account) and any settings/
reconfiguration action inside an account's own page remain gated by
`manage_connector_accounts` — unchanged from the approved contract's "Layer is a
ceiling, not a grant" principle. Reaching this page does not imply management
capability.

**Rule — 0-account and "connect another" states specifically:** an actor
without `manage_connector_accounts` never sees a disabled `Підключити` /
`Підключити ще` button with a tooltip explaining why it's inactive.
Instead, that actor sees explanatory secondary text in its place:

```
Для підключення зверніться до адміністратора
```

A visibly-present-but-non-functional primary action is worse for a
non-technical audience than a clear statement of what to do instead.
This rule applies identically to the 0-account state (§10) and to
`Підключити ще` inside an N-account platform's own list.

**Historical pre-B-2 (PR #102):** before GAP-026B-2, authorization used fixed
`User.role` checks (Merchandiser safe read/discovery vs Admin/Director
management bypass) and `ConnectorAccountMerchandiserPresentation` for safe
projection — superseded by the matrix above in repository runtime.

```
Для підключення зверніться до адміністратора
```

A visibly-present-but-non-functional primary action is worse for a
non-technical audience than a clear statement of what to do instead.
This rule applies identically to the 0-account state (§10) and to
`Підключити ще` inside an N-account platform's own list.

---

## 12. Layout — card grid, justified by content shape

**Rule:** A responsive card grid (not the project's table-first
convention used for operational surfaces) is the correct pattern for
this specific page — justified by content shape (sparse per-platform
content: name, one status line, one action, optional count), not
because an external comparator happens to use cards. Single column on
mobile, 2–3 columns on desktop.

**v1 scope:** no search/category UI, appropriate for the current small
platform count. This is a v1 scope statement, not a permanent
constraint — search/categorization is added later if and when the
active platform count makes plain visual scanning insufficient; it is
neither pre-built now nor ruled out for the future.

---

## 13. Icons — no schema change for v1

**Confirmed:** `connector_definitions` has no icon/logo column.

**Settled decision:** no new database column for v1. A platform logo,
if used, is app-owned static presentation metadata (a code→asset
mapping in the presentation layer, with a text fallback), never
tenant/workspace data — this is a presentation-layer addition only if
a mock shows it meaningfully helps recognition, not a blocking
requirement.

---

## 14. Deprecated platforms

**Settled decision:** a workspace with no existing **non-deleted**
account for a `Deprecated` `ConnectorDefinition` never sees its card
(§1's exact eligibility rule applies here too — soft-deleted accounts
do not count). A workspace
that already has a non-deleted account against a since-deprecated
definition keeps seeing that existing card/account (it must not
disappear), but
`Підключити ще` for that platform is unavailable. Exact merchant
copy for the deprecated-with-existing-account case is deferred until
the real operational meaning of `Deprecated` (sunset date? read-only?
no new discovery?) is confirmed — this contract does not invent
copy ahead of that meaning being settled.

---

## 15. Naming — the merchant concept for an individual `ConnectorAccount`

**Settled decision:** the universal merchant-facing noun is
**"Підключення."** Domain class names (`ConnectorAccount`) are
unchanged; this is presentation vocabulary only.

---

## 16. Acceptance criteria for this page

1. Zero UI-only pseudo-statuses invented — every rendered health value
   traces to a real `ConnectorAccountConnectionStatus` case or the
   explicit 0-account Tier-1 state (§2).
2. The rollup algorithm (§5) is implemented exactly as specified,
   including disabled-account exclusion, with a test proving a mixed
   healthy+disabled account set does not render "Вимкнено."
3. Active-check overlay (§3) reuses `ConnectorAccountUiState`'s
   `activeConnectionCheck()` + `runtimeStatusLabel()` for
   single-account and per-connection-row presentation. Stable landing
   labels/colors use `IntegrationsStatusVocabulary`. No parallel
   runtime-check implementation, and no new aggregate runtime-check
   indicator invented for N-account platform cards.
4. No Discovery-derived data appears on the landing card (§6) —
   verified by a rendered-content test.
5. No freshness/staleness threshold is introduced for `Connected`
   (§7).
6. The merchant-safe platform-catalog read path (§9) is implemented as
   `EligibleConnectorPlatformCatalog` — a reviewed projection separate from
   widened `PlatformAdminAuthorization` and not inferred from
   `ConnectorAccountPolicy` alone.
7. Active connection-check/runtime overlay is loaded and serialized only for
   actors with `manage_connector_accounts`; safe-read/discovery-only actors
   receive null overlay state with no connection-check queries on mount.
8. Authorization and connect-copy behavior follow the frozen workspace
   permission matrix (§11) — not Merchandiser/job-title semantics; actors
   without `manage_connector_accounts` receive approved explanatory connect
   copy instead of management actions.
9. Merchant setup availability is resolved only via
   `ConnectorProfileRegistry::resolveAccountSetupProfile()` gated on
   `ConnectorCapability::AccountSetup` — no UI-local profile map, no
   Coming Soon cards for Active-but-not-connectable definitions.
10. Rollup must never treat `is_enabled=true` +
    `connection_status=Disabled` as Connected / "Підключення перевірено" (defensive
    invariant; no enable/disable write path added — §8 Option B).
11. Implementation MUST NOT introduce a new enable/disable action or
   write path as part of this page's task (§8's settled Option B) —
   verified by final-diff inspection, not left as an open decision for
   the implementing agent to resolve.
12. No forbidden vocabulary (per the approved contract's §13) appears
   anywhere on this page, including the 0-account and N-account states
   added here.

---

## Relationship to prior work

Final correction pass before approval: unified platform-eligibility
definition resolving the §1/§9/§14 Active-vs-Deprecated contradiction;
replaced an unverifiable "just now configured" claim with honest
never-checked copy (§10); explicit no-dead-button rule for roles
without `create` (§11); scoped the active-check overlay to
single-account/per-row use, not platform-level aggregation (§3);
settled §8's Option A/B as Option B (no enable/disable in this page's
scope); made the second-account naming fallback collision-safe against
the real uniqueness constraint rather than a naive count-based scheme
(§4).

Supersedes the `gap-025-integratsii-rebaseline-research.md` research
pass's open recommendations with settled answers on: adaptive
destination (§1, refining the research's Pattern A into a sharper
version); the two-tier state model correcting the research's own
"5-vs-6 states" framing (§2 — `Ще не підключено` is not a sixth health
state); active-check overlay confirmed as already-implemented, not a
new pattern (§3); corrected rollup excluding disabled accounts (§5,
replacing the research's original literal worst-wins draft); the four
directly-verified facts from the follow-up code trace (is_enabled/
Disabled unreachability §8, active-check architecture §3, merchant
visibility gap §9, no staleness threshold §7); and the three
product-owner decisions already settled (deprecated handling §14,
naming §15, icons §13).
