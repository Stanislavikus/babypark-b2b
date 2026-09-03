# 03-DOMAIN_MODEL.md


## Domain Model


### Purpose


This document defines the core domain model of the platform.

The goal is to create an enterprise-grade internal architecture while keeping the user experience simple enough for a non-technical product manager, small merchant or business owner.

The platform must feel simple in the user interface:

- My company;

- Products;

- Product fields;

- Customers;

- Prices;

- Orders;

- B2B catalogue;

- Import / Export.

Internally, the platform must remain strict, extensible and protected from hardcoded one-off logic.

The domain model must support:

- multi-company SaaS architecture;

- product data management;

- native B2B catalogue;

- B2B storefront experience;

- product variants;

- attribute dictionary;

- pricing;

- availability;

- order capture;

- future online payments;

- connector-based imports and exports;

- future billing;

- future marketplace and website channels.

The platform must not become a full ERP, CRM, accounting system, warehouse system, marketplace, website builder or e-commerce CMS.

It may integrate with these systems.

### Core Principle


The platform has two layers of complexity.

The internal model may be enterprise-grade.

The user interface must remain extremely simple.

The user should not need to understand:

- tenants;

- aggregates;

- variants;

- attribute values;

- price resolvers;

- inventory ledgers;

- connector mappings;

- channel projections;

- payment webhooks.

The user should understand only practical concepts:

- company;

- product;

- field;

- price;

- availability;

- customer;

- order;

- catalogue;

- import;

- export;

- payment.

The architecture must protect the system from chaos without exposing that complexity to the user.

The non-official product principle is:

Enterprise SaaS under the hood, simple enough for a non-technical user to operate by trial and error.

### Domain Boundaries


The platform should be organized around clear domain areas.

Initial domain areas:

- Workspace

- Users and Permissions

- Product Catalogue

- Attribute Dictionary

- Pricing

- Availability

- Customers

- B2B Channel

- Orders

- Payments

- Connectors and Mappings

- Billing

These are domain boundaries, not necessarily separate microservices.

For the MVP, the system should be a modular monolith.

The architecture should keep domain boundaries clear so that future extraction or scaling is possible without rewriting the product.

## Workspace Context


A Workspace is the technical SaaS boundary.

Every company using the platform owns one workspace.

In the user interface, this may be shown as:

- My Company

- Company

- Business

In code and database design, tenant isolation should be based on:

- workspace_id

The term tenant should not be used in the user interface.

It may be used only in technical architecture discussions where necessary.

### Workspace


A workspace represents one isolated business account.

A workspace owns:

- products;

- product variants;

- product fields;

- categories;

- customers;

- customer groups;

- price lists;

- availability records;

- B2B channels;

- orders;

- payments;

- connectors;

- mappings;

- users;

- settings.

All business data must be scoped by workspace_id.

No product, order, customer, price, mapping, connector account or payment should exist without clear workspace ownership, unless it is a global platform reference entity.

Examples of workspace-owned entities:

- products

- product_variants

- categories

- customers

- orders

- payments

- price_lists

- connector_accounts

- field_mappings

Examples of global platform entities:

- system attribute definitions;

- platform attribute library records;

- connector definitions;

- country codes;

- currency codes;

- unit definitions.

### Workspace Isolation


The MVP should use single-database tenancy.

This keeps DevOps complexity low.

However, single-database tenancy requires strict discipline.

Every workspace-owned table must include workspace_id.

Every query that reads or writes workspace data must be scoped by workspace_id.

The application should enforce workspace scoping through:

- model scopes;

- repositories;

- service layer checks;

- authorization policies;

- tests for tenant data leakage.

The platform must avoid relying on developers to manually remember where workspace_id = ... in every query.

Low-level queries and background jobs must be especially careful.

Any background job that processes workspace data must carry explicit workspace context.

## Users and Permissions Context


Users are people who access the platform.

A user may belong to one or more workspaces.

The relationship between users and workspaces should be explicit.

Core entities:

- User

- WorkspaceUser

- Role

- Permission

### Workspace access model and authorization (Resolved — Task 4C-1c-2a, 2026-08-13)

This decision freezes the workspace-scoped authorization contract required before
the Layer-B FieldMapping UI (Task 4C-1c-2b). It does **not** choose Spatie Teams
migration mechanics, UUID team columns, a custom Role model, or the final
`WorkspaceUser` schema — those remain separate implementation decisions.

**Authorization source of truth**

- **Atomic permissions** are the authorization source of truth.
- **Job-title / role names** (`User.role`, `UserRole` enum values such as
  Merchandiser, Admin, Director, Manager, …) have **no authorization semantics**
  in the target model.
- Policies, gates, and services must **not** grant a capability merely because
  `User.role === Merchandiser|Admin|Director|…`.
- Authorization is evaluated for **User × Workspace**, never globally for the
  User alone.
- Cross-workspace permission leakage is a **critical failure**.
- Future **`WorkspaceUser` / workspace membership** is the ownership boundary
  for role and permission assignments inside a workspace.

**Workspace roles / access profiles**

- A workspace **role / access profile** is a workspace-owned, merchant-configurable
  **named bundle of atomic permissions**.
- A workspace membership authorized with effective **`manage_workspace_access`** may create/name/manage workspace **Roles / Access profiles** using business-owned labels.
- **Platform-provided roles** are onboarding / default **templates only** — they
  are not job taxonomy and carry no authorization semantics by name alone.
- One workspace membership may receive **multiple roles**, with **additive**
  effective permissions.
- **Direct per-user permission overrides** are **deferred** in the first
  implementation slice. Unique access is expressed through custom and/or multiple
  workspace roles instead.

**Access-model composition:**

- Atomic permissions are the authorization source of truth.
- Workspace roles/access profiles are merchant-owned named bundles of those atomic permissions.
- The underlying authorization model is component-based; merchant-facing default templates are persona/task-oriented for usability.
- Platform-provided role names are onboarding templates only and have no authorization semantics.
- A workspace may rename templates, create custom roles, and assign multiple roles to one membership.
- Effective permissions in the first implementation are additive: union(all assigned workspace-role permissions).
- Absence of a permission means deny.
- There is no explicit deny/mute precedence in the first RBAC foundation.

**Deferred**

Known deferred access-model extension: The first workspace RBAC foundation
intentionally does **not** implement direct per-user permission overrides,
negative permissions, or permission muting. Industry precedent (for example
Salesforce Permission Set Group muting) shows that an exception mechanism can
reduce proliferation of nearly-identical role bundles when organizations
repeatedly need "bundle X minus permission Y". This is an accepted MVP tradeoff,
not an overlooked requirement. If real customer usage shows workspace-role
proliferation or repeated "role minus one permission" support cases, evaluate a
narrow assignment/group exception mechanism before adding unrestricted
per-user overrides. Exact allow/deny precedence is **NOT** resolved now and must
receive its own security/authorization decision before implementation.

**Mapping permissions (frozen for Layer B)**

These atomic permissions are independent from connector credential/settings
management. They are part of the frozen minimum permission vocabulary below.

**Frozen permission vocabulary (minimum workspace RBAC slice)**

| Permission | Authority |
|---|---|
| `view_connector_accounts` | Safe Layer A/B `ConnectorAccount` read only; no decrypted credentials/settings secrets. |
| `run_connector_discovery` | Manual discovery and the safe read surface necessary to follow its progress/result. |
| `manage_connector_accounts` | Create/manage account settings and credentials, disable/archive where supported, run connection check; also permits safe account read and manual discovery. |
| `view_sync_mappings` | Read the mapping surface for a `SyncConfiguration` (effective mappings, suggestions, discovery-unavailable read-only state). |
| `manage_sync_mappings` | Mutate confirmed mappings through the approved mutation service. Inherently includes the same mapping read surface as `view_sync_mappings`. |
| `manage_workspace_access` | Manage workspace Roles / Access profiles and role assignments for memberships. |
| `manage_workspace_tax_settings` | Manage workspace tax-settings surfaces governed by workspace tax authorization. |

**Permission independence (frozen):**

- `manage_connector_accounts` **does not** imply `view_sync_mappings` or
  `manage_sync_mappings`.
- `view_sync_mappings` / `manage_sync_mappings` **do not** imply connector
  settings/credentials management.
- `manage_workspace_access` **does not** automatically imply connector, mapping,
  product, price, order, or other business permissions.
- No job-title name may substitute for any permission above.

Mapping-specific rules:

- `manage_sync_mappings` grants mutation authority and inherently permits the
  same mapping read surface.
- Possessing mapping permissions **never** grants credential, settings, base URL,
  or auth-profile access.
- **No particular named role**, including Merchandiser, is normatively entitled to
  any permission in this vocabulary.

**Workspace access administration (capability-based)**

- A workspace membership with effective **`manage_workspace_access`** may manage
  workspace **Roles / Access profiles** and their membership assignments.
- Users/logins receive **one or more roles** for that workspace.
- Role names are **business-owned labels**, not predefined job taxonomy.
- Absence or temporary replacement is handled by assigning an **additional
  role**, not by changing application code or hardcoding job-title exceptions.
- Do **not** expose technical Spatie terminology (`Permission`, `Role` model
  names, pivot tables, team resolver, etc.) to merchants.

**Anti-lockout invariant (Resolved — security)**

- Every active workspace must have at least one active membership with effective
  `manage_workspace_access`.
- Initial workspace creation/bootstrap must establish at least one such membership.
- Changing, deleting, deactivating memberships, role assignments, or role
  definitions must be rejected if the resulting state would leave zero active
  memberships with effective `manage_workspace_access`.
- This invariant must be enforced transactionally in the future write service.
- Exact physical representation (`WorkspaceUser` schema, Spatie team mechanics,
  `owner_user_id`, protected role IDs, etc.) is **not** resolved in 4C-1c-2a.
- Platform-support/recovery mechanics are a separate future operational/security
  decision and must **not** silently bypass tenant authorization.

**Historical note (superseded by GAP-026B — do not treat as current truth)**

Before GAP-026B production cutover (2026-08-14), the repository mixed fixed
`User.role` checks with a small global Spatie permission set; `WorkspaceUser`
membership was not yet authoritative for connector/tax/mapping/access domains.
That transitional state is historical evidence only.

**Current truth (post-GAP-026B, post-PR #139):** GAP-026B authorization prerequisite is
satisfied; production authority cutover completed; Task 4C-1c-2b Layer-B Mapping
UI shipped on `WorkspaceAuthorization` / `WorkspaceUser` RBAC (PR #139).

### Workspace RBAC physical architecture [Resolved — GAP-026-0, 2026-08-13]

Freeze custom workspace RBAC, not Spatie Teams.

**Assignment principal**

`WorkspaceUser` is the authoritative workspace-membership and role-assignment principal.

A global `User` must never receive a workspace role directly.

Conceptually:

```text
User
  └── WorkspaceUser
        └── WorkspaceUserRole
              └── WorkspaceRole
                    └── WorkspaceRolePermission
                          └── WorkspacePermission
```

Spatie Teams is not the authoritative tenant-scoping mechanism.

Reason to freeze normatively:

- workspace role assignment must be structurally scoped to a concrete membership;
- explicit workspace identity is required for authorization;
- cross-workspace role assignment must be impossible at DB level;
- target model forbids direct user permission overrides.

**Minimum physical tables (first slice)**

`workspace_users` — minimum schema:

| Column | Type / constraint |
|---|---|
| `id` | UUID PK |
| `workspace_id` | UUID NOT NULL |
| `user_id` | BIGINT UNSIGNED NOT NULL |
| `is_active` | BOOLEAN NOT NULL DEFAULT true |
| `created_at` | timestamp |
| `updated_at` | timestamp |

Constraints:

- `UNIQUE (workspace_id, user_id)`
- `UNIQUE (id, workspace_id)`

Parent FK (RESTRICT):

- `workspace_users.workspace_id` → `workspaces.id` ON DELETE RESTRICT
- `workspace_users.user_id` → `users.id` ON DELETE RESTRICT

Do **not** add in first slice: `deleted_at`, `invited_at`, `activated_at`,
`deactivated_at`. Invitation/deactivation history/soft-delete lifecycle is not
currently resolved and remains future additive scope.

`workspace_users.is_active` is workspace-level membership availability and is
independent from global `users.is_active`.

**Active membership**

```text
active membership :=
    workspace_users row exists
    AND workspace_users.is_active = true
    AND users.is_active = true
```

`vacation_until` is not authorization state.

There is currently no workspace-suspension lifecycle. Every existing `workspaces`
row participates in anti-lockout. Do not add `workspaces.is_active` in GAP-026.

`workspace_roles` — minimum schema:

| Column | Type / constraint |
|---|---|
| `id` | UUID PK |
| `workspace_id` | UUID NOT NULL |
| `name` | string NOT NULL |
| `template_key` | nullable stable ASCII key, provenance/bootstrap only |
| `created_at` | timestamp |
| `updated_at` | timestamp |

Constraints:

- `UNIQUE (workspace_id, name)`
- `UNIQUE (workspace_id, template_key)`
- `UNIQUE (id, workspace_id)`

Parent FK (RESTRICT):

- `workspace_roles.workspace_id` → `workspaces.id` ON DELETE RESTRICT

`template_key`:

- carries no authorization semantics;
- non-null `template_key` is the stable template/bootstrap identity inside one
  workspace;
- exists only for stable platform-template/bootstrap provenance and idempotency;
- custom merchant-created roles may have NULL;
- multiple NULL values remain valid;
- merchant rename of `name` never changes `template_key`;
- bootstrap/idempotency must resolve platform template roles by stable key, not
  mutable display name;
- role `name` remains merchant-owned and freely renameable.

If implementation research shows `template_key` is unnecessary for
deterministic/idempotent bootstrap, implementation must STOP and report before
deleting it from the documented model rather than silently changing the contract.

`workspace_permissions` — global platform reference catalogue:

| Column | Type / constraint |
|---|---|
| `id` | UUID PK |
| `code` | string NOT NULL UNIQUE |

Permissions are platform-defined, seeded/version-controlled, not merchant-created,
immutable by merchants. Role/access-profile composition is merchant configurable;
atomic permission vocabulary is not. Do not reuse Spatie's `permissions` table as
the target authoritative catalogue.

`workspace_user_roles`:

| Column | Notes |
|---|---|
| `workspace_id` | tenant guard column |
| `workspace_user_id` | FK to membership |
| `workspace_role_id` | FK to role |

Constraints:

- `UNIQUE (workspace_user_id, workspace_role_id)`
- FK `(workspace_user_id, workspace_id)` → `workspace_users(id, workspace_id)`
- FK `(workspace_role_id, workspace_id)` → `workspace_roles(id, workspace_id)`

Both composite FKs share the same child `workspace_id`. Membership from workspace A
+ role from workspace B is structurally unrepresentable.

`workspace_role_permissions`:

| Column | Notes |
|---|---|
| `workspace_id` | deliberately redundant tenant guard |
| `workspace_role_id` | FK to role |
| `workspace_permission_id` | FK to permission |

Constraints:

- `UNIQUE (workspace_role_id, workspace_permission_id)`
- FK `(workspace_role_id, workspace_id)` → `workspace_roles(id, workspace_id)`
- FK `workspace_permission_id` → `workspace_permissions(id)`

Supporting indexes required for MySQL composite FKs must be documented in
implementation migrations (composite FK child columns indexed per MySQL 8 rules).

**Parent workspace integrity**

Every `WorkspaceUser` and `WorkspaceRole` must have a real `workspaces` parent
through the parent FKs above. Workspace deletion lifecycle remains outside
GAP-026. GAP-026 must not silently CASCADE workspace deletion through
access-control state.

**Delete behavior — RESTRICT, not silent CASCADE**

Access-control children must not disappear through implicit DB cascades that can
bypass anti-lockout coordination.

Use RESTRICT / equivalent guarded deletion semantics for at least:

- `users` → `workspace_users`
- `workspace_users` → `workspace_user_roles`
- `workspace_roles` → `workspace_user_roles`
- `workspace_roles` → `workspace_role_permissions`
- `workspace_permissions` → `workspace_role_permissions`

Workspace-parent deletion itself is outside GAP-026 and must not be used to invent
a tenant-deletion lifecycle.

Reason: a direct hard delete of a User/membership/role must not silently remove
the last effective `manage_workspace_access` holder without the workspace-access
mutation coordinator seeing the transition.

Current `User` has no SoftDeletes; membership deletion therefore cannot rely on
implicit recovery semantics.

DB constraints alone do not implement aggregate anti-lockout.

**Authorization service boundary**

Target conceptual API:

```php
interface WorkspaceAuthorization
{
    public function allows(
        User $user,
        Workspace $workspace,
        string $permission,
    ): bool;

    public function effectivePermissions(
        User $user,
        Workspace $workspace,
    ): array;

    public function activeMembership(
        User $user,
        Workspace $workspace,
    ): ?WorkspaceUser;
}
```

Exact PHP interface/class packaging may follow code conventions later, but the
security boundary is frozen:

- `Workspace` is a mandatory argument.
- No authorization API overload may silently derive its `Workspace` from
  `WorkspaceContext`.
- `WorkspaceContext` may continue to exist for data/UI scoping, but it is not the
  authorization authority.

Do not change `WorkspaceContext` implementation in 026A merely because
multi-workspace context is future work; current helper still explicitly uses the
single-default-workspace MVP shortcut.

**Seven atomic permissions (frozen minimum catalogue)**

Amend the frozen minimum catalogue from six to seven:

| Permission | Authority |
|---|---|
| `view_connector_accounts` | Safe Layer A/B `ConnectorAccount` read only; no decrypted credentials/settings secrets. |
| `run_connector_discovery` | Manual discovery and the safe read surface necessary to follow its progress/result. |
| `manage_connector_accounts` | Create/manage account settings and credentials, disable/archive where supported, run connection check; also permits safe account read and manual discovery. |
| `view_sync_mappings` | Read the mapping surface for a `SyncConfiguration`. |
| `manage_sync_mappings` | Mutate confirmed mappings through the approved mutation service. |
| `manage_workspace_access` | Manage workspace Roles / Access profiles and role assignments for memberships. |
| `manage_workspace_tax_settings` | Manage workspace tax-settings surfaces governed by workspace tax authorization. |

`manage_workspace_tax_settings` is not a new invented capability: it already exists
in production authorization and the current permission seeder.

Keep all existing independence rules. No mapping permission is automatically
implied by Admin/Director legacy status.

**026A / 026B staging — legacy backfill execution**

The legacy membership/role backfill **contract** below is frozen. Its **production
execution** is gated to GAP-026B — not GAP-026A.

**026A foundation scope (no production legacy assignment)**

GAP-026A creates the physical/code foundation only.

It **MUST NOT** materialize `WorkspaceUser` / `WorkspaceRole` /
`WorkspaceUserRole` legacy production assignments for existing `Users` as part of
026A activation.

026A may implement and test:

- legacy-state preflight service;
- deterministic/idempotent backfill service;
- template-role construction logic;
- anti-lockout coordinator;

but these services are **not** executed against production legacy users as part of
026A activation.

The global `workspace_permissions` catalogue may be seeded in 026A because it
has no `User` / workspace assignment authority by itself.

026A completion must **not** claim target workspace RBAC is already populated or
authoritative.

Reason 026A does not execute production legacy assignment:

- current `UserResource` still exposes staff `User` hard-delete, legacy `role`, and
  `is_active` mutation — creating authoritative `WorkspaceUser` rows early would
  let a non-authoritative shadow membership graph drift before 026B;
- once `WorkspaceUser` rows exist, frozen `users` → `workspace_users` ON DELETE
  RESTRICT changes hard-delete behavior while legacy User lifecycle guards are not
  yet cut over.

**Legacy-data backfill contract (026B production execution)**

**026B authorization cutover gate (frozen ordering)**

Before workspace RBAC becomes authoritative in 026B:

1. run Spatie assignment preflight;
2. run legacy workspace/Admin preflight (below);
3. execute deterministic/idempotent legacy backfill from **current** legacy state;
4. perform fresh anti-lockout validation;
5. only if all four succeed may workspace-permission authorization become
   authoritative.

Failure at any step: STOP → no permission-policy cutover → no Access/Roles
mutation activation → no Mapping authorization activation. Do not silently fall
back to partial RBAC.

Exact deployment mechanics (migration vs guarded deployment command, maintenance-mode
sequencing, etc.) are implementation-level and must preserve this ordering.

**Legacy User lifecycle compatibility**

Current `User` lifecycle can still:

- create staff `Users`;
- change legacy `role`;
- change `is_active`;
- hard-delete `Users` (via `UserResource`).

Once `WorkspaceUser` rows exist, frozen `users` → `workspace_users` ON DELETE
RESTRICT changes hard-delete behavior.

Therefore GAP-026B must bring the necessary `User` lifecycle integrity protection
(**`is_active`**, hard-delete, deterministic multi-workspace anti-lockout locking)
**no later than** the same cutover in which legacy `WorkspaceUser` assignments are
materialized and made authoritative.

Do not weaken or remove the RESTRICT FK.

**Automatic backfill deployment preflight (fail-closed — 026B step 2)**

Before automatic 026B legacy membership/role backfill (step 3 above), require
fail-closed verification of:

1. exactly one row exists in `workspaces`;
2. that same row is the exactly-one row with `is_default = true`;
3. at least one active legacy staff `User` exists with:
   - `customer_id IS NULL`
   - AND `users.is_active = true`
   - AND `role IN (Admin, Director)`

Reason:

- historical application has no membership data from which multiple existing
  workspaces can be assigned safely;
- assigning all staff to additional workspaces would be privilege escalation;
- leaving another workspace without an effective `manage_workspace_access` holder
  violates anti-lockout;
- inactive Admin/Director does not satisfy active-membership semantics.

If any precondition fails: STOP → report actual counts/state → do not infer
memberships → do not auto-promote a different legacy role → do not reactivate a
`User` → do not assign all users to every workspace. Require explicit
reconciliation before retrying deployment.

Once preflight succeeds, preserve `workspace_users.is_active = true` for the
legacy membership row itself regardless of `users.is_active` (see below).

026B legacy backfill must resolve the current default workspace by
`is_default = true`. Never hardcode the UUID.

Create a `WorkspaceUser` row for each existing staff `User` where
`customer_id IS NULL`, with `workspace_users.is_active = true` for the legacy
membership itself, regardless of `users.is_active`.

Why: `workspace_users.is_active` is a workspace membership switch;
`users.is_active` is a global User switch. Effective authorization still requires
both to be true. This preserves the semantic behavior that globally disabling and
later re-enabling an existing staff User does not silently destroy or permanently
disable their workspace membership.

Initial role/permission backfill — preserve current effective connector/tax
behavior and do **not** grant new Mapping capability:

| Legacy role | Granted permissions |
|---|---|
| Admin / Director | `view_connector_accounts`, `run_connector_discovery`, `manage_connector_accounts`, `manage_workspace_tax_settings`, `manage_workspace_access` |
| Merchandiser | `view_connector_accounts`, `run_connector_discovery` |
| Manager / Programmer / Warehouse | none of these seven permissions |
| `view_sync_mappings` | assigned to nobody |
| `manage_sync_mappings` | assigned to nobody |

`manage_workspace_access` is the one bootstrap capability required to satisfy the
already-Resolved anti-lockout invariant. Do not describe it as a generic "Admin
gets all permissions" role.

**Backfill materialization — roles, not direct user permissions**

The target schema intentionally has no direct membership-permission table.
Permissions in the legacy matrix above are materialized through deterministic
seeded/bootstrap `WorkspaceRole` bundle(s). Those role(s) are assigned to the
relevant `WorkspaceUser`. No direct `User` / `WorkspaceUser` permission grant is
created.

Merchant-facing role `name` is not authorization identity; stable non-null
`template_key` is the bootstrap identity for platform template roles. Do not invent
per-user overrides.

**Spatie assignment deployment preflight (026B step 1)**

Before any production backfill/cutover, inspect existing Spatie assignment tables:

- `roles`
- `model_has_roles`
- `model_has_permissions`
- `role_has_permissions`

Expected application baseline currently has no known role/direct-permission
assignment write path, but production DB must not be assumed clean from source
code alone.

If unexpected assignment rows exist: STOP → report exact counts/types → reconcile
explicitly → do not silently discard or auto-convert them.

Do not remove Spatie package/tables in GAP-026A or GAP-026B as a prerequisite.
Package/table removal is a later cleanup decision after cutover complete,
production DB audit complete, and repository usage search proves it inert.

**Anti-lockout algorithm (transaction coordinator)**

Every authoritative workspace-access mutation that can change effective
`manage_workspace_access` must serialize on one stable mutex:

```sql
SELECT workspace
FOR UPDATE
```

Then:

1. apply proposed membership / role assignment / role-permission mutation;
2. perform a fresh post-mutation effective-permission query;
3. require at least one active membership with `manage_workspace_access`;
4. otherwise reject/rollback.

Lock the `Workspace` row, not merely the touched membership/role rows, because two
concurrent transactions can otherwise create write skew by each removing a
different surviving administrator.

For global User deactivation/deletion after authorization cutover:

- determine all affected workspace memberships;
- lock those workspace rows in deterministic `workspace_id` order;
- evaluate anti-lockout in every workspace before commit.

`lockForUpdate()` concurrency correctness must ultimately be verified on MySQL 8,
not inferred from SQLite.

Phase timing:

- In 026A, physical tables, models, `WorkspaceAuthorization`, preflight/backfill
  **machinery**, and anti-lockout coordinator may be implemented and tested, but
  legacy production authorization/write paths are not cut over and production legacy
  `WorkspaceUser` / role assignments are **not** materialized.
- 026B begins with fail-closed production preflight → current-state legacy backfill
  → anti-lockout validation → authorization cutover, then connector/tax/mapping
  policy cutover, Access/Roles UI, and `User` lifecycle protection in the same
  cutover window.
- Before 026B becomes authoritative, it must revalidate anti-lockout against
  current production state; route every newly authoritative access mutation through
  the coordinator; protect global `User.is_active` / hard-delete paths that could
  invalidate effective access.

026A alone does not make every legacy User mutation anti-lockout-safe and does not
populate or authorize workspace RBAC in production.

**Platform plane and cabinet boundaries**

- `PlatformAdminAuthorization` remains outside GAP-026 workspace RBAC.
- Workspace permissions can never grant platform-global authority over connector
  definitions/canonical registry/platform governance.
- Current Admin / Programmer `UserRole` checks in that platform plane remain
  transitional legacy, not a newly approved permanent authorization design. Their
  eventual replacement requires a separate platform-authorization decision.
- `/cabinet` is outside GAP-026. Its authenticated principal is `Customer`, not
  workspace staff `User`. Do not mix customer/cabinet authorization into workspace
  staff RBAC.

### Workspace RBAC authority cutover [Resolved — GAP-026B-0, 2026-08-13]

GAP-026B changes workspace authorization **only for explicitly cut-over domains**
from the transitional combination of:

- `User.role`
- `WorkspaceMembership`
- global Spatie permissions

to the authoritative evaluation path:

```text
User
  × explicit Workspace
  × active WorkspaceUser
  × WorkspaceRole(s)
  × canonical WorkspacePermission(s)
```

Outside Connector / Tax / Mapping / Workspace Access scopes cut over by GAP-026B,
legacy authorization remains transitional until **GAP-027**. GAP-026B does **not**
complete platform-wide RBAC and does **not** claim whole-admin panel admission,
`canAccessPanel()`, unrelated Filament Resources, `/cabinet`, or platform-global
governance are already RBAC-complete.

**Cut-over domains (026B only)**

| Domain | Authoritative after cutover |
|---|---|
| `ConnectorAccount` read / discovery / management | workspace permissions via `WorkspaceAuthorization` |
| Connector safe presentation / merchant Integrations gating | effective workspace permissions (not `User.role`) |
| Workspace tax settings | `manage_workspace_tax_settings` on explicit `Workspace` |
| Mapping read / mutation seam | `view_sync_mappings` / `manage_sync_mappings` |
| Merchant Access / Roles (existing memberships only) | `manage_workspace_access` |

**ConnectorAccount permission matrix (exclusive authority after cutover)**

Safe `ConnectorAccount` read — allowed when effective workspace permissions
contain **at least one** of:

- `view_connector_accounts`
- `run_connector_discovery`
- `manage_connector_accounts`

Reason: `run_connector_discovery` necessarily includes the safe read required to
observe its progress/result; `manage_connector_accounts` includes safe read and
discovery. Role names do **not** contribute.

Discovery control — visible/eligible when:

- `run_connector_discovery` **OR** `manage_connector_accounts`

Actual manual execution additionally requires existing account runtime eligibility
(for example `is_enabled`).

Management — **only** `manage_connector_accounts` for:

- create account;
- settings changes;
- credential replace/remove;
- connection check;
- disable/archive where supported.

After 026B authority cutover, legacy labels Admin, Director, Merchandiser, Manager,
Programmer, Warehouse, and legacy Spatie grants have **no** connector authorization
semantics by themselves.

**026B repository status (post-B-2 implementation):** `ConnectorAccountPolicy` and
`ConnectorAuthorization` evaluate the workspace-RBAC matrix above via
`WorkspaceAuthorization`. Legacy `User.role` labels have no connector authorization
semantics in cut-over paths. Reference-environment production cutover completed
2026-08-14 (see GAP-026B in `IMPLEMENTATION_GAPS.md`).

**Connector dispatch authorization freshness (frozen)**

Manual connection-check and discovery dispatch may perform an optional preliminary
authorization for early rejection only.

After acquiring the existing `ConnectorAccount` critical-section lock, authorization
must be evaluated again before any sensitive operational state is returned or
consequential dispatch-state mutation is performed.

This post-lock check is the **authoritative** dispatch authorization decision.

A pre-lock hydrated `User` object is **not** authorization truth.

`WorkspaceAuthorization` must evaluate all effective Workspace authority inputs from
persistence:

- global `users.is_active`;
- `workspace_users.is_active`;
- explicit `Workspace` ownership;
- current `WorkspaceRole` assignments;
- current canonical `WorkspacePermission` assignments.

GAP-026B-2 implementation **must** evaluate those authority inputs through one
database-backed effective-permission projection/query rather than trusting
`User.is_active` from the supplied Eloquent instance.

Authoritative `WorkspaceAuthorization::effectivePermissions()` must **not** rely on a
sequence of partially stale hydrated-model checks as equivalent compliance.

Connector authorization remains scoped to the explicit `Workspace` and the existing
`ConnectorAccount` critical section.

**Connector dispatch revocation boundary (frozen residual)**

Authorization is a point-in-time post-lock authorization snapshot inside the enqueue
transaction.

- If revocation/deactivation commits **before** the authoritative post-lock authorization
  snapshot → fail closed.
- If it commits **after** that snapshot, GAP-026B-2 does **not** require another
  initiating-actor authorization check before the enqueue transaction commits.
- The already-authorized in-flight enqueue transaction may complete.
- Already queued/running Connector work is **not** retroactively cancelled.
- Connector jobs do **not** re-authorize the initiating `User` at execution time.
- Results/side effects remain workspace-owned; later merchant visibility remains governed
  by live workspace authorization.

Do **not** call this “authorized as of transaction commit”; no shared serialization lock
guarantees authority at commit time.

Future cancellation-on-revocation remains a new Stop-and-Amend.

**Connector dispatch must not acquire Workspace anti-lockout mutex (frozen)**

Connector dispatch must **not** acquire the `Workspace` anti-lockout row mutex merely
to serialize against `User` deactivation.

- Preserve existing per-`ConnectorAccount` dispatch serialization.
- Do **not** add a `User`-row mutex.
- Do **not** couple Connector dispatch to Access anti-lockout locking in GAP-026B-2.

The architectural reason is lock-domain separation and per-account granularity — not a
presumed environment-specific lock-wait default.

**Connector presentation invariant (capability-based, not job-title-based)**

Connector presentation is **capability-based**, never job-title-based.

A user who has safe read/discovery but does **not** have `manage_connector_accounts`
must receive the restricted safe projection regardless of legacy `User.role`.

The safe-only projection must continue excluding at least the existing
sensitive/configuration attributes:

- `credentials`
- `settings`
- `base_url`
- `store_code`
- `tenant_context`
- `auth_profile`

and management-only connection-check state/relations where currently protected.

The restriction must happen **before** sensitive state becomes part of merchant-facing
Livewire/Filament record state — not merely through visual hiding.

- A legacy Admin label must **not** widen presentation if effective workspace
  permissions are read-only.
- A legacy Merchandiser label must **not** restrict presentation if the membership
  legitimately has `manage_connector_accounts`.

**026B repository status (post-B-2):** `ConnectorAccountCapabilityPresentation`
replaced transitional `ConnectorAccountMerchandiserPresentation` and applies
capability-based safe projection from effective workspace permissions — not
`User.role === Merchandiser`.

**WorkspaceMembership is not an additional Connector authority gate**

After cutover, `WorkspaceMembership` is **not** an additional authorization gate for
`ConnectorAccount` operations. Explicit `WorkspaceAuthorization(User, Workspace,
permission)` already incorporates active `WorkspaceUser` membership and permission
evaluation.

GAP-026B-2 migrated connector entry/write paths that previously performed a separate
legacy membership check before Gate/permission evaluation.

`ConnectorAccountSettingsService` no longer calls `WorkspaceMembership::belongs()`
before Gate authorization in cut-over connector paths.

Do **not** globally rewrite or delete `WorkspaceMembership` as part of GAP-026B;
unrelated legacy use remains GAP-004 / GAP-027 territory.

**Workspace tax-settings authority**

Target authority:

```php
WorkspaceAuthorization::allows(
    User $user,
    Workspace $workspace,
    'manage_workspace_tax_settings',
);
```

No Admin/Director special case. No legacy Spatie permission authority.

`WorkspaceContext` may resolve the current UI target `Workspace`, but authorization
must receive that resolved `Workspace` explicitly.

**TOCTOU protection (frozen):** every consequential tax-settings write must
re-authorize against the current explicit `Workspace` immediately before persistence —
including normal save and confirmation action after VAT-rate warning.

**026B repository status (post-B-2):** `WorkspaceTaxSettingsAuthorization` and
`WorkspaceTaxSettings::persist()` perform write-time reauthorization against the
explicit `Workspace` before persistence.

**Mapping authorization seam**

GAP-026B introduces authorization for Mapping, but **not** Mapping UI.

| Operation | Required permission |
|---|---|
| Mapping read | `view_sync_mappings` **OR** `manage_sync_mappings` |
| Mapping mutation | `manage_sync_mappings` |

- `manage_connector_accounts` does **not** imply either Mapping permission.
- Mapping permissions do **not** imply Connector settings/credential access.

Keep existing `FieldMapping` domain mutation/read machinery free of `User`/role policy
logic. Authorization belongs at an outer application/policy seam receiving `User` +
explicit `Workspace` / workspace-owned `SyncConfiguration` before invoking the
existing projector/mutation service.

Do **not** rewrite `FieldMappingMutationService` into an actor-aware RBAC service
merely for GAP-026B. Task **4C-1c-2b** shipped the first merchant Mapping UI (PR #139).

**Merchant Access / Roles scope (026B)**

026B introduces a minimal merchant-facing workspace access area using ordinary
business vocabulary:

- **Доступ**
  - **Користувачі**
  - **Ролі** / **Профілі доступу**

No Spatie/RBAC/pivot/team terminology in merchant UI.

**Users / memberships — 026B supports (existing `WorkspaceUser` only)**

- list existing members;
- display active/inactive membership state;
- display assigned access roles/profiles;
- assign one or more existing roles;
- remove role assignments;
- deactivate membership;
- reactivate membership.

**Roles — 026B supports**

- list roles;
- create merchant custom role;
- rename role;
- edit role's canonical seven-permission bundle;
- show number of assigned memberships;
- delete a role only when unused and when the operation passes all integrity rules.

All authoritative writes that can affect `manage_workspace_access` must route through
`WorkspaceAccessMutationCoordinator`. `manage_workspace_access` is required for every
Access/Roles mutation.

Read access to the Access management surface in the first 026B slice also requires
`manage_workspace_access`; do **not** invent a separate `view_workspace_access`
permission.

**Explicit temporary limitation — existing memberships only (user-approved)**

GAP-026B does **NOT** create or attach new `WorkspaceUser` memberships.

026B does **not** implement:

- invite employee;
- attach an existing `User` to another `Workspace`;
- create a `Workspace` membership;
- membership hard-delete;
- multi-workspace onboarding.

The Access screen must **not** present a functional Add user / Invite user action.

Near the place where a merchant would naturally expect such an action, show concise
informational copy equivalent to:

> Adding new users will be available in the next access-management stage. For now,
> access can be managed for existing company users.

Exact localization/copy may follow project conventions; do **not** expose GAP numbers
to merchants. This is an intentional transitional limitation, not final product
behavior. Removal is owned by **GAP-027**.

**Anti-lockout routing for Access mutations (026B applicability)**

The following mutations must route through `WorkspaceAccessMutationCoordinator`:

- assign role to membership;
- remove role assignment;
- activate membership;
- deactivate membership;
- create role if its permission state participates in authoritative access;
- edit role permission bundle;
- delete role.

The coordinator remains an integrity boundary, **not** actor authorization.

Authoritative sequence:

1. optional preliminary authorization for early rejection only (non-authoritative fast-fail/UX);
2. `WorkspaceAccessMutationCoordinator` acquires the explicit `Workspace` row mutex;
3. inside the locked transaction:
   - freshly reload the requesting `User` from persistence by stable ID;
   - fresh actor authorization against the locked explicit `Workspace` using the reloaded `User`;
   - freshly resolve/revalidate mutable membership/role targets against the locked `Workspace`;
   - perform the mutation;
4. fresh effective-holder query;
5. reject/rollback if zero holders.

Normative requirements:

- post-lock actor authorization is **mandatory**;
- any pre-lock authorization is optional fast-fail only and is **not** authoritative for mutation execution;
- do not reuse a pre-lock hydrated `User` as authorization truth;
- **post-B-2 repository implementation:** authoritative `WorkspaceAuthorization`
  evaluates effective permissions from persistence via one database-backed projection
  (including `users.is_active`, `workspace_users.is_active`, ownership, role assignments,
  and canonical permission assignments — not from the supplied Eloquent `User` instance);
- Access post-lock fresh `User` reload by stable ID remains required and must **not** be
  removed merely because the central authorization query becomes DB-backed;
- membership/role identity and mutable target state relevant to the mutation must be freshly resolved/revalidated after the `Workspace` lock;
- this applies the same TOCTOU principle already frozen for consequential Tax writes to Access/Roles mutations.

Do **not** merge actor authorization into `WorkspaceAccessMutationCoordinator`.

**User lifecycle during transition**

`User.role` — after backfill/cutover:

- changing `User.role` **MUST NOT** synchronize, create, delete, or replace
  `WorkspaceRole` assignments;
- it may remain used by unrelated legacy/GAP-027/platform surfaces temporarily;
- for domains cut over by 026B, `User.role` has **zero** authorization effect.

`customer_id` — not Workspace RBAC authority. Changing it must not implicitly create/
delete `WorkspaceUser` membership or rewrite role assignments in GAP-026B.

**Reactivation** (`users.is_active`: false → true):

- does not recreate/reset roles or membership state;
- existing `WorkspaceUser` membership state remains as stored.

**Global deactivation** (`users.is_active`: true → false):

- must use a guarded lifecycle service;
- because 026B forbids membership creation, the set of existing memberships is stable
  enough for the already-Resolved algorithm:
  - discover all existing `WorkspaceUser` memberships for the `User`;
  - acquire corresponding `Workspace` row locks in deterministic `workspace_id` order;
  - update global `User` active state in the guarded transaction;
  - run fresh anti-lockout validation for every affected workspace;
  - rollback if any workspace would have zero effective `manage_workspace_access`
    holders.
- Workspace access mutations already serialize on their `Workspace` row, so role/
  permission/membership activation changes serialize against this deactivation path.
- Do **not** introduce a new `User`-row mutex in GAP-026B while membership creation
  is forbidden.

**Hard delete (first cutover behavior)**

- `User` with ≥1 `WorkspaceUser` membership → hard delete **denied** → deactivate
  instead.
- Do **not** weaken `users` → `workspace_users` ON DELETE RESTRICT.
- A `User` with no `WorkspaceUser` membership may retain current legacy hard-delete
  behavior unless another existing invariant forbids it.
- Do **not** introduce destructive membership cleanup in 026B.

Current `UserResource` exposes `is_active`, `role` mutation, and hard `DeleteAction` —
these are real migration seams.

**Production authority activation boundary (one-time cutover)**

Merging 026B code into `develop` is **not** itself production cutover execution.

The 026B authority-changing release must be activated in a controlled maintenance
window. Do **not** use a permanent dual-authority mode or role fallback. Do **not**
add a persistent legacy/new RBAC feature flag unless a later verified implementation
blocker forces a new Stop-and-Amend.

**First B-2 production deployment = maintenance-window cutover (frozen)**

The **first production deployment** that contains GAP-026B-2 authority-switching
code must **itself** be the one-time maintenance-window cutover deployment.

- GAP-026B-2 must **not** be delivered through ordinary recurring deployment and then
  exposed to merchant traffic pending a later EXECUTE.
- Merchant traffic remains blocked from B-2 deployment through successful EXECUTE →
  fresh anti-lockout validation → smoke verification.
- Pre-EXECUTE B-2 authority must **never** fall back to legacy roles.
- Recovery is reconciliation/completion of the cutover while traffic remains blocked,
  not authority fallback.

**Required one-time sequence (frozen ordering)**

1. verified DB backup / snapshot;
2. put application into maintenance / block merchant writes;
3. deploy the **complete** approved GAP-026B-1 + GAP-026B-2 authority-changing
   cutover runtime while traffic is already blocked;
4. run all pending migrations;
5. ensure the canonical `workspace_permissions` catalogue via the approved target
   seeder;
6. run guarded RBAC cutover in **CHECK-ONLY** mode;
7. if safe: run **EXECUTE** (current-state deterministic legacy backfill);
8. run fresh anti-lockout validation;
9. run focused authorization/cutover smoke checks;
10. clear/reload application state and restart relevant queue workers;
11. resume traffic.

If any step from preflight through smoke verification fails:

- application remains unavailable for merchant writes;
- no partial authority fallback;
- no role-based Connector/Tax/Mapping fallback;
- investigate/reconcile before traffic resumes.

Existing resolved cutover order must remain:

Spatie preflight → legacy workspace/Admin preflight → current-state backfill → fresh
anti-lockout validation → new authority becomes usable.

**Cutover command contract (CHECK-ONLY / EXECUTE — slice ownership frozen)**

Freeze a guarded one-time application command/service contract with two modes, split
across GAP-026B-1 and GAP-026B-2:

**CHECK-ONLY (GAP-026B-1)**

- May exist and run in a B-1-only release.
- Performs no RBAC assignments or production legacy membership/role materialization.
- Reports structured A-2 preflight state.
- May run before maintenance for advance diagnostics.

**EXECUTE (GAP-026B-2 only)**

- Must **not** exist as an executable production mode in a release that does not also
  contain GAP-026B-2 authority-switching runtime code.
- B-1-only EXECUTE is **forbidden by slice placement**, not by operator confirmation.
- Requires application maintenance mode / explicitly quiesced merchant-write
  environment; refuses to proceed otherwise; re-runs fresh preflight itself; invokes
  existing transactional backfill machinery; performs fresh post-backfill anti-lockout
  validation; fails non-zero on any unsafe state.
- Production legacy assignment materialization is structurally unavailable until the
  release also carries GAP-026B-2 authority code.

Do **not** introduce `--confirm-maintenance-window`, persistent environment activation
state, marker tables, legacy/new authority selectors, or dual-authority policy switches
merely to enforce this boundary.

**Why B-1 cannot materialize production RBAC early**

GAP-026A intentionally did not materialize production `WorkspaceUser` / role
assignments early because legacy `User.role`, `is_active`, and hard-delete could
continue changing while the new graph remained non-authoritative — allowing a shadow
graph to drift before cutover.

Early materialization while legacy authorization is still live would create a
non-authoritative shadow RBAC graph. Because `User.role` changes after cutover are
explicitly forbidden from synchronizing `WorkspaceRole` assignments, a legacy
role/lifecycle mutation between premature backfill and B-2 activation could leave
stale grants that later become authoritative.

Exact Artisan command/class name is implementation-level.

Do **not** make migration/seeder/service-provider boot automatically execute
production legacy backfill.

Operational runbook detail: `DEPLOY.md` → **GAP-026B one-time Workspace RBAC
cutover**.

**GAP-026B implementation split (frozen)**

| Slice | Future runtime scope |
|---|---|
| **GAP-026B-1 — Access & Cutover Machinery** | Guarded cutover command/service: **CHECK-ONLY mode only** (diagnostics; no RBAC assignment/materialization). Access/Roles application write services; existing-membership role assignment/removal; membership activate/deactivate; role create/rename/permission edit/safe unused-role delete; merchant Access/Roles UI; global `User` deactivation integrity service; hard-delete guard; CHECK-ONLY cutover/runbook tests. **Explicitly no** connector/tax policy authority switch. **B-1-only release must not ship an executable production EXECUTE mode** — no production legacy membership/role backfill in a B-1-only deployment. |
| **GAP-026B-2 — Authority & Presentation Cutover** | **Done / production-activated (2026-08-14).** EXECUTE mode of the guarded cutover command/service (production legacy assignment materialization). `ConnectorAccountPolicy` migration; remove legacy `WorkspaceMembership` from connector authority paths; permission-based safe Connector presentation; merchant Integrations/catalog gating migration; tax authorization migration + write-time reauthorization; Mapping authorization seam; DB-fresh `WorkspaceAuthorization` effective-permission evaluation (persistence-backed authority inputs, not hydrated `User` state); Connector post-lock dispatch authorization freshness; accepted asynchronous revocation boundary (post-snapshot enqueue is not retroactively cancelled); explicit no-`Workspace`-row-mutex / no-`User`-row-mutex rule for Connector dispatch; cross-workspace + safe-state + Livewire serialization regressions; EXECUTE cutover/runbook tests. Reference-environment maintenance-window cutover completed 2026-08-14. Layer B mapping UI (4C-1c-2b) shipped in PR #139 after B-2 production EXECUTE. |

This separates repository implementation readiness from environment production
activation.

**Explicit non-goals (026B-0 and later 026B runtime)**

Do **not** silently absorb into GAP-026B:

- new `WorkspaceUser` creation/onboarding;
- invitations;
- membership hard-delete;
- multi-workspace selector UX;
- whole-admin permission vocabulary;
- whole-admin policies;
- `canAccessPanel` rewrite;
- `strictAuthorization`;
- `PlatformAdminAuthorization` redesign;
- `/cabinet` authorization;
- Spatie package/table removal;
- direct per-user permission overrides;
- deny/muting;
- workspace suspension;
- Field Browser Layer-C redesign;
- Mapping UI itself;
- sync execution/scheduling authorization.

These remain in GAP-025 / GAP-027 / later explicitly resolved work.

**GAP-027 transitional state (new staff after 026B cutover)**

A new staff `User` created through transitional legacy `UserResource` after the 026B
cutover receives **no** `WorkspaceUser` automatically. Connector/tax/mapping/access
surfaces fail closed for that new `User` until onboarding is implemented in GAP-027.
This does **not** authorize adding a role-based fallback.

Current panel admission may still admit such a `User` to unrelated legacy areas until
GAP-027; this is transitional and must not be represented as completed RBAC. Current
`canAccessPanel()` remains legacy-role-based today.

## Product Catalogue Context


The Product Catalogue is the core of the platform.

It manages product identity, product variants, categories, media and product field values.

The user should feel that they are managing simple products.

Internally, the platform must distinguish between:

- product;

- product variant;

- product fields;

- prices;

- availability;

- channel projections.

### Product


A Product is the general product card.

It represents the shared product identity and common information.

Examples:

- Stroller Anex IQ

- Car Seat Cybex Solution

- Baby Bottle Philips Avent

- Office Chair Model X

A product may have one or more variants.

For MVP, every product should automatically receive one default variant.

The user should not be forced to understand variants during basic product creation.

A product may contain common information such as:

- workspace;

- product type;

- category;

- product name;

- description;

- brand;

- status;

- primary image;

- product URL;

- common attribute values.

The product should not directly contain every possible product field as database columns.

Extensible product data should be stored through the Attribute Dictionary and attribute value storage.

### ProductVariant


A ProductVariant is the concrete sellable unit.

It represents the thing that can be priced, stocked and ordered.

Examples:

- stroller Anex IQ, black color;

- stroller Anex IQ, grey color;

- T-shirt, size M, blue;

- same product with a different SKU or GTIN;

- same model with a different package quantity.

A product variant may contain:

- workspace;

- product;

- SKU / article number;

- GTIN / EAN;

- variant status;

- base price cache;

- sale price cache;

- cost price cache;

- currency;

- available quantity cache;

- availability status;

- primary image;

- default variant flag.

For MVP, each product should have one automatically created default variant.

The default variant should be hidden from the user unless variant functionality is enabled later.

This gives the user a simple product experience while keeping the architecture ready for colors, sizes and other variants.

### Product and Variant Rule


The platform must follow this rule:

- Product = shared product card and common information.

- ProductVariant = sellable SKU-level unit.

Pricing and availability should usually belong to the variant level.

This avoids future problems when one product has several sellable versions with different SKU, price or stock.

### Platform Product Capability Baseline
[Resolved]

The platform is a universal multi-tenant SaaS e-commerce Product Data Platform. It is not defined by a tiny fixed field list, a named customer, a first connected commerce account, or the first Magento connector.

Reference clients validate the platform; they do not define the platform.

The platform must support heterogeneous e-commerce catalogues across product verticals. Illustrative examples only — not a closed enum and not encoded as generic Product-core logic:

- apparel;
- footwear;
- electronics;
- home/furniture;
- toys;
- beauty;
- automotive parts;
- industrial products;
- food/non-food packaged goods;
- sports;
- specialty retail;
- B2B supplies.

#### Product + Variant is a first-class invariant

Normal platform cardinality:

```text
Product
  └── 0..N ProductVariants
```

Product variants may differ by merchant-defined option dimensions such as color, size, material, capacity, memory, voltage, pack size, style, configuration, or arbitrary workspace-defined dimensions. These examples are not a closed enum.

The architecture must not assume:

- one Product = one SKU;
- one Product = one price;
- one Product = one inventory quantity;
- one Product = one image;
- one Product = one external record.

Where domain ownership places SKU/GTIN/price/inventory/media on variants, connector execution must respect that model.

MVP UI may still auto-create one hidden default variant for a simple single-SKU Product so merchants are not forced to understand variants. That is UX hiding. Do not invent a fake default variant merely to simplify Magento.

Platform invariant remains:

```text
Product → 0..N ProductVariants
```

Zero variants does not mean Magento configurable. Magento Product Export V1 execution semantics distinguish:

- ordinary non-variant / single-sellable-unit Product → Magento simple;
- Product with meaningful option variants → Magento configurable family.

#### Configurable / variant product families are mandatory

**Product + variants/options** is a configurable / variant product family. This is a first-class platform capability.

This is different from a true **bundle / kit / composite product**, which combines multiple independently meaningful sellable components.

Both concepts are distinct platform capabilities.

Bundle / kit / composite composition is a legitimate future Product composition capability. Today's Product/Sync boundaries must not make it impossible. This baseline does not invent a full bundle persistence schema. Magento bundle-product support is not in Magento Product Export V1.

Do not declare Magento Product Export V1 DONE while ordinary platform multi-variant Products are unsupported.

#### Rich and extensible Product data

The platform's product vocabulary is extensible and standards-backed; canonical fields are defaults/known concepts, not the maximum allowed product model.

Preserve FieldDefinition, FieldBinding, workspace custom fields, mapping, and dynamic values as the mechanism for diverse product characteristics.

Do not hardcode every possible e-commerce attribute as a Product DB column.

Do not treat current Adobe rows in `docs/data/canonical_product_field_mappings.csv` as the complete Product vocabulary.

#### Product assets / rich content

The Product Data Platform conceptually supports rich Product assets including at least:

- images;
- video;
- product manuals / instructions;
- documents / PDFs;
- certificates / technical documents where applicable.

The architecture must allow both Product-level assets and Variant-specific assets where business semantics require them.

Current implementation truth: Product currently carries an `images` JSON field. No first-class MediaAsset / ProductMedia / VariantMedia runtime model with asset-type or variant-level semantics is implemented.

The Domain Model names MediaAsset / ProductMedia / VariantMedia as the conceptual target. Do not create a competing second media model. Do not collapse every asset forever into `products.images` JSON if richer media entities are later implemented along that existing conceptual path.

This section defines required conceptual extensibility, not a new persistence schema. Do not freeze storage implementation beyond the already-resolved hybrid Field Dictionary and the current minimal `products.images` JSON.

Required semantic concerns to preserve or explicitly leave extensible:

- asset type;
- product/variant association;
- ordering;
- primary/role;
- locale where relevant;
- external/source reference;
- importability;
- exportability.

The target architecture must remain capable of evolving from the current minimal representation toward first-class Product/Variant assets without forcing connector-specific media fields into Product core.

If Magento Product Export V1 does not consume video or documents, mark:

`PLATFORM CAPABILITY — NOT IN THIS CONNECTOR V1`

not:

`BACKLOG BECAUSE PLATFORM DOES NOT NEED IT`

#### Imported content is not limited to primitive attributes

The connector/import architecture must be capable of bringing in structured attributes, variants/options, images, videos, documents/instructions, identifiers, relations where supported, and domain-owned values through their domains.

Do not implement all import domains in this contract. FieldMapping remains semantic correspondence, not a universal transport DSL.

#### Localization and market richness

Do not assume one language or one storefront scope as permanent Product semantics.

Preserve compatibility with:

- localized product content (JSONB translation objects for `is_localizable = true`; MVP UI shows the primary workspace language);
- multi-store / store-view external contexts (`SyncConfiguration.external_context` is connector-owned, not Product-core columns);
- channel-specific presentation.

Do not invent another localization persistence system here.

#### Connector V1 must not redefine platform Product capabilities as nonexistent

Connector V1 scope may defer vendor-specific support for some platform Product capabilities, but it must never redefine those platform capabilities as nonexistent.

Examples: platform video asset; platform instruction/document asset; bundle/kit composition; advanced localization; additional product types.

### Product Type


A ProductType defines an internal template for product structure.

In the user interface, this may be called:

- Product Type

- Тип товара

For MVP, the default product type is:

- Basic Product

- Обычный товар

The user should not be forced to choose or configure product types in MVP.

Product types may later define:

- which fields are shown;

- which fields are recommended;

- which fields are required for a channel;

- whether variants are enabled;

- which fields are product-level;

- which fields are variant-level.

Product types should remain mostly invisible until the business needs them.

### Category


Categories are workspace-owned.

For MVP, the platform should support a simple category tree inside each workspace.

A category may contain:

- workspace;

- parent category;

- name;

- slug;

- sort order;

- status.

The platform should not introduce global taxonomy in MVP. See **Product classification model** below for how this relates to the separate, not-yet-built Standard Category concept.

Global taxonomy, marketplace taxonomy mapping and channel-specific category mapping should be handled later in connector/channel mapping layers.

This keeps the platform simple for small businesses that already think in their own Excel or Google Sheets categories.

### Media


Media assets should be reusable.

Initial media entities:

- MediaAsset

- ProductMedia

- VariantMedia

A media asset may belong to a workspace.

A product or variant may reference one or more media assets.

For MVP, this can be simple.

The first version may support:

- primary product image;

- additional product images later.

Media handling should not become a full DAM system in MVP.

See **Platform Product Capability Baseline** for conceptual images, video, and
document/instruction assets. Current runtime remains `products.images` JSON.

## Field Dictionary Context

> **Renamed from "Attribute Dictionary Context".** This section describes the
> canonical, target architecture — entity-agnostic from the start (Product,
> Variant, and Customer share this registry; see "Field Foundation
> (cross-object fields)" in Domain Decisions for the rationale of this
> generalization and for what it replaces). As of this writing, the
> **codebase still uses the pre-generalization names** (`AttributeDefinition`,
> `product_attribute_values`, `variant_attribute_values`) — that code migration
> is tracked separately; see `IMPLEMENTATION_GAPS.md`, GAP-016. Do not read this
> section as a description of current code; it is the target this and future
> Cursor tasks must build toward.

The Field Dictionary manages field metadata definitions, distinct from the storage of actual values. It acts as the structural registry for both core system fields and custom vendor properties, enforcing data integrity before any product, customer, or (future) other-entity updates reach the database.

### Hybrid Field Storage Implementation


To balance high performance with infinite extensibility, the platform utilizes a hybrid storage engine:

- **Column-Backed Fields:** Core operational and transaction-critical fields (name, sku, gtin, status, cached prices and quantities on Product/Variant; name, tax_number, credit_limit on Customer) are kept as standard database columns for indexing, rapid sorting, and foreign key integrity.

- **Relation-Backed Fields:** Fields that are really a reference to another entity (e.g. Customer's `default_price_list`) are Eloquent relations, not scalar columns or dynamic values.

- **Dynamic Fields:** Extensible, tenant-specific properties (e.g., color, material on Product; a custom segment field on Customer) are stored in Entity-Attribute-Value (EAV) structures, one typed table per bound entity — never a single shared polymorphic table (see Domain Decisions, "Attribute value storage").

- **The Registry Rule:** The Field Dictionary tracks all available fields via two
  cooperating entities — `FieldDefinition` (what the field means) and
  `FieldBinding` (what entity it's attached to and how it's physically stored).
  Every `FieldBinding` must define its `storage_type` (**column, relation, or
  dynamic**) to prevent structural duplication and instruct data-access
  services where to read or write the data payload. `computed` is a `data_type`
  value only (see Computed Fields Operational Boundary) and is never a valid
  `storage_type`.

### Core Entity: FieldDefinition

*(renamed from `AttributeDefinition`; table renamed from `attribute_definitions` to `field_definitions`)*

Defines the semantic meaning, data type, and governance level of a field —
**entity-agnostic**. Does not know which entity (Product, Variant, Customer,
...) it is attached to, or how it is stored — that is `FieldBinding`'s job.

- id (UUID)
- workspace_id (UUID, nullable for system/platform-wide definitions)
- code (String/Slug, immutable)
- data_type (Enum): text, long_text, number, decimal, money, boolean, date, select, multi_select,
  image, url, computed
- scope (Enum): system, platform_library, workspace_custom
- localized_labels (JSONB)
- description (Text, nullable)
- validation_rules (JSONB, nullable)
- is_localizable (Boolean)
- is_multi_value (Boolean)
- status (Enum): active, archived

### Core Entity: FieldBinding

*(new entity; table `field_bindings`)*

Defines what entity a `FieldDefinition` applies to, and how its value is
physically stored for that entity. **One binding = exactly one `object_type`.**
A field that applies to both Product and ProductVariant (e.g. a field that can
be set at product level and overridden per variant) is represented as **two
separate `FieldBinding` rows** on the same `FieldDefinition` — there is no
`both` value and no null/undefined level for entities (like Customer) that
have no variant-equivalent concept. This replaces the previous
`AttributeDefinition.value_level` enum (`product | variant | both`), which is
removed, not carried forward.

- id (UUID)
- workspace_id (UUID, nullable for system/platform-wide bindings — mirrors
  `FieldDefinition.workspace_id` nullability rule)
- field_definition_id (UUID, FK → field_definitions)
- object_type (Enum): product, product_variant, customer *(future: order, supplier, ...
  added only when a real feature needs them — see UI direction in Domain Decisions)*
- storage_type (Enum): column, relation, dynamic
- storage_path (String, nullable): e.g. `product_variants.barcode_ean`,
  `customers.credit_limit`, `Customer.defaultPriceList` (relation accessor);
  null only for `storage_type: dynamic`
- field_group (String, stable snake_case code: basic_information, identifiers, pricing,
  availability, images_media, descriptions, characteristics, b2b, seo, logistics, internal);
  UI labels for groups are translated via Laravel lang/config files, not stored per-binding
- is_required (Boolean)
- is_filterable (Boolean)
- is_sortable (Boolean)
- visibility_settings (JSONB): e.g. {"admin": true, "b2b": false, "channels": {}}
- sort_order (Integer)
- status (Enum): active, archived — allows deprecating a binding independently
  of its `FieldDefinition` (e.g. a field stays defined but is unbound from a
  retired entity type)

**Constraint:** a `FieldBinding` may only be referenced by rows in the value
table matching its `object_type`, and only when `storage_type = dynamic` (see
below). This is an application-level invariant (enforced in the write path),
not expressible as a single database constraint across separate value tables.

### Strict Architectural Rules for Localization and Values


- **JSONB Storage Mandate:** If a `FieldDefinition` has is_localizable = true, the application and database must store its values strictly within a **JSONB structure** inside the dynamic value tables or column entries. Flat string overwrites are prohibited.

- **Separated Value Tables — one per bound entity type, never polymorphic:**

  - `product_field_values` *(renamed from `product_attribute_values`)*:
    `id`, `workspace_id`, `product_id` (FK → products), `field_binding_id`
    (FK → field_bindings, **not** `field_definition_id` — see rationale below),
    `value_text`, `value_num`, `value_jsonb`.
    Unique index: (`workspace_id`, `product_id`, `field_binding_id`).
  - `variant_field_values` *(renamed from `variant_attribute_values`)*:
    `id`, `workspace_id`, `variant_id` (FK → product_variants), `field_binding_id`,
    `value_text`, `value_num`, `value_jsonb`.
    Unique index: (`workspace_id`, `variant_id`, `field_binding_id`).
  - `customer_field_values` *(new)*:
    `id`, `workspace_id`, `customer_id` (FK → customers), `field_binding_id`,
    `value_text`, `value_num`, `value_jsonb`.
    Unique index: (`workspace_id`, `customer_id`, `field_binding_id`).

  **Why `field_binding_id`, not `field_definition_id`:** a raw value row must
  unambiguously resolve to one `object_type` and one `storage_type`. Referencing
  `field_definition_id` directly would allow (in theory) a `customer_field_values`
  row to reference a binding whose `object_type` is `product` — referencing
  `field_binding_id` and enforcing the object_type match at the write-path
  level closes that hole. This does not reopen the "no polymorphic value
  table" decision — each value table still serves exactly one entity type; it
  only changes which column the FK points to.

- **Multi-value fields** (`is_multi_value = true` on `FieldDefinition`) store
  their value as a JSON array inside `value_jsonb` on the single value row for
  that binding — not as multiple rows. This is the existing convention,
  unchanged by this renaming.

- **Only `storage_type: dynamic` bindings may have value rows.** A `FieldBinding`
  with `storage_type: column` or `relation` must never have a corresponding
  row in any `*_field_values` table — its value lives at `storage_path` on the
  entity itself. Write-path code must validate this before insert.

- Write Routing: If is_localizable is true, strings are formatted as language dictionaries and committed to value_jsonb. If false, data goes to value_text or value_num based on the configuration.

### Anti-Duplication and Smart Import Layer


To power the Anti-Duplication Wizard and prevent users or sloppy import spreadsheets from generating redundant fields (e.g., creating "Цвет", "Color", and "Колір" as three separate definitions), the dictionary includes a tenant-isolated synonym registry.

- Entity: workspace_import_aliases

- id (UUID): Primary key.

- workspace_id (UUID): Binds the alias scope to a specific tenant.

- field_binding_id (UUID) *(renamed from `attribute_definition_id`)*: Foreign
  key to the specific `FieldBinding` this alias resolves to — not just the
  `FieldDefinition` — because the same raw external column name (e.g. "Назва")
  is ambiguous between Product and Customer at the definition level, and is
  only unambiguous once resolved to a specific entity binding.

- alias_name (String): Normalized string token (e.g., колор, цвет, colour).

- source (String, nullable): Import/connector origin of this alias (e.g. "1c",
  "google_sheets"), for future Connector Foundation (GAP-006) disambiguation.
  Null means manually registered / source-agnostic — do not store "manual" as a literal value.

- Validation Rule: Before the system creates a new custom field, the Anti-Duplication Wizard checks the input name against existing code entries, localized_labels, and workspace_import_aliases (scoped to the relevant object_type). If a match is found, the system blocks creation and suggests mapping to the existing field instead.

### Computed Fields Operational Boundary


Fields registered with data_type = 'computed' (such as margin_percentage or b2b_readiness_status) represent derived calculations.

- **No Physical Persistence Rule:** The platform is strictly forbidden from allocating physical rows or strings within `product_field_values`, `variant_field_values`, or `customer_field_values` for computed types.

- **Runtime Execution:** These properties must be calculated dynamically on-the-fly inside the application layer (Runtime Services) or handled via native database virtual columns (Virtual Generated Columns / Read Views). This eliminates data staleness when base prices or stock variables change.


## Pricing Context


The pricing architecture manages complex B2B financial relationships, multi-tier wholesale discounts, and currency isolation, while maintaining flattened caches for instant catalog indexing.

### Core Entity: PriceList


Defines a distinct pricing layer within a workspace.

- id (UUID): Primary key.

- workspace_id (UUID): Tenant owner.

- name (String): Internal title (e.g., "Wholesale Base", "VIP Tier Gold", "Default Retail").

- currency (String): Three-letter ISO currency code (e.g., USD, EUR, UAH).

- is_default (Boolean): Flag indicating if this list applies to unauthenticated or standard guests.

- priority (Integer): Evaluation weight utilized by the resolver when a customer matches multiple lists.

- status (Enum): active, inactive.

### Core Entity: PriceListItem (B2B Volume Tiers)


Defines the concrete price matrix rules. Volume tier support is a core architectural requirement for the Wholesale platform and is embedded directly into the schema.

- id (UUID): Primary key.

- workspace_id (UUID): Tenant owner.

- price_list_id (UUID): Parent price list relationship.

- product_variant_id (bigint, matching the existing product_variants primary key): Link to the concrete sellable SKU unit.

- quantity_min (Integer): The minimum quantity threshold required to unlock this price point. Defaults to 1 for standard single-item pricing.

- price (Decimal): The flat base price for this quantity tier before customer-specific discounts.

- sale_price (Decimal, Nullable): Promotional temporary price overriding the standard tier price.

- valid_from (Timestamp, Nullable): Time lock activation.

- valid_until (Timestamp, Nullable): Time lock expiration.

- status (Enum): active, suspended.

### Tier Matrix Structure Logic


Multi-level pricing operates by declaring multiple PriceListItem entries pointing to the same product_variant_id within the same price_list_id, differentiated strictly by their quantity_min thresholds:

- Entry 1: product_variant_id: X, quantity_min: 1, price: 100.00 (Applies to purchases of 1 to 9 items)

- Entry 2: product_variant_id: X, quantity_min: 10, price: 90.00 (Applies to purchases of 10 to 49 items)

- Entry 3: product_variant_id: X, quantity_min: 50, price: 80.00 (Applies to purchases of 50+ items)

### Domain Service: PriceResolver


The PriceResolver component is responsible for evaluating final contractual pricing in real-time. It accepts a VariantID, a CustomerID, and an intended Quantity.

- It identifies the target PriceList assigned to the customer or falls back to the workspace default list.

- It fetches all PriceListItem rows matching the target variant and price list.

- It filters out records that fall outside of valid_from / valid_until windows or are marked as inactive.

- It isolates the specific row where the requested Quantity satisfies the tier condition: Quantity >= quantity_min, selecting the highest matching quantity_min row.

- It applies any overlaying adjustments from the PricingRule or CustomerGroup percentage matrices to return the final net price.

### Runtime Computed Metrics: margin_percentage


Margin calculation is an operational tool for managers and must never be stored as static data.

- **Calculation Flow:** margin_percentage is calculated exclusively at runtime by evaluating the variant's active price or sale_price resolved from the system against its internal cost_price_cache.

- **Formula:** Margin % = ((Price - Cost Price) / Price) * 100

- **Visibility Boundary:** This calculation occurs entirely inside backend services. The output is stripped from responses directed at public or B2B storefront layers, rendering exclusively for authenticated workspace managers with elevated permissions.

## Availability Context


Availability coordinates physical warehouse balances, cross-dock allocations, and checkout reservations to deliver a reliable stock picture while avoiding double sales during high-concurrency cart activities.

### Operational Inventory Cache


To prevent heavy query calculations during search indexing and bulk storefront views, the ProductVariant table carries operational counters:

- available_quantity_cache (Integer): The physical balance recorded in the system.

- availability_status (Enum): in_stock, low_stock, out_of_stock, pre_order.

### Core Entity: InventoryRecord


The transaction ledger tracking all raw inventory updates.

- id (UUID): Primary key.

- workspace_id (UUID): Tenant isolation key.

- product_variant_id (bigint, matching the existing product_variants primary key): Target variant link.

- source_type (Enum): manual_adjustment, bulk_import, connector_sync, order_allocation.

- source_reference_id (String, Nullable): Tracks the originating document ID (e.g., 1C document number or import job log reference).

- quantity_change (Integer): Signed integer representing the stock movement (e.g., +150, -12).

- resulting_quantity (Integer): Snapshot of the historical balance immediately following this entry.

- reason (String, Nullable): Auditor notes.

### Core Entity: InventoryReservation (Overbooking Protection Layer)


To guarantee an accurate storefront availability snapshot and protect checkout flows from race conditions (where multiple clients try to buy the last 3 items simultaneously), the system implements a soft-reservation layer.

- id (UUID): Primary key.

- workspace_id (UUID): Tenant scope.

- order_id (bigint, Nullable, matching the existing orders primary key): Present if the reservation is bound to a pending order undergoing processing.

- order_item_id (bigint, Nullable, matching the existing order_items primary key): Link to the precise item row.

- product_variant_id (bigint, matching the existing product_variants primary key): The reserved item link.

- quantity (Integer): Number of units locked by this reservation.

- status (Enum): pending (active lock), confirmed (converted to physical deduction), expired (lock invalidated).

- created_at (Timestamp): Record initiation time.

- expires_at (Timestamp): Time-To-Live (TTL) timestamp. Reservations are strictly time-bound (e.g., a system configuration of 15 minutes for cart checkouts or 48 hours for pending invoice bank wire verifications).

### Net Availability Calculation Logic


When the platform displays stock numbers to a customer on the B2B storefront or evaluates if a checkout can proceed, it asks the AvailabilityResolver for the net sellable inventory.

- **The Formula:** Net Sellable Stock = available_quantity_cache - SUM(InventoryReservation.quantity Where status = 'pending' AND expires_at > CurrentTime)

- **Cleanup Management:** Expired reservations are treated as non-existent by the formula. An automated system cron service periodically updates pending records past their expires_at mark to expired, freeing unpurchased quantities back to the general public pool.

## Customers Context


The platform uses Customer as the main B2B customer entity.

In the user interface, customers are shown as:

- Customers

- Клиенты

The platform should not use Contractor as the main user-facing term.

The term contractor may appear only in connectors where external systems use it.

For example, a 1C connector may map an external contractor to the platform Customer.

### Customer


A customer represents a person or business that may view a B2B catalogue, receive prices and place orders.

A customer may contain:

- workspace;

- name;

- email;

- phone;

- company name;

- tax number;

- customer group;

- status;

- notes;

- default price list;

- billing address;

- shipping address.

For MVP, the customer model may be simple.

A future version may support multiple contacts per customer.

### CustomerGroup and Access


A customer group may define:

- default price list;

- discount;

- visibility rules;

- catalogue access;

- payment terms;

- future delivery terms.

For MVP, customer groups may mainly support pricing and B2B access.

## B2B Channel Context


B2B is the first native sales channel.

The B2B catalogue must not duplicate product data.

It should be a dynamic projection of shared product data, pricing, availability and customer rules.

The B2B channel should also support a simple customer-facing storefront experience.

This is important for small businesses that previously worked only with Google Sheets or Excel and do not have their own website.

### B2BChannel


A B2BChannel represents one customer-facing B2B catalogue or storefront configuration.

It may contain:

- workspace;

- name;

- slug;

- public URL;

- access mode;

- default price list;

- default customer group;

- visibility mode;

- default display mode;

- customer display mode switching flag;

- category navigation settings;

- search settings;

- sorting settings;

- filter settings;

- cart settings;

- order settings;

- future payment settings;

- status;

- settings.

Possible access modes:

- public catalogue with visible prices;

- public catalogue with hidden prices;

- invitation-only catalogue;

- login-required catalogue;

- customer-specific catalogue.

Possible display modes:

- grid

- list

- table

MVP may implement a simpler access mode first.

The model should not block future access modes or display modes.

### B2B Catalogue Projection — Resolved


A B2B catalogue is not a copied product table.

It is a runtime projection built from shared workspace data. The projection never duplicates product identity — it composes eligibility, pricing, availability, and presentation over the same `Product` / `ProductVariant` models used elsewhere.

**Projection inputs and their code mapping (verified on `develop`, PR #58–66):**

- **Products and variants** — `App\Models\Product`, `App\Models\ProductVariant`. Catalog eligibility requires `products.is_active = true` and at least one active variant. Enforced by `App\Support\Pricing\CustomerPricingScope::applyProductScope()`.
- **Categories** — `App\Models\Category`. Used for navigation, filtering, and sort in `App\Services\Pricing\CustomerCatalogQuery`.
- **Price list** — `App\Models\PriceList`. Assigned per customer via `Customer.default_price_list_id`; fallback to workspace default via `CustomerPricingScope::priceListIdFor()`.
- **Pricing / tier rules** — `App\Models\PriceListItem` quantity tiers resolved by `App\Services\Pricing\PriceResolver`. VAT defaults from `App\Services\Pricing\WorkspaceTaxDefaults`. Resolver output is wrapped in `App\Services\Pricing\Resolution\PriceResolutionResult` with three statuses (`App\Services\Pricing\Resolution\PriceResolutionStatus`: Resolved, Unavailable, ConfigurationError).
- **Availability** — net sellable stock via `App\Services\Availability\AvailabilityResolver::netAvailable()`. Stock badges use `ProductVariant::badgeFromQty()` with the category's `stock_display_threshold`.
- **Visibility** — product list scope in `CustomerCatalogQuery` + `CustomerPricingScope::applyProductScope()`. **Decoupled from price availability** (PR #62): products without a resolvable price remain in the catalogue with `CatalogProductDisplayState::PriceUnavailable`, not hidden.
- **Presentation** — per-row projection via `App\Support\CatalogRowData` → `App\Support\Pricing\CatalogRowProjection`, using `App\Enums\CatalogProductDisplayState` (five cases). Customer-facing price labels via `App\Enums\PriceDisplayMode`, `App\Services\Pricing\PriceDisplayModeResolver`, and `App\Services\Pricing\PriceDisplayPresenter`.
- **Channel / storefront settings** — the `B2BChannel` entity described elsewhere in this document is **not implemented yet**. MVP cabinet (`App\Livewire\Cabinet\Catalog`) and Preview as Customer (`App\Filament\Resources\CustomerResource\Pages\PreviewAsCustomer`) use workspace-level defaults (`Workspace.default_vat_rate`, `Workspace.default_price_display_mode`) and page-level UI settings instead.

The platform may use helper tables or caches for performance.

However, those tables must be treated as cache or configuration, not as a separate product model.

The B2B channel must always use the shared product model, shared pricing model and shared availability model.

**Implemented (verified via PR #58–66):**

- Price resolution: `App\Services\Pricing\PriceResolver`, `App\Services\Pricing\Resolution\PriceResolutionResult` (Resolved / Unavailable / ConfigurationError).
- Product/variant eligibility independent of price availability: `App\Support\Pricing\CustomerPricingScope::applyProductScope()`.
- Workspace-level tax defaults: `App\Services\Pricing\WorkspaceTaxDefaults`.
- Display mode (net/gross primary): `App\Enums\PriceDisplayMode`, `App\Services\Pricing\PriceDisplayPresenter`, `App\Services\Pricing\PriceDisplayModeResolver`.
- Per-product display projection: `App\Support\CatalogRowData`, `App\Enums\CatalogProductDisplayState`.
- Shared catalogue query for cabinet and admin preview: `App\Services\Pricing\CustomerCatalogQuery`.

**Not yet implemented, deliberately open (does not block this decision):**

- Customer group / segment-level product selection rules — GAP-010.
- `PricingRule` overlays on top of resolved `PriceListItem` tiers — GAP-010.
- `B2BChannel` entity and channel-specific visibility configuration — future; MVP uses workspace defaults and cabinet routes directly.

This decision is closed and must not be reopened without a documentation-level decision.

### Audience Resolution — Resolved


"Audience resolution" means: given a specific `Customer`, what products appear in their catalogue and how each row is displayed. Today this is a fixed, code-enforced pipeline — not a configurable rules engine.

1. **Product/variant eligibility** — `CustomerCatalogQuery::paginateFor()` starts from `CustomerPricingScope::applyProductScope()`: active products (`products.is_active = true`) with at least one active variant. Inactive products are excluded entirely (see `Tests\Unit\CustomerCatalogVisibilityTest::test_inactive_product_is_hidden`).
2. **Optional catalogue filters** — search, category, brand, and sort from `App\Support\Pricing\CustomerCatalogCriteria` inside `CustomerCatalogQuery`. Sorting may reference price-list tiers via `App\Services\Pricing\PricingSqlExpressions` but does not hide products.
3. **Per-product price resolution** — `CatalogRowData::forProduct()` calls `App\Services\Pricing\ProductPricingSummary::resolveVariantDisplay()` for each active variant, which delegates to `PriceResolver`. Three outcomes per variant: Resolved, Unavailable, ConfigurationError (via `PriceResolutionResult` / exceptions caught in `ProductPricingSummary`).
4. **Display state selection** — resolved and unresolved variants map to one of five `CatalogProductDisplayState` values: `OrderableVariantSelected`, `ExpectedVariantSelected`, `InformationalPriceOnly`, `ConfigurationError`, `PriceUnavailable`. Selection priority: in-stock resolvable variant → expected-date resolvable variant → cheapest informational price → configuration error → price unavailable.
5. **Availability overlay** — within projection, `AvailabilityResolver::netAvailable()` and stock `expected_date` / `expected_quantity` drive orderability (`orderable`, `maxQty`) and stock badges. Availability does not remove products from the catalogue list.
6. **Price display formatting** — resolved prices are formatted through `PriceDisplayModeResolver` + `PriceDisplayPresenter` according to `Workspace.default_price_display_mode`.
7. **Cabinet / Preview parity** — `App\Livewire\Cabinet\Catalog` and `App\Filament\Resources\CustomerResource\Pages\PreviewAsCustomer` share `CustomerCatalogQuery`, `CatalogRowData`, and the same display-state labels (see `Tests\Unit\CustomerCatalogVisibilityTest::test_cabinet_and_preview_parity_for_product_ids_and_projection`, PR #59).
8. **No customer segmentation beyond direct price-list assignment** — there is no `CustomerGroup`, no per-segment product-selection rules, and no per-customer visibility matrix. A customer's price context comes only from `Customer.default_price_list_id` (with workspace-default fallback). Segment-level rules remain open — GAP-010.

This decision is closed and must not be reopened without a documentation-level decision.

### Native B2B Storefront


The native B2B catalogue may work as a simple storefront for each workspace.

This does not mean that the platform is a website builder, e-commerce CMS or marketplace.

Each workspace has its own isolated customer-facing catalogue.

Only that company's products are shown.

There is no platform-wide marketplace search.

There is no competition between sellers inside the platform.

The B2B storefront is a native sales channel on top of the Product Data Platform.

For a small business, the ideal flow is:

- Import products from Excel or Google Sheets.

- Organize products into workspace categories.

- Publish the B2B storefront.

- Share the catalogue link with customers.

- Customers browse products as cards, list or table.

- Customers search, sort and filter products.

- Customers add products to cart.

- Customers submit an order.

- In the future, customers may pay online through a connected payment gateway.

This gives a small merchant a focused product sales space without building a separate website, using a marketplace or paying marketplace commissions.

The B2B storefront should remain simple.

It should not become a full website builder.

### B2B Storefront Views


A B2BChannel should support storefront presentation settings.

The storefront is not a separate product database.

It is a customer-facing view over shared workspace data:

- products;

- variants;

- categories;

- prices;

- availability;

- customer access rules;

- visibility rules;

- payment settings;

- channel settings.

A B2B storefront may support several display modes:

- grid view for visual browsing;

- table view for fast B2B ordering;

- list view for compact browsing.

The display mode should be stored as a channel setting.

The platform may also allow the customer to switch between views when enabled by the workspace.

The storefront should support category navigation.

For MVP, categories are workspace-owned.

The platform should not require a global taxonomy for storefront navigation. See **Product classification model** below for how this relates to the separate, not-yet-built Standard Category concept.

Marketplace taxonomy mapping should remain part of connector/channel mapping, not the core B2B storefront.

A B2BChannel may contain settings such as:

- default display mode;

- whether customers can switch display mode;

- default sort order;

- enabled filters;

- category navigation enabled;

- search enabled;

- show images;

- show availability;

- show prices;

- allow cart;

- allow order submission;

- future payment enabled.

These settings must not duplicate product data.

They only control how shared product data is presented to customers.

### B2B Visibility Rules


Visibility may be controlled by:

- product status;

- variant status;

- category;

- customer group;

- customer-specific rules;

- price list;

- availability;

- channel configuration.

For MVP, visibility may be simple.

Initial rule:

- show active products that are enabled for B2B and have enough required data for B2B publication.

Future rules may support more complex customer-specific visibility.

### Admin Product Views


The admin product area should support different views over the same product data.

Initial admin views may include:

- table view;

- card view.

Table view is useful for managing many products quickly.

Card view is useful for checking how product cards look in the storefront.

Both views must use the same underlying product, variant, price, availability and attribute data.

Switching between table and card view must not create separate product records or separate catalogue records.

The admin product area should support:

- category filtering;

- status filtering;

- availability filtering;

- price sorting;

- search by product name, SKU or GTIN.

The goal is to let the user manage many products simply, even if the workspace has hundreds or thousands of items.

## Orders Context


Orders serve as permanent legal and operational documents within the ecosystem. Once submitted, an order detaches from volatile catalog entities, embedding static snapshots of names, SKUs, and prices to preserve historical business ledgers.

### Core Entity: Order


The parent document tracking fulfillment progress.

- id (bigint): Primary key (Laravel auto-increment, matching the existing orders table).

- workspace_id (UUID): Tenant isolation key.

- customer_id (UUID): The associated B2B client account.

- order_number (String): Human-readable alphanumeric code generated sequentially per workspace.

- order_status (Enum): Core state track (draft, pending, confirmed, processing, completed, cancelled).

- payment_status (Enum): Financial state track (unpaid, awaiting_payment, paid, failed, refunded).

- external_sync_status (Enum): ERP state track (not_queued, queued, synced, failed).

- currency (String): ISO code matching the purchase contract currency.

- subtotal, discount_total, grand_total (Decimal).

- shipping_address_snapshot (JSONB): Flattened delivery criteria.

- requires_attention (Boolean): Operational flag raised when stock exceptions or sync errors require human review.

### Core Entity: WorkspaceOrderStatusMatrix


To prevent rigid code paths and allow different workspaces to govern their own unique order lifecycles, state progression rules are externalized into a configuration matrix entity.

- id (UUID): Primary key.

- workspace_id (UUID): Unique tenant owner. One matrix configuration map exists per workspace.

- allowed_transitions_json (**JSONB**): A map defining valid step-by-step pathways for order_status.

- Example Layout: {"pending": ["confirmed", "cancelled"], "confirmed": ["processing", "cancelled"], "processing": ["completed"]}. If an API request or user action attempts a state change not explicitly listed here, the state machine rejects the update.

- payment_triggers_json (**JSONB**): A behavior map declaring automatic cross-lifecycle state triggers.

- Example Layout: {"on_payment_status_paid": {"update_order_status_to": "confirmed"}}. This map tells the PaymentWebhookHandler or billing core how to automatically update the parent order_status without hardcoded system rules.

### Detailed Lifecycle Definitions


The platform enforces a strict separation between operational fulfillment tracking and financial settlement states:

### 1. Order Status Lifecycle (order_status)


- draft: The order is being constructed inside the management back-office and is invisible to the customer storefront.

- pending: The customer has submitted the order. It is awaiting manager approval, inventory confirmation, or the receipt of payment credentials.

- confirmed: The order is verified valid, pricing terms are locked, and inventory is officially approved for allocation.

- processing: Items are being picked, packed, or prepped for courier dispatch at the warehouse.

- completed: Items have been handed over to the client, and tracking documents are finalized. This is an end state.

- cancelled: The order is voided. Any associated active soft reservations are deleted, and completed inventory allocations are rolled back via reversing InventoryRecord entries.

### 2. Payment Status Lifecycle (payment_status)


- unpaid: No transactional activity has occurred. Default state for newly generated invoice terms.

- awaiting_payment: The checkout gateway link has been active or an invoice document has been delivered, and the system is waiting for webhook confirmations or manual wire inputs.

- paid: The financial total has been secured in full.

- failed: The payment gateway processing timed out, was rejected by the clearing house, or encountered insufficient customer funds.

- refunded: Capital was returned to the buyer.

### Core Entity: OrderItem


Represents individual product entries bound to an order.

- id, order_id, product_id, product_variant_id (bigint, matching the existing orders/products/product_variants primary keys).

- quantity (Integer): Total requested units.

- price_snapshot, discount_snapshot, total (Decimal).

- product_name_snapshot, sku_snapshot, gtin_snapshot (String): The Data Immutability Shield. During creation, these fields copy text and code literals directly from the product catalog. If a merchant later deletes the product or edits its title, this item remains untouched, preserving the exact state of the historical transaction.

- stock_warning_status (Boolean): Computed during item assembly. If quantity exceeds the net sellable stock pool, this flag marks as true. It acts as a visual alert for back-office managers, highlighting potential fulfillment issues without throwing hard validation errors that block order entry.

## Payments Context


Payments are not part of the MVP UI by default.

However, the domain model should be ready for future payment support.

Payment support is important for small merchants who want to sell directly from the B2B storefront.

The platform should support two business realities:

- B2B companies may work through invoice and bank transfer.

- Small businesses may want online payment through payment gateways.

The model should support both without turning the MVP into a payment platform.

### Invoice and Bank Transfer


For many B2B businesses, payment may mean:

- generate invoice;

- send invoice to customer;

- customer pays by bank transfer;

- external ERP/accounting system reconciles payment.

In this case, the platform may only need:

- invoice generation later;

- order payment status;

- optional invoice file;

- external sync to ERP/accounting.

This should not require online card payment integration.

### Payment Gateway Integration


For small businesses, future online payment may be a strong sales feature.

The platform should integrate through hosted payment gateways.

The platform should not collect or store card numbers.

The payment flow should be:

- Customer chooses to pay.

- Platform creates a payment request with the configured gateway.

- Gateway returns a hosted payment URL, payment link or QR code.

- Customer pays on the gateway page.

- Gateway sends webhook to the platform.

- Platform updates payment status.

- Platform may update order status according to workspace rules.

Payment gateway UI is not required for MVP.

The domain model should allow it later.

### Small Merchant Online Sales Flow


The domain model should support a future small merchant sales flow.

Example:

- Merchant imports products from Google Sheets.

- Platform creates products, variants and categories.

- Merchant publishes B2B storefront.

- Customer opens the storefront.

- Customer browses products by category, card view, list view or table view.

- Customer adds products to cart.

- Customer submits order.

- If online payment is enabled, platform creates a payment request.

- Payment gateway returns hosted payment URL or QR code.

- Customer pays on the gateway page.

- Gateway sends webhook to the platform.

- Platform updates payment status.

- Platform may confirm the order according to workspace rules.

The platform must not collect or store card numbers.

Payment gateways should be integrated through hosted payment pages, payment links, QR codes or similar secure provider-owned flows.

This allows small businesses to sell directly from the B2B storefront without forcing the platform to become a payment processor, marketplace or full e-commerce CMS.

### Payment


A Payment represents a payment attempt or transaction related to an order.

A payment may contain:

- workspace;

- order;

- gateway name;

- gateway account;

- external transaction ID;

- amount;

- currency;

- status;

- payment URL;

- paid at;

- failed at;

- raw gateway reference;

- created at.

Initial payment statuses:

- pending

- successful

- failed

- cancelled

- refunded

Refund support may be postponed.

The model should not store sensitive card data.

The model should only store references needed for reconciliation, status tracking and customer support.

### PaymentGatewayAccount


A future PaymentGatewayAccount may represent the workspace payment configuration.

It may contain:

- workspace;

- gateway name;

- status;

- public configuration;

- encrypted credentials;

- webhook secret;

- settings.

Payment credentials must be stored securely.

For MVP, this entity may remain unimplemented.

The domain model should not block adding it later.

### Payment Status vs Order Status


Payment status and order status are separate.

Examples:

- an order may be pending while payment status is unpaid;

- an order may be pending while payment status is awaiting_payment;

- an order may become confirmed after payment becomes paid;

- an order may remain confirmed but unpaid if the business works by invoice and bank transfer;

- a failed payment should not automatically cancel the order unless the workspace configures that behavior.

Order status changes after payment should be controlled by workspace settings.

## Connectors and Mappings Context


Connectors allow the platform to exchange data with external systems.

Examples:

- Excel;

- CSV;

- Google Sheets;

- ERP / 1C;

- website import;

- marketplace feed;

- API;

- future supplier feeds.

Connectors must not define the core domain model.

Connectors adapt external systems to the platform.

The platform core must not adapt itself to each connector through hardcoded fields.

### ConnectorDefinition (Resolved — physical schema)

Table `connector_definitions`:

- id (UUID)
- code (string, unique, immutable after creation)
- name (string)
- direction (enum: import | export | both) — **coarse platform-level envelope only**
- status (enum: draft | active | deprecated)
- notes (text, nullable)
- created_at / updated_at

`ConnectorDefinition.direction` describes the platform catalog envelope for a
connector type (import-capable, export-capable, or both). It is **not**
authoritative runtime capability truth for whether a specific connected
`ConnectorAccount` may activate a given `(data_domain, semantic operation)`
pair. That truth belongs at the connection / profile / runtime-contract
boundary (see Sync Domain Rebaseline below). Do not reuse this enum/type as
`SyncConfiguration` capability state.

Rules:
- `code` is immutable once set.
- Hard delete is forbidden once any reference exists (schema sources,
  future ConnectorAccount rows); use `deprecated` instead.
- `draft` definitions are not offered in production connector workflows
  (Task 4B onward).
- `status: active` requires at least one `connector_schema_sources` row
  with `is_primary: true`, `schema_scope: global`, and
  `verification_status: verified`. This prevents an administrator from
  activating an empty platform — exactly the invisible/incomplete state
  the initial seeder (section 2a) is meant to avoid.

Examples of `code`: `google_merchant`, `shopify`, `adobe_commerce`,
`bigcommerce`, `csv`, `google_sheets`, `1c`.

**Registry channels are not the same set as ConnectorDefinition codes.**
Registry mapping/channel-decision channels (e.g. `schema_org`) may have no
runtime ConnectorDefinition at all, and some ConnectorDefinitions (e.g.
`csv`) have no global product-field schema in the Registry. The Field
Matrix (06-UI_DESIGN_SYSTEM.md) derives its columns from Registry channel
values actually present in `mappings.csv`/`channel_decisions.csv`;
ConnectorDefinition metadata only enriches a column when its `code`
happens to match that Registry channel value. The two concepts must never
be treated as identical.

### ConnectorSchemaSource (Resolved — new entity)

Table `connector_schema_sources`:

- id (UUID)
- connector_definition_id (FK → connector_definitions)
- code (string, unique within the connector)
- label (string)
- source_kind (enum: api_schema | official_web_doc | repository_code |
  repository_document | account_api | static_registry | manual_import)
  — this is a compatible superset of the Registry's existing `source_kind`
  vocabulary (`canonical_product_field_sources.csv`): it reuses the same
  names where semantics coincide (`api_schema`, `official_web_doc`,
  `repository_code`, `repository_document`) and adds three connector-only
  values (`account_api`, `static_registry`, `manual_import`) that have no
  meaning in the global field-evidence context. It is not a literally
  identical enum, but Governance UI never needs a translation layer for
  the four shared values.

Invariants (enforced at the application level, not by a database
constraint that would also forbid multiple non-primary rows):

- `code` is immutable after creation.
- Unique: `(connector_definition_id, code)`.
- If `source_kind: account_api`, then `schema_scope` must be `account`,
  `acquisition_mode` must be `live_fetch`, and `endpoint_path` must not be
  null.
- If `schema_scope: global`, then `endpoint_path` must be null.
- If `verification_status: verified`, then `last_verified_at` must not be
  null.
- `reference_url`, when present, must be a valid absolute URL.
- At most one `is_primary: true` row per
  `(connector_definition_id, schema_scope)`. Enforced by: an application
  service that, within a DB transaction, locks the parent
  `ConnectorDefinition` row and atomically unsets any previous primary in
  the same scope before setting the new one — not by a naive unique index
  on `(connector_definition_id, schema_scope, is_primary)`, which would
  also forbid multiple `is_primary: false` rows. A feature test must cover
  this transition.
- acquisition_mode (enum: remote_static | live_fetch | bundled_file | manual)
- schema_scope (enum: global | account)
- reference_url (string, nullable) — for `schema_scope: global` sources
  only, this is the documentation/schema reference URL. For
  `schema_scope: account` sources, `reference_url` holds the URL of the
  *official documentation describing the endpoint*, never a specific
  client's store base URL — the actual per-store base URL belongs to
  `ConnectorAccount` (Task 4B), not here.
- endpoint_path (string, nullable) — e.g. `/V1/products/attributes`, only
  meaningful when `schema_scope: account`.
- schema_version (string, nullable)
- is_primary (boolean) — see invariants below for the exact uniqueness rule.
- verification_status (enum: verified | stale | broken | unverified)
- last_verified_at (nullable timestamp)
- notes (text, nullable)
- sort_order (integer)
- created_at / updated_at

Example — Adobe Commerce, two rows:

| label | source_kind | acquisition_mode | schema_scope | reference_url | endpoint_path | is_primary |
|---|---|---|---|---|---|---|
| Admin REST API reference | api_schema | remote_static | global | adobe-commerce.redoc.ly/... | null | true |
| Live account attributes | account_api | live_fetch | account | experienceleague.adobe.com/.../products-api (docs about the endpoint) | /V1/products/attributes | true |

Both rows may be `is_primary: true` simultaneously because they have
different `schema_scope` values (global vs account) — the uniqueness rule
is scoped, not connector-wide.

No credentials are stored here. Credentials belong to `ConnectorAccount`
(Task 4B).

This is global platform data.

### ConnectorAccount (Resolved — Task 4B-0 Stop-and-Amend)

> **Status marker:** `Resolved` — approved and merged via Task 4B-0 docs-only PR.
> Application implementation proceeds in Task 4B-1 onward.

A `ConnectorAccount` is a **workspace-owned** connection to one external store or
tenant. It references exactly one global `ConnectorDefinition` and holds
account display name, auth profile, base/tenant context, non-secret settings,
encrypted credentials, and a **current connection-health projection** updated by
domain services after terminal connection checks and discovery runs.

`ConnectorAccount` does **not** contain:

- global platform metadata (that is `ConnectorDefinition`);
- immutable schema history (that is snapshots/diffs — see below);
- `FieldMapping` rows (Task 4C);
- raw vendor response bodies by default;
- credentials on `ConnectorDefinition`, `ConnectorSchemaSource`, or snapshots.

#### Boundary vs legacy `SyncLog`

`SyncLog` remains a **legacy summary log** for existing legacy import/export
sync flows. It has no `workspace_id`, no `connector_account_id`, no running state,
coarse `success|error` only, and legacy product/price/stock type enums. Task 4B
**does not** extend or reuse `SyncLog` as a parent event table. New connector
operational history uses the dedicated append-only entities below with explicit
workspace ownership.

#### Current projection vs operational history

**Current account overview** (`ConnectorAccount` row) answers:

- Чи підключення працює зараз?
- Коли його востаннє перевіряли?
- Коли востаннє успішно отримували поля?
- Що користувач має зробити зараз?

**Operational history** (`ConnectorConnectionCheck`, `ConnectorDiscoveryRun`,
snapshots, diffs) answers:

- Коли проблема з’явилась?
- Чи була вона тимчасовою?
- Хто запускав перевірку?
- Чи відновилось підключення?
- Який snapshot створено?

The list UI must read the **current projection** on `ConnectorAccount`. It must
not recompute “last event” with an expensive history query per row. History rows
are append-only after terminal state (`running → succeeded | failed | cancelled`).

#### Physical schema — `connector_accounts` (Resolved)

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `workspace_id` | UUID FK | Required from first migration; `BelongsToWorkspace` |
| `connector_definition_id` | UUID FK | → `connector_definitions` |
| `name` | string | Merchant-facing display name |
| `auth_profile` | string | Stable code, e.g. `adobe_commerce_paas_oauth1_integration`, `adobe_commerce_saas_ims_server_to_server` |
| `base_url` | string nullable | PaaS store origin; SSRF-validated; normalized (scheme/https, no trailing slash) |
| `store_code` | string nullable | PaaS REST store-view segment |
| `tenant_context` | string nullable | SaaS tenant/API path segment when not encoded in `base_url` |
| `is_enabled` | boolean | Disabled accounts retain history but do not schedule discovery |
| `settings` | JSON | Non-secret deployment options only |
| `credentials` | TEXT | Laravel `encrypted:array` — never indexed or searched |
| `connection_status` | enum | `untested`, `connected`, `attention_required`, `temporarily_unavailable`, `disabled` |
| `last_checked_at` | timestamp nullable | |
| `last_successful_check_at` | timestamp nullable | |
| `last_discovery_at` | timestamp nullable | |
| `last_successful_discovery_at` | timestamp nullable | |
| `last_error_cause` | enum nullable | See dual-axis errors |
| `last_error_actionability` | enum nullable | See dual-axis errors |
| `last_error_message_key` | string nullable | Translation key, not raw vendor text |
| `last_error_at` | timestamp nullable | |
| `deleted_at` | timestamp nullable | Soft delete; history retained per retention policy |
| `created_at` / `updated_at` | timestamps | |

**Uniqueness (Resolved):** `(workspace_id, connector_definition_id, name)` among
non-deleted rows. A workspace may hold **multiple accounts** for the same
`connector_definition_id` when `name` differs (e.g. two Magento stores). This is
not a one-connection-per-platform model.

Implement this as a DB-level constraint via a driver-conditional generated column
`active_name_uniqueness_key`, using the same technique already established by
`FieldFoundationMigrator::addWorkspaceUniquenessKey()`:

- active row (`deleted_at IS NULL`): `active_name_uniqueness_key = name`;
- soft-deleted row: `active_name_uniqueness_key = NULL`.

Unique index: `(workspace_id, connector_definition_id, active_name_uniqueness_key)`.

This migration contract is verified for the two drivers used by this task:
MySQL (production/development) and SQLite (automated tests). Both permit multiple
`NULL` values in the generated uniqueness key, so active rows index the real `name`
and conflict correctly while soft-deleted rows do not block reuse of the name. Application-level validation may improve UX (clearer error message
before submit) but is not a substitute for this DB constraint. Restoring a
soft-deleted account must fail (DB and application level) if another active account
already occupies the same `(workspace, definition, name)` key.

Never include secrets or credential hashes in unique indexes.

**Credentials storage decision:** encrypted `credentials` TEXT on the same row as
`settings` JSON (recommended MVP). A separate `connector_account_credentials`
1:1 table was considered for narrower SELECT exposure but rejected for MVP
complexity — rotation and masking are handled via cast + policy + `$hidden`, with
jobs passing `connector_account_id` only.

**Adobe first adapter, generic core:** auth profile codes and adapter services are
vendor-specific; generic tables remain free of Adobe-only columns.

### ConnectorConnectionCheck (Resolved)

Append-only history of connection test attempts.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `workspace_id` | UUID FK | |
| `connector_account_id` | UUID FK | |
| `trigger` | enum | `manual`, `scheduled`, `before_discovery` |
| `initiated_by_user_id` | unsigned bigint FK nullable | Null for scheduled; matches `users.id` (bigint, not UUID) |
| `status` | enum | `running`, `succeeded`, `failed` |
| `cause_category` | enum nullable | `authentication`, `authorization`, `configuration`, `rate_limit`, `vendor_unavailable`, `network`, `schema_validation`, `data_validation`, `unknown` |
| `actionability` | enum nullable | `user_action_required`, `automatic_retry`, `workspace_admin_required`, `support_required` |
| `error_code` | string nullable | Internal stable code |
| `http_status` | smallint nullable | |
| `user_message_key` | string nullable | e.g. `connectors.errors.invalid_credentials` |
| `safe_message_parameters` | JSON nullable | Non-secret interpolation params |
| `technical_summary` | string nullable | Redacted, length-capped |
| `vendor_request_id` | string nullable | Support reference when not secret |
| `started_at` | timestamp | |
| `finished_at` | timestamp nullable | |
| `duration_ms` | unsigned int nullable | |
| `created_at` | timestamp | Immutable after terminal state |

**Concurrency:** at most one `running` check per account (application lock).
**No** secrets, Authorization headers, or raw response bodies.

### ConnectorDiscoveryRun (Resolved)

Append-only history of schema discovery executions against one
`connector_schema_source`.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `workspace_id` | UUID FK | |
| `connector_account_id` | UUID FK | |
| `connector_schema_source_id` | UUID FK | |
| `trigger` | enum | `manual`, `scheduled`, `after_connection_check` |
| `initiated_by_user_id` | unsigned bigint FK nullable | Null for scheduled; matches `users.id` (bigint, not UUID) |
| `status` | enum | `queued`, `running`, `succeeded`, `failed`, `cancelled` |
| `execution_attempts` | unsigned tinyint, default 0 | Counts claimed full-discovery execution slots, not individual HTTP page requests. One discovery execution may issue up to 50 HTTP page requests; this counter is atomically incremented exactly once, before page 1, at the start of each complete paginated execution attempt, and is capped at 3. Conservative over-counting after a crash is acceptable; under-counting is forbidden. |
| `retry_until_at` | timestamp nullable | Absolute deadline from initial dispatch, shared by the job's `retryUntil()` and persisted for deterministic stale-row recovery. |
| `next_attempt_at` | timestamp nullable | Guards against the database queue driver's own `retry_after`-based redelivery bypassing the intended backoff delay. |
| `started_at` | timestamp nullable | Null while `status: queued` |
| `finished_at` | timestamp nullable | Set only on terminal state (`succeeded`/`failed`/`cancelled`) |
| `duration_ms` | unsigned int nullable | |
| `fields_received` | unsigned int nullable | Count of raw Magento list items received across all pages, including service-only attributes excluded from normalization. Must be `>= fields_normalized` on success. |
| `fields_normalized` | unsigned int nullable | Count of merchant-facing attributes that were normalized into `ConnectorSchemaSnapshotField` rows. Equals `ConnectorSchemaSnapshot.field_count` on success. |
| `added_count` / `changed_count` / `removed_count` / `unchanged_count` | unsigned int nullable | Populated when diff computed |
| `cause_category` / `actionability` / `error_code` / `http_status` | nullable | Same vocabulary as checks |
| `user_message_key` / `technical_summary` / `vendor_request_id` | nullable | |
| `snapshot_id` | UUID FK nullable | Set only on full success |
| `previous_snapshot_id` | UUID FK nullable | For diff context |
| `created_at` | timestamp | |

**Rules:**

- Failed or incomplete pagination **does not** publish a canonical snapshot.
- `partial` is not a terminal success state for snapshot publication.
- Latest successful snapshot for account+source is resolved via indexed query,
  not by mutating prior snapshots.

#### Retry contract (Resolved)

- Maximum vendor-execution attempts: 3 total (initial + 2 retries).
- Base retry delays: 60s before the first retry, 300s before the second.
- Jitter: equal jitter — actual delay = ceil(base / 2) + random(0, floor(base / 2)).
- `retry_until_at` = dispatch time + 60 minutes.
- Mechanism: the job uses the persisted `retry_until_at` via its own
  `retryUntil()`; a classified-retryable failure records `next_attempt_at`
  and calls `release($delay)` manually — the numeric queue `$tries`
  property is not the business attempt counter, `execution_attempts` is.
- HTTP-client-level automatic retries: 0 (all retry logic lives at the
  job/persistence layer, not inside the HTTP client).
- 429 responses respect `Retry-After`, capped at 300 seconds (mirrors the
  connection-check pattern).
- Retryable failure classes: timeout, connection reset, HTTP 408, HTTP
  429, HTTP 5xx.
- Non-retryable: HTTP 401, 403, 404; any schema-validation, pagination-
  limit, or response-size classification (these are terminal outcomes,
  not transient failures).

#### Schema-source resolution rule (Resolved) — pre-dispatch, not a run field

Before a discovery run row is created, the dispatch service resolves
exactly one `ConnectorSchemaSource` for the target account, using:
- `connector_definition_id` = the account's own `connector_definition_id`;
- `schema_scope` = `Account`;
- `source_kind` = `AccountApi`;
- `acquisition_mode` = `LiveFetch`;
- `is_primary` = `true`;
- `endpoint_path` is a non-null, non-empty **relative** API path — no scheme,
  host, user, password, or port; no query or fragment; no `.`/`..` traversal
  segment; normalized to exactly one leading slash. The host always comes from
  the account's own base URL, never from `endpoint_path` itself.

Exactly one matching row → dispatch proceeds and the resolved
`connector_schema_source_id` is persisted on the new run row. **Zero or
more than one matching row is a pre-dispatch configuration failure — no
`ConnectorDiscoveryRun` row is created, and no HTTP call is made.** This
is not a value of `connector_discovery_runs.error_code`, because the
required, non-nullable `connector_schema_source_id` column makes it
physically impossible to persist a run without a resolved source.

**Pre-dispatch source-resolution failure UX (Resolved):**

- the dispatch service throws `ConnectorDiscoverySourceResolutionException`,
  carrying an internal `reason` of `missing` or `ambiguous` (not exposed to
  the end user — used only for logging);
- the single safe translation key shown to the user in both cases, without
  distinguishing missing from ambiguous (distinguishing them in the UI would
  leak internal source-configuration detail):
  `connectors.errors.discovery_source_unavailable`;
- this is surfaced as a **pre-render disabled state** on the manual-trigger
  action, alongside the other four disabled states from discovery Scope 8
  (bringing the total to five user-facing disabled states: four feature states
  plus source unavailable — the deployment activation gate is **hidden**, not
  disabled, and is not counted here);
- what gets logged for support: workspace ID, connector account ID, connector
  definition ID, and the match count (0 or the actual count for ambiguous) —
  never credentials, the full `endpoint_path`/URL, or other settings;
- the actual HTTP fetch must use the **persisted**
  `ConnectorSchemaSource.endpoint_path` value — never a hardcoded Adobe path.
  This is a hard requirement, not an implementation detail left implicit.

After a run is created, the worker re-loads the `ConnectorAccount` and
`ConnectorDiscoveryRun` within workspace context (both are
workspace-scoped), then separately re-loads the *persisted*
`ConnectorSchemaSource` by ID — this row is global platform
configuration, not workspace-owned — and re-verifies, before any HTTP
call, that it still belongs to the same `connector_definition_id` as the
account and still satisfies all six conditions above — a different
source is never substituted automatically. If the source has become
invalid between dispatch and execution, the run terminates with the
lifecycle code
`discovery_source_invalid_before_execution` (see the lifecycle table
below).

#### Discovery dispatch and execution transaction phases (Resolved)

Do not describe discovery (or connection-check) execution as "two
transactions." The verified persistence layer uses distinct phases:

- **Phase A — dispatch-time reservation** (inside `executeManual()`'s own
  `DB::transaction()` in the dispatch service, mirroring
  `ConnectorConnectionCheckDispatchService::executeManual()`);
- **Phase B — execution-slot reservation** (a separate transaction, inside the
  job/persistence layer, mirroring `reserveExecutionSlot()` in
  `ConnectorConnectionCheckPersistence`);
- **Phase C — vendor execution** (paginated HTTP + normalization + hashing),
  entirely outside any database transaction;
- **Phase D — terminal finalization**, itself potentially one of several
  distinct transacted methods depending on outcome (success via
  `finalizeAfterVendorAttempt()`, lifecycle failure via `writeLifecycleFailure()`,
  stored-vendor-classification terminal write via
  `terminalizeWithStoredVendorClassification()`, attempts-exhausted terminal
  write via `terminalizeAttemptsExhausted()`, account-disabled terminal write
  via `terminalizeAccountDisabledBeforeExecution()`, stale-row recovery via
  `recoverStaleRowIfNeeded()` / `recoverStaleRow()`) — mirroring
  `ConnectorConnectionCheckPersistence`'s actual distinct methods, not a single
  generic "Transaction B."

Active-run uniqueness per (connector_account_id,
connector_schema_source_id) pair is an **application-level invariant**
— enforced by the dispatch service's locked lookup-then-create logic
(mirroring connection-check), not by any database constraint. The new
index accelerates this lookup; it does not enforce uniqueness by itself.

#### Deterministic latest-snapshot ordering (Resolved)

The "latest successful snapshot" for no-change comparison and current-
snapshot linking is the row with the greatest `(created_at, id)` pair,
ordered `created_at DESC, id DESC`, for the same (connector_account_id,
connector_schema_source_id) pair.

#### Deterministic pagination-success contract (Resolved)

- pages are fetched sequentially starting at `currentPage=1`;
- every request uses `searchCriteria[pageSize]=200` explicitly;
- the response's `items` must be a JSON list; `total_count` must be a
  non-negative integer and must remain identical across every page of
  the same run;
- if `total_count > 10,000`, the run fails before any further page is
  fetched;
- an empty page received before the accumulated count reaches
  `total_count` is a terminal `discovery_incomplete_pagination` result —
  not a retry condition;
- if the final accumulated field count does not exactly equal the
  stable `total_count`, the run fails — never publishes a snapshot for a
  mismatched count;
- a 51st page request is never issued, regardless of what `total_count`
  claims;
- a snapshot is published only when the accumulated count exactly equals
  the stable `total_count` and normalization/hashing succeeded with no
  `schema_validation` failure.

#### Split lifecycle vs result error-code design (Resolved)

`ConnectorDiscoveryRunLifecycleErrorCode` — lifecycle and pre-execution
control failures that do not represent a classified vendor/HTTP/schema
result (this includes queue/infrastructure failures **and**
account/source invalidation checked before any HTTP call — it does not
mean "queue-only"; it means "not a vendor/transport/schema outcome").
Mirrors `ConnectorConnectionCheckLifecycleErrorCode`'s exact scope and
actionability choices:

| Code | Cause | Actionability | Message key | Technical summary |
|---|---|---|---|---|
| `discovery_dispatch_failed` | `unknown` | `support_required` | `connectors.errors.discovery_failed` | `queue_dispatch_failed` |
| `discovery_job_failed` | `unknown` | `support_required` | `connectors.errors.discovery_failed` | `queue_job_failed` |
| `discovery_attempts_exhausted_without_result` | `unknown` | `support_required` | `connectors.errors.discovery_failed` | `vendor_attempt_budget_exhausted_without_result` |
| `discovery_account_disabled_before_execution` | `configuration` | `workspace_admin_required` | `connectors.errors.account_disabled` | `account_disabled_before_execution` |
| `discovery_source_invalid_before_execution` | `configuration` | `support_required` | `connectors.errors.discovery_failed` | `source_invalid_before_execution` |

**`discovery_attempts_exhausted_without_result` never overwrites the
account projection** — it means retries were exhausted *without any
persisted vendor result at all* (mirrors
`ConnectorConnectionCheckLifecycleErrorCode::AttemptsExhaustedWithoutResult`'s
own `SupportRequired` actionability exactly — not `AutomaticRetry`, which
does not apply here). This is distinct from the case where a real,
persisted vendor result *was* obtained and classified `AutomaticRetry`-
actionable (a **result**-level classification, in the table below) before
attempts ran out — that case is what can legitimately drive a
`TemporarilyUnavailable` projection (see the account-projection mapping
below), not the lifecycle code itself. None of these five lifecycle codes
overwrite an already-persisted vendor result.

A separate, richer **`ConnectorDiscoveryRunErrorCode`** is a **superset** of the
existing **`ConnectorConnectionCheckErrorCode`**. Every shared Adobe OAuth/HTTP/
transport case name, string value, `Cause`, `Actionability`, message key, HTTP-
status acceptance, and HTTP-vs-transport classification is reused **verbatim** —
discovery does not rename, re-derive, or substitute competing terms for any
shared case. The full shared vocabulary (Adobe OAuth identifiers, HTTP fallback,
and transport cases) is defined once in `ConnectorConnectionCheckErrorCode` (see
`ConnectorConnectionCheckErrorCode enum vocabulary` below) and applies unchanged
to discovery result persistence on `connector_discovery_runs.error_code`.

Discovery additionally defines exactly three discovery-specific result codes
(no connection-check equivalent to reuse):

| Code | Cause | Actionability | Message key | Technical summary |
|---|---|---|---|---|
| `DiscoveryPaginationLimitExceeded = 'discovery_pagination_limit_exceeded'` | `schema_validation` | `support_required` | `connectors.errors.discovery_failed` | `pagination_limit_exceeded` |
| `DiscoveryIncompletePagination = 'discovery_incomplete_pagination'` | `schema_validation` | `support_required` | `connectors.errors.discovery_failed` | `incomplete_pagination` |
| `DiscoverySchemaValidationFailed = 'discovery_schema_validation_failed'` | `schema_validation` | `support_required` | `connectors.errors.discovery_failed` | `schema_validation_failed` |

**Shared `automatic_retry` result codes (verbatim reuse):** exactly these six
shared cases retain `AutomaticRetry` actionability in discovery — no more, no
fewer (same grouped match arm as
`ConnectorConnectionCheckErrorCode::actionability()`):

| Code | String value | Cause | Actionability | Message key |
|---|---|---|---|---|
| `AdobeRequestTimeout` | `adobe_request_timeout` | `network` | `automatic_retry` | `connectors.errors.timeout` |
| `AdobeRateLimited` | `adobe_rate_limited` | `rate_limit` | `automatic_retry` | `connectors.errors.rate_limited` |
| `AdobeVendorUnavailable` | `adobe_vendor_unavailable` | `vendor_unavailable` | `automatic_retry` | `connectors.errors.vendor_unavailable` |
| `TransportDnsResolutionFailed` | `transport_dns_resolution_failed` | `network` | `automatic_retry` | `connectors.errors.network_unavailable` |
| `TransportTimeout` | `transport_timeout` | `network` | `automatic_retry` | `connectors.errors.network_unavailable` |
| `TransportConnectionFailed` | `transport_connection_failed` | `network` | `automatic_retry` | `connectors.errors.network_unavailable` |

HTTP 5xx / gateway outcomes map to the existing `AdobeVendorUnavailable` case —
discovery does **not** define a separate gateway-specific code.

`TransportResponseSizeExceeded` (`transport_response_size_exceeded`) is a
shared case reused verbatim from connection-check; it is mapped from
`TransportFailureReason::ResponseSizeExceeded` via a new
`AdobePaaSDiscoveryTransportMapper` mirroring
`AdobePaaSConnectionCheckTransportMapper` exactly — reusing the existing
term, not inventing a competing one.

**Pagination-error precedence (Resolved)** — explicit order, so
implementation and tests cannot disagree on an overlapping case (e.g.
`total_count=10,000`, 50 pages fetched, only 9,900 items received —
which would trigger *both* candidate rules under an unordered reading):
1. if `total_count > 10,000` at any point, before fetching further pages
   → `DiscoveryPaginationLimitExceeded`, checked first;
2. after the 50th page, if the accumulated count is still less than
   `total_count` → `DiscoveryPaginationLimitExceeded` (continuing would
   require a forbidden 51st page) — this takes precedence over rule 3
   whenever both would otherwise apply;
3. an empty page received before the accumulated count reaches
   `total_count`, and before the 50-page limit is hit →
   `DiscoveryIncompletePagination`;
4. an accumulated count greater than `total_count`, or any other
   count mismatch not covered by rules 1–3 → `DiscoveryIncompletePagination`.

#### Account projection mapping after discovery (Resolved)

- any terminal vendor outcome (success or failure) updates
  `ConnectorAccount.last_discovery_at`;
- success additionally sets `last_successful_discovery_at`, sets
  `connection_status = Connected`, and clears all four `last_error_*`
  fields;
- a **result-level** (`ConnectorDiscoveryRunErrorCode`) outcome whose
  actionability is `automatic_retry` — meaning a real, persisted vendor
  result exists and was classified retryable — sets
  `connection_status = TemporarilyUnavailable` if it remains the terminal
  state once retries are exhausted;
- **`discovery_attempts_exhausted_without_result` (the lifecycle code) by
  itself never changes `connection_status`** — it means no persisted
  vendor result exists at all, so there is nothing retryable to reflect;
  this keeps it consistent with "lifecycle codes never overwrite the
  projection";
- a result whose actionability is `user_action_required`,
  `workspace_admin_required`, or `support_required` sets
  `connection_status = AttentionRequired` and writes the four
  `last_error_*` fields from that result;
- a lifecycle-only failure (dispatch/job-failed) does not by itself
  change `connection_status`;
- `discovery_account_disabled_before_execution` never changes the
  projection (the account is already disabled);
- the projection is updated **only if this run is the newest run for
  the account by `(created_at, id) DESC`** — a stale/delayed terminal
  write from an older run must never overwrite a newer run's result.

**Worker-activation gate (reference environment):** closed 2026-08-15 — Supervisor program
`babypark-connector-queue` installed, verified `RUNNING`, and confirmed processing
`ConnectorDiscoveryRunJob` on the `database_connectors` / `connectors` lane; manual
Discovery enabled only after worker verification; one successful production manual
UI Discovery recorded (see `DEPLOY.md`). Task 4B-2b-1 gates the manual UI trigger on
confirmed worker `RUNNING` state — see `07-TECH_STACK.md`.

### ConnectorSchemaSnapshot (Resolved)

Immutable successful normalized schema capture.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `workspace_id` | UUID FK | |
| `connector_account_id` | UUID FK | |
| `connector_schema_source_id` | UUID FK | |
| `discovery_run_id` | UUID FK | Producing run |
| `previous_snapshot_id` | UUID FK nullable | Chain |
| `schema_version` | string nullable | From source/account context |
| `field_count` | unsigned int | Count of normalized snapshot fields only (`fields_normalized`), never the raw received total. |
| `canonical_hash` | char(64) | Hash of ordered normalized field hashes |
| `captured_at` | timestamp | Vendor-normalized capture instant |
| `created_at` | timestamp | Append-only |

If `canonical_hash` equals previous snapshot, a new run may still append a snapshot
for audit, but UI labels the outcome **«Без змін»** rather than implying field churn.

**Raw external payload:** not stored by default.

### ConnectorSchemaSnapshotField (Resolved)

Normalized field state inside one snapshot. **No** `previous_value` / `current_value`
columns — diffs are separate entities.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `workspace_id` | UUID FK | |
| `snapshot_id` | UUID FK | |
| `external_field_key` | string | Adobe: `attribute_code` |
| `external_label` | string nullable | |
| `normalized_data_type` | string | Connector-neutral type code |
| `is_required` | boolean nullable | |
| `is_multi_value` | boolean nullable | |
| `is_localizable` | boolean nullable | |
| `external_scope` | string nullable | |
| `normalized_payload` | JSON | Whitelisted metadata + options |
| `canonical_hash` | char(64) | Per-field deterministic hash |
| `sort_order` | unsigned int nullable | |
| `created_at` | timestamp | |

Unique: `(snapshot_id, external_field_key)`.

### Adobe attribute normalization (Resolved)

This section defines how Adobe Commerce PaaS/on-prem `GET /V1/products/attributes`
**list** responses are converted into the canonical
`ConnectorSchemaSnapshotField` shape **before** hashing (see
`Connector schema canonical hashing (Resolved)` below). It is versioned contract
`v1` — the same discipline as the hashing contract: it must never change
silently; any change requires an explicit documentation-level decision and a
rebaseline plan.

**Source: list endpoint only, no per-attribute enrichment.** Adobe's own
documentation and field experience show that list/search endpoints may not
reliably return full per-object detail (for example `frontend_labels` returning
`null`, or `frontend_input` for a swatch attribute showing as plain `select`, on
list responses). Given the 50-page/10,000-field budget assumes exactly one list
endpoint, **do not add N+1 per-attribute detail requests in v1.** Normalize
only fields reliably present in `GET /V1/products/attributes` list responses. A
field this contract marks as sourced from the list response but which arrives as
`null`/missing is handled per the missing-value rule below — it is **not**
fetched via a follow-up detail call.

Confirmed upstream service contracts (Magento 2.4 `AttributeInterface`,
`EavAttributeInterface`, `ProductAttributeInterface`): list items expose
`attribute_code`, `frontend_input`, `is_required`, `options[]`,
`default_frontend_label`, `frontend_labels[]`, `scope`, `position`,
`backend_type`, `validation_rules[]`, `is_unique`, `default_value`, `note`, and
other standard properties — but v1 maps only the subset in the table below.

| Canonical field | Adobe source (list response) | Conversion rule |
|---|---|---|
| `external_field_key` | `attribute_code` | direct copy, no transformation |
| `external_label` | `default_frontend_label` | direct copy. `frontend_labels[]` (per-store labels) are **not** captured anywhere in v1 — not in this field, not in `normalized_payload`. Per-store label capture is deferred to a future version; capturing it now without a defined localization consumer would be premature scope |
| `normalized_data_type` | `frontend_input` | mapped through this exact, closed lookup table for v1 — genuinely connector-neutral values, not raw Magento/Adobe terms (per the existing "Connector-neutral type code" note on this column): `text`→`text`, `textarea`→`long_text`, `texteditor`→`long_text`, `date`→`date`, `datetime`→`datetime`, `boolean`→`boolean`, `select`→`select`, `multiselect`→`multi_select`, `price`→`money`, `media_image`→`image`, `gallery`→`image_collection`, `weight`→`number`. `weight` was confirmed as a real `frontend_input` value via a real-store discovery smoke test (see PR history) and added as `number` — the first, and currently only, entry in this v1 vocabulary representing a plain decimal number without a currency. Any `frontend_input` value not in this table terminates the whole vendor execution attempt with `DiscoverySchemaValidationFailed` — never guessed, never passed through unmapped. Explicitly **not** derived from `backend_type` (Magento's internal DB storage type is a different concept from the merchant-facing input type). This discovery-level vocabulary is not required to match the future `FieldDefinition`/Field Dictionary vocabulary exactly — reconciling the two (e.g. how `datetime` or `image_collection` map onto whatever Task 4C's import model uses) is that later task's own decision; discovery must not lose information just because a downstream consumer doesn't exist yet |
| `is_required` | `is_required` | `true`/`false` → direct copy; missing or `null` → `null` (per the canonical value-type contract's own `is_required: boolean or null` — never defaulted to `false`, since an unknown value is not the same claim as "confirmed optional"); any other type terminates the whole vendor execution attempt with `DiscoverySchemaValidationFailed` |
| `is_multi_value` | derived | `true` when `frontend_input` is `multiselect` or `gallery` (both represent a collection of values, per the `normalized_data_type` mapping above — `gallery`'s `image_collection` type is definitionally multi-value), else `false` |
| `is_localizable` | derived from `scope` | `global`→`false`, `website`→`false`, `store`→`true`. This is a v1 approximation: it reflects "capable of varying by store view," not a verified match to this project's specific JSONB-language-dictionary localization model — `website`-scoped values are intentionally treated as non-localizable in v1 since website-level variation is not the same concept as language localization. Document this distinction explicitly: the boolean must not imply more than it means |
| `external_scope` | `scope` (the REST-visible string field on the attribute object) | normalized to the closed lowercase vocabulary `global`/`website`/`store`; any other value terminates the whole vendor execution attempt with `DiscoverySchemaValidationFailed` |
| `normalized_payload` | whitelist, closed for v1 | exactly: normalized `options[]` (per the already-Resolved option-normalization rule, sourced from the list response's own `options[]`) for `select`/`multiselect` types only, producing `{"options":[...]}` (empty list allowed: `{"options":[]}`); for all other `normalized_data_type` values, `normalized_payload` is always `{}` — vendor-supplied `options` on a non-selectable type are ignored, not copied. `validation_rules`, `note`, `is_unique`, `default_value` are explicitly **excluded from v1** — not because they're unimportant, but because their exact shape/reliability on the list endpoint hasn't been verified against this project's actual pilot Adobe instance; adding them later is a new versioned decision, not a silent addition |
| `sort_order` | `position` | Adobe REST `ProductAttributeInterface` (extending `Magento\Catalog\Api\Data\EavAttributeInterface`) exposes the attribute ordering value as `position`. A JSON integer `>= 0` is copied directly into canonical `sort_order`; missing or `null` becomes `null`; any non-integer value — including a numeric string like `"10"`, since the canonical contract forbids coercing a numeric string into a number — or a negative integer terminates the whole vendor execution attempt with `DiscoverySchemaValidationFailed`. Never derived from page, array, database-insertion, or response order. A vendor extension field literally named `sort_order`, if one happens to be present, is not used in v1 — only `position` is read. **If the real pilot instance's actual response lacks a `position` field, stop and report the exact Adobe Commerce version, endpoint, and a redacted literal response item — do not silently fall back to any other field name, including `sort_order`.** This would signal a version/module drift from the documented service contract, not a reason to guess |

#### Discovery eligibility before normalization (v1)

Before `AdobePaaSAttributeNormalizer` runs, each raw list item is classified as
one of:

- **merchant-facing discoverable attribute** — normalized, hashed, and persisted;
- **Magento internal/service-only attribute without `frontend_input`** — counted
  as received, excluded from normalization and the canonical hash, and not a
  schema-validation failure;
- **unknown or malformed merchant-facing attribute** — fails the whole vendor
  execution attempt via the existing schema-validation contract.

A raw item may be excluded as service-only **only when all** of the following
hold simultaneously:

1. the raw item is a JSON object (`\stdClass`);
2. `attribute_code` passes the same structural identifier contract as the
   normalizer: property present, JSON string, non-empty, valid UTF-8 (this is
   identifier validation, not an allowlist of specific codes — an invalid
   `attribute_code` never permits skip and must fail schema validation even when
   the other three conditions below match);
3. `frontend_input` is present and exactly `null` (read with `property_exists()`
   plus `is_null()` — missing `frontend_input` is not skip-eligible);
4. `is_user_defined` is present and exactly boolean `false` (read with
   `property_exists()` plus `is_bool()` — no truthy checks, no `== false`, no
   implicit cast; `"0"`, `0`, or any non-boolean value is not skip-eligible);
5. `is_visible` is present and exactly boolean `false` (same strict mechanics as
   `is_user_defined`).

`apply_to` is **not** part of this rule. All four service-only examples
observed on the pilot store happened to carry `apply_to = ["downloadable"]`, but
that is a single-store observation, not proof that `apply_to` is irrelevant in
general. The three-condition rule above was deliberately chosen to stay
independent of `apply_to`; if a future real store exposes a
`frontend_input = null` / `is_user_defined = false` / `is_visible = false`
attribute with a different `apply_to` value, skipping it is expected behavior,
not a bug.

`backend_type` is never used to infer canonical type or to justify skip —
Magento's internal storage type is unrelated to merchant-facing input type.

`is_visible = false` alone is not sufficient for exclusion. Useful invisible
merchant-facing attributes (for example `created_at`, `minimal_price`,
`url_path`) remain normalized when they carry a non-null `frontend_input`.

Skipped service-only attributes are operational, not anomalous. After a fully
successful paginated discovery, when one or more were skipped, the adapter emits
**exactly one** `INFO`-level log entry summarizing the skipped count and their
(now-validated) `attribute_code` values. No entry is emitted when the skipped
count is zero, and no per-page skip logging occurs.

#### Missing/null/empty handling (v1)

- `attribute_code` missing, `null`, or empty string → terminates the whole
  vendor execution attempt with `DiscoverySchemaValidationFailed` (this is the
  field-hash primary key, it cannot be defaulted or absent);
- `external_label` missing or `null` → the canonical field is `null`;
  `external_label` present as an empty string → preserved as an empty string
  (distinct from `null`, per the already-Resolved canonical contract);
- `frontend_input` missing → terminates the whole vendor execution attempt
  with `DiscoverySchemaValidationFailed` (load-bearing for
  `normalized_data_type`, cannot be defaulted);
- `frontend_input` present as `null` → service-only skip **only** when the
  eligibility rule above matches; otherwise terminates the whole vendor execution
  attempt with `DiscoverySchemaValidationFailed`;
- `is_required` missing or `null` → canonical `null` (never defaulted to
  `false`);
- `scope` missing or `null` → terminates the whole vendor execution attempt
  with `DiscoverySchemaValidationFailed` (no safe default for a value that
  determines `is_localizable`);
- on a `select`/`multiselect` field: `options` missing or `null` → terminates
  the whole vendor execution attempt with `DiscoverySchemaValidationFailed`;
  `options` present as an empty list `[]` → valid, produces
  `normalized_payload: {"options":[]}`;
- on a non-selectable type, any `options` value present is ignored (not an
  error);
- `sort_order` missing or `null` → canonical `null` (not an error).

#### Whole-attempt schema-validation semantics (v1)

Any normalization or option-validation failure — in any **merchant-facing**
Adobe attribute, any mapped property, or any option row — **invalidates the complete vendor
execution attempt**. The adapter must **not** skip the invalid field and
continue processing remaining attributes. Service-only
attributes excluded by the eligibility rule above are not merchant-facing and
are intentionally skipped without failing the attempt.

On such a failure:

- no `ConnectorSchemaSnapshot` row is published;
- no `ConnectorSchemaSnapshotField` rows are published;
- the terminal result code is `DiscoverySchemaValidationFailed`
  (`discovery_schema_validation_failed`);
- actionability is `support_required`;
- the outcome is **non-retryable** (not `automatic_retry`).

This applies uniformly to every rule in this section that terminates the
whole vendor execution attempt with `DiscoverySchemaValidationFailed`, including
unknown `frontend_input`, unknown `scope`, invalid `position`/`sort_order`,
missing required `options` on selectable types, and duplicate option values per
the canonical option-normalization rule.

#### Ignored vendor properties (v1)

Normalization reads only the explicitly mapped source fields listed in the
table above. Every other vendor property on the response object — including
unknown extension/module fields from any installed Magento module — is silently
ignored and never persisted. The list below documents known, standard Adobe
fields this v1 contract deliberately doesn't map, for clarity; it is **not** a
closed allowlist for the entire response object, and an unrecognized field must
never cause `schema_validation` failure by itself:

`attribute_id`, `entity_type_id`, `is_visible_in_grid`, `is_filterable_in_grid`,
`is_used_in_grid`, `is_visible_on_front`, `is_unique`, `is_wysiwyg_enabled`,
`frontend_class`, `source_model`, `backend_model`, `backend_type`, `note`,
`default_value`, `validation_rules`, `frontend_labels`.

Each is ignored because it's either Magento-internal wiring irrelevant to a
merchant-facing canonical schema, or explicitly deferred per the
`normalized_payload` whitelist decision above — not because it was overlooked.

#### Raw value type validation (v1)

- mapped Adobe string properties must arrive as JSON strings — no
  int/bool/float-to-string coercion is performed;
- `attribute_code` and `frontend_input` are required, non-empty strings;
  any other type or an empty string is a whole-attempt failure;
- `default_frontend_label`: missing/`null` → canonical `null`; a string
  (including `""`) is preserved as-is; any other type is a whole-attempt
  failure;
- selectable `options` must be decoded as a genuine JSON list — after
  decoding the response with `json_decode(..., associative: false)`, a
  JSON list `[...]` becomes a plain PHP list array (PHP:
  `array_is_list()` true regardless of the `associative` flag, since
  that flag only affects how JSON *objects* decode); a JSON object in
  this position (including `{}`) decodes to `\stdClass`, not a PHP
  array, and is rejected as a whole-attempt failure — this distinction
  is only reliable because the response is decoded with
  `associative: false` throughout, never `true`;
- each option row must decode as `\stdClass` (a JSON object); any other
  shape — including a PHP array, which cannot occur here under
  `associative: false` decoding unless the raw JSON itself was a nested
  array where an object was expected — is a whole-attempt failure;
- option `value` is required and must be a string (empty string valid);
  any other type or absence is a whole-attempt failure;
- option `label`: missing/`null` → canonical `null`; a string (including
  `" "` and `""`) is preserved as-is; any other type is a whole-attempt
  failure;
- unknown keys inside an option row are ignored and never persisted;
- on a non-selectable type, any raw `options` value is ignored
  completely, even if malformed — malformed data in a field this
  contract doesn't read is not a validation failure;
- no scalar coercion occurs anywhere in this normalizer — a value must
  already be the exact expected raw type, or the field/attempt fails.

#### Placeholder select options (v1)

Adobe list responses for `select`/`multiselect` attributes commonly include a
placeholder first option with an empty value and a single-space label (observed
shape: `{"label": " ", "value": ""}`). **Do not introduce an unconfirmed
heuristic to strip or special-case this row** — every option row Adobe returns,
including this one, is normalized and hashed per the existing, already-Resolved
option-normalization rule (unique `value` bytewise, sorted ascending) exactly
like any other option. Inventing a stripping rule not already in the Resolved
contract would itself be a silent, undocumented normalization decision.

#### Raw payload prohibition

Raw Adobe response bodies are never persisted, only the mapped canonical shape
(already stated for the hash contract — restated here for the mapping step
specifically, since that's where raw data first enters the system).

### Connector schema canonical hashing (Resolved)

Canonical hashes provide deterministic no-change detection for normalized
external schemas. They never hash raw vendor responses.

#### Field canonical hash

`ConnectorSchemaSnapshotField.canonical_hash` is the lowercase hexadecimal
SHA-256 digest of the following exact byte sequence:

1. the ASCII bytes of `babypark.connector-schema-field.v1`;
2. exactly one LF byte (`0x0A`) — not the two characters `\` and `n`;
3. the canonical JSON UTF-8 bytes immediately after that LF, with no
   further bytes following.

The preimage contains no BOM, no NUL byte, no carriage return, and no
trailing newline after the JSON document.

The canonical field object contains exactly:

- `external_field_key`
- `external_label`
- `normalized_data_type`
- `is_required`
- `is_multi_value`
- `is_localizable`
- `external_scope`
- `normalized_payload`
- `sort_order`

Identifiers, workspace/snapshot foreign keys, timestamps, request metadata,
pagination position, and the hash column itself are excluded.

**The canonical field object's value types are fixed:**

- `external_field_key`: UTF-8 string;
- `external_label`: UTF-8 string or `null`;
- `normalized_data_type`: UTF-8 string;
- `is_required`: boolean or `null`;
- `is_multi_value`: boolean or `null`;
- `is_localizable`: boolean or `null`;
- `external_scope`: UTF-8 string or `null`;
- `normalized_payload`: JSON object, subject to the container and
  whitelist rules elsewhere in this section;
- `sort_order`: non-negative integer or `null`.

Adapters must normalize values to these exact types before hashing.
Boolean fields must be encoded as JSON `true`/`false`/`null`, never as
`0`, `1`, `"0"`, or `"1"`. String fields must never be converted to
numbers merely because their contents are numeric. `null` and an empty
string are distinct canonical values.

**Canonical JSON is produced in PHP as:**

```php
json_encode(
    $value,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_THROW_ON_ERROR
)
```

No `JSON_*` flags other than the three listed above are permitted.
`JSON_FORCE_OBJECT` is forbidden because it changes JSON container-type
semantics by encoding PHP lists as objects; canonical container kinds
must remain explicit and stable (see the container-kind rules below).
`JSON_PRETTY_PRINT`, `JSON_NUMERIC_CHECK`, `JSON_INVALID_UTF8_IGNORE`,
`JSON_INVALID_UTF8_SUBSTITUTE`, and `JSON_PARTIAL_OUTPUT_ON_ERROR` are
likewise forbidden — each either introduces non-canonical whitespace,
silently reinterprets values, or silently tolerates data that must
instead fail with `schema_validation`.

Canonical values may contain only `null`, booleans, integers, valid UTF-8
strings, associative objects, and JSON lists. Floats, resources, and all
other unsupported values fail with `schema_validation`. All enum
instances, including backed enums, must be converted to their approved
primitive string/integer representation before canonicalization — enum
objects themselves are forbidden canonical input.

Canonical container kinds are explicit and must survive normalization:

- `normalized_payload` is always a JSON object. When it has no keys, its
  canonical encoding is `{}`, never `[]`.
- `options` is always a JSON list. When it has no items, its canonical
  encoding is `[]`, never `{}`.
- a JSON list is a zero-based contiguous sequence; a JSON object is a
  string-keyed map;
- the canonicalizer must not infer an empty object's kind from an empty
  PHP array — before `json_encode()`, an empty object must be represented
  as `(object)[]` or an equivalent explicit object node, since PHP's
  `json_encode([])` produces `[]` while `json_encode((object)[])`
  produces `{}`, and this distinction does not resolve itself.

Canonical serialization rules:

- top-level keys are always present; unknown top-level values are `null`;
- object keys are recursively sorted using locale-independent bytewise order;
- decoded string values are preserved exactly — no trimming, lowercasing, or
  Unicode normalization;
- invalid UTF-8 and unsupported values fail with `schema_validation`; they are
  never silently replaced;
- vendor identifiers and option values are normalized to strings;
- `normalized_payload` contains only adapter-approved whitelisted metadata;
- optional null-valued keys inside `normalized_payload` are omitted;
- JSON list (array) element order is preserved by the canonical serializer
  as-is — canonicalization only sorts object keys, never reorders arrays.
  Before serialization, every collection whose vendor order is not
  semantically meaningful must already be normalized by its adapter using
  an explicit, documented stable comparator (`options` use the comparator
  defined below). No unordered collection may enter `normalized_payload`
  without an explicit normalization rule — silently retaining arbitrary
  vendor response order, or silently sorting an array with no defined
  comparator, are both forbidden.

`sort_order` represents only an explicit semantic ordering value supplied
by the external schema itself and normalized by the adapter (type per the
value-type list above: non-negative integer or `null`, `null` when the
external schema provides no such value). It must never be derived from
page number, item offset,
response-array position, database insertion order, or the order in which
pages completed. This is what allows `sort_order` to affect the hash while
pagination/fetch order does not — they are not the same kind of ordering.

Each normalized option is an object containing exactly:

- `value`: non-null UTF-8 string;
- `label`: UTF-8 string or `null`.

Option values must be unique by bytewise comparison after normalization.
Duplicate values fail with `schema_validation`. After uniqueness
validation, options are sorted by `value` using locale-independent
bytewise ascending order — `label` is part of the hashed option object but
is never used as a sort key or tie-breaker (uniqueness already guarantees
`value` alone determines order). Vendor response order does not affect
the hash.

This is a deliberately custom v1 format, not full RFC 8785 (JSON
Canonicalization Scheme) compliance — it borrows JCS's general principles
(deterministic key sorting, no whitespace, UTF-8 output) without adopting
JCS's ECMAScript-specific number serialization or UTF-16-code-unit-based
property sorting, both unnecessary complexity for this fully-controlled,
server-side-only DTO. Floats are forbidden entirely rather than given a
JCS-style serialization rule, which sidesteps that complexity outright.

#### Snapshot canonical hash

`ConnectorSchemaSnapshot.canonical_hash` is the lowercase hexadecimal
SHA-256 digest of the following exact byte sequence:

1. the ASCII bytes of `babypark.connector-schema-snapshot.v1`;
2. exactly one LF byte (`0x0A`);
3. the canonical JSON UTF-8 bytes immediately after that LF, with no
   further bytes following — for example (compact, single-line; shown
   here on multiple lines only for display):

```text
babypark.connector-schema-snapshot.v1
{"fields":[{"canonical_hash":"...","external_field_key":"..."}]}
```

Produced with the same `json_encode()` flag contract as the field hash
above. The field pairs are sorted by `external_field_key` using
locale-independent bytewise ascending order. Pagination order, vendor
response order, database row IDs, and capture timestamps do not affect
the snapshot hash.

Duplicate `external_field_key` values in one discovery run are a
`schema_validation` failure. Failed or incomplete discovery does not publish a
snapshot.

No-change is determined by comparing the canonical hash with the latest
successful snapshot for the same connector account and schema source. An equal
hash may still produce a new append-only audit snapshot; the operator UI labels
the result «Без змін».

This is canonicalization contract `v1`. It uses the existing `char(64)` columns
and requires no migration. Any future change to the preimage or normalization
rules requires an explicit documentation-level decision and a rebaseline plan;
the algorithm must never change silently.

### ConnectorSchemaDiff / ConnectorSchemaDiffItem (Resolved schema; dormant runtime)

`connector_schema_diffs` compares `from_snapshot_id` → `to_snapshot_id` with
aggregate counts. **First snapshot:** UI label `Перший знімок` — baseline, not
misleading “додано N” without explanation.

**Current repository status (reverified):** models, migrations, factories, and
relationships exist, but there is **no write path and no consumer** that
computes or persists diffs yet. Readers must not infer a working schema-diff
runtime from the presence of these entities. Diff computation remains Task
4B-2c scope.

#### Physical schema — `connector_schema_diffs` (Resolved)

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `workspace_id` | UUID FK | Required from first migration |
| `connector_account_id` | UUID FK | Composite guard with `workspace_id` |
| `connector_schema_source_id` | UUID FK | Same source as both endpoint snapshots |
| `from_snapshot_id` | UUID FK nullable | Null only for a true baseline diff |
| `to_snapshot_id` | UUID FK | Resulting snapshot; one canonical diff per snapshot |
| `is_first_snapshot` | boolean | True exactly when `from_snapshot_id` is null |
| `added_count` | unsigned int | |
| `changed_count` | unsigned int | |
| `removed_count` | unsigned int | |
| `unchanged_count` | unsigned int | |
| `created_at` | timestamp | Immutable, append-only; no `updated_at` |

Unique: `(to_snapshot_id)` — each resulting snapshot has at most one canonical diff.
`discovery_run_id` is intentionally **not** stored here — it is available via
`to_snapshot.discovery_run_id`; duplicating it would create an unenforced invariant.

Index: `(connector_account_id, connector_schema_source_id, created_at)` for history
queries.

Both endpoint snapshots must belong to the same workspace, account, and schema
source represented by the diff — enforced through composite FK guards where
possible, and application invariants where a cross-reference cannot be expressed
portably.

#### Physical schema — `connector_schema_diff_items` (Resolved)

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `workspace_id` | UUID FK | Required from first migration |
| `connector_schema_diff_id` | UUID FK | Composite guard with `workspace_id` |
| `change_type` | enum | `added`, `removed`, `changed` |
| `external_field_key` | string | Connector field key |
| `before_snapshot_field_id` | UUID FK nullable | Required for `removed`/`changed` |
| `after_snapshot_field_id` | UUID FK nullable | Required for `added`/`changed` |
| `changed_paths` | JSON nullable | JSON array; `changed` items only |
| `created_at` | timestamp | Immutable, append-only; no `updated_at` |

Unique: `(connector_schema_diff_id, external_field_key)`.
Index: `(connector_schema_diff_id, change_type)`.

**Application invariants** (documented now; enforcement and behavioral rejection
tests belong to Task 4B-2, where the snapshot/diff computation service is introduced.
Task 4B-1 provides columns, casts, relationships, FK integrity, and factories only,
and must not add model observers/events that pretend to replace that future domain
service):

- `added`: `before_snapshot_field_id = null`, `after_snapshot_field_id != null`,
  `changed_paths = null`;
- `removed`: `before_snapshot_field_id != null`, `after_snapshot_field_id = null`,
  `changed_paths = null`;
- `changed`: both field FKs required, `changed_paths` is a non-empty JSON array;
- `external_field_key` must match the referenced before/after fields;
- referenced fields must belong to the diff's corresponding endpoint snapshots;
- all parent references satisfy the documented composite workspace guards.

Both tables follow the same append-only, immutable-after-creation discipline as
`ConnectorConnectionCheck`/`ConnectorDiscoveryRun`/snapshots.

### Dual-axis error classification (Resolved)

**Cause:** `authentication`, `authorization`, `configuration`, `rate_limit`,
`vendor_unavailable`, `network`, `schema_validation`, `data_validation`, `unknown`.

**Actionability:** `user_action_required`, `automatic_retry`,
`workspace_admin_required`, `support_required`.

User-facing text uses `user_message_key` + safe parameters — never raw vendor
exceptions or a single coarse `business|technical` axis.

Example keys: `connectors.errors.invalid_credentials`,
`connectors.errors.insufficient_permissions`, `connectors.errors.rate_limited`.

### Task 4B vs Task 4C boundary (Resolved)

| Task | Scope |
|---|---|
| **4B-0** (this PR) | Stop-and-Amend docs + visual contract only |
| **4B-1** | Migrations/domain foundation for `ConnectorAccount` + history tables |
| **4B-2** | Adobe live discovery, snapshots, diffs, operational UI |
| **4C** | Sync Domain mapping slice: `FieldMapping` suggestions, confidence, confirmation, manual resolution against discovered `external_field_key` identity (owned by `SyncConfiguration` per Sync Domain Rebaseline) |

Task 4B snapshots are **input** to Task 4C. Discovery must **not** auto-create
`FieldMapping` rows. Canonical Adobe mapping rows in
`docs/data/canonical_product_field_mappings.csv` are platform-global suggestion/
evidence knowledge only — not account schema and not workspace mapping state.

### Retention (Resolved initial policy)

| Data | Retention |
|---|---|
| Connection checks / failed attempts | 90 days |
| Discovery run metadata | 12 months |
| Successful normalized snapshots | Last 30 per account+source |
| Latest successful snapshot | Always retained |
| Raw vendor payload | Not stored by default |

Diff summaries are retained only while their endpoint snapshots are retained —
a diff must never outlive the snapshot it describes as `latest`, and must never
block deletion of a non-latest, non-endpoint snapshot.

Pruning order: `connector_schema_diff_items` → `connector_schema_diffs` →
old `connector_schema_snapshot_fields` → old `connector_schema_snapshots` →
eligible `connector_discovery_runs` → old `connector_connection_checks`, never
deleting a snapshot still referenced as `latest` or as a diff endpoint.

FK delete behavior: `connector_schema_diffs.from_snapshot_id` and `.to_snapshot_id`,
and `connector_schema_diff_items.before_snapshot_field_id` /
`.after_snapshot_field_id`, all use `restrictOnDelete()` — the pruning service is
responsible for deleting dependent diff/diff-item rows first, in the order above.
This preserves referential integrity without requiring nullable endpoint FKs.

### FK delete-behavior matrix (Resolved)

Required because a naive `restrictOnDelete()` default on every FK would make the
documented pruning order (old snapshots deleted before their producing/eligible
runs, older snapshots pruned while newer ones may still chain-reference them)
impossible at the DB level.

| FK | Behavior | Why |
|---|---|---|
| `connector_discovery_runs.snapshot_id` | `restrictOnDelete()` (composite) | **Not** `nullOnDelete()` — MySQL requires every column in a composite FK to be nullable for `SET NULL`, and `workspace_id` is `NOT NULL`, so the constraint cannot even be created. See pruning exception below. |
| `connector_discovery_runs.previous_snapshot_id` | `restrictOnDelete()` (composite) | Same MySQL composite-FK-with-NOT-NULL-column restriction |
| `connector_schema_snapshots.previous_snapshot_id` | `restrictOnDelete()` (composite) | Same restriction |
| `connector_schema_snapshots.discovery_run_id` | `restrictOnDelete()` (composite) | Producing-run link; pruning order deletes snapshots before their run becomes eligible, so this never blocks correct-order pruning |
| `connector_schema_diffs.from_snapshot_id` / `.to_snapshot_id` | `restrictOnDelete()` (composite) | Per Зміна 3 — pruning service deletes diffs before their endpoint snapshots |
| `connector_schema_diff_items.before_snapshot_field_id` / `.after_snapshot_field_id` | `restrictOnDelete()` (composite) | Same reasoning — items deleted before fields |
| `connector_schema_snapshot_fields.snapshot_id` | `restrictOnDelete()` (composite) | Per pruning order, fields are deleted before their own snapshot by the pruning service, not by cascade |
| All `connector_account_id` / `connector_schema_source_id` / `connector_definition_id` references | `restrictOnDelete()` | Consistent with `connector_schema_sources.connector_definition_id`'s existing precedent — global/parent metadata is never silently orphaned |
| `initiated_by_user_id` (checks, runs) | `nullOnDelete()` | Single-column FK, no composite — audit-log semantics, history survives user deletion |

**Pruning exception (narrow, deliberate):** snapshot/run records are immutable
operational history, except that the three nullable archival pointer columns above
(`connector_discovery_runs.snapshot_id`, `.previous_snapshot_id`,
`connector_schema_snapshots.previous_snapshot_id`) may be explicitly cleared —
`UPDATE ... SET <column> = NULL WHERE <column> = ?` — by the future pruning service
(Task 4B-2+) immediately before deleting the snapshot they point to. MySQL
implements MATCH SIMPLE semantics (a composite FK with any column NULL is not
checked against the parent), so clearing only the pointer column — leaving
`workspace_id` untouched — lets the referenced snapshot be deleted afterward under
`restrictOnDelete()`. Task 4B-1 does not implement this pruning service; it only
ensures the FK shape supports it correctly later.

### Cross-reference consistency invariants (documented now, enforced in Task 4B-2)

These are **not** database constraints and are **not** implemented by Task 4B-1 —
they are the contract the future discovery/diff computation service (4B-2) must
satisfy and be tested against:

- For every discovery run, snapshot, and diff, the selected
  `connector_schema_source.connector_definition_id` must equal the related
  `connector_account.connector_definition_id`. An account for one platform must
  never discover or diff a schema source owned by another platform definition.
- If `connector_discovery_runs.snapshot_id` is non-null, the referenced
  `connector_schema_snapshots.discovery_run_id` must equal that run's own `id`,
  and both rows' `connector_account_id`/`connector_schema_source_id` must match.
- If `connector_schema_diffs.from_snapshot_id` and `.to_snapshot_id` are both
  non-null, both referenced snapshots must belong to the same
  `connector_account_id` and `connector_schema_source_id` as the diff itself.
- For `connector_schema_diff_items`, `before_snapshot_field_id` must belong to the
  diff's `from_snapshot_id`, and `after_snapshot_field_id` must belong to the
  diff's `to_snapshot_id` — not merely to *some* snapshot.

Task 4B-1 provides the columns, relationships, and FK integrity that make these
invariants checkable; it does not add observers/events that enforce them.

The 12-month `connector_discovery_runs` retention applies only to runs not
referenced by a retained snapshot. A producing run (`connector_schema_snapshots.discovery_run_id`)
is retained for at least as long as any snapshot that references it — including
the "latest successful snapshot" exception, which is always retained regardless of
age. This is what makes "eligible discovery runs" in the pruning order unambiguous:
eligible means both older than 12 months **and** not the producing run of any
still-retained snapshot.

Indexes (Resolved):
- `connector_connection_checks`: `(connector_account_id, created_at)`
- `connector_discovery_runs`: `(connector_account_id, created_at)`
- `connector_schema_snapshots`: `(connector_account_id, connector_schema_source_id, created_at)`

Supported and tested in this task: MySQL, SQLite.

Generated column syntax:
- MySQL:  `VARCHAR(255) AS (...) VIRTUAL`
- SQLite: `TEXT GENERATED ALWAYS AS (...) VIRTUAL`

`config/database.php` retains Laravel's standard `pgsql` connection template, but
Task 4B-1 does not introduce or claim a PostgreSQL migration contract because no
PostgreSQL environment is part of the project's current deploy/test matrix. The
existing `FieldFoundationMigrator` branches on `mysql` versus a generic fallback;
that fallback is verified here only for SQLite and must not be presented as verified
PostgreSQL support.

### Connector adapter capabilities (Resolved)

Connector runtime uses a shared adapter base plus explicit capability ports.
Profiles declare supported capabilities in the adapter registry; unsupported
capabilities must fail before enqueue with a stable internal error — never with a
fallback adapter.

Minimum read capabilities through Task 4B-2c:
- `connection_check` — prove auth and permission for the next capability
- `schema_discovery` — paginated fetch and normalization of external product-attribute metadata

Write/import/export and FieldMapping are out of scope until Task 4C+.

#### ConnectorCapability as UI source of truth (Resolved — UX contract 2026-08-10)

**Normative UX reference:** `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md`.

`App\Enums\ConnectorCapability` is the single domain source of truth for
which optional connector abilities exist today (`ConnectionCheck`,
`SchemaDiscovery`, `AccountSetup`). Each profile declares its supported set in
`config/connectors.php`; `ConnectorProfileDefinition::supports()` and
`ConnectorProfileRegistry::requireCapability()` are the callable checks.

**Rules:**
- UI must gate **connector-capability-dependent** surfaces on `supports()` for
  the real enum case — no parallel UI-only connector-capability flags.
- A new **connector-specific/runtime** ability requires a new
  `ConnectorCapability` case in its own scoping pass **before** UI that depends
  on that ability ships; UI must not invent interim connector-capability flags.
- Connector-capability-gated sections appear only when `supports()` is true —
  never present-by-default with per-connector hiding.

**Governing invariant:** a feature must become a `ConnectorCapability` only
when its availability or semantics genuinely vary by connector/runtime support.
Platform-owned functionality must **not** become a connector capability merely
because it is optional, future, configurable, UI-driven, or not yet
implemented. Examples of platform-owned concerns include scheduling, mapping
UI, dry-run/preview orchestration, issue aggregation, bulk resolution,
sync-run history, and similar platform workflow/UI/orchestration capabilities.
Those require their own platform/domain/runtime design and implementation
passes; they do **not** require `ConnectorCapability` enum extension merely to
exist. Genuinely new connector/runtime semantics (for example, whether a
connected profile can support a given `(data_domain, semantic operation)`) may
still require domain design and capability-contract evolution.

The UX contract defines required merchant behavior for sync surfaces when those
platform or connector concerns are implemented; it does not assert they exist
in code today.

**Sync capability truth boundary (Resolved — Sync UX / Domain Rebaseline):**
a `SyncConfiguration` is valid/activatable only when the connected runtime
contract authoritatively supports the requested `(data_domain, semantic
operation)`. That connection / profile / runtime-contract boundary is the
single source of truth for the combination. `ConnectorDefinition.direction`
remains only a coarse platform envelope and must not be treated as execution
capability truth. Do not invent a large future capability taxonomy or DSL in
this documentation pass — the first real sync implementation slice must derive
the minimum concrete vocabulary required by the runtime.

#### ConnectorAccount cardinality (Resolved — UX contract 2026-08-10)

A workspace may have **zero, one, or many** `ConnectorAccount` rows for the same
`ConnectorDefinition`, distinguished by `name`. Uniqueness is
`(workspace_id, connector_definition_id, active_name_uniqueness_key)` among
non-deleted rows — not one-connection-per-platform. Platform identity in
merchant UI (`Інтеграції`) is not equivalent to a single account.

#### Field/data-domain write ownership (future — UX contract 2026-08-10)

Per-data-domain control ("Де ви хочете керувати цінами?") is a required merchant
question in Layer B when bidirectional sync is enabled. **No global silent
ownership default** and no hardcoded platform-side default. The product default
itself remains an open Product Owner question (Sync Domain Rebaseline PO-3).
Do **not** introduce mandatory per-field authority before that product need
exists.

The storage and enforcement mechanism (domain-level ownership policy vs
last-write-wins vs later per-field rules) is an **open domain decision**
requiring its own architectural pass when bidirectional ships. This
documentation records the merchant question only.

**Safe non-destructive defaults remain allowed:** automation/scheduling off
until explicitly enabled; a connector supporting only one data-changing
behavior does not present a fake choice for consistency.

#### Layer C diagnostics audience (Resolved — UX contract 2026-08-10; authorization rebaselined 4C-1c-2a)

`ConnectorDiscoveryRun`, schema snapshots, canonical hashes, technical summaries,
and raw error codes belong to **Layer C** (platform support/operator) — not to
workspace merchant users regardless of their business-owned role/access profile
names.

Layer C is unavailable to **all** workspace merchant role/access profiles unless
a separate platform-support identity exists. Job-title names (Admin, Director,
Merchandiser, …) do **not** grant Layer C access.

If no platform-support identity model exists at implementation time, Layer C
surfaces are unavailable rather than defaulting to any workspace merchant
membership. Layer assignment is a visibility ceiling; workspace-scoped atomic
permissions defined in **Workspace access model and authorization (Resolved —
Task 4C-1c-2a)** remain authoritative inside Layers A/B.

Discovery runtime, snapshot persistence, and Field Browser read architecture
are shipped and retained. Merchant-facing copy and navigation migration to the
UX contract is tracked under GAP-025 — not an architectural regression.

#### Credential and settings classification (Resolved)

Every profile field maps to exactly one storage boundary:
1. typed `connector_accounts` column,
2. non-secret `settings` JSON,
3. encrypted `credentials` (`encrypted:array`),
4. ephemeral token cache (IMS/SaaS only, later).

Adobe PaaS (`adobe_commerce_paas_oauth1_integration`):
- `base_url`, `store_code`, optional `tenant_context` → typed columns
- OAuth consumer/access token material → `credentials`
- other non-secret options → `settings`

Adobe SaaS profile field placement remains documented in the runtime proposal
until IMS discovery parity is confirmed; reusing `store_code` for the `Store`
header value is the preferred convention pending approval.

### ConnectorAccount authorization (Resolved — rebaselined Task 4C-1c-2a, 2026-08-13)

Rebased under **Workspace access model and authorization (Resolved — Task
4C-1c-2a)**. Job-title / role names carry **no** authorization semantics.
Connector operations require workspace-scoped permission evaluation through
`ConnectorAccountPolicy` checks (or successor) on every read and mutating action.

**Normative permission vocabulary:** see the frozen minimum permission vocabulary
in **Workspace access model and authorization (Resolved — Task 4C-1c-2a)** —
especially `view_connector_accounts`, `run_connector_discovery`, and
`manage_connector_accounts`.

**ConnectorAccount capability evaluation (frozen):**

| Capability | Effective when membership has |
|---|---|
| Safe `ConnectorAccount` view (Layer A/B read; no decrypted credentials/settings secrets) | `view_connector_accounts` **OR** `run_connector_discovery` **OR** `manage_connector_accounts` |
| Manual discovery trigger + safe progress/result read surface | `run_connector_discovery` **OR** `manage_connector_accounts` |
| Connection check | `manage_connector_accounts` |
| Create / settings / credential mutation / disable / archive | `manage_connector_accounts` |

**Security boundaries (unchanged):**

- Decrypted credentials must never appear in API resources, logs, events, queue
  payloads, or exception reports.
- Discovery dispatch goes through policy and an application service, never a direct
  Filament/Eloquent action; it records `initiated_by_user_id`, trigger, and a
  history row, and respects the same account-level lock/overlap/rate-limit rules
  as any other trigger.
- When a user lacks credential/settings mutation permission, the UI shows a safe
  recommendation to contact a colleague with access-management authority — it does
  not expose the underlying restriction as a raw permission error.
- Scheduled discovery remains a system-initiated operation; configuring it
  (enabling/disabling, changing schedule) is outside the manual-discovery
  permission slice and requires its own future workspace-permission decision when
  scheduling ships.

**Historical pre-B-2 shipped authorization (GAP-026 — not normative):**

Before **GAP-026B-2**, the repository shipped `ConnectorAccountPolicy` that granted
some connector abilities via fixed `User.role` checks (for example Merchandiser
read/discovery, Admin/Director management bypass) and applied safe presentation
through transitional `ConnectorAccountMerchandiserPresentation`. That pre-B-2
behavior was transitional implementation mismatch only — it was **not** the target
authorization contract and must not be extended or reintroduced.

**026B repository status (post-B-2):** connector authorization and safe presentation
in the repository now follow **Workspace RBAC authority cutover (Resolved —
GAP-026B-0, 2026-08-13)** — the frozen workspace-permission matrix via
`ConnectorAuthorization` / `WorkspaceAuthorization`, capability-based presentation
through `ConnectorAccountCapabilityPresentation`, and removal of
`WorkspaceMembership` as an additional connector gate. **Production activation**
of that authority switch completed on the reference environment on 2026-08-14 via the
verified maintenance-window **EXECUTE** cutover. Merging B-2 code was not itself
the production cutover; the separate production activation has now also completed.

### Connection-check capability and error mapping (Resolved)

PaaS connection check is a single staged call:
`GET {base_url}/rest/{store_code}/V1/products?searchCriteria[pageSize]=1` —
this proves OAuth signature validity and the Product read permission required by
the Product integration (`Magento_Catalog::products`) in one round trip. Schema
Discovery remains a separate read of `/V1/products/attributes`; connection
readiness never requires `Magento_Catalog::attributes_attributes`.

| Vendor signal | HTTP | Cause | Actionability | User message key |
|---|---|---|---|---|
| Invalid/revoked token or consumer key | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_credentials` |
| OAuth signature/nonce/timestamp | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_signature` |
| Exact structured Product route ACL denial (`parameters.resources` contains `Magento_Catalog::products`) | 401/403 | `authorization` | `user_action_required` | `connectors.errors.insufficient_permissions` |
| Invalid base URL/store/path, or unsupported endpoint on an otherwise valid host | 404 | `configuration` | `user_action_required` | `connectors.errors.invalid_or_unsupported_endpoint` |
| Timeout | 408 / curl timeout | `network` | `automatic_retry` | `connectors.errors.timeout` |
| Rate limited | 429 | `rate_limit` | `automatic_retry` | `connectors.errors.rate_limited` |
| 5xx / gateway | 5xx | `vendor_unavailable` | `automatic_retry` | `connectors.errors.vendor_unavailable` |
| JSON/schema mismatch | 200 + bad body | `schema_validation` | `support_required` | `connectors.errors.unexpected_response` |

A single HTTP 404 from the connection-check URL does not, by itself, reveal
whether the base path, store code, endpoint, Adobe module/version, or
reverse-proxy routing is at fault — collapsing these into two differently
named causes would invent a distinction the error mapper cannot actually make
from one status code alone. All 404s map to one stable
`configuration`/`user_action_required` category; safe technical detail (the
attempted URL, safely redacted) may still be shown to help diagnosis, but the
cause/message-key does not pretend to know which specific configuration
field is wrong. A future probe that can genuinely disambiguate these cases
may split this category later — that is not part of this decision.

Raw vendor response bodies are never user-facing. For bounded JSON protected-REST failures, only the allowlisted top-level `oauth_problem` and `parameters.resources` fields are inspected. Certified resource representations are a non-empty string or a list of non-empty strings. Exact recognized OAuth problems take precedence; Product ACL denial is inferred only when the normalized set contains `Magento_Catalog::products`. HTTP status, safe request ID, probe family, recognized problem/resource identifiers, and response-shape class may be retained transiently; raw bodies and authorization material are never persisted.

#### Adobe OAuth identifier vocabulary (Task 4B-2a-2b)

Protected REST API failures are classified only from the certified, bounded JSON
fields above. A present top-level `oauth_problem` must exactly match the identifier
vocabulary below; localized `message` text is never parsed. When neither a
recognized OAuth identifier nor the exact Product ACL resource is present, 401/403
fail closed to the unknown/support result rather than a status-only authentication
or permission guess.

| Adobe identifier | HTTP | Cause | Actionability | Message key |
|---|---|---|---|---|
| `timestamp_refused` | 400 | `authentication` | `user_action_required` | `connectors.errors.invalid_signature` |
| `signature_method_rejected` | 400 | `authentication` | `user_action_required` | `connectors.errors.invalid_signature` |
| `nonce_used` | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_signature` |
| `signature_invalid` | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_signature` |
| `consumer_key_rejected` | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_credentials` |
| `token_used` | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_credentials` |
| `token_expired` | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_credentials` |
| `token_revoke` | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_credentials` |
| `token_rejected` | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_credentials` |
| `verifier_invalid` | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_credentials` |
| `consumer_key_invalid` | 403 | `authentication` | `user_action_required` | `connectors.errors.invalid_credentials` |
| `permission_unknown` | 403 | `authorization` | `user_action_required` | `connectors.errors.insufficient_permissions` |
| `permission_denied` | 403 | `authorization` | `user_action_required` | `connectors.errors.insufficient_permissions` |
| `method_not_allowed` | 405 | `configuration` | `user_action_required` | `connectors.errors.invalid_or_unsupported_endpoint` |
| `version_rejected` | 400 | `unknown` | `support_required` | `connectors.errors.connection_check_failed` |
| `parameter_absent` | 400 | `unknown` | `support_required` | `connectors.errors.connection_check_failed` |
| `parameter_rejected` | 400 | `unknown` | `support_required` | `connectors.errors.connection_check_failed` |

#### HTTP-status fallback table (Task 4B-2a-2b)

Extends the B7 table above for statuses B7 does not enumerate. B7 rows are
unchanged.

| HTTP result | Mapping |
|---|---|
| `200` + valid Adobe list shape | success |
| `200` + invalid JSON or wrong shape | B7 row: `schema_validation`/`support_required`/`unexpected_response` |
| other `2xx` | `schema_validation`/`support_required`/`connectors.errors.unexpected_response` |
| `3xx` | `configuration`/`user_action_required`/`connectors.errors.invalid_or_unsupported_endpoint` |
| `400`/`401`/`403`/`405` with a recognized Adobe identifier | per Adobe OAuth identifier table |
| `400` unrecognized | `unknown`/`support_required`/`connectors.errors.connection_check_failed` |
| `401` unrecognized | `unknown`/`support_required`/`connection_check_failed` |
| `403` unrecognized (including HTML, malformed/generic JSON, unrelated or unsupported resource shape) | `unknown`/`support_required`/`connection_check_failed` |
| `404` | B7 row: exact single-category mapping |
| `405` without a recognized OAuth identifier | `configuration`/`user_action_required`/`connectors.errors.invalid_or_unsupported_endpoint` |
| `408` | B7 row: `network`/`automatic_retry`/`connectors.errors.timeout` |
| `429` | B7 row: `rate_limit`/`automatic_retry`/`connectors.errors.rate_limited` |
| `5xx` | B7 row: `vendor_unavailable`/`automatic_retry`/`connectors.errors.vendor_unavailable` |
| any other `4xx` | `unknown`/`support_required`/`connectors.errors.connection_check_failed` |

#### Transport-failure mapping (Task 4B-2a-2b)

| `TransportFailureReason` | Cause | Actionability | Message key |
|---|---|---|---|
| `InvalidDestination` | `configuration` | `user_action_required` | `connectors.errors.invalid_or_unsupported_endpoint` |
| `UnsafeDestination` | `configuration` | `user_action_required` | `connectors.errors.invalid_or_unsupported_endpoint` |
| `DnsResolutionFailed` | `network` | `automatic_retry` | `connectors.errors.network_unavailable` |
| `Timeout` | `network` | `automatic_retry` | `connectors.errors.network_unavailable` |
| `ConnectionFailed` | `network` | `automatic_retry` | `connectors.errors.network_unavailable` |
| `TlsVerificationFailed` | `network` | `support_required` | `connectors.errors.tls_verification_failed` |
| `ResponseSizeExceeded` | `schema_validation` | `support_required` | `connectors.errors.unexpected_response` |
| `ChildProcessProtocolFailed` | `unknown` | `support_required` | `connectors.errors.connection_check_failed` |
| `ChildProcessCleanupFailed` | `unknown` | `support_required` | `connectors.errors.connection_check_failed` |
| `OtherTransportFailure` | `unknown` | `support_required` | `connectors.errors.connection_check_failed` |

`DestinationRequestMismatch` and `TransportConfigurationException` propagate
uncaught (internal wiring/deployment defects, not connection-check outcomes).

#### `ConnectorConnectionCheckErrorCode` enum vocabulary (Task 4B-2a-2b)

Persisted in `connector_connection_checks.error_code` (Task 4B-2a-2c):

Adobe OAuth: `adobe_oauth_version_rejected`, `adobe_oauth_parameter_absent`,
`adobe_oauth_parameter_rejected`, `adobe_oauth_timestamp_refused`,
`adobe_oauth_nonce_used`, `adobe_oauth_signature_method_rejected`,
`adobe_oauth_signature_invalid`, `adobe_oauth_consumer_key_rejected`,
`adobe_oauth_token_used`, `adobe_oauth_token_expired`, `adobe_oauth_token_revoke`,
`adobe_oauth_token_rejected`, `adobe_oauth_verifier_invalid`,
`adobe_oauth_permission_unknown`, `adobe_oauth_permission_denied`,
`adobe_oauth_method_not_allowed`, `adobe_oauth_consumer_key_invalid`.

HTTP fallback: `adobe_unexpected_response`, `adobe_unexpected_success_status`,
`adobe_redirect_response`, `adobe_unrecognized_bad_request`,
`adobe_invalid_credentials`, `adobe_insufficient_permissions`,
`adobe_invalid_or_unsupported_endpoint`, `adobe_request_timeout`,
`adobe_rate_limited`, `adobe_vendor_unavailable`,
`adobe_unrecognized_client_error`.

Transport: `transport_invalid_destination`, `transport_unsafe_destination`,
`transport_dns_resolution_failed`, `transport_timeout`,
`transport_connection_failed`, `transport_tls_verification_failed`,
`transport_response_size_exceeded`, `transport_child_process_protocol_failed`,
`transport_child_process_cleanup_failed`, `transport_other_failure`.

### Connection-check enqueue state (Resolved)

`ConnectorConnectionCheckStatus` includes `Queued`, `Running`, `Succeeded`, and
`Failed`. `connector_connection_checks.started_at` is nullable (null while
`status` is `queued`; set when the worker begins HTTP work).

Additional queue-lifecycle columns on `connector_connection_checks`:
- `execution_attempts` (unsigned tinyint, default `0`) — counts **claimed
  vendor-execution slots**, not confirmed HTTP calls; atomically incremented
  before each vendor call, capped at 3; conservative over-counting is
  acceptable, under-counting is not.
- `retry_until_at` — absolute 15-minute deadline from dispatch, shared by the
  job's `retryUntil()` and persisted on the row for deterministic stale-row
  recovery.
- `next_attempt_at` — guards against the database queue driver's independent
  `retry_after` redelivery bypassing an Adobe-mandated `Retry-After` or
  classified backoff delay.

Time semantics:
- `created_at` — operator requested / enqueued
- `started_at` — worker began external work (null while `queued`)
- `finished_at` — terminal
- `duration_ms` — cumulative HTTP/work duration across attempts (hrtime-based,
  summed per attempt), excludes queue wait

#### `ConnectorConnectionCheckLifecycleErrorCode` (queue/infrastructure only)

Never mixed into `ConnectorConnectionCheckErrorCode` (Adobe OAuth/HTTP/transport).
Lifecycle codes never change `connector_accounts` projection.

| Code | Cause | Actionability | Message key | Technical summary |
|---|---|---|---|---|
| `connection_check_dispatch_failed` | `unknown` | `support_required` | `connectors.errors.connection_check_failed` | `queue_dispatch_failed` |
| `connection_check_job_failed` | `unknown` | `support_required` | `connectors.errors.connection_check_failed` | `queue_job_failed` |
| `connection_check_attempts_exhausted_without_result` | `unknown` | `support_required` | `connectors.errors.connection_check_failed` | `vendor_attempt_budget_exhausted_without_result` |
| `connection_check_account_disabled_before_execution` | `configuration` | `workspace_admin_required` | `connectors.errors.account_disabled` | `account_disabled_before_execution` |

**Vendor-result precedence:** when a real vendor classification is already
persisted on a row (intermediate retry persistence), that classification is the
terminal truth in the attempts-exhausted branch, `failed()`, and stale-row
recovery — lifecycle codes never overwrite it.

#### Authorization and projection

- `ConnectorAccountPolicy::runConnectionCheck()` — dedicated ability; **management-only**
  via `manage_connector_accounts` through `ConnectorAuthorization` /
  `WorkspaceAuthorization` (not discovery-only or safe-read tiers). Dispatch uses
  `Gate::forUser($actor)->authorize('runConnectionCheck', $account)`.
- Account projection mapping on terminal **vendor** outcomes:

| Terminal vendor outcome | `connection_status` |
|---|---|
| `Succeeded` | `Connected` (clears all four `last_error_*` fields) |
| Failure, `AutomaticRetry`, attempts exhausted | `TemporarilyUnavailable` |
| Failure, `UserActionRequired` / `WorkspaceAdminRequired` / `SupportRequired` | `AttentionRequired` |
| Lifecycle/infrastructure failure | **unchanged** |
| Disabled account (before execution) | **unchanged** |

On any terminal vendor failure, also write `last_error_cause`,
`last_error_actionability`, `last_error_message_key`, and `last_error_at`.
Set `last_checked_at` on vendor terminal writes; set `last_successful_check_at`
only on `Succeeded`.

### Workspace isolation (Resolved)

Every table above includes `workspace_id` from the first migration, uses
`BelongsToWorkspace` (or approved equivalent), composite FK guards where parent
rows are workspace-scoped, policies on read/write, and tests for direct model,
service, and relation cross-workspace rejection.

## Receive / Import Foundation Contract (Resolved)

**Status:** Approved normative Receive / Import architecture.

This contract governs connector-backed Receive through the Sync Domain (`ConnectorAccount` + `SyncConfiguration`) path. It does **not** redefine the separate Smart Import / spreadsheet / CSV onboarding flow. File/snapshot imports may reuse shared Product/Variant domain writers and Field Foundation invariants, but they do **not** automatically inherit `ExternalRecordLink`, ENTITY TRUST, live remote reread, or Magento entity-bound transport requirements; their own source identity, provenance, and staleness semantics remain governed by their own import architecture.

### 1. Existing Sync Architecture Remains Intact
`SyncConfiguration` identity is exactly:

```text
ConnectorAccount
+ data_domain
+ external_context
```

Enabled semantic operations (`import`, `export`, or both) are **configuration state**, NOT part of identity. One `SyncConfiguration` may therefore enable `import` only, `export` only, or both. Do **not** create separate hidden Import and Export configurations merely because both operations are enabled. `FieldMapping`, `FieldOptionMapping`, `ExternalRecordLink`, and the zero-mutation `SyncRun` preview semantics are unchanged.

### 2. FieldMapping is Direction-Neutral
`FieldMapping` represents **semantic correspondence** between an internal target and an external logical identity. It is not an execution plan, field ownership record, data authority, Import-only mapping, Export-only mapping, or a reversible transformation. It possesses no `direction`, `authority`, `import_enabled`, `export_enabled`, `master_system`, or `last_writer` attributes. Import and Export use the same semantic correspondence but follow different execution pipelines.
The existence of a direction-neutral `FieldMapping` does **not** imply that the mapped field must execute in every enabled semantic operation. Execution eligibility may differ by semantic operation, connector/runtime capability, domain ownership policy, operation-specific planner/transformation, and future verified per-operation configuration. Independent per-operation mapping/configuration remains deferred until a verified product requirement exists (see Sync Domain Rebaseline historical/deferred notes).

### 3. Receive is Not Export Reversed
Export translates `FieldMapping` into platform execution input, then into a connector semantic planner, desired external state, and finally the Safe Sync external mutation boundary.
Receive flows from trusted remote identity → remote read → external normalization → `FieldMapping` resolution → Receive planner (candidate values) → domain-owner routing → zero-mutation proposal/diff → explicit Apply → platform domain writers. No universal reversible transformer exists.

### 4. Entity Trust is Direction-Neutral
The same merchant-confirmed `ExternalRecordLink` (ENTITY TRUST) serves both Receive and Send. Receive must not introduce a weaker second trust model. SKU alone, name matching, fuzzy matching, discovery snapshot existence, Receive proposal, or cached schema metadata are forbidden as identity authority. SKU may remain a mandatory equality/addressing precondition where already required, but it is not remote logical identity authority. The first Receive slice operates only against an existing internal Product/Variant with an established trusted `ExternalRecordLink`. Remote Product to new internal Product creation is out of the first Receive slice (though not permanently impossible).

### 5. Manual Receive Uses Operation-Time Authority
For the first manual Receive/Send experience, the user's explicit confirmed action is the authority for that one operation. There is no persistent field-level ownership or silent last-write-wins mechanism. Without a persisted synchronization baseline, the system cannot determine "only Magento changed" or "both changed". The contract recognizes: equal, differs, remote absent, local absent, unsupported/blocked, or explicit clear. Equal may silently no-op, but destructive replacement or clear requires explicit action.

Consequential Live execution authority for manual Receive Apply remains the existing
`run_sync_live` permission for both semantic operations:

- Import;
- Export.

Do **not** introduce `run_sync_receive`.

The current Stage 3-0 merchant consequential Live admission gate list is
the first Products/Export gate list. It is **not** a proof that every future Live
semantic operation inherits the same non-authority prerequisites unchanged.

For first manual Receive Apply:

- fresh `run_sync_live` is required;
- Export Preview evidence is **not** a Receive prerequisite;
- the matching operation-support gate is
  `ConnectorSyncOperationSupport(Products, Import, Live) === true`;
- the transient server-authoritative Receive proposal plus mandatory Apply-time
  revalidation is the manual Receive trust/readiness prerequisite.

Apply authorization commit points are frozen:

- the first fresh `run_sync_live` check occurs **before** proposal consumption,
  so an unauthorized actor does not burn the proposal;
- a second fresh `run_sync_live` check occurs **inside** the short Live Import
  admission transaction, immediately before `SyncRun` creation, against the
  locked/current Workspace authority state.

Revocation before successful admission means no `SyncRun` and no mutation.
Revocation after successful admission does **not** cancel that already-admitted
execution; this matches existing Live authority semantics.

This clarification does **not** enable Adobe Products/Import support.

### 6. Ownership, Baselines, and Automated Bidirectional Sync (Cross-Reference)
- Manual Receive requires **no** persistent field-level ownership.
- A persisted synchronization baseline — or equivalent evidence sufficient to distinguish change provenance and conflicts — is required **before** the platform may make unattended conflict claims (such as "only Magento changed", "only platform changed", or "both changed"). Without such baseline, the honest contract is only the manual diff vocabulary in §5.
- Ownership and the default-authority mechanism for automated bidirectional behavior remain the **existing open Product/domain decision** — see [Field/data-domain write ownership (future — UX contract 2026-08-10)](#fielddata-domain-write-ownership-future--ux-contract-2026-08-10) above. This contract does **not** choose now between domain-level ownership, later per-field rules, or any other approved mechanism, and it does **not** reopen that open decision.

### 7. Receive Mutation Routing by Domain Owner
Receive applies two **distinct** mutation routes. The storage path alone does not grant write capability.

**7.1 Dynamic route — `storage_type = dynamic`**
Target: ordinary Product/Variant dynamic `FieldBinding` values (text, number, boolean, date, select option resolution, etc.).
Boundary: the governed Product/Variant field-value writer (see `docs/IMPLEMENTATION_GAPS.md` → GAP-028). GAP-028 is implemented today as the current governed boundary for ordinary dynamic `Text`, `LongText`, `Number`, `Decimal`, `Boolean`, `Date`, single-value `Select`, `MultiSelect`, and `Url` fields. This writer MUST validate/enforce at minimum:
- explicit `Workspace` scope;
- active `FieldDefinition`;
- active `FieldBinding`;
- correct `Product` vs `ProductVariant` object type;
- declared data type;
- null vs explicit-clear semantics;
- option validity/resolution where applicable;
- localization / storage invariants, including prohibition of illegal flat overwrites of `is_localizable = true` structured values.

It may handle only ordinary values whose invariants belong entirely to Field Dictionary.

Current generic GAP-028 scope: `Text`, `LongText`, `Number`, `Decimal`, `Boolean`, `Date`, single-value `Select`, `MultiSelect`, `Url`.
`Money` remains owned by the Pricing domain, `Image` remains owned by the Media domain, and `Computed` remains derived / non-writable; they are outside the generic GAP-028 writer. GAP-028 is **Closed**.

**7.2 Column-backed route — `storage_type = column`**
Target: Product/Variant column-backed core fields (e.g. typed `products` / `product_variants` columns).
Boundary: `app/Services/Catalog/GovernedProductVariantColumnMutationService.php`, the appropriate Product/Variant domain mutation boundary with an explicit Receive allowlist, closed under GAP-029. This contract does **not** propose migrating column-backed core fields into dynamic storage merely to reuse §7.1. Invariants:
- Column-backed values **MUST NOT** go through the generic dynamic field-value writer in §7.1.
- `storage_type = column` is never sufficient authority.
- Storage path alone does not grant write capability.
- Mutation authority requires the full canonical metadata tuple: `FieldDefinition` code, scope, workspace ownership, declared data type, active status, `is_localizable`, `is_multi_value`, supported validation-rules shape, plus `FieldBinding` workspace ownership, object type, storage type, storage path, and active status.
- Every column-backed field must be **explicitly admitted** based on its domain semantics (its routing is not implied by its column location).
- Connector code MUST NOT use broad `fill()`, mass assignment, or arbitrary `Model::update()` with remotely supplied values.
- First explicit allowlist: Product `name` and Product `description` only.
- Product `name` is admitted only for the canonical global/global System `FieldDefinition` / `FieldBinding` tuple bound to `products.name`; Set requires a PHP string, rejects `null`, empty string, and whitespace-only string, preserves the exact string, rejects physically oversized payloads, and `clear()` is forbidden.
- Product `description` is admitted only for the canonical global/global System `FieldDefinition` / `FieldBinding` tuple bound to `products.description`; Set requires a PHP string, rejects `null`, preserves the exact string including `''`, rejects physically oversized payloads, and `clear()` sets `NULL`.
- The first consequential column-backed Receive Apply MUST NOT call GAP-029
  `set()` blindly.
- Future Apply runtime requires an additive expected-current-value mutation path
  conceptually equivalent to `setIfCurrentValue(...)`.
- This expected-current-value precondition must be checked only **after**
  locking the target Product row inside the authoritative GAP-029 mutation
  transaction.
- Existing GAP-029 `set()` / `clear()` semantics remain unchanged. This
  contract does **not** claim that `setIfCurrentValue(...)` is already
  implemented.
- Immediately before local consequential mutation, the same locked section must
  also verify the Receive `SyncRun` is still executable: the run exists, its
  `status = Running`, `writer_deadline_at` is present, and current time is
  before that deadline.
- A recovered, failed, or expired run must **not** mutate `Product`, even if
  the earlier Receive proposal was valid when issued.
- No remote HTTP may occur inside this authoritative locked mutation
  transaction.
- All other current and future column-backed fields remain fail-closed until separately admitted, including `sku`, `gtin`, status / lifecycle, `brand`, `url`, `merchant_type`, `net_weight`, `gross_weight`, `volume_m3`, `internal_product_id`, pricing, availability, media, relations, and connector metadata.
- `sku` is **NOT** Receive-writable in the first slice. SKU remains an identity/addressing precondition, not an incoming mutable field.
- Product lifecycle status remains excluded from this first boundary; the interim two-state `products.is_active` representation must not be frozen as generic Receive lifecycle semantics.

GAP-029 is **Closed**.

**7.3 Out of both routes (always routed to domain owners)**

- **Pricing** — `PriceList` / `PriceListItem` / pricing domain. `PriceResolver` is not a writer.
- **Availability / Inventory** — inventory / availability domain. No direct mapped stock assignment.
- **Media** — media owner / runtime. Not generic field mutation.
- **Relations / categories** — relation-owning domain services.
- **Connector-owned metadata** — Magento `entity_id`, `attribute_set_id`, structural execution metadata, etc.

This routing contract is connector-independent. See `docs/IMPLEMENTATION_GAPS.md` → GAP-028 and GAP-029.

### 8. Receive Proposal/Diff is Not SyncRun Preview
A per-item or per-operation Receive proposal is short-lived, server-authoritative, and transient. It is not execution history, authorization, identity, or ENTITY TRUST, and is not persisted in `sync_runs` / `sync_run_items`. It reuses the existing opaque server-side flow pattern rather than a new persisted entity.

Consequential Receive Apply uses the existing Sync execution history shape:

- `SyncRun.mode = Live`;
- `SyncRun.semantic_operation = Import`;
- `SyncRun.configuration_revision =` the proposal/current verified revision at
  Apply time;
- `SyncRunItem =` Product business-record outcome.

First manual Receive Apply is synchronous/internal, not queue-dispatched.
Admission creates the `SyncRun` directly in:

- `mode = Live`;
- `semantic_operation = Import`;
- `status = Running`;
- `started_at =` admission time;
- `writer_deadline_at =` populated from the existing Live execution timing
  model;
- `recoverable_after =` populated from the existing Live recovery window.

Do **not** invent a `Queued` state or connector job for this first foreground
Apply. If the process dies, existing active-run recovery must eventually move
the stale `Running` run to `Failed`.

The Receive proposal itself remains transient and is **not** `SyncRun` history.

For the first name-only slice:

- exactly one affected business `Product`;
- a trusted `ProductVariant` may remain the remote correlation target, but
  its owning `Product` is the business record and local mutation owner;
- `SyncRunItem.product_id` is that owning `Product` id;
- no Variant column mutation;
- no SKU mutation.

Do **not** generalize `SyncRunItem` identity beyond `Product` from this slice.
Future genuine Variant-level Receive requires a separate Stop-and-Amend.

Expected/classified no-write outcomes such as stale local/remote state or a
classified pre-write remote-read failure use the existing Live
`not_applied` business outcome. Unexpected execution/lifecycle failure may fail
the `SyncRun`.

Do **not** add a new `SyncRunStatus` or `SyncLiveOutcome` value for Receive
Apply.

The configuration-owned selection contract remains unchanged and remains part of
`configuration_revision`. Do **not** change `SyncConfigurationRevisionHasher`.

A manual per-item Receive Apply has narrower run scope than the
configuration-owned selection. Freeze an additive run-owned `execution_target`
block in `SyncRun.configuration_snapshot` for targeted Receive execution.

The exact first Receive snapshot representation is frozen as
`platform.sync-run-input.v2` with additive run-owned:

```json
"execution_target": {
    "mode": "explicit_product",
    "product_id": "<owning Product id>"
}
```

For the first slice:

- configuration selection remains the truthful configuration state;
- `execution_target` identifies the one effective business `Product` executed;
- if the trusted Receive correlation target is `ProductVariant`, execution still
  resolves to its owning `Product` for this Product-name slice;
- `execution_target` is runtime evidence only, not configurable selection;
- `execution_target` must **not** become a general subset/selection feature.
- existing Export snapshots remain `v1` and unchanged;
- do **not** add generic `object_type` / `internal_record` polymorphism.

### 9. Apply-Time Revalidation is Mandatory
Before applying a Receive proposal, the runtime must freshly verify: actor authorization, target Workspace/ConnectorAccount, existing trusted `ExternalRecordLink`, remote logical identity, SKU equality precondition, `SyncConfiguration.configuration_revision`, mapping/option-mapping state, and that participating local and remote values have not changed. If state has changed, the proposal is invalidated and requires a rebuild (zero mutation).

First manual Apply ordering is frozen:

1. fresh authorization;
2. consume the opaque Receive proposal once;
3. Live Import run admission;
4. fresh remote reread **outside** the DB transaction;
5. short final locked validation/mutation transaction.

Live Import admission reuses the existing one-active-run-per-`SyncConfiguration`
boundary. Inside admission:

- recover stale active runs using the existing recovery semantics;
- reject if any `Queued` / `Running` `SyncRun` still exists for the
  configuration, regardless of Preview/Live or Import/Export.

Do **not** introduce a Receive-specific lock or concurrency table. Receive
Apply intentionally serializes with existing Preview and Export Live activity on
the same `SyncConfiguration`.

After successful proposal consumption, any failure requires a fresh proposal. No
automatic replay.

Apply must revalidate at minimum:

- `SyncConfiguration.configuration_revision`;
- trusted `ExternalRecordLink`, remote logical identity, and SKU precondition;
- current `FieldMapping` / `FieldOptionMapping` state where applicable;
- participating remote value unchanged;
- participating local value unchanged.

For this R3 name-only slice, Apply is executable only when the consumed
proposal contains exactly **one** entry satisfying all of:

- `objectType = Product`;
- `domainRoute = ProductVariantColumn`;
- `diffState = Differs`;
- `localValuePresent = true`;
- `remoteValuePresent = true`;
- `explicitClear = false`;
- `blockedReasonCode = null`;
- `fieldBinding` resolves to the canonical admitted Product `name`.

`Equal` is not a consequential Apply action.
`UnsupportedOrBlocked` is not executable.
Any other proposal shape fails closed **before** `SyncRun` admission and before
remote HTTP.

### 10. Option Mappings and Reverse Resolution
`FieldOptionMapping` remains direction-neutral. For Receive, if external option → internal option resolution is ambiguous (not unique) under the current legitimate persistence model, that field is blocked. No uniqueness constraint is added merely to simplify Receive.

### 11. Discovered ≠ Normalized ≠ Supported ≠ Executable
A field may be Discovered → Normalizable → Semantically mappable → Supported for this operation → Has a domain writer → Executable. Invariants:
- Successfully discovered external fields remain represented in the authoritative schema/snapshot truth according to existing discovery contracts.
- Unsupported, non-normalizable, or non-executable fields MUST NOT be silently converted into supported mappings or discarded merely to make execution appear complete.
- Reference / supporting surfaces (Layer C, schema browser, etc.) may expose them appropriately.
- This contract does **not** require the primary merchant mapping UI to list every discovered external field; the concept-first merchant UX is preserved.

### 12. Entity-Bound Receive Transport (Validation Gate)
- Stock REST filtering by `entity_id` (e.g., `GET /V1/products?searchCriteria[...] entity_id ...`) is a **candidate** transport for entity-bound Receive, not a proven production assumption.
- No Receive runtime may depend on this route until a real supported-target smoke / certification proves it satisfies the frozen logical-entity + SKU-precondition contract. A source-code inference alone is insufficient.
- If real-target proof fails, the existing first-party Safe Sync entity-bound read remains the fallback.
- This decision does **not** weaken ENTITY TRUST.

### 13. Stage 3E Send Remains Unchanged
Receive-first sequencing does not reopen or weaken Stage 3E Send. ENTITY TRUST, no-link mutation prohibitions, entity-bound consequential writes, ambiguous applied-state handling, no blind retry, real-target certification gates, and current support=false truth remain strictly enforced.

### 14. First-Slice Boundaries (R3 Contract)
The first manual Receive Apply contract remains:

**IN**

- existing trusted `Product` / `ProductVariant`;
- existing name proposal;
- canonical Product `name` only;
- manual explicit Apply;
- existing GAP-029 column owner;
- existing `SyncRun` / `SyncRunItem`;
- existing `run_sync_live`.

**OUT**

- new `Product` creation;
- Variant field Apply;
- SKU Receive;
- `description` or broader fields;
- pricing / availability / media;
- ownership / baseline;
- unattended sync;
- new permission;
- new persistence table / column;
- Import support flip;
- merchant UI.

Adobe Products/Import support remains **false** until separate truthful runtime
and real-target validation work is completed.


## Sync Domain Rebaseline (Resolved — normative)

**Status:** Approved normative Sync UX / Domain model. Supersedes earlier
proposed `ImportJob` / `ExportJob` / `SyncJob` framing as the primary sync
execution model. Those names may remain only as historical design context;
they are **not** current normative sync entities.

**Non-goals of this rebaseline:** no migrations, no runtime implementation,
no final DB column inventory, no transport DSL, no capability taxonomy freeze,
and no Product Owner decisions for open merchant-product choices listed below.

### Minimum conceptual relationship

```text
ConnectorAccount
    └─1:N─ SyncConfiguration
             ├─1:N─ FieldMapping
             └─1:N─ SyncRun
                      └─1:N─ SyncRunItem
```

- `SyncRun` belongs to `SyncConfiguration`.
- `SyncRun` is **not** a child of `FieldMapping`.
- `FieldMapping` and `SyncRun` are siblings owned by `SyncConfiguration`.
- `ExternalRecordLink` remains a separate **account-scoped** external-identity
  concept (not SyncConfiguration-scoped).

Do **not** introduce speculative entities merely for symmetry. Unless current
repository evidence creates a real requirement, the following remain out of
scope for this rebaseline:

- `MappingSet`;
- persistent `SyncIssue` lifecycle;
- `ExternalFieldIdentity` entity;
- transport-operation entity/DSL;
- readiness-state entity/enum;
- generic edition/deployment-model entities.

### SyncConfiguration — identity and responsibility

Normative conceptual identity / ownership boundary:

```text
ConnectorAccount
+ data_domain
+ external_context
```

`external_context` is deliberately **direction-neutral**. The same external
Magento website/store/store-view context can be a source during import and a
destination during export. Do **not** call this generic concept
`target_context` in normative architecture. Exact DB/property names are not
normative in this documentation pass.

`external_context` represents connector-specific external business context that
changes the meaning/scope of synchronization. Magento website/store/store-view
behavior provides verified examples of such dimensions. Do **not** hard-code
Magento-specific scope vocabulary into the generic SyncConfiguration domain
model, and do **not** prescribe the physical DB representation yet.

How `external_context` is exposed in MVP is a Product Owner decision (see
open product questions below). Normative domain docs recognize the concept
without choosing whether MVP uses one implicit/default context or allows
merchants to configure multiple websites/store views independently.

#### Semantic operations

Direction/import/export is **not** part of SyncConfiguration identity.

A SyncConfiguration may enable one or more semantic operations supported by
the connected runtime contract:

- import;
- export.

One domain/context configuration may therefore conceptually enable import
only, export only, or both. Merchant UI may expose two operation checkboxes;
that must **not** be translated into two persisted SyncConfiguration rows
merely because import and export can be independently enabled. Semantic field
correspondence may remain shared across the enabled operations.

`ConnectorDefinition.direction` remains a separate coarse platform envelope
(`Import | Export | Both`). It is not the same domain concept/type as enabled
operations on SyncConfiguration, and must not be reused as SyncConfiguration
capability truth.

#### SyncConfiguration owns conceptually

- `data_domain`;
- `external_context`;
- independently enabled semantic operations supported by runtime-contract
  capability truth;
- selection scope;
- effective FieldMappings;
- schedule state/policy;
- enabled / paused operational state;
- stable comparable configuration revision.

Exact database columns are not prescribed unless current repository
conventions make a representation unavoidable.

#### MVP operational constraint

For MVP, one domain/context SyncConfiguration **may** share one selection
policy and one scheduling policy across its enabled import/export operations.

Treat that as an MVP **product constraint**, not a permanent architectural
invariant. Independent per-operation selection, schedules, mappings, or other
independently-owned configuration must be introduced only when a verified
product requirement demonstrates the need. Do not split import/export
configurations merely for hypothetical flexibility.

### FieldMapping — semantic correspondence only

Minimum normative FieldMapping responsibility:

```text
internal target
    ↔
external logical identity
```

FieldMapping represents **semantic correspondence**. It is not an execution
plan. The correspondence itself is direction-neutral.

#### Not mandatory FieldMapping persistence

The minimum FieldMapping does **not** require:

- external JSON/payload access paths;
- REST/GraphQL endpoint names;
- immutable `ConnectorSchemaSnapshotField` IDs as long-lived mapping identity;
- schema-source namespace/source FK merely as future insurance;
- per-field authority/ownership;
- one generic persisted transformation assumed valid for both import/export;
- connector transport/cardinality mechanics.

#### Transformation semantics

Do **not** prescribe one direction-neutral `transformation` property as
mandatory FieldMapping state. Import parsing/normalization and export
formatting/transformation may differ, be asymmetric, or be non-reversible. If
mapping-specific transformation behavior is required, it must be explicitly
aware of the semantic operation/direction for which it applies. Persistence/API
shape of such transformation behavior is not decided by this rebaseline.

#### Internal vs external handler boundaries

Internal domain target resolution and external connector transport are
orthogonal responsibilities. Keep conceptually distinct:

A. **Internal platform/domain target** — e.g. canonical field binding, pricing
   domain, availability/inventory domain, media/domain-owned concepts.

B. **External connector transport** — how the external system actually
   reads/writes/executes the semantic intent.

Do **not** create one universal `handler` abstraction that spans both
boundaries. Descriptive names such as DomainTarget / DomainTargetHandler or
external transport/access/planning concepts may be used in design discussion;
exact implementation class/interface names are not normative here.

For Field Foundation-backed internal targets, mappings reference a field
binding identity (`field_binding_id` / approved equivalent), not a bare
FieldDefinition code. Domain-owned targets such as pricing, availability, or
media are not represented solely by `field_binding_id` and require an internal
domain-target boundary whose physical FieldMapping representation is finalized
before Task 4C persistence.

### External logical identity and discovery

For the **current** implemented Magento discovery contract,
`external_field_key` is sufficient logical external identity in the existing
account/domain context.

Reverified repository facts:

- `ConnectorDiscoverySourceResolver` selects exactly one source matching all of
  `schema_scope = Account`, `source_kind = AccountApi`,
  `acquisition_mode = LiveFetch`, `is_primary = true`, and fails on zero or
  multiple matches.
- The Adobe `admin_rest_api` global/RemoteStatic source does not participate in
  this account discovery contract.
- `ConnectorSchemaSnapshotField` uniqueness is `(snapshot_id, external_field_key)`.

Therefore: do **not** add schema-source/namespace persistence to FieldMapping
merely as hypothetical future insurance.

#### Discovery responsibility

Discovery answers:

- what logical external fields actually exist for **this** connected account now;
- what normalized schema metadata describes them
  (including the already-established normalized data-type/scope vocabulary).

Mappings must survive immutable snapshot replacement by reconciling their
stable logical identity against the current authoritative discovery state.
An immutable snapshot row ID must **not** be the long-lived mapping identity.

#### Normalization precedent

Preserve the already-implemented architectural precedent:

```text
connector-specific schema interpretation
    ↓
connector normalizer
    ↓
platform-usable normalized schema semantics
    ↓
persistence
```

`AdobePaaSAttributeNormalizer` currently maps Adobe list attributes into
canonical fields (`external_field_key`, normalized type/scope metadata,
normalized payload whitelist). Document the principle as strongly as current
code supports it:

> Persist stable logical identity and normalized semantic metadata required by
> the platform. Keep connector-specific transport interpretation/mechanics
> inside the connector boundary.

Do **not** state the false blanket rule “raw external vocabulary is never
persisted.” `external_field_key` itself is intentionally connector-local
external logical identity and may be persisted.

#### FieldMapping first persistence contract
[Resolved — Task 4C-1a, 2026-08-12]

This section freezes the **minimum physical and lifecycle contract** for the
first FieldMapping implementation slice (Task 4C-1b). It does **not** authorize
migrations, models, services, or UI — documentation only.

##### First-slice scope

| Dimension | First slice (4C-1b) | Explicitly deferred |
|---|---|---|
| `data_domain` | `products` only | pricing, availability/inventory, media, categories, customer, connector-only technical concepts |
| Internal target | `FieldBinding` only (`field_binding_id`) | `target_type` / `target_id` / `target_kind` polymorphic targets; pricing-domain, availability-domain, media-relation, or category-relation targets |
| `FieldObjectType` | `product`, `product_variant` | `customer` and any future object types |
| Persistence | Effective **confirmed** workspace mappings only | Suggestion candidates, confidence, `suggestion_source`, ephemeral prefill state |

Do not add polymorphic target columns “for future universality” in this slice.
Domain-owned non-`FieldBinding` targets require a separate internal domain-target
boundary (already acknowledged above) before their FieldMapping representation
is finalized.

##### Minimum physical schema — `field_mappings`

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `workspace_id` | UUID NOT NULL | Workspace-owned row |
| `sync_configuration_id` | UUID NOT NULL | Owned child of `SyncConfiguration` |
| `field_binding_id` | UUID NOT NULL | Internal semantic target |
| `external_field_key` | string NOT NULL | Stable external **logical** identity |
| `created_at` / `updated_at` | timestamps | |

**Not in the minimum table** (unless a separate, already-Resolved invariant
requires otherwise): `direction`, `operation`, `import_enabled`, `export_enabled`,
`authority`, `confidence`, `suggestion_source`, `suggestion_status`, `snapshot_id`,
`snapshot_field_id`, `schema_source_id`, `endpoint`, `external_path`, `json_path`,
`transformation`, `connector_capability`, `is_valid`, `stale`.

FieldMapping remains **direction-neutral semantic correspondence**
(`internal target` ↔ `external logical identity`). It is not an execution plan,
transport schema, or connector runtime operation descriptor.

##### Ownership and FK contract

`field_mappings` is an owned child of `SyncConfiguration`.

| FK edge | Behavior | Rationale |
|---|---|---|
| `(workspace_id, sync_configuration_id)` → `sync_configurations(workspace_id, id)` | `ON DELETE CASCADE` | Mapping has no standalone meaning after its parent configuration is removed. Task 4C-0 already established the workspace-aware parent key `(workspace_id, id)` on `sync_configurations`. |
| `field_binding_id` → `field_bindings.id` | `ON DELETE RESTRICT` | Prevent silent disappearance of confirmed mappings when an internal target row is physically deleted. |

**Field Foundation governance precedent:** within the same subsystem,
`field_bindings.field_definition_id` uses `cascadeOnDelete()` against
`field_definitions` (`FieldFoundationMigrator` / migration
`2026_07_12_150000_field_foundation.php`). That cascade governs definition→binding
cleanup, not merchant-confirmed connector mappings. `RESTRICT` on
`field_mapping.field_binding_id` is compatible: physical binding deletion is
blocked while effective mappings exist; archival/deprecation is the preferred
governance path (see lifecycle below).

**Transitive definition deletion (intentional fail-closed):** after Task 4C-1b,
`field_mappings.field_binding_id → field_bindings.id` remains
`ON DELETE RESTRICT`. Therefore:

1. **Direct binding delete blocked** — physical deletion of a referenced
   `FieldBinding` is rejected by the database while effective `field_mappings`
   rows exist.
2. **Parent definition delete transitively blocked** — because
   `field_bindings.field_definition_id → field_definitions.id` currently uses
   `ON DELETE CASCADE`, physical deletion of a `FieldDefinition` attempts to
   cascade-delete its descendant `FieldBinding` rows. When any cascaded binding
   is referenced by an effective FieldMapping, that cascade is blocked by
   `RESTRICT` on `field_mappings.field_binding_id`, so the parent
   `FieldDefinition` delete fails transitively at the database level.
3. **No silent mapping loss** — definition/binding deletion must **not**
   silently remove confirmed connector mappings. Do **not** change
   `field_mappings.field_binding_id` to `CASCADE` or `nullOnDelete()` merely to
   preserve pre-mapping physical-delete behavior.

**Preferred lifecycle:** archive/deprecate (`FieldDefinition.status` /
`FieldBinding.status` = `archived`) rather than physical delete.

**When physical delete is genuinely required:** effective mappings must be
explicitly removed or remapped first through the controlled FieldMapping
mutation path (Task 4C-1b).

**Task 4C-1b obligation:** application-level graceful handling of this
constraint (domain exception / user-facing error) — merchants must not see raw
FK `QueryException` failures from attempted definition or binding deletion while
mappings exist.

Do **not** reference `connector_schema_snapshot_fields.id` as persistent mapping
identity. Mappings survive immutable snapshot replacement via stable
`external_field_key`.

##### Workspace / global `FieldBinding` eligibility

`FieldBinding` (via `BelongsToWorkspaceOrGlobal` / `WorkspaceOrGlobalScope`)
may be **global** (`workspace_id IS NULL` — system/platform-library bindings)
or **workspace-scoped** (`workspace_id` = current workspace).

A composite FK `(workspace_id, field_binding_id)` cannot express global bindings.
Enforce at write time (fail closed):

```text
binding.workspace_id IS NULL
OR
binding.workspace_id = sync_configuration.workspace_id
```

Foreign-workspace bindings must be rejected. Global bindings are allowed when
otherwise eligible.

Additionally, first-slice write paths must accept only bindings whose
`object_type` is `product` or `product_variant` and reject `customer`.

##### Cardinality — first-slice MVP (1:1 inside one SyncConfiguration)

Within one `SyncConfiguration`:

```text
UNIQUE(sync_configuration_id, field_binding_id)
UNIQUE(sync_configuration_id, external_field_key)
```

Meaning:

- one internal semantic concept → at most one external logical field;
- one external logical field → at most one internal semantic target;
- import and export may share one direction-neutral correspondence — do not
  split mappings merely because both operations are enabled on the same
  configuration.

**Adversarial check (reverified against `origin/develop` baseline
`12b5b9de5cfaeff482c0d6a267cef5f4168ab72e`):** no confirmed repository case
was found where the first `products` + `FieldBinding` slice requires
1 internal → N external or N internal → 1 external **semantic** FieldMapping
cardinality inside one `SyncConfiguration`.

- Canonical registry rows such as
  `custom_attributes[attribute_code=description].value` describe **connector
  transport representation** for adapter/runtime interpretation — not a second
  external logical identity in account discovery (`external_field_key` =
  `description` in normalized snapshots).
- `price`, `availability`, `image`, and `category` Adobe rows point at
  pricing/availability/media/category domain targets — explicitly **outside**
  this first slice.
- The same internal code may appear in multiple **channel** registry rows
  (Google, Shopify, Adobe, …), but each maps to a different connected account /
  `SyncConfiguration` — not a violation of per-configuration 1:1.
- `workspace_import_aliases` is file/header import memory — a separate concern
  (see boundary below).

Fan-out, merge, and split semantics remain deferred until a verified product
requirement demonstrates the need.

##### Suggestions are not effective FieldMappings

Preserve three layers (unchanged):

1. **Platform-global canonical knowledge** — e.g.
   `docs/data/canonical_product_field_mappings.csv` (documentation/knowledge
   artifact; **not** runtime production dependency in 4C-1a/4C-1b).
2. **Account discovery reality** — authoritative successful discovery snapshot
   fields for this `ConnectorAccount`.
3. **Workspace effective FieldMapping** — merchant-**confirmed** semantic
   correspondence rows in `field_mappings`.

High-confidence canonical or discovery suggestions may **prefill** UI but must
**not** auto-persist as effective mappings. No `confidence`, `suggestion_source`,
or `candidate_state` columns in the minimum table. Prefill = presentation /
suggestion state; confirmed `field_mappings` row = effective configuration state.

**Implementation sequencing:**

| Slice | Scope |
|---|---|
| **4C-1a** (this contract) | Docs-only Stop-and-Amend — Done |
| **4C-1b** | `field_mappings` persistence + manual/explicit confirmation mutation service + authoritative-discovery validation + revision v2 integration — Done |
| **4C-1c-0** | Docs-only suggestion/read-model Stop-and-Amend — see [Resolved — Task 4C-1c-0] below |
| **4C-1c-1** | Canonical deterministic suggestion provider + transient registry/discovery/effective-mapping read-model (no DB/migration scope) — Done |
| **4C-1c-2a** | Workspace access / authorization contract — docs-only Stop-and-Amend (this decision) — Done |
| **4C-1c-2b** | Layer B mapping UI after workspace-scoped authorization foundation exists |

Do not build a production CSV loader, second canonical registry, or suggestion
engine in 4C-1a/4C-1b/4C-1c-0.

##### Authoritative discovery validation

On create/replace of a confirmed mapping, `external_field_key` must exist in the
**current authoritative discovery state** for the `ConnectorAccount` that owns
the parent `SyncConfiguration`.

**Current authoritative discovery** (deterministic resolver — no ambiguity found
in repository baseline):

1. Resolve the account's primary discovery source using the same contract as
   `ConnectorDiscoverySourceResolver`: exactly one `ConnectorSchemaSource` with
   `schema_scope = Account`, `source_kind = AccountApi`,
   `acquisition_mode = LiveFetch`, `is_primary = true` (fail closed on zero or
   multiple).
2. Select the **latest successful snapshot** for
   `(connector_account_id, connector_schema_source_id)` as the row with the
   greatest `(created_at, id)` pair (`created_at DESC, id DESC`) — per
   **Deterministic latest-snapshot ordering (Resolved)** above. This is the
   authoritative `ConnectorSchemaSnapshot`.
3. Valid external keys = `ConnectorSchemaSnapshotField.external_field_key` rows
   for that snapshot (`UNIQUE(snapshot_id, external_field_key)` already enforced).

Do **not** use `ConnectorAccount.last_successful_discovery_at` alone as proof
that a specific `external_field_key` exists. Do **not** add
`current_snapshot_id` to `connector_accounts` without a separate demonstrated
need.

**Lifecycle when discovery changes:**

| Event | Behavior |
|---|---|
| Confirm against missing / failed discovery | Reject |
| Confirm against key absent from authoritative snapshot | Reject |
| New immutable snapshot published; previously mapped key still present | Mapping remains valid (reconciled by stable `external_field_key`) |
| Previously mapped `external_field_key` disappears from new authoritative discovery | Row **retained**; readiness becomes unresolved / remediation-required; **no** automatic silent delete or remapping |

Validity against current discovery is **derived** at evaluation time — no
persisted `is_valid` / `stale` column in the minimum schema unless a later task
proves otherwise.

##### `FieldBinding` target lifecycle

Write-time requirements for create/update of confirmed mappings:

| Check | Rule |
|---|---|
| `FieldBinding.status` | Must be `active` |
| `FieldBinding.object_type` | Must be `product` or `product_variant` for this slice |
| Associated `FieldDefinition.status` | Must be `active` |
| Workspace eligibility | Global or same-workspace binding only (see above) |

If a mapped binding (or its definition) is later **archived**:

- existing `field_mappings` row is **retained**;
- readiness becomes unresolved / remediation-required;
- no silent delete.

Physical deletion of a referenced `FieldBinding` remains blocked by
`ON DELETE RESTRICT` while mappings exist. Physical deletion of a parent
`FieldDefinition` is transitively blocked when any cascaded descendant
`FieldBinding` is referenced by an effective FieldMapping (see transitive
definition deletion invariant above). Archive/deprecate remains the preferred
lifecycle path; Task 4C-1b must surface blocked deletes gracefully.

##### `configuration_revision` must include effective FieldMappings

Task 4C-0 revision hash (`babypark.sync-configuration-revision.v1`) covers only
`enabled_operations` and `operational_state`. That is insufficient once
effective mappings exist — `SyncConfiguration` conceptually owns them, and future
`SyncRun` rows must record the revision of the configuration actually executed.

**Invariant:** any semantic add/change/delete of an effective FieldMapping must
atomically advance `SyncConfiguration.configuration_revision`. Reconfirming the
same semantic pair (`field_binding_id` + `external_field_key`) is a **no-op** and
must **not** change revision.

**Revision v2 (canonical payload):** `babypark.sync-configuration-revision.v2`
with minimum payload:

```json
{
  "enabled_operations": ["..."],
  "operational_state": "enabled",
  "field_mappings": [
    {"field_binding_id": "...", "external_field_key": "..."}
  ]
}
```

`field_mappings` entries are canonicalized and sorted (e.g. by
`field_binding_id`, then `external_field_key`) independently of DB insertion
order.

Because `SyncRun` persistence does not yet exist on `develop`, Task 4C-1b may
safely rebaseline/recalculate existing `SyncConfiguration.configuration_revision`
values to v2 when mappings land — no historical SyncRun comparison constraint.

##### Concurrency / mutation boundary (4C-1b implementation protocol)

All semantic mutations that affect configuration revision — including
`SyncConfigurationService` operation/state updates **and** FieldMapping
add/change/delete — must serialize on the same `SyncConfiguration` row inside
one DB transaction:

```text
BEGIN
SELECT sync_configurations ... FOR UPDATE  (workspace-scoped)
validate target + discovery + slice rules
mutate field_mappings (if applicable)
mutate enabled_operations / operational_state (if applicable)
recalculate full revision v2 from persisted effective state
save configuration_revision
COMMIT
```

This prevents: mapping changed but revision unchanged; or operation update
racing mapping mutation and overwriting revision. Document only in 4C-1a;
implement in 4C-1b.

##### `workspace_import_aliases` boundary

`workspace_import_aliases` maps **file/header import** column names to
`field_binding_id` per workspace. It is tenant-isolated import memory — not
connector `external_field_key` mapping.

Aliases may inform future **suggestions** but must not automatically become
effective connector FieldMappings without explicit confirmation through the
4C-1b mutation path. Do not merge or delete the existing alias architecture.

##### Transformation boundary (unchanged)

Generic transformation DSL is **not** part of minimum FieldMapping persistence.
Canonical registry `transformation` values describe connector-adapter/runtime
interpretation — not mandatory generic persisted transformation on the
correspondence row.

#### FieldOptionMapping persistence contract
[Resolved — Stage 1 Preview Engine, 2026-08-17]

Narrow Stage-1 addition for Adobe configurable products. This is **not** a global
canonical option registry and **not** transport state.

##### Relationship

```text
FieldMapping
  └── 0..N FieldOptionMappings
```

| Question | Owner |
|---|---|
| Which internal semantic field ↔ which external field? | `FieldMapping` |
| Which internal stable option ↔ which external connector option value? | `FieldOptionMapping` |

##### Minimum physical schema — `field_option_mappings`

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `workspace_id` | UUID NOT NULL | Workspace-owned row |
| `field_mapping_id` | UUID NOT NULL | Child of exactly one `FieldMapping` |
| `internal_option_key` | string NOT NULL | Stable internal option code — never translated display label |
| `external_option_value` | string NOT NULL | Opaque connector option value/identity |
| `created_at` / `updated_at` | timestamps | |

Minimum uniqueness:

```text
UNIQUE(field_mapping_id, internal_option_key)
```

Do **not** create generic uniqueness on `external_option_value`.

Workspace-safe FK integrity required. If composite FK requires it,
`field_mappings` must expose `UNIQUE(workspace_id, id)`.

Deleting a `FieldMapping` **cascades** its `FieldOptionMappings` — these rows
have no meaning independently of the parent mapping.

##### Confirmation semantics

Persisted `FieldOptionMapping` = explicit authoritative correspondence.

Label equality may later generate a **suggestion**. Label equality must **never**
become persisted authority automatically.

No Stage-1 merchant `FieldOptionMapping` UI.

##### Revision participation

Effective `FieldOptionMappings` participate in `configuration_revision` (v4)
and immutable `configuration_snapshot` for admitted Preview runs.

##### Mutation boundary

All `FieldOptionMapping` mutations route through the locked
`SyncConfiguration` mutation coordinator — same boundary as `FieldMapping`.
Do **not** call `save()` directly from UI/controller code.

Initial mutation operations:

- confirm/upsert exact correspondence;
- replace external correspondence;
- remove correspondence.

#### Canonical FieldMapping suggestion/read-model contract
[Resolved — Task 4C-1c-0, 2026-08-12]

This section freezes the **smallest deterministic contract** for Task 4C-1c
before application implementation: canonical suggestion qualification,
confidence semantics, registry→discovery projection boundary, transient mapping
read-model, and implementation sequencing. It does **not** authorize migrations,
models, services, UI, or CSV changes — documentation only.

##### A. Three-layer boundary (unchanged)

Keep distinct:

```text
canonical platform knowledge
        ↓ suggestion only
account authoritative discovery
        ↓ validation
merchant-confirmed FieldMapping
```

No suggestion becomes effective configuration without explicit confirmation
through the existing 4C-1b mutation service (`FieldMappingMutationService`).

##### B. First 4C-1c suggestion source

The first implementation slice is **canonical deterministic suggestions only**.

Explicitly **deferred** (may be separately scoped later):

- fuzzy-name matching;
- AI/LLM suggestions;
- Levenshtein/scored similarity;
- `workspace_import_aliases` as connector suggestion evidence;
- automatic learning from prior merchant confirmations;
- additional discovery-only guessing.

##### C. Registry channel matching

For the first canonical provider:

- an **exact equality** between `ConnectorDefinition.code` and registry
  `channel` permits lookup of registry knowledge for that account;
- this is an **optional exact match**, not an assertion that the two namespaces
  are identical sets;
- no matching registry channel → **no canonical suggestion**, not an error;
- do **not** add `registry_channel` to `ConnectorAccount`, `ConnectorDefinition`,
  or `ConnectorProfileDefinition` in this slice;
- do **not** hardcode `adobe_commerce` inside the generic provider.

**Normative rule:** registry `channel` namespace ≠ `ConnectorDefinition.code`
namespace. Equality is evaluated per connected account only when codes happen to
match; neither namespace is defined by the other.

**Non-normative current-baseline evidence only** (may change as connectors are
added; do not treat as permanent `[Resolved]` invariants):

- registry channels observed in `canonical_product_field_mappings.csv` on current
  `develop` include `adobe_commerce`, `google_merchant`, `rozetka`, `schema_org`,
  `shopify`;
- `ConnectorDefinition.code` values on current `develop` also include codes
  without matching registry channel rows (e.g. `1c`, `csv`, `google_sheets`,
  `bigcommerce`);
- registry channels without a matching `ConnectorDefinition.code` on current
  `develop` include `schema_org` and `rozetka`.

##### D. Runtime/schema version

Do not guess or persist a connected store's runtime version merely to select
suggestions.

Do **not** hardcode `2.4.9-admin-rest` as “the account's runtime version.”

For the first provider, version/applicability rows are **knowledge evidence
only**.

If multiple eligible verified canonical rows could imply different suggestions
for the same internal target, the result is **ambiguous** → **no**
high-confidence prefill.

No arbitrary “latest”, lexical max, first-row, or config-order selection.

Exact runtime contract/version identification remains deferred before a second
real runtime variant (see **Connector profile / runtime-contract variance**
below).

##### E. Logical external-key projection — conservative first slice

The first generic provider **must not** parse connector transport syntax.

A canonical registry row may yield an external logical-key candidate only when
its `external_field` **exactly equals** an `external_field_key` present in the
single authoritative account snapshot.

Therefore:

| Registry `external_field` | Snapshot `external_field_key` | First-slice result |
|---|---|---|
| `sku` | `sku` | eligible evidence |
| `custom_attributes[attribute_code=description].value` | `description` | **not** an automatic high-confidence suggestion |

Reverified Adobe example in `canonical_product_field_mappings.csv`: `description`
→ `custom_attributes[attribute_code=description].value` describes connector
**transport representation**; normalized account discovery uses logical
`external_field_key = description`.

Do **not** strip wrappers, parse Magento custom-attribute paths, interpret Shopify
nested paths, or introduce connector-specific parsing inside generic Sync code.

This conservative false-negative is intentional: manual confirmation is
preferable to a false-positive mapping.

A later connector-owned projection mechanism may be scoped if real product value
justifies it.

##### F. Confidence semantics

For the first slice, confidence is a **qualification gate**, not a numeric score.

Do **not** introduce:

- percentage confidence;
- arbitrary threshold;
- high / medium / low persisted states;
- DB columns;
- fuzzy score.

Semantics:

- candidate satisfies **every** deterministic high-confidence condition → provider
  may return it as a prefill suggestion;
- anything else → **no** prefill suggestion.

A richer confidence taxonomy requires its own demonstrated need.

##### G. High-confidence qualification

A candidate qualifies only when **all** per-candidate conditions (below), the
canonical internal-target resolution chain (§G.1), and the projection-level
suggestion-set 1:1 invariant (§G.2) are satisfied.

**Per-candidate conditions:**

1. parent `SyncConfiguration.data_domain` = `products`;
2. internal target is a `FieldBinding` produced by the §G.1 resolution chain;
3. binding is global or same-workspace;
4. binding is `active`;
5. parent `FieldDefinition` is `active`;
6. binding `object_type` is `product` or `product_variant`;
7. registry `channel` exactly matches this connector definition `code`;
8. canonical **mapping** evidence has `verification_status = verified`;
9. candidate `external_field` exactly exists as `external_field_key` in the
   authoritative snapshot resolved for this projection (§H);
10. for this internal `field_binding_id`, there is **exactly one** resulting
    semantic candidate after §G.1–§G.2;
11. candidate does **not** violate existing per-configuration 1:1 mappings:
    - internal target already mapped → effective mapping wins;
    - external key already consumed by another effective mapping → do not suggest
      it.

Fail closed to “no suggestion” on ambiguity. Do **not** invent fallback to
labels, aliases, or fuzzy matching.

###### G.1 Canonical internal target resolution (first slice)

For a canonical mapping row with `internal_code = X`, high-confidence
`FieldBinding` qualification requires this deterministic chain:

1. **Load canonical field row** — read the corresponding
   `canonical_product_fields.csv` row for `X`.
2. **`field_definition_eligibility = yes`** — rows with `no` (pricing-domain,
   availability-domain, media-domain, relation, connector-only, computed
   projection, etc.) **cannot** be projected to a `FieldBinding` merely because
   an identically named `FieldDefinition` happens to exist in the workspace.
3. **Canonical field active + verified** — canonical field `status = active` and
   `verification_status = verified`.
4. **Canonical scope** — canonical field `scope` must describe a
   `FieldDefinition`-backed **global** canonical field (`system` or
   `platform_library`).
5. **Resolve `FieldDefinition`** — exactly one row where:
   - `workspace_id IS NULL`;
   - `code = X` (canonical `internal_code`);
   - actual definition `scope` equals canonical registry `scope`;
   - definition `status = active`.
6. **Workspace-custom same-code exclusion** — workspace-scoped definitions that
   merely reuse the same `code` are **not** canonical suggestion targets in this
   first slice.
7. **Resolve `FieldBinding`** — active binding on that definition whose
   `object_type` matches canonical `binding_strategy`:
   - `product` → `product` binding;
   - `product_variant` → `product_variant` binding;
   - `product_and_variant_two_bindings` → each matching `product` / `product_variant`
     binding may be evaluated separately as its own internal target.
8. **Uniqueness** — fail closed if the canonical-field → definition → binding
   chain is not unique for the internal target under evaluation.

Do **not** resolve targets by label, alias, fuzzy name match, or workspace import
memory.

###### G.2 Suggestion-set 1:1 invariant (projection level)

After generating deterministic candidates for the **whole**
`SyncConfiguration` projection:

```text
one field_binding_id     → at most one suggested external_field_key
one external_field_key   → at most one suggested field_binding_id
```

**Reservation order:**

1. existing effective `FieldMapping` rows reserve their `field_binding_id` and
   `external_field_key` first and **always win**;
2. only **unconsumed** bindings and external keys may receive suggestions.

**Collision rule:** if the same unconsumed `external_field_key` would be
suggested for two or more different internal bindings, or the same unconsumed
`field_binding_id` would receive two or more different external keys:

```text
collision among suggestions → no high-confidence suggestion for any colliding candidate
```

Do **not** choose first row, lexical sort winner, global-over-workspace winner,
or any other arbitrary priority.

This projection-level check is required even when each internal binding
individually has exactly one candidate.

##### H. One authoritative discovery view per projection

Distinguish **mutation validation** (4C-1b, unchanged) from **read-model
construction** (4C-1c-1).

**4C-1b (mutation) — unchanged:**

- confirm/replace with no authoritative discovery → **reject**.

**4C-1c-1 (read-model projection) — renderable without discovery:**

The projection must remain buildable when:

- primary discovery source is missing or ambiguous; or
- no successful authoritative snapshot exists.

**One resolution attempt per projection:**

1. make **at most one** authoritative discovery resolution attempt per
   projection;
2. if a snapshot is resolved, use that **same** immutable snapshot for every
   candidate validation within the projection;
3. if not resolved, build the read-model in the discovery-unavailable state;
4. do **not** call `resolveRequiredSnapshot()` per row or per field.

This extends the already-corrected 4C-1b temporal-consistency principle.

**Discovery-unavailable first-slice behavior:**

| State | Behavior |
|---|---|
| No usable authoritative snapshot | no canonical suggestions; no discovered external choices |
| Existing effective `FieldMapping` rows | retain and project unchanged |
| Current discovery validity | cannot be proven → derived needs-attention / discovery-unavailable presentation state |
| Persistence | **no** persistence changes |

Do **not** delete or replace an existing `FieldMapping` merely because discovery
is unavailable. Do **not** expose raw resolver exceptions, schema-source
terminology, or other Layer C/D vocabulary to merchant UI. Do **not** introduce a
persisted availability/status column or new DB enum in this slice.

##### I. Suggestions are side-effect free

Building suggestions/read-model **must not**:

- insert/update/delete `field_mappings`;
- update `configuration_revision`;
- mutate `SyncConfiguration`;
- update discovery state;
- write suggestion/confidence state anywhere.

Only explicit confirmation calls the existing `FieldMappingMutationService`.

##### J. Existing mappings beat suggestions

For every internal row:

- effective confirmed mapping exists → show effective mapping; **never**
  replace/prefill over it;
- if its binding/definition becomes archived or its `external_field_key`
  disappears from current discovery:
  - retain effective `FieldMapping`;
  - derived remediation-required/readiness problem;
  - **no** automatic replacement/remap.

##### K. Read-model boundary

4C-1c read-model is **transient/presentation-oriented**.

It may combine:

- eligible internal `FieldBinding` / `FieldDefinition`;
- existing effective `FieldMapping`;
- high-confidence suggestion, if any;
- authoritative discovered field presentation metadata (when discovery resolved);
- derived mapped / suggested / unresolved / needs-attention / discovery-unavailable
  presentation state.

When discovery is unavailable (§H), the read-model still renders:

- existing effective mappings remain visible;
- no canonical suggestions;
- no discovered external field choices;
- derived discovery-unavailable / needs-attention state only.

It must **not** become a new persistence entity.

Do **not** expose raw canonical-registry rows, transport paths, snapshot IDs,
schema-source terminology, or other Layer C/D data to merchant UI.

##### L. Existing Field Browser

Retain/reuse existing read architecture:

- snapshot persistence remains reusable;
- workspace/account/snapshot ownership-chain validation remains reusable;
- existing field query/read-model/presenter architecture may be reused
  (`ViewConnectorSchemaSnapshot`, `ConnectorSchemaFieldPresenter`).

This does **not** freeze current merchant authorization/navigation gating.
Authorization/navigation migration follows **GAP-025** / **GAP-026** and
**Workspace access model and authorization (Resolved — Task 4C-1c-2a)**. There
is no requirement to redesign the Field Browser data/read architecture merely to
implement that gating.

When the actual mapping UI ships, Field Browser becomes a supporting action such
as:

> Переглянути всі доступні поля Magento

after merchant copy is made Layer-B compliant.

##### M. 4C-1c implementation slicing

| Slice | Scope |
|---|---|
| **4C-1c-0** | Docs-only suggestion/read-model Stop-and-Amend — this contract |
| **4C-1c-1** | Canonical deterministic suggestion provider + transient registry/discovery/effective-mapping read-model (**no** DB/migration scope) — Done |
| **4C-1c-2a** | Workspace access / authorization contract — docs-only Stop-and-Amend — Done |
| **4C-1c-2b** | Layer B mapping UI: high-confidence prefill + manual choice + explicit confirmation through 4C-1b service — after workspace-scoped authorization foundation |

Do **not** create `SyncRun`, Preview, scheduling, selection persistence,
`ExternalRecordLink`, or full synchronization setup in these slices.

##### UI placement (4C-1c-2b)

Mapping is **Layer B** (`CONNECTOR_INTEGRATION_UX_CONTRACT.md`, Layer B —
Налаштування даних).

- do **not** embed mapping controls into the current **Інтеграції** /
  Connector Account Overview merely because that page exists;
- do **not** establish a new top-level navigation IA in this task;
- mapping belongs to a specific **`SyncConfiguration`** — no arbitrary "first
  configuration" selection;
- 4C-1c-2b must use the approved **concept-first matrix**:
  merchant-facing row is **internal concept first**, **external system field
  second**, **simple state third**;
- raw snapshot, discovery, schema source, canonical registry internals and
  transport paths are **forbidden** in merchant UI;
- high-confidence suggestion may be visually prefilled, but merchant confirmation
  is still **explicit**;
- no-discovery state remains renderable and read-only as already resolved in the
  suggestion/read-model contract.

Mapping mutation authorization is frozen by **Workspace access model and
authorization (Resolved — Task 4C-1c-2a)** — `view_sync_mappings` /
`manage_sync_mappings`, independent from `manage_connector_accounts`, with no
normative entitlement for Merchandiser or any other job-title role. Layer B UI
(4C-1c-2b) ships only after workspace-scoped authorization foundation implements
that contract; do not widen fixed `User.role` checks as a workaround.

##### Registry access path

`CanonicalRegistryReader` is the existing read-only CSV access path. Do **not**
create a second registry/loader in 4C-1c.

#### Preview-first Sync Execution Foundation Contract
[Resolved — Task 4C-2a]

This section freezes the **minimum architecture** required before the first
Preview foundation implementation (`SyncRun`, `SyncRunItem`, Preview
authorization, revision v3, and the first Adobe Products/Export planner
foundation). It is **architecture/documentation only**. It **authorizes** a later
Preview foundation implementation slice. It does **not** authorize Live
external mutation. First Live requires a separate Stop-and-Amend for external
identity, retry/idempotency, and ambiguous applied-state semantics.

##### First implementation target (frozen)

| Dimension | First slice (4C-2b foundation) | Explicitly deferred |
|---|---|---|
| `data_domain` | `products` only | prices, inventory, categories, media, and other domains |
| `semantic_operation` | `export` only | import (creates its own separate `SyncRun` when later implemented) |
| Connector / profile target | Adobe PaaS | other connectors/profiles |
| Execution mode | `preview` only | `live` (separate Stop-and-Amend) |

This does **not** close the broader Product Owner question about eventual MVP
domain breadth (PO-1 remains open).

##### One SyncRun = one semantic operation

Normative cardinality:

```text
one SyncRun
  = one SyncConfiguration
  + one semantic_operation
  + one execution mode
  + one configuration revision
```

A `SyncConfiguration` may enable both import and export. One run **never**
executes both.

**First-slice explicit admission rule:** there is **no** automatic fan-out such
as “Preview configuration → automatically create Import run + Export run”. The
requested semantic operation is explicit at admission. The first implementation
accepts only `products` + `export` + `preview`. If Import is later implemented,
it creates its own separate `SyncRun`.

##### Preview / Live boundary

Domain modes:

- `preview`
- `live`

Only **Preview** is executable in the first implementation. The existence of a
`live` enum/domain value must **not** make Live reachable.

**Preview invariant:** zero consequential external mutation. No exceptions.

##### First selection contract

First Products Preview uses fixed effective selection:

```text
selection.mode = all_products
```

Meaning: all `Product` records belonging to the `SyncConfiguration` workspace.

This is:

- deliberate;
- Preview-only;
- not merchant-configurable;
- not a permanent platform rule.

Do **not** silently narrow selection by `is_active`, mapping completeness,
channel eligibility, or warning/blocker state. Those affect evaluation/outcome,
not membership.

Because there is only one deterministic selection, do **not** persist a mutable
selection column yet. Before configurable selection ships: persist canonical
selection, include it in configuration revision, and prove selection changes
invalidate readiness.

**Temporal boundary (queued Preview):** freeze the selection **predicate**
`all_products` at admission as part of `configuration_snapshot`. Product
membership and Product field data are **not** admission-time snapshotted.

For the first Preview slice:

- the effective Product execution set is resolved when the run **begins
  execution**, under the fixed `all_products` predicate and workspace boundary;
- a Product created after admission but before execution begins **may** belong
  to the run;
- `queued` status does **not** promise an admission-time catalogue snapshot;
- once execution begins resolving/evaluating its Product set, the run must
  **not** silently expand because new Products are created later.

Task **4C-2b** must choose a mechanism that yields one coherent execution set
without holding a long-lived DB transaction for the whole planner run. Do **not**
prescribe exact SQL/cursor/chunk/materialization implementation in this
docs-only contract. Do **not** claim immutable Product data replay.

##### Revision v3

Current v2 hashes `enabled_operations`, `operational_state`, and
`field_mappings` but no selection.

Freeze:

```text
babypark.sync-configuration-revision.v3
```

Canonical conceptual payload (first-slice example — export-only configuration):

```json
{
  "enabled_operations": ["export"],
  "operational_state": "enabled",
  "selection": {
    "mode": "all_products"
  },
  "field_mappings": [
    {
      "field_binding_id": "...",
      "external_field_key": "..."
    }
  ]
}
```

**Full configuration revision (not run-filtered):** `SyncConfiguration.configuration_revision`
is a fingerprint of the **complete** configuration-owned revision state for the
`SyncConfiguration`, not of one `SyncRun`. Therefore revision-v3 `enabled_operations`
must contain the complete canonical enabled-operation set:

- deduplicated;
- canonically sorted per `SyncOperationSet` / revision-hasher contract;
- independent of the `semantic_operation` selected for any particular `SyncRun`.

The JSON example above with `"enabled_operations": ["export"]` is a valid
**first-slice example only**. A future configuration with both import and export
enabled would hash conceptually as:

```json
"enabled_operations": ["export", "import"]
```

using the canonical lexical ordering defined by `SyncOperationSet` / the revision
hasher.

Do **not** let a run with `semantic_operation = export` produce a configuration
revision that silently omits another enabled operation.

Preserve one `SyncRun` = one explicit semantic operation. These are different
dimensions:

- `configuration.enabled_operations` — full enabled set on the configuration;
- `run.semantic_operation` — the single operation this run executes.

Selection is included because revision represents effective execution
configuration, not merely mutable DB columns.

`configuration_revision` tracks the full configuration-owned revision state:

- enabled operations;
- operational state;
- selection contract;
- field mappings.

It does **not** prove Product catalogue membership or field data are unchanged.
Product data freshness is a distinct concern. Do **not** invent a persisted
product-data readiness flag in 4C-2a. Matching `configuration_revision` between
a Preview run and the current configuration means the **configuration-owned
semantic input** matches — not that the Product catalogue is identical.

Before merchant Preview is later used as a prerequisite for consequential Live,
the later exposure/Live contract must define how Product changes after Preview
affect readiness and re-preview requirements. That is **not** part of 4C-2a
runtime implementation.

Task **4C-2b** must recompute existing `SyncConfiguration.configuration_revision`
values under v3 before the first `SyncRun` comparison. This is safe because no
`SyncRun` history currently exists. This docs-only contract does **not**
modify hashes in code.

Reverified repository truth: `configuration_revision` is currently written on
mutation but not compared against run history.

##### Preview execution permission

Freeze new atomic permission:

| Permission | Authority |
|---|---|
| `run_sync_preview` | Execute a non-consequential Preview for an eligible `SyncConfiguration` and access the safe progress/result surface required for that execution. |

Properties:

- independent from Connector, Mapping, Access, and Tax permissions;
- no existing permission implies it;
- it implies none of them;
- **no automatic legacy grant** to Owner, Admin, Director, Merchandiser,
  Integration manager, or any legacy role/profile merely because of job title;
- existing roles gain it only through deliberate access configuration.

**Normative eighth permission:** this docs-only contract introduced a normative
**eighth** atomic workspace permission (`run_sync_preview`; historical runtime
implementation target: Task 4C-2b / **Stage 1 — Preview Engine**).

**Repository status (post–Stage 1):** `run_sync_preview` is implemented in the
runtime catalogue (`WorkspaceRbacPermissionSeeder`; eighth seeded permission at
Stage 1 landing). Historical GAP-026B cutover documentation correctly continues to
describe the **seven-permission** production cutover state at the time of
EXECUTE.

**Normative ninth permission (Stage 2-0 / Stage 2A-1):** `manage_sync_configurations`
is frozen in **Merchant Preview Authorization & Remediation Contract (Resolved —
Stage 2-0)** and **implemented in Stage 2A-1** runtime catalogue. **Stage 2A-2**
implemented the merchant Preview work surface (landing reachability, advisory read
model, explicit start, lifecycle, completed summary, Needs-attention worklist,
contextual remediation presentation). **Stage 2A is Done.** **Stage 2B is Done**
(Option Mapping remediation UI on `ManageSyncFieldOptionMappings`). Stage 3
remains pending.

**Live authority:** do **not** add or freeze an implemented Live permission in
4C-2a beyond the invariant that Preview authority must never silently become
consequential Live authority. The exact Live permission is frozen in the Live
Stop-and-Amend.

##### Adobe operation-support truth

Current repository truth (reverified):

- `AdobePaaSConnectorAdapter` does **not** implement
  `ConnectorSyncOperationSupport`;
- `ConnectorSyncSupportResolver` therefore remains **fail-closed** for Adobe
  `(products, export)` support advertisement.

Freeze:

- the presence of an internal Adobe Products/Export Preview planner is **not**,
  by itself, sufficient to advertise the semantic operation as supported;
- do **not** flip `ConnectorSyncOperationSupport(products, export)` merely
  because planner code later exists;
- before any real merchant Preview can become reachable, the application must
  have a truthful support boundary for that runtime stage;
- if implementation discovers that current `ConnectorSyncOperationSupport` cannot
  truthfully represent “Preview supported, Live not yet supported”, that requires
  a narrow Stop-and-Amend instead of stretching its meaning.

This is intentionally a gate before merchant exposure, not something to bypass.

##### Pure connector-owned Preview planner

Preserve: `FieldMapping` ≠ execution plan.

First Preview boundary:

```text
generic orchestration
  → normalized semantic run input
  → Adobe/profile-owned pure Preview planner
  → normalized Preview findings/outcomes
```

Connector/profile owns:

- Adobe-specific export payload construction;
- transformation;
- required external shape;
- operation-specific validation.

Core owns:

- authorization;
- admission;
- selection;
- immutable configuration input;
- Product iteration;
- run/item lifecycle;
- normalized outcomes;
- persistence.

Preview planner must:

- perform **no** mutating HTTP call;
- create **no** `ExternalRecordLink`;
- write **no** external record;
- mutate **no** `Product`;
- mutate **no** `FieldMapping`.

Do **not** implement one shared `execute(..., dryRun=true)` where safety
depends only on a flag. Do **not** define a universal transport DSL.

##### Run admission and consistency

Do **not** hold a database lock during queued/background execution.

Freeze a short admission transaction:

```text
BEGIN
  lock SyncConfiguration
  freshly authorize run_sync_preview
  verify workspace/configuration/account ownership
  verify enabled state
  verify requested operation enabled
  verify required Preview planner is present
  capture configuration_revision R
  materialize immutable configuration_snapshot for R
  verify no active run for this SyncConfiguration
  create queued SyncRun
COMMIT
```

Execution then uses the immutable `configuration_snapshot`. It must **not**
reread current mutable `FieldMapping` rows as execution truth. Subsequent
configuration changes are allowed and naturally move current revision away from
run revision `R`.

At admission, `configuration_snapshot` freezes the run-effective
configuration-owned semantic input for this run under revision `R` (including
the selection **predicate** `all_products`). It does **not** freeze Product
membership or Product field values. The effective Product execution set is
resolved when execution **begins**, per the temporal boundary above.

This contract guarantees configuration consistency, not full immutable
Product-data replay or bit-for-bit run replay.

##### Immutable configuration snapshot

Freeze non-secret `SyncRun.configuration_snapshot`.

**Revision vs snapshot (frozen):**

- `configuration_revision` — fingerprint of the full configuration-owned
  revision state that admitted this run;
- `configuration_snapshot` — immutable **run-effective** configuration-owned
  semantic input consumed by this specific run under that revision.

`configuration_snapshot` is **not** a complete serialized `SyncConfiguration`
and is **not** required to be sufficient to recompute `configuration_revision`.
The run records both fields together: which configuration state admitted it,
and which immutable semantic inputs it executes with.

Conceptual payload (first-slice example):

```json
{
  "version": "babypark.sync-run-input.v1",
  "data_domain": "products",
  "semantic_operation": "export",
  "external_context": {},
  "selection": {
    "mode": "all_products"
  },
  "field_mappings": [
    {
      "field_binding_id": "...",
      "external_field_key": "..."
    }
  ]
}
```

Must contain **no**:

- credentials;
- access tokens;
- secret connector settings;
- raw HTTP diagnostics;
- raw vendor failures;
- Product payload snapshot;
- Product catalogue membership list;
- Product field-value snapshots;
- the full `enabled_operations` set;
- `operational_state`.

First-slice `configuration_snapshot` contains run-effective planner inputs:

- `data_domain`;
- requested `semantic_operation`;
- `external_context`;
- selection predicate;
- `field_mappings`.

It is **not** required to contain the full `enabled_operations` set or
`operational_state` — those are admission/configuration-state facts, not planner
execution inputs for the already-admitted run. Do **not** add them merely for
symmetry.

`configuration_snapshot` makes the run-effective configuration-owned semantic
input auditable/reproducible for this run. It does **not** reconstruct the
complete revision payload and does **not** enable bit-for-bit replay of the
Product catalogue state evaluated by the run.

It is semantic configuration evidence, not the connector transport plan.

##### SyncRun first physical contract

Minimum later schema:

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `workspace_id` | required |
| `sync_configuration_id` | required |
| `configuration_revision` | required |
| `mode` | `preview` / `live` domain; Preview executable first |
| `semantic_operation` | explicit one-operation-per-run |
| `status` | `queued` / `running` / `completed` / `failed` |
| `initiated_by_user_id` | nullable |
| `configuration_snapshot` | canonical safe JSON |
| `started_at` | nullable |
| `completed_at` | nullable |
| `created_at` / `updated_at` | project convention |

Lifecycle:

```text
queued → running → completed
                 ↘ failed
```

Do **not** add `cancelled` until cancellation exists.

A Preview with blocked/warning items may still have `run.status = completed`
because business findings are not infrastructure failure.

Require workspace-aware FK from `SyncRun` to `SyncConfiguration` and historical
retention semantics. Do **not** cascade-delete historical runs with
configuration deletion.

Do **not** add in the first slice: summary counters, retry count, transport
attempts, schedule id, `ExternalRecordLink`, diagnostic reference, or readiness
flag.

##### SyncRunItem identity (Products first slice)

First domain is Products. Do **not** introduce `internal_record_type` /
`internal_record_id`. Use typed `product_id`.

| Column | Contract |
|---|---|
| `id` | UUID PK |
| `workspace_id` | required |
| `sync_run_id` | required |
| `product_id` | typed `Product` FK |
| `outcome` | Preview outcome |
| `findings` | canonical safe historical findings JSON |
| `created_at` / `updated_at` | project convention |

Each `SyncRunItem` is immutable historical/audit evidence of what that execution
evaluated and concluded for one `Product`. It records outcome/findings against
live Product state at evaluation time; it is **not** a persisted Product input
snapshot and does not by itself enable catalogue replay.

Prefer workspace-aware product integrity.

**Product deletion edge (frozen):**

```text
SyncRunItem → Product
ON DELETE RESTRICT
```

after verifying physical FK feasibility.

This deliberately differs from the existing compositional
`Product → ProductVariant` `CASCADE` relationship: `ProductVariant` has no
independent meaning after its `Product` disappears, while `SyncRunItem` is
immutable historical evidence about a `Product`.

Current repository truth: `Product` has no `SoftDeletes` and no normal
`ProductResource` `DeleteAction`; merchant/admin Product hard-delete is not
presently a reachable product flow. Therefore `RESTRICT` is forward-looking
historical protection, not something already validated against an existing
deletion workflow. A future Product-deletion feature must explicitly resolve
historical-run retention rather than silently inheriting cascade deletion.

##### Preview outcomes — exactly three

Freeze only:

| Outcome | Merchant concept |
|---|---|
| `ready` | готові |
| `warning` | потребує уваги |
| `blocked` | неможливо |

Do **not** add `excluded` in 4C-2a. With fixed `all_products` selection and no
configurable filtering/exclusion mechanism, there is no truthful first-slice
producer for an excluded outcome. A Product outside selection would have no
`SyncRunItem`; under fixed `all_products`, that case does not occur for a
`Product` belonging to the selected workspace.

Do **not** freeze Live outcomes in 4C-2a.

##### Findings are historical evidence, not SyncIssue

`SyncRunItem.findings` may contain zero or more normalized findings. Minimum
semantic structure:

- stable normalized code;
- semantic subject where relevant;
- merchant-safe message key;
- whitelisted safe context.

No raw exception text, HTTP response, credentials, or vendor diagnostics.

Do **not** create `SyncIssue`. Do **not** claim historical Preview findings
represent current unresolved issues.

##### Preview history visibility

Persist Preview runs and their `SyncRunItem` rows as immutable
historical/audit evidence of what that execution evaluated and concluded. Do
**not** expose them automatically as completed synchronization history. No
Preview-history merchant page in the first runtime slice. PO-4 remains open.

**Audit vs replay (frozen):** `configuration_snapshot` makes the run-effective
configuration-owned semantic input auditable/reproducible for this run. It does
**not** reconstruct the complete revision payload. The first slice does **not**
guarantee bit-for-bit replay or reproduction of the Product catalogue state
because Product input snapshots are not persisted. Do **not** add Product-data
persistence merely to justify replay wording.

##### Concurrency

First invariant: at most one **active** `SyncRun` per `SyncConfiguration`.

Active means `queued` or `running`.

Serialize admission using the stable `SyncConfiguration` row inside a short DB
transaction. Do **not** keep the lock during job execution and do **not** require
a long-lived DB transaction for the whole planner/execution pass. Task **4C-2b**
must still yield one coherent Product execution set without admission-time
Product snapshots. Do **not** invent a distributed lock unless implementation
proves DB admission insufficient.

**Historical 4C-2a state:** Preview-vs-Live coexistence was intentionally deferred
at that stage. **Current truth (Stage 3-0):** one active queued/running `SyncRun`
per `SyncConfiguration` is mode-agnostic (Preview+Preview, Preview+Live,
Live+Preview, Live+Live all reject a second).

##### Retry / idempotency / ExternalRecordLink

Preview has no consequential mutation.

**Historical 4C-2a state:** 4C-2a did **not** freeze Live retry semantics.
`ExternalRecordLink`, operation-specific idempotency, retry rules, and
ambiguous/unknown applied-state semantics required a later Stop-and-Amend. That
Stop-and-Amend is now fulfilled by **Stage 3-0** (runtime implementation remains
Stage 3A–3E).

Transport attempts are never `SyncRunItem` rows. `ExternalRecordLink` is not
required for Preview.

##### Implementation sequencing

| Slice | Scope |
|---|---|
| **4C-2a** | This docs-only contract — Done |
| **4C-2b** (immediate next code foundation) | May implement: revision v3; `run_sync_preview` runtime permission; `SyncRun` / `SyncRunItem` persistence; `configuration_snapshot`; run admission/concurrency foundation; pure Preview planner contract; Adobe Products/Export planner implementation + isolated regression harness. Must **not** ship: merchant Preview UI; consequential external mutation; automatic flip of `ConnectorSyncOperationSupport`. |
| **Before first real merchant Preview** | Explicitly reconcile the operation-support boundary so the platform can truthfully represent the runtime actually available. Do not bypass `ConnectorSyncOperationSupport`. If current support vocabulary cannot represent Preview-only support safely, require a narrow Stop-and-Amend before exposure. |
| **Before Live** *(historical 4C-2a sequencing — prerequisite now fulfilled by Stage 3-0)* | Separate contract for `ExternalRecordLink`, Live permission, Adobe Live executor, idempotency/retry, ambiguous applied-state behavior. Scheduling/history/current issues later. **Stage 3-0 resolves this prerequisite; runtime implementation remains Stage 3A–3E.** |

Fixed `all_products` is a first-slice safe Preview constraint, not a sixth
Product Owner question. PO-1 and PO-4 remain open. PO-2, PO-3, and PO-5 remain
untouched.

Historical implementation-slice IDs such as `4C-2b`, `4C-2b-2`, and `4C-2b-3`
remain tracking labels. They are not mandatory future PR boundaries. Current
coherent Magento execution stages are frozen in **Magento Product Export V1
Execution Contract** below: Stage 1 Preview Engine → Stage 2 Merchant Preview →
Stage 3 Live Engine.

### Magento Product Export V1 Execution Contract
[Resolved — Platform Product Scope Rebaseline]

This section freezes Magento Product Export V1 remaining architecture so further
implementation proceeds in three coherent stages. It does **not** duplicate
Task 4C-2a.

**Explicitly inherited unchanged from Preview-first Sync Execution Foundation
Contract (Resolved — Task 4C-2a):**

- Preview zero consequential mutation;
- one SyncRun = one semantic operation;
- SyncRunItem = Product;
- `all_products` first selection;
- `configuration_revision` / snapshot boundary;
- one active run per SyncConfiguration;
- short admission transaction;
- no ExternalRecordLink during Preview;
- normalized ready/warning/blocked Preview outcomes.

Magento does not define the generic Product model. Magento V1 must support the
platform's normal simple / non-variant Product and configurable / multi-variant
Product cases.

#### E1. Generic Product execution input

Freeze the semantic boundary, not a PHP class name.

Conceptually:

```text
Product execution aggregate
  Product semantic values
  0..N ProductVariants
  variant option dimensions/values
  domain-owned resolved values required by operation
  run/configuration context
```

It is read-only execution input. It is not a second Product persistence model,
not a serialized Magento payload, and not a Product snapshot database.

The generic Product execution aggregate is vendor-neutral. Connector planner
owns vendor representation.

Scope discipline for Magento V1: the first runtime Product execution aggregate
must contain only semantic inputs actually required by Magento V1 — ordinary
Product data, 0..N ProductVariants and their option/value semantics, mapped
fields, and required domain-owned resolved values.

The aggregate MUST NOT pre-allocate speculative structures for capabilities
that Magento V1 does not consume merely because they are valid platform
concepts elsewhere. In particular, do not add speculative bundle/kit component
collections, video payload collections, document/manual payload collections,
generic future-channel bags, or unused universal transport metadata.

Bundle composition, video, documents/instructions and other rich Product
capabilities remain valid platform capabilities, but they enter an execution
aggregate only when an actual connector/operation requires them.

Reusable means extensible without redesign, not containing every future feature
from day one.

#### E2. SyncRunItem cardinality

Preserve: one SyncRunItem = one platform Product outcome.

One Product may produce 1 vendor request, N vendor requests, parent + child
writes, option operations, link operations, or media operations.

Transport/vendor operation cardinality must not redefine platform
business-record outcome cardinality. Multi-store/store-view operations must
not change SyncRunItem = Product.

#### E3. Magento V1 product completeness

Magento Product Export V1 must support:

- simple products;
- configurable / multi-variant products;

corresponding to the platform's normal Product/Variant model.

Platform `Product → 0..N ProductVariants` does not itself select Magento configurable. Magento execution maps:

- ordinary non-variant / single-sellable-unit Product → simple;
- Product with meaningful option variants → configurable family.

Zero variants does not mean configurable. Do not invent a Magento-only fake default variant.

Intermediate implementation may add planner paths incrementally. The Magento V1
DONE definition must not exclude multi-variant Products.

Explicitly OUT unless separately justified:

- Magento bundle;
- grouped;
- virtual;
- downloadable;
- gift-card-specific semantics.

These vendor product types must not contaminate generic Product core.

#### E4. Execution input classes

Minimum classification:

**Class A — mapped semantic Product data.** FieldBinding → FieldMapping →
planner. Examples: name, brand, description, GTIN, custom product
characteristics.

**Class B — domain-owned resolved values.** Examples: price → PriceResolver; availability → AvailabilityResolver when included. No connector-specific alternate pricing path. PriceResolver remains the only price calculation path.

**Class C — connector-owned operation configuration / metadata.** Examples:
`attribute_set_id`, `type_id`, visibility, store-scope execution requirements.
These are not fake Product fields. Adobe `attribute_set_id` is not a generic
Product field.

**Derived-value watch item.** A fourth class for platform-computed/derived
values that are not stored FieldBinding values, have no separate canonical
domain resolver, and are not vendor configuration is **not** created now. No
current producer requires it. Document only as a watch-item.

#### E5. Adobe attribute-set ownership

Reject accidental equivalence: platform ProductType == Adobe `attribute_set_id`.
Adobe `attribute_set_id` is not a generic Product field.

| Concern | Owner |
|---|---|
| Semantic owner | Connector / Adobe profile — vendor attribute-set identity |
| Persistence owner | SyncConfiguration-owned connector execution configuration |
| Revision participation | Yes — part of `configuration_revision` when present |
| `configuration_snapshot` participation | Yes — run-effective connector execution configuration |
| Merchant/default behavior | Connected-account default / discovered attribute set; merchant does not edit it as a Product field |
| Future multiple attribute-set compatibility | Additional SyncConfiguration-owned connector configuration or connector-owned mapping; not ProductType and not FieldDefinition. A later connector-owned mapping from Product classification/type to Adobe attribute sets is allowed if that becomes the correct generalized Adobe behavior. |

**First Magento V1 shape:** one SyncConfiguration resolves one Adobe attribute-set context/default for its run. Heterogeneous Products must not silently receive an invalid attribute set. Preview must block/report a Product when the selected Adobe configuration cannot represent its required mapped attributes. Future multiple-attribute-set support remains possible through connector-owned configuration/mapping.

Do not persist `attribute_set_id` in `external_context` merely because that
field is JSON. `external_context` remains external business context
(website/store/store-view), not a dump for vendor operation metadata.

ProductType remains the platform template for field structure (hidden Basic
Product in MVP). FieldDefinition / FieldBinding remain Product vocabulary.
ConnectorAccount holds connection identity. Discovery metadata remains
discovery.

#### E6. Execution support truth

Current binary `ConnectorSyncOperationSupport(data_domain, semantic_operation)`
cannot truthfully advertise Preview supported / Live unsupported.

Freeze support semantics that include execution mode, conceptually:

```text
data_domain
semantic_operation
execution_mode
```

Requirements:

- unsupported pair/mode fails closed;
- Preview never implies Live;
- Preview and Live support are independent;
- planner existence alone never advertises Live;
- connector/profile owns declared support;
- generic core understands semantic support without Adobe branching.

Exact implementation API remains implementation-owned unless a later
architectural contradiction appears.

#### E7. SyncConfiguration merchant reachability

A real merchant must reach the correct Adobe `products` / `export`
SyncConfiguration without internal UUID knowledge.

Rejected: picking an arbitrary "first configuration". Rejected: requiring the
merchant to type a configuration UUID.

**Frozen business behavior:** the unique configuration identity is:

```text
Workspace
→ ConnectorAccount
→ data_domain
→ external_context
```

Semantic operation (e.g. `products` + `export` for Adobe V1) is an enabled/run-target
operation on that exact `SyncConfiguration` — **not** part of `SyncConfiguration`
identity and **not** a reason to create a second `SyncConfiguration`. One
domain/context `SyncConfiguration` may enable multiple semantic operations (see
**Semantic operations** above). For Adobe V1 merchant reachability, resolve the
`(products, export)` operation through this identity — not by picking an arbitrary
first configuration.

Rejected: silently choosing the first configuration; creating a configuration
because the actor opened Preview; inferring authorization from URL IDs; using a
foreign workspace/account/configuration relationship; creating a second
`SyncConfiguration` merely because import and export are independently enabled.

**Mutating ensure path (Stage 1 runtime):** `SyncConfigurationReachabilityService::
ensureProductsExportConfiguration()` and `AdobeProductExportSetupService::
ensureProductsExportConfiguration()` may create a row and/or enable Export —
committed mutations. When invoked from a **merchant-facing mutation path**, an
outer actor-aware boundary must require `manage_sync_configurations` (Stage 2A).
This contract does not silently outlaw trusted/system orchestration paths. See
**Stage 2-0** — Preview-only actors must never call them.

**Read-only existence (Stage 2A-1 implemented):** `SyncPreviewConfigurationReadinessPort::
isReady(SyncConfiguration)` answers readiness for an **already-resolved**
configuration, not whether one exists. `SyncConfigurationLookupService` provides
a genuinely non-mutating identity lookup (workspace + connector account +
data domain + external context) without calling `ensure*()` helpers. Merchant
Preview result/worklist UI is **implemented in Stage 2A-2**.

Adobe `(products, export)` Preview support is declared (Stage 1). Layer-B Adobe
Products Export setup authority and reachability are implemented in Stage
2A-1. Merchant Preview work surface is **implemented in Stage 2A-2**. Option
Mapping remediation UI is **implemented in Stage 2B**.

#### E8. Live authority

[Resolved — Stage 3-0] Freeze the **tenth** atomic workspace permission:

| Permission | Authority |
|---|---|
| `run_sync_live` | Execute consequential Live synchronization for an eligible SyncConfiguration and access the merchant-safe progress/result surface required for that execution. |

**Independence (frozen):**

| Permission | Does **not** imply `run_sync_live` |
|---|---|
| `run_sync_preview` | yes — Preview permission != Live permission |
| `manage_sync_configurations` | yes |
| `view_sync_mappings` / `manage_sync_mappings` | yes |
| `view_connector_accounts` / `manage_connector_accounts` | yes |

No role/job-title name implies Live authority. Absence means deny.

**Runtime rollout (Stage 3A):** append to `WorkspacePermissions` catalogue; normal
idempotent permission seeder creates the catalogue row; **no** GAP-026B-style
membership cutover; **no** automatic grant to existing roles/templates/memberships.
Existing actors remain fail-closed until explicitly granted.

**Admission commit point:** fresh `run_sync_live` authorization is required
immediately before consequential Live admission. After successful admission, actor
revocation does **not** cancel that already-admitted background command. Merchant
result/read surfaces continue to use fresh authorization. Do not introduce generic
cancellation in Stage 3.

**Historical repository status (post–Stage 3-0 and before Stage 3A):** normative
target frozen; runtime catalogue remained **nine** permissions until Stage 3A
landed `run_sync_live`.

**Current repository status (post–Stage 3A):** `run_sync_live` is implemented in
the workspace permission catalogue (**ten** permissions).

Canonical atomic wording follows existing RBAC vocabulary (`run_sync_*` beside
`run_connector_discovery` / `run_sync_preview`).

#### E9. ExternalRecordLink structural contract

[Resolved — Stage 3-0] Minimum generic architecture necessary for safe Live. This
is connector-neutral. Do not freeze Magento product-type roles as platform
ExternalRecordLink vocabulary.

`ExternalRecordLink` is a separate external-identity concept:

- workspace-safe;
- ConnectorAccount-scoped;
- **not** SyncConfiguration-scoped;
- independent from transport attempts;
- explicit trusted correspondence between an internal business record and an
  external record identity.

Products V1 may distinguish structurally: `Product`, `ProductVariant`. Prefer
typed workspace-aware FKs over an unrestricted polymorphic framework.

**Candidate first physical shape:**

| Column | Notes |
|---|---|
| `id` | UUID PK |
| `workspace_id` | tenant scope |
| `connector_account_id` | account scope |
| `product_id` | nullable |
| `product_variant_id` | nullable |
| `external_identifier` | connector-owned identity string |
| `timestamps` | |

Require: exactly one of `product_id` / `product_variant_id` is non-null. Require
workspace-safe composite FKs. No CASCADE behavior may silently forget external
identity.

**Fan-out remains allowed.** Do **not** freeze `UNIQUE(connector_account_id,
product_id)` or `UNIQUE(connector_account_id, product_variant_id)`. A single
internal record may legitimately fan out to multiple external identities.

**Exact duplicate association prevention (frozen):**

```text
UNIQUE(workspace_id, connector_account_id, product_id, external_identifier)
UNIQUE(workspace_id, connector_account_id, product_variant_id, external_identifier)
```

These prohibit duplicate copies of the **same** correspondence while still
allowing `Product A → external X` and `Product A → external Y`. Do **not** make
`external_identifier` globally unique per account.

**Follow-on provenance fields (Stage 3E-R2a — implemented):**
`external_record_links` now persists connector-neutral ENTITY TRUST provenance:

- `trust_origin` — first recognized value: `merchant_confirmed`
- `external_record_discriminator` — for Adobe: Magento logical Product `entity_id`
- `established_by_workspace_user_id` — attributable confirmation actor (`WorkspaceUser`)
- `established_at` — fresh confirmation timestamp

Legacy rows with NULL provenance are **not** grandfathered trusted. A link is trusted for Adobe `merchant_confirmed` only when the complete provenance tuple is valid. There is **no** generic DB `UNIQUE(workspace_id, connector_account_id, external_record_discriminator)` constraint; Adobe discriminator collision remains connector-aware in application guards. Existing exact-association unique constraints are unchanged.

Adobe Product Live support remains **FALSE** until real-target certification and the final truth-flip gate complete. Trusted simple Product execution now consumes the entity-bound Safe Sync WRITE bridge after trusted-link, discriminator, exact-SKU, and consequential-write gate checks; automatic ERL trust establishment from execution remains impossible. Configurable/media expansion and public support remain pending.

**Stage 3E-R2b-1 (implemented — backend only):** merchant-confirmed ENTITY TRUST review/confirm backend exists for Adobe Products. Current/prospective link readiness projection, tamper-resistant review envelope, Safe Sync entity-bound verification, and dedicated `AdobeProductMerchantConfirmedLinkPersister` are implemented. Existing configurable parent uses confirmed merchant Magento SKU (not `cfg-*` substitution). Merchant Filament/Livewire UI remains **R2b-2** follow-on. The R2b-1 trust backend itself performs **no** consequential writes; trusted simple Product WRITE consumption now exists internally, while configurable/media completion and public Live support remain pending.

**V1 Adobe configured Review target (frozen — R2b-1 correction):** merchant-confirmed ENTITY TRUST is bound to Magento logical `entity_id` (identity authority). The authenticated Review envelope also binds the configured Adobe target snapshot `base_url + store_code` derived from the same normalized `ConnectorAccount` state used for outbound requests. Credentials are access material only — not target identity — and may rotate without invalidating trust. `tenant_context` currently has no Adobe outbound-runtime target semantics and does not participate in target identity. After any genuinely merchant-confirmed trusted ERL exists for a `ConnectorAccount`, changing `base_url` or `store_code` on that account is prohibited; V1 migration to another Magento installation/store scope requires a new `ConnectorAccount` and reconfirmation. Target fingerprint/version lifecycle is explicitly deferred. Same configured hostname is not proof of immutable Magento installation identity; future consequential WRITE still relies on fresh entity-bound verification at consumption time.

Do not create generic Magento parent/simple/child enum, external product role
vocabulary, or unrestricted `internal_type`/`internal_id` polymorphism. No
`onec_guid` migration/backfill for Adobe. No fuzzy/name matching.

If implementation research demonstrates exact-association uniqueness above is not
portable to supported MySQL/SQLite or conflicts with a real known connector
identity requirement: **STOP** before migration and report. Do not silently
substitute one-link-per-subject uniqueness.

**Historical repository status (post–Stage 3-0 and before Stage 3A):** normative
contract frozen; table/model were absent until Stage 3A.

**Current repository status (post–Stage 3A):** `ExternalRecordLink` persistence foundation is implemented (`external_record_links` table/model). Adobe Live execution read/write and reconciliation use remain **Stage 3B+**.

#### Adobe Magento V1 identity notes

These are Adobe planner/executor and result semantics. They are **not** generic
ExternalRecordLink vocabulary.

Adobe Commerce REST configurable-product identities include:

- simple product;
- configurable parent;
- simple child;
- parent configurable SKU;
- child simple SKUs;
- numeric resource IDs;
- external option/value identities.

**Adobe child/simple (frozen — Stage 3-0):**

- external identity = canonical mapped `ProductVariant` SKU;
- `ExternalRecordLink` subject = `ProductVariant`.

**Adobe configurable parent (frozen — Stage 3-0; amended Stage 3E Stop-and-Amend):**

Two valid origins exist after Stop-and-Amend:

1. **Existing merchant Magento parent** — merchant-confirmed; Product-scoped ERL
   stores confirmed parent SKU + Magento logical `entity_id` discriminator; do **not**
   rename to `cfg-*`.
2. **Future platform-created parent** — only after proven atomic create capability;
   use deterministic `cfg-*` **connector-owned generated external identity**; do **not**
   rename an existing merchant-confirmed parent to `cfg-*`.

Existing trusted ERL always outranks `cfg-*` recomputation. Do **not** silently use physical `products.sku` as canonical parent identity. Canonical platform SKU is
variant-level. Simple/non-configurable export uses `ProductVariant` link, not a
synthetic Product parent link.

Adobe Live may associate one platform Product with parent and child external
records. That fan-out does not change `SyncRunItem = Product` and does not
authorize Magento role names as platform ExternalRecordLink columns.

#### E10. Live safety — hard invariants NOW

[Resolved — Stage 3-0] invariants:

- ambiguous consequential mutation is never blindly retried;
- transport retry != business idempotency != job retry != business re-execution !=
  reconciliation;
- `KNOWN_NOT_APPLIED`, `KNOWN_APPLIED`, `UNKNOWN_OR_AMBIGUOUS` are semantically
  distinct applied-state knowledge; transport/HTTP failure does **not**
  automatically mean `KNOWN_NOT_APPLIED`;
- Preview authority never authorizes Live;
- connector owns vendor-specific interpretation;
- result persistence must remain merchant-safe and secret-safe;
- Live must have a reconciliation strategy where the external API permits it;
- automatic retry policy must be operation-specific;
- Live job retry: **none automatically** (`tries = 1`);
- at most one queued/running `SyncRun` per `SyncConfiguration` — **mode-agnostic**
  (Preview+Preview, Preview+Live, Live+Preview, Live+Live all reject a second);
- stale active-run recovery is **required** before Adobe Live support may be
  advertised — execution-lease/runtime-window model; recovery must prevent
  overlapping consequential writers;
- historical Preview `connectorPlan` is **not** a Live write script;
- selective "retry failed only" is **out** of Stage 3 V1.

#### E10.1 Live Product outcomes (frozen — Stage 3-0)

`SyncRun.status` remains infrastructure lifecycle: `queued`, `running`,
`completed`, `failed`. Do **not** add `partial`/`ambiguous` to `SyncRunStatus`. A
`completed` run may contain unsuccessful Product outcomes.

`SyncRunItem` remains: one Product = one business outcome. Preview outcomes
remain `ready` / `warning` / `blocked` — do **not** reuse them for Live.

**Live Product outcomes (frozen):**

| Outcome | Meaning |
|---|---|
| `SYNCHRONIZED` | Desired external Product state confirmed for every intended operation (create/update/reconciled/no-op where appropriate). |
| `NOT_APPLIED` | No intended consequential operation applied; includes current validation block or known external rejection. |
| `PARTIAL` | At least one intended operation is `KNOWN_APPLIED`; at least one is `KNOWN_NOT_APPLIED`; no unresolved `UNKNOWN` remains. |
| `AMBIGUOUS` | At least one intended operation remains `UNKNOWN_OR_AMBIGUOUS`. |

`AMBIGUOUS` outranks `PARTIAL`. Do **not** create item-level `FAILED`. Run/job
lifecycle failure belongs to `SyncRun.status`. The database outcome column already
physically supports strings — do not add a migration merely for Live outcome
values. Future runtime must replace/refine the Preview-only Eloquent cast so
Preview and Live rows are mode-safe.

Do **not** create one `SyncRunItem` per Adobe request. Do **not** create a
vendor-operation persistence table in V1 unless implementation / real-Adobe
evidence proves deterministic identities + links + GET reconciliation
insufficient.

#### E11. Live safety — mechanics NOT over-frozen

[Resolved — Stage 3-0 for stale-run safety model; other mechanics remain
revalidation-sensitive]

**Now frozen (Stage 3-0):** stale active-run recovery uses an execution-lease /
runtime-window model — not merely `started_at older than N → mark Failed`. Live
job explicitly uses `tries = 1` independent of worker `--tries`; explicit
execution timeout below connector queue `retry_after`; finite writer
lease/deadline; worker must not start a **new** consequential external request
after lease expiry; automatic stale-running recovery may terminalize a run only
after the original writer lease is conclusively expired; recovery timing must
include allowance for maximum in-flight connector request / worker termination
window; queued-run recovery must no-op if run is no longer `Queued`; stale Live
run becomes terminal historical evidence; recovery must **not** automatically
replay the consequential Product command; subsequent Live uses
reconciliation-first behavior for identities whose prior applied state might be
unknown.

Do not pretend exact algorithms for the following are already proven. They remain
revalidation-sensitive rather than falsely [Resolved]:

- POST vs PUT;
- create-vs-update decision;
- read-after-write;
- ambiguous timeout reconciliation;
- 429 handling;
- record-level partial failure;
- safe re-execution;
- batch semantics;
- exact Adobe mutation endpoint sequence for configurable Products.

Before Stage 3B+ Live implementation, revalidate these mechanics against actual
Adobe API behavior, Preview runtime lessons, and real connector test evidence.

This revalidation does not require a new broad Stop-and-Amend unless a real
architectural contradiction is found.

#### E12. Multi-store / store-view scope

Adobe REST store-view behavior (Adobe Bulk endpoints / store scopes): omitting
store code uses default store; `<store_code>` updates one store; `all` updates
all store scopes. Localized values and media gallery inheritance are
store-view-scoped.

**Magento V1 freeze:**

- single/default store context only;
- `SyncConfiguration.external_context` records that default/empty context;
- multiple store views are out of Magento V1;
- localized/store-scoped value fan-out is out of Magento V1;
- SyncRunItem remains Product — one Product may still require multiple vendor
  operations inside that one default context (parent + children + options +
  media), which does not change business-record cardinality.

**Safe Sync consequential WRITE scope (Stage 3E post-#168 amendment — frozen):**

The above freeze governs the SyncConfiguration / business-record surface.
Within that surface, the **Safe Sync consequential WRITE** is further
constrained as follows (this narrows E12 for the Safe Sync path;
it does not create a parallel rule):

- the write is scoped to **one explicit Magento Store View code** per
  execution context;
- `all` is **NOT** a V1 consequential WRITE target;
- one operation MUST NOT silently fan out across all Store Views;
- the first certification target uses the target's Default Store View;
- additional Store Views may later use their **own explicit execution
  contexts**, not implicit multi-context fan-out;
- Magento **Website** or **Store Group** names are never REST store codes —
  only the explicit REST store code is identity for the write scope.

Future multi-store extensibility is preserved by routing additional
Store Views through their own explicit execution contexts, not by
relaxing the above.

PO-2 remains open for later merchant-configurable independent contexts.

#### E13. Deactivation / removal semantics

| Internal event | Magento V1 behavior |
|---|---|
| `Product.is_active` becomes false | Disable/unpublish the corresponding Adobe product(s) (`status` disabled). Do not delete the external resource. |
| Product becomes unavailable (stock/availability) | Availability domain; do not map to Adobe deletion. Include AvailabilityResolver values only when the operation consumes them. |
| Product is later hard-deleted | Hard-delete propagation is **outside V1**. Do not silently delete Adobe resources. Blocked/manual reconciliation. `SyncRunItem → Product` remains `ON DELETE RESTRICT`. |
| Variant is deactivated | Disable the corresponding Adobe child simple when a child link exists. Do not delete. |
| Variant is removed | Do not delete the Adobe child in V1. Disable if linked; leave ExternalRecordLink for reconciliation. |

Do not silently map internal lifecycle to destructive Adobe deletion.

**Semantic separation (frozen — Stage 3-0):**

- **Active execution input:** active/sellable variants used for normal Product
  projection and simple/configurable classification (Preview aggregate semantics
  unchanged).
- **Live lifecycle input:** enough inactive linked-variant information to disable
  already-existing Adobe children safely.

Stage 3 must **not** change Preview semantics so inactive variants become
configurable sellable dimensions.

#### E14. Rich media scope for Magento V1

Do not let Magento V1 redefine the platform media model.

Two decisions:

1. **Platform capability** — rich assets (images, video, documents/instructions)
   are first-class Product Data. See Platform Product Capability Baseline.
2. **Magento Product Export V1 scope** — export primary image and additional
   gallery images from current platform image assets (`products.images` JSON
   today). Variant-specific images enter the child simple planner when the
   platform actually has variant assets; current runtime does not. Video and
   documents/instructions are `PLATFORM CAPABILITY — NOT IN THIS CONNECTOR V1`
   because first V1 uses ordinary Adobe product image gallery APIs and must not
   require Adobe-specific document extensions.

Do not mark video/documents as globally deferred/unsupported merely because
Adobe V1 does not consume them.

#### E15. First Live transport strategy

**Chosen:** synchronous Adobe REST through the existing Laravel connector queue
runtime.

Rejected for first V1: Adobe async/bulk. Official Adobe Bulk endpoints require
RabbitMQ (or equivalent message broker) on the Commerce side, add partial-result
and operation-status complexity, and are not justified by first V1 catalogue
scale. Do not add infrastructure merely because Adobe offers an API.

Transport choice remains connector-specific.

#### Magento V1 DONE

The connector must not be declared V1 complete merely because one simple SKU
can be sent.

Observable V1 DONE requires at minimum:

- account setup works;
- connection check works;
- schema discovery works;
- mapping setup works;
- SyncConfiguration becomes merchant-reachable;
- Preview works;
- Preview performs zero consequential mutation;
- Product-level results are understandable;
- simple Product export works;
- multi-variant/configurable Product export works;
- external identities are preserved safely;
- re-running does not blindly duplicate;
- ambiguous consequential failures are handled safely;
- deactivation behavior is defined;
- store-context behavior is defined;
- merchant receives understandable result;
- real Adobe Commerce create/update validation succeeds.

Media requirements follow E14.

#### Coherent implementation stages

Replace architecture micro-slicing with three principal outcomes. Existing
labels such as `4C-2b-2` and `4C-2b-3` may remain historical tracking labels.
They are not mandatory future PR boundaries. Internal PR subdivision is
allowed for code-review/manageability. It must not create new architecture
micro-slices unless a real unresolved architecture issue is discovered.

**Stage 1 — Preview Engine**

Business outcome: an authorized merchant/runtime actor can execute a
persisted, zero-mutation Adobe Products Export Preview end-to-end against the
full platform Product/Variant model.

May coherently include: `run_sync_preview`; RBAC catalogue/materialization;
SyncConfiguration reachability; snapshot builder; admission/concurrency;
coherent Product execution set; generic Product execution aggregate;
Product + variant value resolution; Adobe simple planner; Adobe configurable
planner; background execution; normalized findings; truthful Preview
execution support; **SyncConfiguration-owned connector execution configuration
required by E5** (Adobe attribute-set context/default for the run), including
persistence ownership, revision-version change/rebaseline if the hasher input
set grows (current revision v3 has no connector execution-configuration input),
`configuration_snapshot` inclusion, and corresponding migration/tests.

Do not rediscover a hidden revision-v4 prerequisite halfway through Stage 1.
Do not implement that persistence in this docs contract.

No merchant-facing polished Preview page is required in Stage 1. No Live
mutation.

**Stage 2-0 — Merchant Preview Authorization & Remediation Contract**

Docs-only freeze (this section). No runtime/UI implementation in this slice.
See **Merchant Preview Authorization & Remediation Contract (Resolved —
Stage 2-0)** below.

**Stage 2A — Merchant Preview Core + Connector Setup**

Business outcome: an authorized non-technical merchant can reach Preview through
the approved Integrations/Data Setup path, complete prerequisite connector setup
when authorized, and understand Product-level ready/warning/blocked outcomes with
honest contextual remediation.

Includes: runtime `manage_sync_configurations`; actor-aware SyncConfiguration
setup authorization; non-mutating existence check; exact Preview
entry/reachability; safe Adobe attribute-set setup; Preview run lifecycle;
completed-result summary; Needs-attention working set; Product-level findings;
contextual remediation; Mapping deep links; temporal/staleness behavior; honest
`NO_EDIT_SURFACE`; explicit rerun.

Do not build generic Sync History product, SyncIssue, scheduling, or analytics
merely for this stage.

**Stage 2B — Minimal Option Mapping Remediation** — **Done**

Option Mapping read model/UI on `ManageSyncFieldOptionMappings`; outer
actor-aware authorization via existing `view_sync_mappings` /
`manage_sync_mappings` permissions only (no separate option-mapping permission).
Focused exact option remediation for `MissingOptionMapping` and
`ExternalOptionMissingOrStale`.

Key runtime behaviors (Stage 2B):

- **Read:** authoritative persisted connector snapshot metadata only — zero HTTP
  on read (`AuthoritativeExternalOptionChoiceResolver`).
- **Mutate:** `confirm` / `replace` retain connector external validation **outside**
  the locked DB transaction (`FieldOptionMappingMutationService`).
- **Preview remediation:** findings remain **historical** after remediation; current
  actionability recomputed from current authorization + configuration state.
- **Stale/orphan cleanup:** narrow removal of stale `FieldOptionMapping` rows whose
  `internal_option_key` no longer exists in the field definition catalog; does
  **not** repair Product/Variant select value integrity.

Independently reviewable after 2A architecture is established.

**Stage 3-0 — Live Safety, Identity & First-Live Contract** — **Done (docs contract)**

Docs-only freeze before the first consequential external write. See **Live Safety,
Identity & First-Live Contract (Resolved — Stage 3-0)** below. No runtime
implementation in this slice.

**Stage 3A — Live Safety Foundation** — **Done**

Safety foundation before the first consequential external write: `run_sync_live`
permission, stale-active-run lease/recovery, Live outcome vocabulary,
`ExternalRecordLink` persistence, `SyncLiveAdmissionService`, and fail-closed Live
job shell. Adobe Products / Export / Live support remains **false**.

**Stage 3B–3E — Live Engine implementation slices** — **3B and 3C Done (internal); 3D-1 E14 media runtime Done (internal); normative Stage 3D Done (internal); Stage 3E docs contract Done — entity-bound Safe Sync runtime contract frozen; Safe Sync read + isolated simple WRITE foundation implemented internally; runtime consumption + real target validation NOT YET EXECUTED**

**Stage 3E Stop-and-Amend (docs correction — Magento primary-source research + Security/Concurrency arbitration):** the previous [Resolved] stock no-link Product create safety and create-provenance ownership model are **invalidated**. See **Stage 3E Stop-and-Amend — Magento ownership and entity-bound Safe Sync runtime contract** below. PR #160 runtime from the discarded Part 1 approach is **reverted**; final normative contract is frozen in this docs-only PR; replacement runtime follows separately.

After Stage 3-0 merges, implement in order:

| Slice | Scope | Adobe Products/Export/Live support | Current repository status |
|---|---|---|---|
| **3A — Live Safety Foundation** | `run_sync_live` permission; stale-active-run lease/recovery; Live outcome persistence; `ExternalRecordLink` persistence; `SyncLiveAdmissionService`; Live job shell (`tries = 1`, safe timeout); Preview×Live concurrency tests | remains **false** | **Done** |
| **3B — Adobe Simple Live** | Shared Adobe semantic planning boundary; child/simple external identity; GET/POST/PUT Product transport; `ExternalRecordLink` read/write; create/update/reconciliation; simple Product Live execution; applied-state classification | remains **false** | **Done (internal)** — **historical no-link create assumption invalidated by Stage 3E Stop-and-Amend**; replacement link-first runtime pending |
| **3C — Adobe Configurable Live** | Connector-owned deterministic parent SKU; child/parent/options/link command compilation; partial/ambiguous outcomes; inactive linked-variant lifecycle; configurable recovery/reconciliation | remains **false** | **Done (internal)** — existing-parent link identity clarified by Stage 3E Stop-and-Amend; cfg-* generator applies only to future proven atomic create |
| **3D — Adobe Media + Merchant First Live** | Required E14 primary/gallery image export; merchant Live admission **UI/read model** on `ManageAdobeProductsExportPreview` (non-actionable for consequential execution while Live support is **false**; must not bypass `ConnectorSyncOperationSupport`); queued/running/result presentation; final safe merchant copy | remains **false** | **Done (internal)** — 3D-1 E14 media runtime + 3D-2 merchant first-Live UI/read model |
| **3E — Real Adobe Validation + Truth Flip** | Merchant link-first runtime; ERL provenance/discriminator persistence; informed merchant confirmation; atomic configurable-family confirmation; first-party Magento entity-bound Safe Sync component; disposable validation harness; target-version proof; only then flip `Adobe Products / Export / Live = true` | flip only after successful evidence | **Done (docs contract)** — entity-bound Safe Sync runtime contract frozen; **Stage 3E-R1** internal read foundation is implemented; **trusted simple entity-bound Product WRITE consumption is implemented internally**; **Stage 3E-R2a** ERL provenance foundation is implemented; **Stage 3E-R2b-1** merchant-confirmed ENTITY TRUST backend is implemented; **Stage 3E-R2b-2** merchant per-item informed review/confirm UI is implemented with an opaque server-side review-flow store keeping `reviewToken` out of browser state; browser state remains presentation/input only and never identity authority; configurable/media completion, real target validation, and support flip remain **pending**; support remains **false** |

Production Live remains **NOT IMPLEMENTED** until Stage 3E runtime lands and
completes real-target validation with explicit human authorization. No deployment
without separate explicit approval.

##### Stage 3E Stop-and-Amend — Magento ownership and entity-bound Safe Sync runtime contract

[Resolved — Stage 3E docs contract] This section freezes the final entity-bound
Safe Sync runtime contract after Magento primary-source research and
Security/Concurrency arbitration. The original contract landed as a
**docs-only** slice. Current repository status now includes the standalone
Magento Safe Sync read foundation, trusted simple entity-bound Product WRITE
consumption, and an internal
validation-only disposable validation harness. Live support remains **false**;
no real-target validation harness execution/certification or deployment has
occurred.

#### First-party Magento Safe Sync component (frozen)

The entity-bound mutation boundary is implemented as a **separate Composer
`magento2-module`** first-party component — not a write-only REST bridge.

| Requirement | Rule |
|---|---|
| Boundary shape | Entity-bound **read + write** boundary for consequential Live mutations |
| Magento core | **No** Magento core modification |
| Product core | **No** Product-core schema changes in SaaS or Magento for this contract |
| DB triggers | **No** DB triggers |
| SaaS→Magento DB | **No** direct SaaS→Magento DB access path |
| Interceptors | **No** broad/global Product interceptors |

The component exposes a narrow Safe Sync seam consumed by the existing Adobe
connector runtime (`AdobeProductRemoteStateClient`, command executors, media
executors). Current SKU-addressed REST paths in `develop` remain superseded for
consequential safety decisions once the component ships.

#### Primary-source facts — stock no-link create is unsafe

Verified stock Magento facts (Stage 3E research):

1. `POST /V1/products` and `PUT /V1/products/:sku` both route to
   `Magento\Catalog\Api\ProductRepositoryInterface::save()`.
2. `ProductRepository::save()` resolves an existing Product by SKU when no body ID
   is supplied and continues save semantics if that Product already exists — Product
   POST is **upsert-like**, not atomic create-if-absent.
3. Stock REST provides no proven create-only Product service, `If-None-Match`,
   expected-absence precondition, or equivalent atomic conditional mutation.
4. `catalog_product_entity.sku` is indexed with a normal B-tree index, **not** a DB
   UNIQUE constraint.
5. Magento Product import may write Product rows directly through `insertMultiple()`.

Therefore:

```text
GET missing + POST 2xx + response SKU match + post-write state match
```

does **not** prove this connector created the Product. The previous [Resolved]
no-link-create contract is **invalidated**.

#### No-link stock Magento rule (frozen)

```text
NO trusted ExternalRecordLink
        ↓
NO consequential Product mutation
```

Under stock Magento specifically:

- **NO** `POST /V1/products`;
- **NO** blind PUT;
- **NO** automatic adoption;
- **NO** create disguised through an update path.

Remote reads may be performed only for merchant remediation / link discovery.

| Remote read outcome | Behavior |
|---|---|
| Remote **Found** | Potential merchant-confirmed link candidate; **no mutation** before confirmation |
| Remote **Missing** | `KnownNotApplied`; zero Product write; merchant-safe message that remote Product is not available for linking |

Future automatic creation remains **deferred** behind a separately proven remote
create capability. Do **not** declare auto-create permanently impossible.

#### Merchant-confirmed link is a trust origin

Merchant-confirmed linking is legitimate because the merchant explicitly asserts
authority over their own remote Product. Confirmation must be: fresh; informed;
attributable; anchored to the exact remote logical Product.

Minimum confirmation contract:

- Fresh read-only Magento GET during the confirmation flow
- Remote record classified **Found**
- Workspace + `ConnectorAccount` explicitly verified
- No existing ambiguous/trusted ERL conflict
- Cross-subject collision check passes
- Remote type compatible with intended platform subject
- For simple/child: remote SKU **exactly** equals canonical `ProductVariant` SKU
- Merchant sees safe desired-vs-observed controlled-field comparison
- Merchant explicitly confirms
- Remote logical discriminator captured
- Confirmation provenance persisted

No stale cached discovery alone may establish trust.

#### ENTITY TRUST — not SKU TRUST (frozen)

Trust semantic: **ENTITY TRUST** — the merchant confirms one specific logical
Magento Product, not merely a reusable SKU string.

For Adobe/Magento:

- stored Magento logical `entity_id` (via `external_record_discriminator`) remains
  the remote identity discriminator
- `external_identifier` remains the merchant-visible addressing SKU
- expected SKU remains a **mandatory equality precondition** for bind/mutate
  cycles, but is **not** identity authority

If the SKU is later deleted/recreated/reassigned to another Magento logical
Product, the old trust does **not** automatically transfer.

Explicitly reject **SKU TRUST** ("whatever currently occupies this SKU is ours")
because that would be automatic adoption after delete/recreate.

**Post-trust read rule (frozen):** after a trusted `ExternalRecordLink` exists,
stock SKU GET (`GET /V1/products/:sku`) must **not** participate in
verification, reconciliation, or applied-state proof for consequential mutations.
All post-bind safety reads are **entity-bound** through the first-party component.

Pre-trust candidate discovery may still use bounded stock SKU lookup only to find
link candidates; final link confirmation must freshly verify exact logical entity +
expected SKU and reject ambiguous/conflicting logical Products.

#### Follow-on ERL provenance / discriminator requirement

**Implemented in Stage 3E-R2a.** `external_record_links` now persists:

| Field | Purpose |
|---|---|
| `trust_origin` | First recognized value: `merchant_confirmed` |
| `external_record_discriminator` | For Adobe: Magento logical Product `entity_id` |
| `established_by_workspace_user_id` | Attributable confirmation actor |
| `established_at` | Fresh confirmation timestamp |

Legacy rows are not grandfathered trusted. No generic discriminator DB unique constraint. Adobe Product Live mutation remains fail-closed until the entity-bound WRITE bridge exists. **Stage 3E-R2b-1** implements merchant-confirmed trust persistence backend (review/confirm services, link readiness projection), and **Stage 3E-R2b-2** implements the merchant per-item confirmation UI on `ManageAdobeProductsExportPreview`. The browser never receives `reviewToken`; Livewire state is presentation/input only, while server-side re-projection plus the opaque review-flow store remain authoritative.

#### Entity-bound mutation boundary (frozen)

Every consequential Live mutation through the first-party component must execute
inside one **outer transaction** with this invariant sequence:

1. **Lock** the logical Product through Magento metadata
   `getIdentifierField()` / logical `entity_id` — **not** physical `getLinkField()`
   / `row_id`; fail closed if the logical entity is absent
2. **Load** the entity with `getById(..., forceReload=true)`
3. **Verify** expected stored `entity_id` **and** expected SKU equality on the
   loaded entity; reject ambiguous/conflicting logical Products
4. **Mutate** only that loaded entity; **no** target re-resolution by SKU
5. **Save** through normal repository/resource pipeline
6. **Fresh entity-bound postcondition** on the same logical entity; exact SKU must
   remain unchanged; rollback outer transaction on any divergence

**Load-bearing SKU postcondition:** Magento `Sku::beforeSave()` may silently suffix
a duplicate SKU rather than fail closed. Post-save exact SKU equality is therefore
**load-bearing** applied-state proof — not optional defence-in-depth.

**Create-fallback guard:** do **not** claim that a Product object carrying an ID
makes Magento repository create fallback structurally impossible. The component's
own locked-existence invariant must make update→create/recreate unreachable, and
real-target validation must prove it.

**Body `id` is FORBIDDEN** as a REST safety mechanism (see linked-update failure
matrix A–E below). Never send expected Magento Product `id` in REST payload as an
optimistic identity guard.

| Case | Outcome under stock body-ID / SKU-addressed REST |
|---|---|
| **A.** Expected ID exists + expected SKU still belongs to it | Ordinary expected state |
| **B.** Expected ID exists + another Product now occupies expected SKU | May silently change SKU rather than fail closed |
| **C.** Expected ID exists + its SKU changed elsewhere | May rename it back / alter identity |
| **D.** Expected ID deleted + another Product occupies old SKU | May enter create semantics |
| **E.** Expected ID deleted + old SKU absent | Linked "update" may silently become create |

#### Content Staging (frozen)

When Content Staging is enabled on the target:

- lock **all relevant physical rows** through logical
  `getIdentifierField()` / `entity_id`
- **never** define logical identity with `getLinkField()` / `row_id`
- **no** dependency on Commerce-only `VersionManager`; Magento repository/resource
  pipeline resolves the operational version
- real Commerce proof must include a Product with a **pending scheduled update**

#### Galera / multi-node concurrency (frozen)

- InnoDB gap locks are **defence-in-depth only**, not cluster-wide safety
- all critical authorization / verification / reconciliation reads **after binding**
  are entity-bound — not SKU GET
- critical entity-bound reads on Galera must also provide proven
  **causal-current / read-after-write** semantics; exact low-level implementation
  stays inside the Magento component and must be real-target validated
- stock SKU lookup may be used **only** as pre-trust candidate discovery; final
  link confirmation must freshly verify exact entity + expected SKU

#### Safe mutation primitives (frozen)

After ENTITY TRUST binding, SKU-addressed Product / media / configurable operations
are **forbidden** for safety decisions.

| Mutation category | Required primitive |
|---|---|
| Product / lifecycle | Entity-loaded Product + normal repository save |
| Media | Entity-loaded Product / media extension mechanics — **not**
  SKU-addressed `GalleryManagement` operations |
| Configurable options / child links | Entity-loaded parent extension attributes /
  ID-bound Magento mechanics |

#### Rollback and EntityManager callbacks (frozen)

- nested Magento transaction behavior is **intentionally reused**
- after bridge-owned outer rollback, pending `EntityManager` callbacks for the
  connection/entity must be **cleared**
- this requires **one narrowly isolated Magento internal compatibility seam**; do
  **not** claim the component uses only public `@api` contracts
- require target-version compatibility tests for callback-pool cleanup behavior

#### Media transactional boundary (frozen)

- media filesystem/object write is **not** transactionally rolled back with DB;
  failed transaction may leave benign orphan storage
- **no** destructive media DELETE/cleanup subsystem in V1
- byte-identity reconciliation uses **entity-bound** media read with bounded
  response size

#### Account readiness freeze (ConnectorSyncOperationSupport vs ConnectorLiveRuntimeReadiness)

**Safe Sync component readiness (Resolved — 2026-08-30):** after the ordinary
Magento connection check succeeds, one bounded handshake classifies the
requested operation as `READY`, `SETUP_REQUIRED`, or `UPDATE_REQUIRED`.
`SETUP_REQUIRED` requires the conjunction of a successful baseline and the
structured existing `AdobeInvalidOrUnsupportedEndpoint` result with exact HTTP
404; it means the endpoint is unavailable or installation/setup/deployment is
incomplete, not that physical module absence was proven. An unaccepted
compatibility epoch or missing required operation family is `UPDATE_REQUIRED`.
Simple Product WRITE readiness also requires a comparable semantic module
version at or above the validation runtime's proven minimum; Product READ does
not inherit that operation-specific floor.
Other failures preserve existing connection/error semantics with component
readiness absent. Evaluation is stateless and presentation-only: no readiness
table or account projection is introduced. The result does not replace the
fresh `ConnectorLiveRuntimeReadiness` consequential-write timing rules.

**Invariant (merchant-safe causality):** when baseline connection succeeds but a subsequent operation-readiness probe fails, the runtime result must preserve baseline success as distinct evidence from the failed probe. Merchant presentation must not translate this case into "connection failed".

Handshake payloads may include additive optional diagnostic fields
`application_version` and `php_version` (for merchant-safe support/troubleshooting
only). These fields MUST remain non-authoritative: they must not participate in
readiness classification, gating, or compatibility decisions.

| Concept | Meaning |
|---|---|
| `ConnectorSyncOperationSupport` | Static software capability — what the connector profile advertises |
| `ConnectorLiveRuntimeReadiness` | Fresh account-specific remote prerequisite — can this account execute Live right now |

**V1 exclusions (frozen):** no second auth profile; no Product Magento flag; no readiness table; no persisted handshake evidence on `SyncRunItem` (business-record
outcome only).

**Handshake timing (frozen):**

- cached handshake is **presentation-only**
- **Start Live** fresh handshake occurs **outside** the DB admission transaction
- worker fresh handshake occurs **immediately before first consequential write**
  after the run has its writer lease/deadline; DB-fresh
  `SyncRunConsequentialWriteGate` is rechecked after handshake

Current seams to extend (runtime follow-on): `SyncLiveAdmissionService`,
`SyncLiveRunJob`, `SyncRunConsequentialWriteGate`.

#### Failure semantics (frozen)

Preserve existing applied-state vocabulary:

- `KnownApplied`
- `KnownNotApplied`
- `UnknownOrAmbiguous`

**IdentityMismatch** is a **reason beneath `KnownNotApplied`**, not a new
applied-state enum and not a persisted ERL untrusted lifecycle.

- only a **bridge-authored response** may prove rollback / `KnownNotApplied`
- transport ambiguity after a consequential attempt remains `UnknownOrAmbiguous`
- **no** blind retry

#### Auto-create (OUT of V1 — frozen)

Auto-create remains **OUT of V1**. `getById` / entity missing must fail with
**zero intended mutation**.

Validation must prove repository fallback cannot turn a linked update into
create/recreate. Future auto-create requires a separately proven atomic create
capability — not stock POST upsert semantics.

Explicitly **rejected** as ownership proofs: second GET; POST response SKU
equality; matching reconciliation state; marker written by the same POST;
temporary SKU + rename; tiny-race acceptance; gap lock alone; synthetic child SKU.

#### Simple / child identity (preserved)

Adobe simple / child addressing identity = canonical mapped `ProductVariant` SKU.
No synthetic child SKU.

For a confirmed simple/child link:

- `external_identifier` = canonical `ProductVariant` SKU
- remote discriminator = confirmed Magento logical Product ID

#### Configurable parent identity (frozen — two valid origins)

**Existing merchant Magento parent:** merchant-confirmed existing configurable —
Product-scoped `ERL.external_identifier` = confirmed existing Magento parent SKU;
remote discriminator = confirmed logical Magento parent Product ID. Do **not**
rename to `cfg-*`. Do **not** create a duplicate `cfg-*` parent.

**Future platform-created parent:** only after a future proven atomic create
capability exists — use current deterministic `cfg-*` generator.

**Hard precedence:** existing trusted ERL always outranks recomputation of `cfg-*`.
If recomputation disagrees with a trusted link: do **not** normalize; do **not**
rewrite the link; trusted link remains authoritative until explicit merchant
remediation. Mixed parent identity conventions are legal (existing linked merchant
SKU parents; future platform-created `cfg-*` parents).

#### Configurable family link confirmation (atomic)

Future merchant confirmation for a configurable family must be atomic at family
scope. All intended subjects (parent; intended active children) must validate and
link as **one** confirmation operation, or **none** persist.

Required validation: parent exists; parent is configurable; children exist; each
child maps to intended `ProductVariant` SKU; remote types compatible; all remote
discriminators captured; no cross-subject collisions; no ambiguous existing ERLs;
exact Workspace + `ConnectorAccount`; explicit informed merchant confirmation.

**No** partial child-by-child family linking.

#### Merchant presentation — per-item Live linking

Linking is **per-item Live readiness/remediation**. It is **not**:
`SyncLiveMerchantSetupBarrier`; a Preview finding; a Preview HTTP lookup.

Preview remains safe/read-only per its existing contract. Preview readiness does
**not** imply Live applicability.

An unlinked Product in the Live surface: uses existing `SyncLiveOutcome::NotApplied`;
carries a distinguishable merchant-safe linking reason; appears in Live worklist;
offers contextual link/reconfirmation action when authorized.

Do **not** add a fifth Live outcome for linking. Do **not** add a per-product case
to the run-level setup barrier.

#### Link confirmation authorization (frozen)

Link-confirmation mutation authority requires **both**:

- fresh `manage_sync_configurations`
- fresh `run_sync_live`

for the current `Workspace`. Setup authority asserts synchronization
ownership/configuration; Live authority asserts authorization for future
consequential external mutation. Neither permission alone is sufficient. Do **not**
create a new permission in Stage 3E. Revocation of either before confirmation
must fail closed.

#### Informed merchant confirmation

A bare checkbox is insufficient. Before confirmation the merchant must see a
concise controlled-field comparison (Platform value vs current Magento value) for
fields the connector will own/update.

Merchant action remains simple, e.g. *Пов’язати з цим товаром Magento*, with clear
explanation that subsequent synchronization may update those fields.

Do **not** expose: `ExternalRecordLink`, `entity_id`, discriminator, ownership
policy, reconciliation, HTTP evidence.

#### Validation harness contract (frozen — harness implemented; real-target proofs pending)

The Part 1 validation harness runtime is **reverted**. Retain existing disposable
harness rules and add real-target proofs for:

- silent SKU suffix rollback (`Sku::beforeSave()` collision path)
- `CallbackPool` / EntityManager callback cleanup after bridge-owned rollback
- Content Staging scheduled-version Product with pending update
- cross-node duplicate SKU race
- causal cross-node entity read (Galera read-after-write)
- repository create-fallback guard (linked update cannot become create/recreate)
- certification / brute-force abort and transport loss around COMMIT

Harness environment rules (unchanged):

- dedicated validation-only environment (`APP_ENV=stage3e-validation` or equivalent)
- disposable/non-production target; exact target hostname confirmation
- no credentials on CLI; credentials through existing encrypted `ConnectorAccount`
- explicit real-write acknowledgement; validation-only command surface absent from
  normal production
- safe evidence only; no raw request/response bodies or secrets
- `B2BVAL-*` validation variant SKU namespace where appropriate
- production configurable parent generator only where the production path requires it

##### Certification abort disambiguation (frozen — Step-4 arbitration)

The earlier shorthand "certification / brute-force abort" was undefined and
ambiguous. The contract below freezes the exact meaning of the
"certification abort" class of evidence in Step-4, separating three distinct
scenarios. Do **not** reintroduce "brute-force abort" as a normative term.

The literal phrase **transport loss around COMMIT** is preserved because an
existing documentation contract test requires it.

The three scenarios are:

**A. Worker termination around COMMIT**

The PHP-FPM / request worker executing Safe Sync is terminated after the
target may have physically committed but before the caller receives a
reliable HTTP result.

Required evidence:

- caller classifies the result as `UnknownOrAmbiguous`;
- **no** automatic consequential retry occurs;
- durable target state is independently reconciled afterward.

Do **not** require a later request to reuse "the same connection": the
original worker / process is gone.

**B. DB session loss around COMMIT**

The server-side DB session is deliberately lost in the COMMIT /
commit-acknowledgement window.

Required contract:

- if physical application cannot be proven, the result must not be falsely
  classified as `KnownApplied`; ambiguous state remains `UnknownOrAmbiguous`;
- if the Magento adapter returns with uncertain / non-zero logical
  transaction state, the exact adapter must be quarantined / reset before
  reuse (see Decision 3);
- a subsequent safe operation must **not** inherit poisoned transaction
  state;
- target-side evidence must establish whether the mutation physically
  applied.

Do **not** promise that every possible DB-session-kill timing necessarily
enters one exact module branch.

**C. Transport loss around COMMIT**

Keep this separate from A and B. The current S3 post-delegate transport-loss
scenario proves:

- target response completed at the delegate boundary;
- caller receives `UnknownOrAmbiguous`;
- exactly one consequential PUT;
- no automatic retry;
- read-only reconciliation only.

It does **not** by itself prove the instant of physical DB COMMIT. Step-4
real-target certification must additionally satisfy A and B as separate,
independently proven scenarios.

#### Live truth-flip gate (expanded)

Adobe Products / Export / Live remains **false**. Truth flip blocked until **all**
required Stage 3E evidence exists:

1. Merchant link-first runtime implemented
2. ERL provenance/discriminator persistence implemented
3. Informed merchant confirmation implemented
4. Atomic configurable-family confirmation implemented
5. Per-item Live link remediation implemented
6. First-party Magento entity-bound Safe Sync component implemented
7. Entity-bound mutation boundary real-target validated for every advertised V1 Live
   mutation category (**detection alone does not satisfy this item**)
8. Body Product `id` is **not** used as a safety mechanism
9. Unsupported no-link POST/create path unreachable; auto-create OUT of V1 proven
10. Post-trust SKU GET excluded from verification/reconciliation/applied-state proof
11. Content Staging scheduled-version proof passes
12. Galera causal-current entity read proof passes
13. CallbackPool cleanup proof passes
14. Silent SKU suffix rollback proof passes
15. Simple linked behavior real-target validated
16. Configurable linked behavior real-target validated
17. Lifecycle behavior validated
18. E14 media validation passes
19. Stage 3D merchant Live surface presents linking/reconfirmation coherently
20. No unlinked Product presented as Live-ready/actionable
21. `ConnectorLiveRuntimeReadiness` fresh handshake integrated per timing rules

#### Stage status after Stop-and-Amend

| Stage | Status |
|---|---|
| 3A | **Done** |
| 3B | **Done (internal)** — old stock no-link-create assumption invalidated |
| 3C | **Done (internal)** — existing-parent link identity clarified |
| 3D | **Done (internal)** |
| 3E docs contract | **Done (docs contract)** — entity-bound Safe Sync runtime contract frozen |
| 3E runtime + validation | **Partially implemented (internal; validation-only)** — trusted simple Product WRITE consumption and disposable validation harness shipped internally; real-target certification **NOT YET EXECUTED**; configurable/media completion and support flip remain pending |
| Adobe Products/Export/Live | **FALSE** |
| Merchant consequential Live | **NOT EXPOSED** |
| Deployment | **NOT PERFORMED** |

No Stage 3D-3. No Stage 3F. No new normative Stage.

##### Stage 3E Post-#168 Real-Target Certification Amendment (docs only)

[Resolved — Stage 3E post-#168 docs amendment] This section records the
post-PR #168 RED research findings and the exact pre-certification sequence
that follow the isolated entity-bound simple Product WRITE foundation landing
internally. **This is documentation only.** No runtime PHP under `app/` or
under `integrations/magento-safe-sync/` was modified. No `composer.json`
change was made. The disposable validation harness is now implemented
separately as a validation-only Laravel control plane, but no real-target
validation execution/certification occurred and Live was not enabled. No new
broad Stage 3F was created. This is an additive Stage 3E certification
amendment layered on top of the
[Stage 3E Stop-and-Amend](#stage-3e-stop-and-amend--magento-ownership-and-entity-bound-safe-sync-runtime-contract)
section above.

#### DECISION 1 — Current state (frozen)

Record explicitly, without overstating readiness:

- An isolated entity-bound simple Product **WRITE** primitive exists in
  `integrations/magento-safe-sync/` and is reachable through the standalone
  `magento2-module` first-party component.
- A bounded Laravel **Safe Sync write client** (`AdobeSafeSyncClient` +
  `AdobeSafeSyncRequestFactory`) exists and is consumed by the trusted simple
  Live executor path.
- `AdobeProductSimpleCommandExecutor` does **not** route a trusted simple
  Product through the historic SKU-addressed `PUT /V1/products/:sku` path.
  For a trusted simple variant link it now performs at most one
  entity-bound Safe Sync consequential WRITE after strict trusted-link,
  discriminator, exact-SKU, and consequential-write gate checks.
- Historic SKU-addressed consequential writers remain separately in
  dormant production-unreachable code paths for: media
  (`GalleryManagement`), configurable options / child link, and lifecycle
  status / visibility. Those downstream seams must be replaced before their
  respective Live paths become reachable.
- `ConnectorSyncOperationSupport(Products, Export, Live)` remains **false**;
  Adobe Products / Export / Live public support is unchanged.
- **No** real-target consequential WRITE certification has occurred.
  PR #168 only proved that the isolated write primitive is reachable; it
  did not prove behaviour on a real Adobe Commerce target.

Do not promote the post-#168 foundation to "ready for Live" or "ready for
real-target". The truthful description is: **trusted simple consumption is
implemented; configurable/media completion and real-target evidence remain
pending; support remains false.**

#### DECISION 2 — Product save must be media-neutral (frozen)

A verified Magento primary-source fact constrains the post-#168 corrective
work:

> A Product loaded through the normal repository/resource pipeline carries
> `media_gallery` state. `ProductRepository::save()` may process
> `media_gallery` when that loaded state is present even if the requested
> Safe Sync mutation only changes `name` / `price` / `status` / `visibility`
> / mapped attributes.

Therefore the normative contract is amended as follows:

- A non-media Safe Sync Product write **MUST NOT** cause uncontrolled
  `media_gallery` or image-role mutation.
- Before real-target certification, the first-party component must either
  **structurally exclude media state from the core Product save** or
  **otherwise prove and enforce media neutrality**.
- A controlled-field postcondition alone is **insufficient** as proof if
  media state could change outside that postcondition.

This is a **documentation amendment** of the contract. The exact PHP
implementation remains a module-local concern to be implemented and
real-target validated in the bounded Safe Sync module correction step
(Decision 9 step 2). Do not prescribe the implementation here.

**Image-role scope requirement (frozen — Step-4 arbitration):**

Record that `Magento\ProductRepository::save()` has store-scope
normalization logic involving:

- `image`
- `small_image`
- `thumbnail`

for a Product save executed in a Store View context.

Therefore Step-4 non-media certification must compare before / after
evidence for:

**A. gallery state:**

- gallery identity / value
- position
- label / store-scope state where applicable

**B. image-role EAV state:**

- `image`
- `small_image`
- `thumbnail`

at **both**:

- default / admin scope representation relevant to Magento storage;
- exact certification Store View scope.

Evidence must preserve whether a store-scoped override existed before the
write and whether exactly that state remains afterward.

Gallery-only comparison is **INSUFFICIENT**.

Do **not** claim this is media WRITE certification. Full media WRITE
remains Decision 9 step 9.

#### DECISION 3 — Connection quarantine (frozen)

Record the post-PR #168 RED research finding on connection cleanup:

> On uncertain transaction/rollback state, merely closing the Magento DB connection is insufficient
> if the adapter logical transaction state remains non-zero / poisoned.

Therefore:

- The Safe Sync compatibility seam must **reset / quarantine the exact
  shared entity connection** so subsequent work cannot inherit stale
  transaction state.
- Current code facts (write-side seams implicated by the RED finding):
  - `ProductWriteManagement::quarantineConnection()` currently closes the
    exact entity adapter after uncertain transaction / rollback
    situations.
  - `GaleraWriteSession::quarantineConnectionAfterRestoreFailure()` currently
    closes the write-side adapter after a `wsrep` restore failure.
  - `GaleraSessionScope::quarantineConnectionAfterRestoreFailure()` has an
    analogous READ-side quarantine seam, but it is **not** the sole or primary
    source of the write transaction-state finding.
- The exact implementation is module-local and must be **target-version
  tested** (Decision 6) on the certified Magento / PHP combination.
- Do not claim real-target proof already exists. Connection reset /
  quarantine at the adapter level remains part of the bounded Safe Sync
  module correction (Decision 9 step 2).

#### DECISION 4 — Price scale (frozen — not an open defect)

Record the post-PR #168 RED research finding on price precision:

- The target Magento 2.4.8 / 2.4.9 catalog decimal storage uses
  **scale 6** for `catalog_product_entity_decimal.value`.
- The existing **fail-closed six-decimal admission** in the Safe Sync
  primitive remains the current contract.
- Do **not** mark `PRICE_SCALE = 6` as an open defect.
- Real-target certification still confirms the deployed schema against
  the target's `DESCRIBE catalog_product_entity_decimal` evidence.

#### DECISION 5 — Store view context (frozen — amends E12 narrowly)

The E12 wording "single/default store context only" remains correct at the
SyncConfiguration level. This amendment makes the **Safe Sync consequential
WRITE** scope explicit within E12 and does not create a parallel rule.

For Safe Sync consequential WRITEs specifically:

- The write is **scoped to one explicit Magento Store View code** per
  execution context.
- `all` is **NOT** a V1 consequential WRITE target.
- One operation **MUST NOT** silently fan out across all Store Views.
- The first certification target uses the target's **Default Store View**.
- Additional Store Views may later use their **own explicit execution
  contexts**.
- Automatic multi-Store-View fan-out within one V1 run is **OUT**.
- Localized / store-scoped fan-out remains **OUT of first V1** (this preserves
  the existing E12 freeze on localized value fan-out).
- Magento **Website** or **Store Group** names are never REST store codes —
  only the explicit REST store code is identity for the write scope.

Future multi-store extensibility is preserved by routing additional
Store Views through their own explicit execution contexts, not through
implicit multi-context fan-out.

#### DECISION 6 — PHP / Adobe certification matrix (frozen)

The post-#168 certification envelope is:

| Tier | Adobe Commerce | PHP |
|---|---|---|
| **PRIMARY** | 2.4.9 | 8.5 |
| **UPGRADE-COMPATIBILITY ONLY — not a production support claim** | 2.4.9 | 8.4 |
| **PREVIOUS CERTIFIED TARGET** | 2.4.8-p5 | 8.4 |
| **OUT OF V1 CERTIFICATION** | — | 8.3 |

- **2026-08-30 Stop & Amend:** newer Adobe primary-source system requirements
  supersede the former statement that PHP 8.4 was a production-supported Adobe
  Commerce 2.4.9 target. Adobe now lists PHP 8.5 for 2.4.9 production use and
  describes PHP 8.4 as upgrade compatibility only. This label correction does
  not broaden or otherwise change the Safe Sync Composer constraints. Primary
  source: [Adobe Commerce system requirements](https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements)
  (current requirements reviewed for this amendment).
- PHP 8.3 is **OUT of V1 certification** and must not be claimed as a
  supported V1 target.
- The current `integrations/magento-safe-sync/composer.json` constraints remain
  the intentional install envelope delivered by the bounded Safe Sync module
  correction. They are unchanged by this certification-label amendment; an
  installable upgrade-compatibility combination is not a production support
  claim.

#### DECISION 7 — Callback failure semantics (frozen)

Record the post-PR #168 callback failure semantics without introducing a
new applied-state enum:

- The physical COMMIT precedes bridge-owned callback processing.
- A post-COMMIT callback exception **does NOT** downgrade durable
  Product DB state from `KnownApplied`.
- The response remains `KnownApplied` with a **warning**.
- A **warning** means post-commit target maintenance / index / cache state
  may be incomplete and requires explicit evidence / remediation
  semantics (not a separate `KnownAppliedWithWarning` enum).
- Real-target certification must prove `CallbackPool` drain / failure behaviour
  on each certified combination in the Decision 6 matrix.
- The existing applied-state vocabulary remains:
  `KnownApplied` / `KnownNotApplied` / `UnknownOrAmbiguous`. `IdentityMismatch`
  remains a reason beneath `KnownNotApplied` (see the
  [Stage 3E Stop-and-Amend](#stage-3e-stop-and-amend--magento-ownership-and-entity-bound-safe-sync-runtime-contract)
  `Failure semantics` sub-section above).
- The merchant-facing UX contract (see `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md` §17 / §18)
  is **unchanged** for this docs task because the new warning is only reachable on a
  real-target consequential WRITE; Live support is still false and
  merchants do not yet see post-COMMIT warnings.

**Stock reachability (frozen — Step-4 arbitration):**

Record these verified Magento primary-source facts:

- Magento 2.4.9 and 2.4.8-p5 register
  `Magento\Framework\Model\ExecuteCommitCallbacks`
  on `Magento\Framework\DB\Adapter\AdapterInterface`.
- `afterCommit()`, when transaction level reaches zero: `CallbackPool::get`
  `(connection-hash)` drains the pool, callbacks execute, callback exceptions
  are logged / swallowed by Magento and are **not** rethrown.
- `afterRollBack()` clears `CallbackPool` for the same adapter hash.

Therefore:

1. Ordinary stock Magento Product callback exceptions do **NOT** normally
   propagate to `ProductWriteManagement`'s later bridge process call.
2. The production bridge's `KnownApplied` +
   `safe_sync_post_commit_callback_failed` handling remains a defensive
   seam for non-stock / future / explicitly injected bridge-processing
   failure.
3. Real-target Step-4 certification must separately prove:

   a. successful COMMIT: pending callback executes exactly once / no
      duplicate drain;
   b. bridge-owned rollback: pending callback does not execute and cannot
      leak into subsequent work;
   c. bridge post-COMMIT exception handling: a VALIDATION-ONLY fault
      fixture may force the bridge processing seam to throw after durable
      COMMIT; Product durable state stays `KnownApplied` and a warning is
      emitted.

For item (c), state explicitly: this proves the Safe Sync bridge's
defensive exception handling; it is **NOT** a claim that stock Magento
Product callback exceptions naturally propagate to that bridge.

Do **not** change the applied-state enum. Do **not** change production
module code.

#### DECISION 8 — Content staging (frozen — no new semantics)

Preserve the frozen Stage 3E Stop-and-Amend Content Staging rules and
**do not** invent a staged-version warning or new applied-state semantics
in this docs task:

- Logical `entity_id` identity is preserved.
- All relevant physical rows are locked.
- Operational version is resolved through the normal Magento
  repository / resource pipeline.
- No Commerce-only `VersionManager` dependency.
- Real Adobe Commerce evidence with a **pending scheduled update**
  remains mandatory for the Decision 9 step 5 proof.
- Any material staging contradiction discovered on a real target returns to architectural arbitration
  **before** Live is enabled.

#### DECISION 9 — Order of work (frozen pre-Live sequence)

The pre-Live sequence is:

1. **Docs certification amendment** — this PR.
2. **Bounded Safe Sync module correction**:
   - media-neutral Product write (Decision 2);
   - correct connection reset / quarantine (Decision 3);
   - truthful Magento Composer support envelope (Decision 6).
3. **Disposable validation harness** — rebuild of the reverted Part 1
   harness, scoped to the real-target proofs listed in the
   [Stage 3E Stop-and-Amend `Validation harness contract`](#stage-3e-stop-and-amend--magento-ownership-and-entity-bound-safe-sync-runtime-contract)
   sub-section above.
4. **Isolated simple writer certification** on:
   - Adobe Commerce 2.4.9 / PHP 8.5 (PRIMARY);
   - 2.4.9 / PHP 8.4 (UPGRADE-COMPATIBILITY ONLY; not a production support claim);
   - 2.4.8-p5 / PHP 8.4 (PREVIOUS CERTIFIED TARGET).
5. **Content Staging proof** — real Commerce Product with a pending
   scheduled update.
6. **Galera proof** — causal cross-node entity read.
7. **Entity-bound lifecycle** — Status / visibility write proof.
8. **Entity-bound configurable** — options / child link write proof.
9. **Entity-bound media** — primary / gallery write proof.
10. **`ConnectorLiveRuntimeReadiness` integration** with the fresh
    handshake timing rules from the
    [Stage 3E Stop-and-Amend `Account readiness freeze`](#stage-3e-stop-and-amend--magento-ownership-and-entity-bound-safe-sync-runtime-contract)
    sub-section above.
11. **Live consumption** — the Live executor is rewired to the Safe Sync
    write client; SKU-addressed `PUT /V1/products/:sku` is removed from
    the consequential WRITE path.
12. **Final truth-flip gate** — `support = true` is the last step, gated
    on all of the items above passing.

The numbered list is **logical evidence gates**, not a per-item-PR
contract. Items may ship in a single PR or in a small set of PRs as long
as each gate's evidence is real and attributable. **Support remains
false** through every pre-truth-flip slice.

#### Code-vs-docs dormant discrepancies (recorded, not fixed in this docs task)

The following dormant discrepancies exist between current code and the
Stage 3E post-trust Safe Sync primitive requirements. They are
**documented here, not fixed in this docs task** because the relevant
code paths are still **production-unreachable** (Live support is `false`
and the Safe Sync writer is not consumed by the Live executor).

| Discrepancy | Safe Sync primitive requirement | Current code state | Production reachability |
|---|---|---|---|
| Stock SKU-addressed **media** consequential path | Entity-loaded Product / media extension mechanics — not SKU-addressed `GalleryManagement` operations | Stock SKU-addressed media path still exists in the historic Live capability surface | Unreachable: `support = false` and Safe Sync write client is not consumed by Live |
| Stock SKU-addressed **configurable options / child link** path | Entity-loaded parent extension attributes / ID-bound Magento mechanics | Stock SKU-addressed configurable path still exists in the historic Live capability surface | Unreachable: same as above |
| Stock SKU-addressed **lifecycle status** path | Entity-loaded Product + normal repository save | Stock SKU-addressed status / visibility path still exists in the historic Live capability surface | Unreachable: same as above |

These must be **replaced before their respective Live path becomes reachable**
(i.e. before any of Decision 9 steps 7–11 is enabled for that mutation
category). Replacement is module-local and **target-version tested**; the
live-truth-flip gate (Decision 9 step 12) remains the last authorisation
point for the `support = true` flip.

#### Post-#168 status after this docs amendment

| Item | Status |
|---|---|
| Stage 3E docs contract | **Done** — entity-bound Safe Sync runtime contract frozen |
| Stage 3E post-#168 certification amendment | **Done (docs only)** — 9 decisions recorded; no runtime change |
| Stage 3E-R1 internal read foundation | **Implemented (internal; support false)** |
| Trusted simple entity-bound Product WRITE consumption | **Implemented (internal; support false)** |
| Bounded Safe Sync module correction (media-neutral Product write, connection reset / quarantine, narrowed Composer envelope) | **Implemented (internal; not real-target certified)** — Decision 9 step 2 runtime landed; real-target certification remains pending |
| Disposable validation harness | **Implemented (internal; validation-only)** — Decision 9 step 3 landed; no real-target certification executed in this PR |
| Isolated simple writer real-target certification (2.4.9 / 8.5; 2.4.9 / 8.4; 2.4.8-p5 / 8.4) | **Pending** — Decision 9 step 4 |
| Content Staging / Galera / entity-bound lifecycle / configurable / media proofs | **Pending** — Decision 9 steps 5–9 |
| `ConnectorLiveRuntimeReadiness` integration | **Pending** — Decision 9 step 10 |
| Live consumption completion (configurable/media + remove remaining stock SKU-addressed consequential paths) | **Pending** — Decision 9 step 11 |
| Final truth-flip gate / `support = true` | **Pending** — Decision 9 step 12 |
| Adobe Products / Export / Live | **FALSE** |
| Merchant consequential Live | **NOT EXPOSED** |
| Deployment | **NOT PERFORMED** |

No Stage 3D-3. No Stage 3F. No new normative Stage.

#### Magento V1 Moduleless-by-default Stop-and-Amend
[Resolved — Post-#168 / Post-D6 rebaseline — 2026-09-03]

This subsection is a superseding Product / Domain Decision that
**re-baselines the Magento / Adobe Commerce V1 product direction**
without removing, retiring, or invalidating any Stage 3E
entity-bound Safe Sync runtime contract recorded above.

It is **docs-only** and introduces **no migrations, no new tables, no
new enums, no new runtime services, no Composer range change, and no
support truth flip**.

##### Normative product direction (freezes the V1 architecture for the standard connector path)

Standard Magento / Adobe Commerce V1 is **MODULELESS BY DEFAULT**.

For the normal merchant path, the standard connector MUST NOT require
the first-party `B2BPlatform_MagentoSafeSync` Composer component for:

- account connection / OAuth handshake;
- standard Product READ;
- field discovery and Mapping;
- merchant Preview ("Створити пробну синхронізацію");
- normal moduleless Magento V1 operation once the stock REST path is
  certified.

The installed first-party Safe Sync component is no longer a basic
connector prerequisite. Its absence must not be reported as:

- connector failure;
- incomplete Magento setup;
- a blocker of ordinary Layer A / Layer B onboarding;
- a merchant-visible warning on the standard path.

##### Safe Sync becomes an OPTIONAL Enhanced Safety candidate

The entity-bound Safe Sync runtime contract documented in the Stage 3E
Stop-and-Amend section above remains a **legitimate, valuable,
implementation-true primitive** in this repository. It is **not
deprecated, not retired, not deleted, and not refused by this
amendment**.

It is re-classified for the standard product path as:

- **Optional "Enhanced Safety" candidate** — installed and certified
  separately when the merchant requires this enhanced safety
  capability. **Commercial packaging, pricing, and paid tiers are
  explicitly UNDECIDED and out of scope of this rebaseline;** this
  amendment is a technical and architectural re-classification, not a
  product packaging decision;
- a documented differentiator for capabilities the vendor stock API
  cannot provide (for example: in-Magento transactional identity / SKU
  verification, atomic mutation with rollback, and postcondition proof
  on the target);
- **never** a precondition for basic read, mapping, preview, or normal
  Magento V1 operation.

The proven Safe Sync differentiator above is its current architectural
strength. This amendment does **not** claim that this differentiator
already applies to every Magento V1 capability today — stock public
WRITE support remains `false` until Tier-1 real-target certification.

This amendment does **not** claim that the Safe Sync component is
useless. Its code paths, contracts, and entity-bound mutation boundary
remain first-class, durable repository artifacts.

##### Stock public WRITE is direction, not yet support truth

The Magento / Adobe Commerce V1 connector direction is to use the
vendor stock public REST API as the **default runtime** for the
standard path.

Until each individual operation (read, write, list, media, lifecycle,
etc.) has been **separately certified against a real Adobe Commerce
target**, the public connector support truth for any consequential
operation remains `false`. The headline support table
(`Adobe Products / Export / Live | **FALSE**`) is unchanged by this
amendment and is the only authoritative public support claim.

Do not claim that any stock public WRITE path is already certified.
Do not claim that any stock public WRITE path is impossible.
Treat each operation as: **direction = stock public API; support truth
= still pending real-target certification**.

##### Re-scope of Decision 6 (PHP / Adobe certification matrix)

Decision 6's PHP / Adobe certification matrix is **re-scoped** to the
remaining contexts where it is still relevant:

- the current **Safe Sync install / certification envelope** (i.e. the
  Composer constraint of the optional first-party component, when the
  merchant chooses Enhanced Safety);
- any future **optional Enhanced Safety** compatibility / certification
  evidence.

The matrix is **no longer** a normal moduleless connector readiness
gate:

- Standard SaaS-style connectors that talk only to the vendor stock
  public API are not bound by a project-side PHP version, because no
  project code runs inside the merchant's Magento.
- Stock `GET /magento_version` (or equivalent stock evidence) does
  **not** provide exact patch + PHP truth; it must not be used to make
  "upgrade to X" recommendations to a merchant.
- This amendment does **not** widen or relax the current Composer
  compatibility of the first-party Safe Sync component. Any such
  widening requires its own separate, narrowly-scoped decision and
  must not be smuggled in via this docs change.

##### Connection truth (re-statement)

Connection truth and downstream capability / readiness truth are
**separate** evidence dimensions.

For the standard moduleless V1 path:

- proving the connector can talk to Magento is a baseline, not a
  guarantee of any specific operation;
- the absence of an unrelated Product Attribute permission MUST NOT be
  reported to a merchant as a basic Magento connection failure when
  structured evidence proves the credentials reached Magento but the
  specific resource is not permitted;
- a standard, bounded Product read probe is the appropriate baseline
  evidence for "Підключено";
- an HTTP status code by itself is **not** a sufficient classification
  for the merchant-facing presentation of a baseline connection
  outcome.

##### Inventory presence does not mean support

The existing rules stand and are not weakened by this amendment:

- inventory presence in any field or capability manifest does NOT
  prove current connector support;
- completeness is NOT proven by a magic stable-field count;
- each public Adobe field/capability row is still resolved against the
  current Magento V1 Product Field Matrix and the
  `MagentoV1ProductFieldMatrixTest` mechanical contract.

##### Narrow distinction for the current runtime owner

The current runtime still consumes the entity-bound Safe Sync primitive
for trusted simple Product WRITE in some internal seams. That is the
**current runtime truth** and is recorded as such in
`08-CONNECTOR_SYNC_RUNTIME_ATLAS.md`. It is not contradicted by this
amendment, because this amendment describes the **approved target
architecture** for the standard merchant path, not a claim that
moduleless code already exists in production.

This intentionally creates a current-runtime-vs-new-contract gap that
must be closed by a later, separately designed runtime migration. That
later migration is **out of scope** for this docs amendment and is
**not** authorised by it.

##### What this amendment does NOT do

This amendment does not:

- delete, deprecate, or invalidate any Safe Sync code;
- change the entity-bound mutation boundary;
- change the entity-bound read + write boundary on the first-party
  component;
- widen the Composer compatibility range of the first-party component;
- authorise a real Magento mutation, deployment, or support flip;
- introduce migrations, new tables, new enums, or new runtime services;
- claim that stock public WRITE is already certified;
- introduce mandatory in-customer-platform installation for the
  standard connector path.

---

#### Merchant Preview Authorization & Remediation Contract
[Resolved — Stage 2-0]

This section freezes the minimum authorization, setup, temporal, and remediation
contract required before merchant-facing Preview UI is implemented. It resolves
the architecture gap: Preview execution has `run_sync_preview`, Mapping has
independent read/manage permissions, but merchant mutation of
SyncConfiguration-owned setup had no explicit workspace authority.

**Docs-only in Stage 2-0.** Runtime implementation of `manage_sync_configurations`,
the non-mutating existence check, merchant Preview UI, and remediation
presenters landed in **Stage 2A** / **Stage 2B** as sequenced above.

##### Normative ninth workspace permission — `manage_sync_configurations`

| Permission | Authority |
|---|---|
| `manage_sync_configurations` | Merchant-facing mutation of SyncConfiguration-owned Layer-B setup state through approved application/domain services. |

First concrete Stage 2A use: Adobe Products Export → connector execution
configuration → selected Adobe attribute set. The permission is intentionally
connector-neutral; future merchant-visible SyncConfiguration setup mutations may
also require it when separately designed. The permission's existence does not
automatically authorize or expose those future controls.

**Runtime status:** normative target frozen in Stage 2-0; **runtime catalogue
implementation landed in Stage 2A-1** (`manage_sync_configurations` ninth
permission). Current PHP permission catalogue is **nine** permissions. **Stage 2A-2**
shipped the merchant Preview work surface; **Stage 2A is Done.** **Stage 2B**
shipped Option Mapping remediation on `ManageSyncFieldOptionMappings`.

##### Permission independence matrix (frozen)

These authority axes are independent. No permission implies another unless a
future contract explicitly changes the matrix.

| Permission | Owns | Does **not** grant |
|---|---|---|
| `manage_connector_accounts` | Connection setup, credentials, connector account settings, enable/disable/archive, management-only connection checks | SyncConfiguration mutation, Mapping mutation, Preview execution |
| `manage_sync_configurations` | SyncConfiguration-owned Layer-B setup mutations (e.g. connector execution configuration, enabled operations, selection, external context when exposed) | Connector credentials/settings mutation, FieldMapping/FieldOptionMapping mutation, Preview execution |
| `view_sync_mappings` | Read-only Mapping remediation/reference surface | Mapping mutation, SyncConfiguration mutation, Preview execution |
| `manage_sync_mappings` | FieldMapping and child FieldOptionMapping mutation | Unrelated SyncConfiguration setup, connector account management, Preview execution |
| `run_sync_preview` | Entering merchant Preview surface; starting/restarting Preview; safe queued/running/completed Preview result; minimum run-relevant setup **read** projection (§ Safe read vs mutation) | SyncConfiguration mutation, Mapping mutation, connector account management |

Do **not** introduce `view_sync_configurations` merely for symmetry in Stage 2.

FieldOptionMapping uses the same Mapping authority boundary as FieldMapping. Do
**not** introduce `manage_sync_option_mappings` merely for symmetry.

##### Safe read vs mutation under `run_sync_preview`

An actor with `run_sync_preview` may read the minimum merchant-safe run-relevant
setup projection needed to:

- understand what Preview will check;
- understand that required setup is incomplete;
- understand that another authorized user must complete setup.

That does **not** turn Preview permission into generic SyncConfiguration
management access. No sensitive connector-account configuration, credentials,
Layer-C diagnostics, or raw configuration JSON becomes visible through either
`run_sync_preview` or `manage_sync_configurations`.

An actor with `manage_sync_configurations` may read the safe state necessary to
manage those settings.

##### Non-mutating existence check (Stage 2A required scope)

Verified gap: `SyncPreviewConfigurationReadinessPort::isReady(SyncConfiguration
$configuration): bool` takes an already-resolved `SyncConfiguration`. The only
current existence helpers — `SyncConfigurationReachabilityService::
ensureProductsExportConfiguration()` and `AdobeProductExportSetupService::
ensureProductsExportConfiguration()` — **mutate** (create a row and/or enable
Export, persist connector execution configuration).

Stage 2A must add one new, genuinely non-mutating existence/lookup method (exact
name/class implementation-owned) that a `run_sync_preview`-only actor's UI calls
to determine "setup required" vs "setup exists" **without** calling either
`ensure*()` helper.

##### No hidden configuration mutation from Preview

A merchant action authorized only by `run_sync_preview` must **not**:

- create a `SyncConfiguration`;
- enable an operation;
- alter `external_context` or selection;
- persist connector execution configuration;
- choose or auto-save an Adobe attribute set;
- call an `ensure*()` helper whose observable result performs any of those
  mutations.

When setup mutation seams are reached from merchant UI in Stage 2A, the outer
actor-aware boundary must require `manage_sync_configurations`. Prefer an outer
actor-aware authorization/application boundary analogous to
`FieldMappingAuthorizationService` — not authorization buried inside
actor-agnostic domain mutation services unless existing architecture requires
that pattern.

##### Three-layer Adobe attribute-set failure trace (frozen)

1. **Write-time validation** — the approved setup mutation path rejects invalid
   Adobe execution configuration before persistence.
2. **Admission/readiness validation** — any invalid persisted configuration
   that nevertheless exists is rejected fail-closed before a normal Preview
   result (`SyncPreviewAdmissionException::attributeSetUnconfigured()` for
   Adobe V1).
3. **Completed-Preview findings** — structurally valid configuration may still
   prove semantically stale/incompatible during execution (e.g.
   `AttributeSetInvalid` when a valid positive `attribute_set_id` no longer
   exists in the connected Adobe account).

`AttributeSetUnconfigured` (admission/setup) ≠ `AttributeSetInvalid` (completed
Preview finding). `MappedFieldAbsentFromSelectedSet` is a different finding
entirely — do not conflate with `AttributeSetInvalid`.

Do not document write-time validation as proof that malformed persisted state is
impossible. Admission/readiness remains defense in depth.

##### Pre-Preview setup vs completed Preview findings

**A. Pre-Preview setup problem** — run-effective connector execution
configuration absent or invalid at admission. Preview admission fails before a
normal Preview result. Merchant concept: *Потрібно завершити налаштування перед
перевіркою* — not *Товар заблокований*. If the actor has
`manage_sync_configurations`, Stage 2A may offer the setup action; otherwise:
*У вас немає доступу до цієї настройки.* Do not convert admission/setup failure
into fake Product-level `SyncRunItem` findings.

**B. Completed Preview problem** — configuration structurally valid at admission
but semantically stale/incompatible during execution. Legitimate completed Preview
evidence (e.g. `AttributeSetInvalid`) may route to connector setup remediation.

##### Connector metadata read and locking (Stage 2A)

Preserve the existing short-lock architecture verified in
`AdobeProductExportSetupService`: metadata reads happen **before** the locked
transaction, never inside it.

```text
read connector metadata
→ validate proposed choice
→ short DB transaction
→ lock SyncConfiguration
→ persist through existing mutation service
→ commit
```

Do not hold a DB transaction across vendor HTTP work.

##### Preview outcomes remain three-state

Normative vocabulary: `ready` / `warning` / `blocked`. Stage 2-0 does not
change severity assignment.

Current Adobe Products Export implementation classifies all implemented finding
codes as blocking in `AdobeProductExportPreviewPlanner::hasBlockingFinding()` —
current implementation truth, **not** a frozen invariant that Adobe Warning
must always equal 0. Stage 2A UI must correctly render zero warnings today and
remain valid when a future planner legitimately produces warnings. No cosmetic
reclassification merely to populate the warning bucket.

##### Historical finding vs current remediation (frozen)

```text
historical cause
    ← SyncRunItem.findings + run configuration_snapshot

current remediation possibility
    ← current authorization + current destination existence
    + current safe configuration state
```

Historical findings are never rewritten because current data/configuration
changed. Current mutable state must not be used to falsify what the old run
evaluated.

##### Configuration drift vs Product-data freshness

`run.configuration_revision != current.configuration_revision` does **not**
automatically invalidate every finding destination (e.g. toggling
`operational_state` may change revision without touching FieldMapping
correspondence). A revision change proves some configuration-owned state changed;
it does not prove the specific finding's remediation target changed.

For a precise finding: use immutable finding/snapshot identity; compare only the
relevant current destination state; if the target is no longer safely meaningful,
suppress the misleading action and recommend rerun. Merchant copy:
*Налаштування змінилися після цієї перевірки. Запустіть перевірку ще раз.*

`configuration_revision` tracks configuration-owned state only. It does **not**
snapshot or prove freshness of Product/Variant values. Even when revisions match,
an old Preview is not proof that today's Product data is unchanged. Verification
after Product-data change requires an explicit new Preview.

##### Remediation presentation contract (presentation-only; no persistence)

Do **not** persist remediation classification. Do **not** create `SyncIssue`.

**A. Remediation area** (what kind of problem): `PRODUCT_DATA`, `VARIANT_DATA`,
`FIELD_MAPPING`, `OPTION_MAPPING`, `CONNECTOR_SETUP`, `PRICING`.

**B. Current actionability** (what may this actor do now): `ACTION_AVAILABLE`,
`VIEW_ONLY`, `PERMISSION_REQUIRED`, `NO_EDIT_SURFACE`,
`CURRENT_CONFIGURATION_CHANGED`.

A finding may expose more than one remediation destination when multiple real
resolution paths exist. Do not force one false primary "Fix".

##### No fake Product editor

Verified: no Filament/Livewire surface references `ProductFieldValue::` or
`VariantFieldValue::` for editing; `ProductResource` renders zero
FieldBinding-driven dynamic inputs. `NO_EDIT_SURFACE` is the correct, **dominant**
current actionability for most Product/Variant-data findings — not an edge case.

Merchant UI may provide *[Відкрити товар]* for context but must not show
*[Виправити]* unless the destination can actually edit the affected value. Do not
solve this gap by creating a second Product editor. Do not tell the merchant to
fix values in 1C/Magento/another source unless configuration establishes that
authority.

##### Field context and taxonomy

For field-related findings, use the existing Product Field model for presentation:
Product / Variant → `attribute_group` → localized `FieldDefinition` label (e.g.
*Варіант → Характеристики → Колір*). Do not create a Preview-specific Product
field taxonomy. Field taxonomy answers what data is affected; remediation
area/actionability answers where/how the actor can deal with it — orthogonal
dimensions.

### Live Safety, Identity & First-Live Contract
[Resolved — Stage 3-0]

**Docs-only in Stage 3-0.** No runtime implementation, migration, permission row,
`ExternalRecordLink` table, Adobe write, Live support flip, or deploy in this
slice. Normative contract for Stage 3A–3E implementation.

##### Current baseline truth (frozen)

| Stage | Status |
|---|---|
| Stage 1 — Preview Engine | **Done** |
| Stage 2A — Merchant Preview | **Done** |
| Stage 2B — Option Mapping Remediation | **Done** |
| Stage 3-0 — Live Safety, Identity & First-Live Contract | **Done (docs contract)** |
| Stage 3A — Live Safety Foundation | **Done** |
| Stage 3B–3E — Live implementation slices | **Done (internal) through normative Stage 3D** — 3B/3C/3D-1/3D-2 Done (internal); Stage 3E **Done (docs contract)** — entity-bound Safe Sync runtime contract frozen; runtime + validation **pending** |
| Production Live | **NOT IMPLEMENTED** |

**Current runtime (reverified post–Stage 3A):**

- `SyncRunMode` already contains Preview + Live;
- `SyncRun` / `SyncRunItem` persistence already exists;
- `ConnectorSyncOperationSupport` is mode-aware;
- Adobe Products / Export / Preview is supported;
- Adobe Products / Export / Live is **not** supported;
- `run_sync_live` exists in the workspace permission catalogue (**ten** permissions);
- `ExternalRecordLink` persistence exists (no Adobe reconciliation/use yet);
- Live admission + fail-closed job shell exist; no consequential Adobe Product writer exists;
- stale active-run lease/recovery exists for `sync_runs`;
- `ProductExecutionAggregateBuilder` currently belongs to the Preview namespace but
  contains semantically reusable Product execution input;
- Preview planner may emit an in-memory `connectorPlan` — that plan is **not** a
  Live HTTP command plan;
- one active queued/running run per `SyncConfiguration` already exists.

**Historical pre–Stage 3A baseline (Stage 3-0 contract freeze):**

- catalogue remained **nine** permissions until Stage 3A;
- `run_sync_live` did not exist yet;
- `ExternalRecordLink` did not exist yet;
- no stale active-run recovery existed.

##### Preview → Manual Live trust

A first manual Live requires a relevant Preview that:

- belongs to the same `SyncConfiguration`;
- is Products / Export / Preview / `Completed`;
- was created from **current** `configuration_revision`.

Do **not** require arbitrary Preview-age TTL, Product-wide revision, or zero
blocked Product rows. A Completed current-revision Preview may contain
warning/blocked Products. Blocked Products do not globally prevent Live for other
safely exportable Products.

The historical Preview `connectorPlan` must **not** be executed. At Live
execution:

1. consume the admitted **current** configuration snapshot;
2. obtain current Adobe execution metadata required for validation;
3. rebuild Product execution aggregates from fresh Product/Variant/Price state;
4. evaluate the shared Adobe semantic planning truth again;
5. compile consequential commands only from that fresh evaluation;
6. currently blocked Products receive Live `NOT_APPLIED` with zero write.

Configuration-owned changes (`FieldMapping`, `FieldOptionMapping`, connector
execution setup / attribute set) change `configuration_revision` and require a
new Preview before first Live. No arbitrary "Preview older than N minutes" rule.

Merchant copy must state: current Product data will be checked again immediately
before transfer.

##### Shared Adobe semantic truth

`AdobeProductExportPreviewPlan` is **not** a Live write script. Known Preview
limitations include: configurable parent lacks external parent SKU; `child_link`
lacks concrete parent/child external identities; no create/update decision; no
`ExternalRecordLink` decision; no reconciliation decision; no inactive lifecycle
command; no media command; current operation order is not a proven Adobe mutation
sequence.

Freeze one semantic source of truth:

```text
Product execution input
        ↓
Adobe semantic classification/projection
    ├── Preview findings/presentation
    └── Live command compilation
```

Preview and Live must **not** independently redefine: simple vs configurable
classification; mapped-field projection; Option Mapping interpretation;
configurable dimensions; required-data semantics; status/visibility semantics.

The Live command compiler owns: concrete Adobe external identity; GET/POST/PUT
decision; external request ordering; Adobe request payload; `ExternalRecordLink`
use; reconciliation semantics.

Do not persist raw request payloads in Preview findings or merchant Livewire
state. Do not implement one shared mutating `execute(..., dryRun=true)` path.

##### Create / update / reconciliation

**Superseded for stock Magento no-link path by Stage 3E Stop-and-Amend.** Without
trusted `ExternalRecordLink`, **no consequential Product mutation** under stock
Magento (no POST, no blind PUT, no adoption). See **Stage 3E Stop-and-Amend —
Magento ownership and entity-bound Safe Sync runtime contract**.

**Historical [Resolved] no-link create (invalidated):** the prior contract assumed
GET known-missing + POST + reconciliation could prove create ownership. Magento
primary-source research proved stock `POST /V1/products` is upsert-like and cannot
prove connector-created identity.

**Link-first (frozen):** merchant-confirmed linking establishes ENTITY TRUST with
fresh entity-bound read during confirmation, informed confirmation, and persisted
discriminator provenance (follow-on runtime). Consequential mutations execute only
through the first-party Magento entity-bound Safe Sync component — not stock
SKU-addressed REST.

**Existing trusted link:** entity-bound load by stored logical `entity_id` → verify
expected SKU equality → mutate loaded entity only → entity-bound postcondition →
exact SKU unchanged; divergence rolls back and fails closed, never `KnownApplied`.
**Never** use body Product `id` as safety mechanism. After trust exists, stock SKU
GET must not prove verification/reconciliation/applied state.

Do not assume HTTP status alone universally proves applied state. Do not
automatically retry a whole Product after a consequential attempt. POST/PUT may
be resent only when connector-specific evidence proves prior attempt
`KNOWN_NOT_APPLIED`. Do not copy Discovery/ConnectionCheck `retryUntil`/`release`
semantics to writes.

##### Configurable Product execution

Adobe V1 must support normal platform configurable/multi-variant Product. Current
Preview operation ordering is **not** frozen as Live ordering. Expected dependency
shape: reconcile/resolve child identities → create/update child simple records →
reconcile/resolve deterministic parent identity → create/update parent → ensure
configurable option definitions → ensure child links → verify/reconcile as
required. Real Adobe validation is required for final command order. No Adobe
bulk/async write transport in V1 — synchronous Adobe REST through existing
connector queue runtime.

##### First-Live merchant UX

Smallest first-Live surface: `ManageAdobeProductsExportPreview`. Do not turn
Integrations into an execution console.

##### Merchant consequential Live admission gates (frozen — Stage 3-0)

Merchant consequential Live admission/exposure requires **all** relevant gates:

- fresh `run_sync_live` authorization;
- relevant Completed current-revision Preview on the same `SyncConfiguration`
  (`products` / `export` / Preview / `Completed`);
- current configuration readiness;
- fresh `ConnectorLiveRuntimeReadiness` (account-specific remote prerequisite);
- `ConnectorSyncOperationSupport(Products, Export, Live) === true`.

This gate list is the first Products/Export Live gate list. Manual Receive
Import admission is governed by the Receive / Import Foundation Contract
clarification and does **not** require Export Preview evidence.

`ConnectorSyncOperationSupport` is static software capability.
`ConnectorLiveRuntimeReadiness` is fresh account-specific remote prerequisite.
Cached handshake is presentation-only; Start Live fresh handshake occurs outside DB
admission transaction; worker fresh handshake immediately before first consequential
write after writer lease/deadline with DB-fresh `SyncRunConsequentialWriteGate`
recheck. Do **not** persist handshake evidence into `SyncRunItem`.

`run_sync_live` means **authority**. It does **not** mean the connector/runtime
currently **supports** Live. A valid Preview prerequisite means
**trust/readiness prerequisite**. It does **not** make unsupported Live
executable.

Stage 3D may implement the Live UI/read model and internal action surface while
support remains **false**, but the merchant Live action must remain
**non-actionable** / **non-publicly-exposed** for consequential execution until
Stage 3E flips truthful Live support. No Stage 3D code may bypass
`ConnectorSyncOperationSupport`. Merchant actionable exposure happens only after
successful Stage 3E real-Adobe validation and truthful Live support flip.

Merchant confirmation concept (only when **all** gates above are satisfied):

> Передати товари в Adobe Commerce?
>
> Це реальна дія — дані будуть передані у ваш магазин. Перед передачею ми ще
> раз перевіримо актуальні дані товарів.

Preview summary may guide but must **not** be described as the frozen payload
Live will send. If Preview contains blocked Products, explain that products still
not ready during the fresh Live check will not be changed externally.

Running state: honest queued/running; optional processed Product count from
persisted outcomes; no fake percentage; no summary-counter table solely for
progress.

Completed summary vocabulary: *Синхронізовано* / *Не передано* / *Частково
синхронізовано* / *Не вдалося підтвердити*. `AMBIGUOUS` copy concept: *Не
вдалося підтвердити результат для N товарів. Не повторюйте передачу, доки їхній
стан не буде перевірено.*

Do **not** expose: `ExternalRecordLink`, idempotency, reconciliation, transport
attempts, HTTP codes, Adobe entity IDs, raw payloads, connector internals.
Preview permission never implies Live authority. No request-access subsystem.

##### Selective retry is out

"Retry failed only" is explicitly **out** of Stage 3 V1. Current run snapshot
freezes `selection.mode = all_products`. V1 recovery: remediate/verify → Preview
when required → new all-products Live execution → `ExternalRecordLink` +
reconciliation prevents blind duplicate create.

##### Live support truth

Current Adobe support truth remains:

- Products / Export / Preview = **true**
- Products / Export / Live = **false**

Keep Live **false** through internal implementation slices 3A–3D. Flip to **true**
only when advertised V1 is coherent for: simple + configurable Products;
`ExternalRecordLink`; safe create/update/reconciliation; inactive lifecycle (E13);
required E14 image behavior; partial/ambiguous Product outcomes; stale-run
safety; `run_sync_live` authorization; merchant first-Live UX; real Adobe
validation (Stage 3E).

##### Real Adobe validation gate

Before support truth flips, an explicitly authorized disposable Adobe smoke
validation harness must prove actual target-version behavior on **linked** Products
(not stock no-link create; auto-create OUT of V1). At minimum: SIMPLE linked
(entity-bound verify/update/reconcile/disable/rerun); CONFIGURABLE linked family
(child/parent/options/link/update/repeated-safe); TRANSPORT (validation rejection,
identity collision, divergence before/after mutation, relevant status codes,
timeout classification); entity-bound Safe Sync component proof for every advertised
V1 consequential operation category including Content Staging scheduled version,
Galera causal-current read, CallbackPool cleanup, silent SKU suffix rollback,
repository create-fallback guard, and the abort scenarios explicitly defined in
the
[`Certification abort disambiguation`](#certification-abort-disambiguation--frozen--step-4-arbitration)
subsection above (worker termination around COMMIT, DB session loss around
COMMIT, and transport loss around COMMIT).

Use disposable `B2BVAL-*` test identities. Require explicit human authorization.
No production catalogue writes. No destructive Product DELETE for cleanup —
disable / disposable identity policy. Harness requirements documented in Stage 3E
Stop-and-Amend; Part 1 harness runtime was reverted.

##### Transport contract

Reuse existing transport chain: OAuth1 → `ConnectorOutboundRequest` →
SSRF-safe destination resolution → `ConnectorRequestSender` → capped response →
`ConnectorHttpResult`. No second HTTP stack. Before Live, consequential-write
transport needs POST/PUT construction, GET Product reconciliation, bounded
outbound request body, Product-resource-specific status/result mapping,
timeout/applied-state classification suitable for reconciliation, merchant-safe
exception normalization. Discovery/ConnectionCheck retry semantics do not
authorize Live write retries.

##### Explicit non-goals (Stage 3)

Scheduling; generic Sync History product; SyncIssue subsystem; selective retry;
Product-ID subset execution; workflow canvas; execution graph; transport log UI;
raw request/response UI; Adobe bulk/async APIs; destructive Adobe Product DELETE;
Product-wide revision; Preview TTL; generic Product parent SKU; Magento role enum
in Product core; universal polymorphic external identity framework; automatic
consequential-write replay.

### Canonical mapping registry role

`docs/data/canonical_product_field_mappings.csv` is **platform-global
knowledge**. It is not workspace mapping state.

Its role may include, as supported by current repository contract:

- high-confidence suggestions;
- requirement/evidence knowledge;
- known mapping recommendations;
- known transformation recommendations where applicable.

It is **not**:

- an account schema;
- a complete external field catalog;
- a substitute for live account discovery;
- merchant-confirmed workspace mapping;
- a reason to pre-author one row for every account-specific custom attribute.

Preserve three distinct layers:

1. **Platform-global canonical knowledge** — what the SaaS generally
   knows/recommends about a channel/platform.
2. **Account discovery reality** — what logical fields actually exist in this
   particular connected account.
3. **Workspace effective FieldMapping** — what semantic correspondence this
   merchant/configuration has confirmed.

Do not merge these concepts. Do not create a second competing global
default-mapping registry.

Verification aid only (reverified against current `origin/develop` HEAD; counts
may grow without changing the architectural conclusion):

- `canonical_product_field_mappings.csv`: 35 rows total
  (`adobe_commerce` 6, `google_merchant` 13, `rozetka` 1, `schema_org` 10,
  `shopify` 5). Of the 6 `adobe_commerce` rows, 5 have
  `requirement_level = undecided`.
- Current Magento discovery fixture: 106 received attributes / 102 normalized
  snapshot fields.

Conclusion: the canonical registry is sparse platform knowledge, not a complete
external schema.

### Connector transport boundary

FieldMapping is not an execution plan. Generic sync core must **not** prescribe
a universal execution loop such as “for each record / for each mapping /
writeField(...)”.

The connector/runtime boundary owns two complementary responsibilities:

1. **Plan** — semantic sync intent → connector-specific external operations.
2. **Interpret** — connector-specific external operation results → normalized
   semantic/business outcomes understood by the generic core.

Generic sync core must permit connector-planned operations whose execution
scope, request structure, and cardinality are not constrained to one business
record or one mapped field. Verified Magento/Adobe examples include inline
record mutation, separate resource operations, and cross-record batch
operations. These are examples only — not a closed taxonomy, required enum,
required three-method interface, or transport DSL to freeze now.

Even sibling operations inside one external business domain may differ in
supported operations, request shape, response shape, and batching semantics
(first-party Adobe pricing APIs demonstrate this). Generic sync core must not
infer external CRUD symmetry or success/failure semantics from generic response
shapes. Connector/runtime interpretation owns those external-contract
semantics.

### SyncRun, revision, and outcome cardinality

`SyncRun` belongs directly to `SyncConfiguration`.

Each SyncRun records the stable comparable SyncConfiguration revision against
which that run was evaluated/executed. This applies to preview runs and live
runs.

#### Configuration revision invariant

Normative invariant:

> SyncRun records the SyncConfiguration revision it executed against.

This is required so readiness can be **derived** rather than persisted.

Example:

- current configuration revision = 12, last relevant successful preview
  revision = 11 → current configuration has not been successfully previewed
  after its latest change;
- both = 12 → preview corresponds to the current configuration state.

Do not prescribe integer/hash/revision storage implementation here. Require
only: stable comparable configuration revision + revision recorded on each
SyncRun.

#### Three cardinalities remain distinct

A. **Transport operation** — one connector/external operation/request.
B. **Semantic operation unit** — one intended external semantic mutation/read
   unit, possibly finer-grained than a product/business record.
C. **Business-record outcome** — merchant/business execution result represented
   by `SyncRunItem`.

`SyncRunItem` represents business-record outcome. It must never be defined as
one HTTP request, one connector batch, or one transport attempt.

#### Result semantics

A business record may conceptually end in states including: succeeded; known
failure; known partial application/result; ambiguous/unknown applied state;
skipped/not attempted where applicable.

Do not freeze the exact persisted enum yet. Unknown applied state is **not**
equivalent to known failure. A mutating transport failure can occur after the
external server may have applied some/all effects. Blind retry of an ambiguous
mutation is safe only when the **specific** external operation has proven
appropriate idempotency/retry semantics. Retry/idempotency safety is
operation-specific, not connector-wide.

### Preview vs Live

Preview and Live are distinct result semantics.

**Preview:**

- performs no consequential external mutation;
- predicts/readies what would happen;
- may produce blockers/warnings/exclusions/predicted outcomes;
- cannot be “partially applied”;
- cannot have “unknown applied state”;
- must never imply that an external write actually occurred.

**Live:**

- performs actual external execution;
- records what actually happened;
- may contain success, known failure, partial application,
  unknown/ambiguous applied state, or skipped/not-attempted where applicable.

Do not define one flat semantic outcome vocabulary pretending preview/live are
the same. They may share technical run infrastructure where appropriate. Exact
persistence representation is not prescribed here. If preview runs are
persisted for reproducibility/audit, they must **not** automatically appear to
merchants as completed synchronizations. Whether preview runs are visible in
merchant history is a Product Owner decision.

### Run selection semantics

Distinguish explicitly:

A. **Outside run selection** — record does not belong to this run's
   query/filter/selection scope → no SyncRunItem is required merely to say it
   was unselected.
B. **Inside run scope, intentionally not executed** → skipped / not_attempted
   semantics may apply.
C. **Inside run scope and evaluated/executed** → predicted outcome for Preview;
   actual outcome for Live.

Do not classify every unselected catalog record as skipped. Do not create huge
result histories merely for records that never belonged to the run scope.

### Historical issues vs current issues

Historical execution outcome and current unresolved merchant problem are
different concepts. SyncRun / SyncRunItem history is immutable historical
evidence.

Do **not** claim:

- current issues = non-success rows from the latest live run
  (incremental/delta runs may not reevaluate every selected record);
- stable issue identity alone is sufficient to derive current issue state.

#### Stable normalized issue identity

Minimum historical issue semantics require a stable normalized identity based
conceptually on:

```text
stable issue kind/code
+ semantic subject
```

within the relevant business-record + configuration context.

`category + subject` is insufficient: one GTIN subject may independently have
missing value, invalid format, duplicate, or external rejection. Category is
presentation/classification and must not be the sole stable discriminator.
Final code enums/DB fields are not prescribed here.

#### Evaluation coverage

Historical outcomes must preserve enough evaluation-coverage semantics to
distinguish:

A. issue absent because reevaluated and clean;
B. issue absent because that subject was not evaluated in this run.

Do not prescribe the persistence shape now. Do not claim a final current-issue
derivation algorithm until full-vs-delta run semantics and evaluation coverage
are defined.

#### Persistent SyncIssue

Persistent `SyncIssue` remains **deferred**. Do not introduce it merely to
mirror historical error rows. Reconsider persistent current-issue projection
only when a real requirement appears (acknowledge, snooze, assignment, durable
workflow state, manual issue management), or when run/evaluation semantics prove
a persistent projection materially simpler and safer than derivation.

### ExternalRecordLink

`ExternalRecordLink` remains **account-scoped**, not SyncConfiguration-scoped.

Conceptually:

```text
one internal business record
    ↔
its corresponding external record
```

within workspace + ConnectorAccount + external object/domain context as
required.

Do not bind external identity to one particular SyncConfiguration. Multiple
sync operations/config concerns against the same connected external account
must not create divergent identities for the same external object.

Initial matching policy (SKU, GTIN, explicit/manual, other) is separate from
the persisted identity link. Do not conflate how a match is initially found
with the identity relationship persisted after matching.

### Readiness state — derive, do not persist stale flags

Do not persist a readiness chain such as `mapping_incomplete`, `preview_ok`,
`schedule_eligible`, `ready_for_sync` when the value can become stale
immediately after mapping, selection, operation, external-context, or other
configuration revision.

Prefer deriving readiness from:

- current SyncConfiguration state;
- validation state;
- current configuration revision;
- relevant Preview/Live run state;
- configuration revision recorded on that run.

Persist only state that cannot be reliably reconstructed. The
`SyncRun.configuration_revision` invariant above is required for this
derivation.

### Connector profile / runtime-contract variance

Current repository baseline (reverified):

- `ConnectorProfile` is the existing extension point and currently couples
  account setup + runtime adapter 1:1
  (example: `adobe_commerce_paas_oauth1_integration` →
  `AdobePaaSAccountSchema` → `AdobePaaSConnectorAdapter`).
- `ConnectorProfileRegistry::resolveAccountSetupProfile()` requires exactly one
  enabled AccountSetup-capable profile per ConnectorDefinition and fails on
  ambiguity.

Do not change that invariant in this documentation pass.

External runtime-contract variance belongs at the connection / profile /
runtime-contract boundary. It must not leak into FieldMapping,
SyncConfiguration semantic mapping, mapping suggestion identity, snapshot field
identity, or generic SyncRun semantics. Generic sync operates against an
already-valid ConnectorAccount and asks the connector boundary to execute
supported semantic operations. It must not contain Magento-specific logic such
as “is this PaaS / on-prem / Open Source?”

Deferred connector-specific verification before a second real runtime variant
(not a blocker for this Sync domain rebaseline):

- what external contract the existing AdobePaaS profile actually intends to
  cover according to current repo docs/config/tests;
- whether it is intentionally PaaS-only or represents a broader traditional
  Adobe/Magento REST-family implementation;
- exact authoritative post-bootstrap mechanism for verifying supported runtime
  contract/version/capabilities;
- Magento Open Source setup/auth compatibility;
- whether AccountSetup and final runtime contract remain one profile or later
  require separate resolution/binding;
- whether existing exactly-one AccountSetup-profile invariant must ever change.

Do not add generic fields for symmetry such as `edition`, `deployment_model`,
or `api_family` to generic sync domain entities. Do not make optional external
preflight metadata (e.g. `/magento_version`) a mandatory bootstrap dependency,
and do not invent an authoritative REST/GraphQL metadata endpoint.

### Superseded concepts

| Earlier concept | Current normative status |
|---|---|
| `ImportJob` / `ExportJob` / `SyncJob` as primary sync entities | Superseded by `SyncConfiguration` → `SyncRun` → `SyncRunItem` |
| FieldMapping as directional execution plan with mandatory bidirectional transformation | Superseded: semantic correspondence only; transformation operation-aware if needed |
| FieldMapping.direction / per-field authority as mandatory minimum sync state | Superseded for the minimum model; domain-level ownership default remains a Product Owner question if bidirectional ships |
| Persisted readiness flags (`preview_ok`, `schedule_eligible`, …) | Superseded by derived readiness from configuration revision + run revision |
| Persistent `SyncIssue` as default current-issue store | Deferred |

Spreadsheet/file import may still use a specialized import flow later; it must
not redefine the connector sync domain relationship above.

### Open Product Owner questions (maximum 5)

Technical architecture that repository evidence can determine is not listed
here.

1. **MVP data domains** — products only; products + prices; products +
   inventory; or another first slice?
2. **External-context exposure in MVP** — for systems with multiple external
   contexts (e.g. Magento websites/store views): one implicit/default context,
   or merchant-configurable independent contexts?
3. **Ownership/authority default if bidirectional ships** — at
   domain/configuration level, what product default should apply (example
   merchant wording: “Де ви хочете керувати цінами?”)? Do not introduce
   mandatory per-field authority before this product need exists.
4. **Preview history visibility** — are Preview runs visible in merchant
   history, or technically retained for audit while hidden from normal
   “completed synchronizations” history?
5. **Simple schedule UX** — what schedule controls are appropriate for a
   non-technical SMB merchant (prefer simple presets unless an existing
   approved product rule already settles this)?

## Billing Context


Billing and subscription are important for SaaS but not central to the first product domain model.

For now, Billing should be treated as a separate future context.

It may later include:

- subscription plan;

- usage limits;

- invoices;

- payment provider for platform subscription;

- feature access.

For MVP, access may be controlled through simple workspace plan flags or middleware.

Billing must not pollute product, order, attribute, pricing, payment or availability logic.

### Domain Services


Some business logic should live in domain services instead of being scattered across controllers.

Initial domain services may include:

- ProductCreator

- DefaultVariantCreator

- AttributeValueWriter

- PriceResolver

- AvailabilityResolver

- B2BPublicationChecker

- B2BCatalogueProjector

- B2BStorefrontPresenter

- OrderCreator

- OrderSnapshotBuilder

- StockWarningEvaluator

- PaymentRequestCreator

- PaymentWebhookHandler

- FieldMappingResolver

- ImportHeaderNormalizer

These services should express business meaning.

They should not become generic utility dumping grounds.

### Product Creation Flow


The simplest product creation flow should be:

- User enters product name.

- Platform creates Product.

- Platform assigns default Product Type.

- Platform creates default ProductVariant.

- Platform generates internal SKU or internal identifier if needed.

- Product appears immediately in the product table.

- User may enrich product data later.

The user should feel that product creation is instant and simple.

The architecture quietly prepares the deeper structure.

### B2B Publication Flow


B2B publication should check only what is required for B2B.

Minimum checks:

- product is active;

- product has product name;

- variant has price or pricing mode;

- variant has availability or availability mode.

Images and descriptions may be recommended but should not block publication by default.

The UI should not show constant readiness noise.

Readiness should appear only when the user is trying to publish, export or fix something.

### B2B Storefront Flow


The basic B2B storefront flow should be:

- User imports or creates products.

- Platform creates products and default variants.

- User organizes products into workspace categories.

- User enables B2B channel.

- Platform creates a customer-facing storefront URL.

- Customer opens the storefront.

- Customer browses categories.

- Customer switches between grid, list or table view if enabled.

- Customer searches, sorts or filters products.

- Customer adds product variants to cart.

- Customer submits order.

- Platform creates order and order item snapshots.

- Platform sends notification.

- Future: customer may pay through hosted gateway payment.

The storefront must remain a sales channel over product data.

It must not become a separate e-commerce CMS.

### Order Creation Flow


A B2B order creation flow should be:

- Customer opens B2B catalogue.

- Platform resolves visible products.

- Platform resolves customer price.

- Platform resolves availability.

- Customer adds variants to cart.

- Customer submits order.

- Platform creates order.

- Platform creates order items with snapshots.

- Platform evaluates stock warnings.

- Platform sets initial order status and payment status.

- Platform sends notification.

- If payment is enabled, order may receive payment status awaiting_payment.

- If connector is enabled, order may be queued for external sync.

Order creation must not depend on external systems being available.

If ERP sync fails, the order should still exist in the platform with sync status failed.

### Data Ownership Rules


The platform must follow clear ownership rules.

Workspace owns business data.

Product owns product identity.

Variant owns sellable SKU-level data.

Field Definition owns field meaning *(renamed from "Attribute Definition"; see Field Foundation)*.

Field Binding owns what entity a field is attached to and how it is stored *(new — see Field Foundation)*.

ProductFieldValue, VariantFieldValue, and CustomerFieldValue own dynamic field values, each scoped to its own entity type via FieldBinding *(renamed from ProductAttributeValue/VariantAttributeValue; CustomerFieldValue is new)*.

Price List owns pricing context.

Price List Item owns variant price inside a price list.

Customer owns customer identity and pricing access.

B2B Channel owns customer-facing catalogue and storefront configuration.

Order owns submitted business transaction.

Order Item owns historical line snapshots.

Payment owns payment attempt or transaction.

Connector owns external system configuration / connection.

SyncConfiguration owns domain/context sync intent, enabled semantic
operations, selection, schedule state, effective FieldMappings, and
configuration revision.

FieldMapping owns semantic correspondence between an internal domain target
and an external logical identity for a SyncConfiguration.

SyncRun / SyncRunItem own historical preview/live execution evidence for a
SyncConfiguration revision.

ExternalRecordLink owns account-scoped internal↔external record identity.

Billing owns SaaS subscription logic.

### MVP Domain Scope


The MVP domain model should include:

- Workspace;

- User;

- WorkspaceUser;

- Product;

- ProductVariant with hidden default variant;

- ProductType with hidden default Basic Product;

- Category tree inside workspace;

- FieldDefinition / FieldBinding *(renamed from AttributeDefinition; see Field
  Dictionary Context above)*;

- ProductFieldValue / VariantFieldValue *(renamed from ProductAttributeValue /
  VariantAttributeValue)* / CustomerFieldValue *(new — cross-object scope)*;

- MediaAsset / primary image;

- Customer;

- CustomerGroup;

- PriceList;

- PriceListItem or simple ProductPrice;

- cached variant prices;

- cached variant availability;

- basic inventory / availability records where needed;

- B2BChannel;

- B2B storefront settings;

- B2B display modes as configuration;

- B2B visibility settings;

- Order;

- OrderItem with snapshots;

- order status;

- payment status field;

- optional Payment placeholder;

- ConnectorDefinition;

- ConnectorAccount;

- SyncConfiguration;

- FieldMapping;

- SyncRun / SyncRunItem;

- ExternalRecordLink.

Historical note: earlier drafts listed `ImportJob` / `ExportJob` / `SyncJob`
here. Those names are superseded by the Sync Domain Rebaseline above and are
not current MVP sync entities.

The MVP should not include:

- database-per-tenant;

- global marketplace taxonomy;

- advanced product type builder;

- complex variant UI;

- complex price engine;

- full WMS and multi-warehouse logistics routing;

- full warehouse management;

- accounting;

- full payment gateway UI;

- full billing system;

- advanced workflow engine;

- marketplace connector complexity;

- full DAM system;

- website builder features;

- theme builder;

- blog/CMS pages;

- platform-wide marketplace search.

### Recommended Table Direction


The implementation may use names similar to the following.

Workspace and users:

- workspaces

- users

- workspace_users

- roles

- permissions

Catalogue:

- products

- product_variants

- product_types

- categories

- media_assets

- product_media

- variant_media

Fields *(renamed from "Attributes")*:

- field_definitions *(renamed from attribute_definitions)*

- field_bindings *(new)*

- workspace_import_aliases

- product_field_values *(renamed from product_attribute_values)*

- variant_field_values *(renamed from variant_attribute_values)*

- customer_field_values *(new)*

Pricing:

- price_lists

- price_list_items

- customer_groups

- pricing_rules

Availability:

- inventory_records

- inventory_reservations

B2B:

- b2b_channels

- b2b_visibility_rules later or simplified settings in MVP

Customers:

- customers

- customer_contacts later

Orders:

- orders

- order_items

Payments:

- payments

- payment_gateway_accounts later

Connectors:

- connector_definitions

- connector_accounts

- field_mappings

- import_jobs

- export_jobs

- sync_jobs

Billing later:

- plans

- subscriptions

- usage_records

This table direction is not the final migration plan.

It defines the domain shape.

Exact migrations should be written during implementation.

## Domain Decisions


The following section records domain-level decisions. Items marked **Resolved** are closed and must not be reopened without a documentation-level decision. Items without **Resolved** remain open and must be finalized before the relevant implementation starts.

### Company vs Workspace naming

**Resolved.**

The technical SaaS boundary is `workspace_id`.

The database table name is `workspaces`.

The code model name is `Workspace`.

The user-facing UI term is `Company` or `My Company`.

The term `tenant` must not be used in the ordinary user interface.

This decision is closed and must not be reopened without a documentation-level decision.

### Attribute value storage

**Resolved — superseded in naming only, see note.**

The platform uses separate isolated tables per bound entity type — this
constraint itself is **not reopened**:

- `product_field_values` *(renamed from `product_attribute_values`)* for product-level dynamic fields;
- `variant_field_values` *(renamed from `variant_attribute_values`)* for variant-level dynamic fields;
- `customer_field_values` *(new)* for customer-level dynamic fields.

A unified polymorphic value table across entity types is strictly forbidden by the Storage Split Mandate in `04-ARCHITECTURE_PRINCIPLES.md`. This section is retained to preserve the historical decision and its rationale; for full current field/table definitions, see "Field Dictionary Context" above and "Field Foundation (cross-object fields)" below.

This decision is closed and must not be reopened without a documentation-level decision.

### Attribute storage model

**Resolved — superseded in naming only, see note.**

The platform uses a hybrid field storage model:

- System/core operational fields (name, brand, category, sku, gtin, status, cost_price,
  etc. on Product/Variant; name, tax_number, credit_limit on Customer) remain column-backed or
  relation-backed, for indexing, sorting and FK integrity.
- Dynamic/custom/tenant-specific fields are stored in `product_field_values` /
  `variant_field_values` / `customer_field_values`.
- Every field, regardless of storage location, is registered in `field_definitions` with one or
  more `field_bindings`, each tracking its own `storage_type` (`column | relation | dynamic`) and,
  for column/relation bindings, its `storage_path`.
- `computed` is a `data_type`, never a `storage_type`; computed fields have no physical
  persistence (see Computed Fields Operational Boundary), and in MVP are limited to
  system-defined read-only fields — merchants cannot create custom computed fields.
- Dynamic value tables store only `value_text`, `value_num`, `value_jsonb`. Boolean values use
  `value_num` (0/1) with an explicit convention; date values use `value_text` in ISO-8601 or
  `value_jsonb`. Adding dedicated `value_boolean` / `value_date` columns requires a separate,
  explicit documentation-level decision.

This section's substance is unchanged from the original decision; only entity names and table
names are updated to match "Field Foundation (cross-object fields)" below, which is the
canonical source for the full rationale (Option C vs A/B) and for cross-object rules not
present in the original Product/Variant-only version of this decision.

This decision is closed and must not be reopened without a documentation-level decision.

### Workspace_id minimum rollout scope for Product Fields Foundation

**Resolved.** *(Table names below updated to Field Foundation naming; the
historical migration order and rationale are unchanged.)*

The combined Workspace Foundation Lite + Product Fields Foundation implementation task must add
`workspace_id` to, at minimum:

- `products`
- `product_variants`
- `categories`
- `field_definitions` *(renamed from `attribute_definitions`)*
- `product_field_values` *(renamed from `product_attribute_values`)*
- `variant_field_values` *(renamed from `variant_attribute_values`)*
- `workspace_import_aliases`

Any new tables created by the Field Foundation migration (`field_bindings`,
`customer_field_values`) must include `workspace_id` from their first
migration, per this same rule — not as an afterthought.

Migration order: create `workspaces` → create default workspace → add nullable
`workspace_id` to `products` / `product_variants` / `categories` → backfill existing rows to the
default workspace → make `workspace_id` not-nullable where safe → create the new Product Fields
tables with `workspace_id` present from their first migration.

Tables not listed above (`orders`, `contractors`/`customers`, `prices`, `stocks`, `reservations`,
`sync_logs`) remain explicitly out of scope for this task and stay tracked under GAP-004 as
separate backlog items. This task must not silently skip them nor silently include them.

This decision is closed and must not be reopened without a documentation-level decision.

### System Attribute seed scope for Product Fields Foundation

**Resolved.** *(Table/entity names below updated to Field Foundation naming;
the historical seed decisions — which system field maps to which column — are
unchanged.)*

The initial `field_definitions` seed for Product Fields Foundation (Phase 1) registers only
System Attributes whose storage is verified stable on `develop` today and whose storage path
does not contradict the documented object_type/binding.

Product-level Phase 1 seed:

- `internal_product_id` — storage_path: `products.id`, data_type: number. Note: 02 describes
  this attribute as a UUID; the current implementation uses a Laravel auto-increment integer
  primary key, not a UUID. This mismatch is documented here and does not block Phase 1; it may
  be revisited separately.
- `name` — storage_path: `products.name` (shared FieldDefinition with Customer binding)
- `brand` — storage_path: `products.brand`
- `category` — storage_type: relation, storage_path: `products.category_id`
- `description` — storage_path: `products.description`
- `status` — storage_path: `products.is_active`; interim convention:
  `is_active=true → active`, `is_active=false → archived`; `draft` is not distinguishable until
  a real product lifecycle status field exists.
- `url` — storage_path: `products.url` (added via a dedicated migration after
  the base `products` table was created; column renamed per DEC-008).

Variant-level Phase 1 seed:

- `sku` — storage_path: `product_variants.sku`; canonical. The duplicate `products.sku` column
  is legacy and is not used as a storage path; tracked as backlog technical debt.
- `gtin` — storage_path: `product_variants.barcode_ean`; canonical. The duplicate
  `products.barcode_ean` column is legacy and is not used as a storage path; tracked as backlog
  technical debt.

Explicitly excluded from Phase 1 seed, with no placeholder record created:

- `price`, `sale_price`, `cost_price` — deferred to Pricing MVP Foundation (GAP-001). `price`
  and `cost_price` are jointly required by the `margin_percentage` computed field and must be
  resolved together, with the correct `FieldBinding.object_type` (product vs variant), once
  PriceResolver-backed storage exists.
  `cost_price` currently physically exists only on `products` (added via a dedicated later
  migration), while 02 classifies it as belonging to the `product_variant` object type — this
  mismatch is intentionally not resolved by registering it prematurely.
- `availability` — deferred to Availability Foundation (GAP-002).
- `image` — deferred. Current `products.images` (JSON) is product-level legacy storage; 02
  classifies `image` as belonging to the `product_variant` object type. Registering it now would
  lock in an object_type mismatch. Deferred until product/variant media storage is explicitly
  resolved.
- `unit` — deferred. Current `products.unit` is product-level; 02 classifies `unit` as belonging
  to the `product_variant` object type. Same class of mismatch as `image`; deferred until
  explicitly resolved.
- `condition` — deferred. No physical storage column exists for `condition` anywhere in the
  current schema (verified: absent from both `products` and `product_variants`).

*(Note: "02 classifies X as belonging to the `product_variant` object type" reflects the
Field Foundation renaming of 02-ATTRIBUTE_DICTIONARY.md's former "Variant-Level" terminology —
see that document's Assignment Level Rules section.)*

Existing `products` columns not covered above (`barcode_box`,
`min_order_quantity`, `order_step`, `package_quantity`, `package_type`, `units_per_box`,
`boxes_per_pallet`, `lead_time_days`, `net_weight`, `gross_weight`, `volume_m3`, `depth_mm`,
`width_mm`, `height_mm`, `synced_at`) are intentionally out of scope for Phase 1 and are
registered in a later Phase 2 pass — this is an explicit, documented scope boundary, not an
oversight.

`products.onec_guid` and `product_variants.onec_guid` are legacy 1C connector identity
columns, not deferred System Fields and not FieldMapping. They must not be promoted into
FieldDefinition, ConnectorMapping, or generic Product/System Attributes. Canonical
classification is `external_identity` with semantic owner ExternalRecordLink (runtime still
absent). See DEC-011 and GAP-007.

`rozetka_category_id` is connector-specific leakage (GAP-007). `meta_title` and
`meta_description` are platform SEO Product concepts that happen to be physical columns;
they are not equivalent connector leakage.

The pre-existing `product_variants.attributes` JSON column (cast as `array` on the
`ProductVariant` model) is a legacy ad-hoc dynamic-attribute mechanism. The Product Fields
Foundation implementation task must first inspect which keys actually occur in production data
and produce a migration plan. Actual migration of this data into `variant_field_values` is
included in the same implementation task only if the discovered keys are simple and safely
mappable; otherwise it becomes a separate, explicitly scoped follow-up task. This documentation
patch does not perform any data migration itself.

This decision is closed and must not be reopened without a documentation-level decision.

### JSONB localization

**Resolved.**

All attributes with `is_localizable = true` store values as JSONB translation objects.

Flat string overwrites are strictly prohibited.

The MVP UI shows the primary workspace language only.

Dedicated translation tables are a future migration path after architecture review.

This decision is closed and must not be reopened without a documentation-level decision.

### Price resolver priority

**Resolved.**

The PriceResolver must evaluate prices in the following priority order:

1. Customer-specific PricingRule, if configured for the individual customer;
2. CustomerGroup PricingRule or discount, if configured for the customer's assigned CustomerGroup;
3. PriceList explicitly assigned to the customer;
4. PriceList assigned through the customer's CustomerGroup;
5. Default workspace PriceList where is_default = true;
6. Cached variant base price on ProductVariant as a final fallback.

Within a PriceList, PriceListItem tier resolution must select the highest valid quantity_min that is less than or equal to the requested quantity, while respecting status, valid_from and valid_until.

The highest-priority applicable rule wins.

This priority is closed and must not be changed without a documentation-level decision.

### Availability source of truth

**Resolved.**

For MVP, the operational availability read source for storefront and checkout flows is `available_quantity_cache` on `ProductVariant`.

`available_quantity_cache` is maintained through controlled inventory update flows and `InventoryRecord` entries.

- `available_quantity_cache` is the fast read path for storefront, catalogue projection and checkout evaluation.
- `InventoryRecord` is the append-only ledger for stock movements such as manual adjustment, bulk import, connector sync and order allocation.
- `AvailabilityResolver` calculates net sellable stock by subtracting active unexpired `InventoryReservation` rows from `available_quantity_cache`.
- External connector sync must update availability through the inventory update flow and `InventoryRecord`; connectors must not bypass the availability domain by writing directly to the cache column.
- Multi-warehouse and multi-location stock are excluded from MVP.

This decision is closed and must not be changed without a documentation-level decision.

### Reservation policy

**Resolved.**

Minimal TTL-based soft reservation via `InventoryReservation` is required from MVP to prevent overbooking during checkout, order submission and payment-awaiting flow.

This is an internal technical safeguard, not a user-facing WMS feature.

User-facing WMS, multi-warehouse logistics and administrative reservation screens remain excluded from MVP.

`InventoryReservation`, `expires_at` TTL and the `AvailabilityResolver` net stock formula are mandatory MVP architecture.

The UI must expose only simple stock warnings and order attention flags.

TTL, reservation engine and stock-locking terminology must never appear in merchant-facing screens.

This decision is closed and must not be reopened without a documentation-level decision.

### Reservation and stock-mutation write atomicity

**Resolved.**

Any operation that reads current stock/availability in order to decide whether a reservation
can be created, and then writes that reservation, must do so as a single atomic unit:

- Wrapped in `DB::transaction()`.
- The relevant `ProductVariant` / stock row must be locked with `lockForUpdate()` for the
  duration of the check-then-write.
- Deadlock retry must be used (Laravel's built-in `DB::transaction($closure, $attempts)`
  parameter), not a hand-rolled retry loop.
- When more than one row must be locked in the same transaction (e.g. variant + an existing
  reservation row), rows must be locked in a single, consistent order (e.g. always by primary
  key ascending) to avoid deadlocks between concurrent transactions locking the same rows in
  different orders.

This responsibility is split cleanly between two kinds of components:

- **Resolvers are read-only display/query services.** `AvailabilityResolver` and
  `PriceResolver` never mutate state. Their normal public read methods are safe for catalogue,
  admin, and storefront display, but their result must **not** be used as the final authority
  for a write operation (e.g. "resolver said 3 available, so create the reservation") unless the
  writer service has already opened the transaction and acquired the required row locks first.
- **Writers own the lock and the write-safe calculation.** A dedicated `ReservationCreator`
  (and, symmetrically, `ReservationConfirmer` / `ReservationReleaser`) is the only code path
  allowed to create, confirm, or expire a reservation. Each of these performs its own final
  availability check *inside* the same transaction that holds the row lock and writes the
  reservation — it does not trust a value read earlier by `AvailabilityResolver` outside that
  transaction.
- **No controller, Livewire component, or Filament action may mutate stock or reservation
  quantities directly.** All such mutations go through the writer services above.

This decision is closed and must not be reopened without a documentation-level decision.

### Availability Foundation — mapping existing code to the documented model

**Resolved.**

`app/Models/Stock.php` and `app/Models/Reservation.php` already exist on `develop` and are
close to, but not identical to, the entities documented above. Availability Foundation
(implementation task) evolves them rather than replacing them from scratch:

- `Stock` (`variant_id`, `warehouse_name`, `quantity`, `reserved`, `expected_date`,
  `expected_quantity`) becomes the source that populates `available_quantity_cache` on
  `ProductVariant`. `expected_date` / `expected_quantity` are kept as-is — they already serve
  the "очікується поставка" (incoming stock) need identified separately, and map directly to
  merchant-facing delivery-date display without needing any new field.
- `Reservation` (`contractor_id`, `variant_id`, `quantity`, `status`, `expires_at`) is already
  structurally equivalent to the documented `InventoryReservation` — including the TTL field.
  It requires, at minimum: `workspace_id` (per the same rollout pattern used in Product Fields
  Foundation), and `order_id` / `order_item_id` (nullable) to link a reservation to the order it
  protects, per the documented `InventoryReservation` shape. The existing table/model name
  (`Reservation`, not `InventoryReservation`) may be kept as-is; this document's use of
  "InventoryReservation" refers to the concept, not a mandated class/table rename.
- **`Stock.reserved` must not remain a second, independent source of reservation truth once
  `InventoryReservation` (i.e. the evolved `Reservation` model) is active.** Availability
  Foundation must either deprecate `Stock.reserved`, treat it as a derived/cache field
  maintained only by the reservation writer services (never updated independently elsewhere),
  or explicitly migrate away from it. Net availability must never subtract both
  `Stock.reserved` and active `InventoryReservation` rows in the same calculation — that would
  double-count reserved quantity and under-report real availability.
- `InventoryRecord` (the append-only stock movement ledger) does not exist yet and must be
  created new in Availability Foundation — there is no existing model to evolve for this one.
- `AvailabilityResolver` does not exist as a formal service class yet and must be created new,
  implementing the documented net-availability formula.
- `AdminAvailabilityPresenter` may remain as an admin/UI presentation adapter — it does not need
  to be deleted. It must no longer calculate availability directly from
  `stocks.quantity - stocks.reserved` itself; instead it delegates the actual net-availability
  calculation to `AvailabilityResolver`, then formats the result into merchant-facing
  labels/badges. This keeps the working badge-rendering UI code while ensuring there is exactly
  one place where the real calculation happens.

This decision is closed and must not be reopened without a documentation-level decision.

### Pricing Foundation — mapping existing code to the documented model

**Resolved.**

`app/Models/Price.php` already exists on `develop` and already carries meaningful pricing logic
(`contractor_id`, `variant_id`, `price`, `price_with_vat`, `vat_rate`,
`recommended_retail_price`, `min_quantity`, `currency`) — this is not a from-scratch build.
However, unlike the Availability mapping above, `Price` must **not** simply be renamed into
`PriceListItem` and kept contractor-bound as the primary architecture. The documented model
requires an intermediate `PriceList` grouping so that pricing scales to new customers without
manual per-customer row configuration:

- Existing `Price` rows migrate into `PriceListItem` rows that belong to a customer-specific or
  workspace-default `PriceList` — the `PriceList` / assignment layer is the primary structure
  going forward, not a compatibility shim bolted onto direct `contractor_id` pricing.
- `min_quantity` on the existing `Price` model maps directly onto `PriceListItem.quantity_min` —
  this existing field is not wasted, it becomes the tier threshold field.
- `recommended_retail_price` (РРЦ) is an informational/reference price shown to the customer for
  context (e.g. to help them see their own resale potential). It is never treated as the
  resolved sale price, and it is never derived from or mixed into `PriceResolver`'s output. This
  follows the general commerce principle that a recommended/reference price and an actual
  transactional price are different concepts serving different purposes, and must not share a
  calculation path.
- `PriceResolver` priority order remains exactly as already Resolved elsewhere in this document
  (customer-specific rule → customer group rule → assigned price list → default workspace price
  list → cached variant fallback) — this patch does not change that order, only clarifies how
  existing data maps onto it.
- No promotions, cart-level rules, multi-year contracts, or channel-stacked pricing are in MVP
  scope for Pricing Foundation. `PriceListItem.sale_price` (already documented) covers simple
  time-boxed promotional pricing; nothing more elaborate is needed yet.
- Existing `Price` data must not be deleted or dropped during Pricing Foundation until migration
  counts, resolver output, and representative before/after examples are verified and explicitly
  reported — the same safe-migration discipline already used for the legacy
  `product_variants.attributes` migration in Product Fields Foundation.

This decision is closed and must not be reopened without a documentation-level decision.

### Reference price fields on ProductVariant

**Resolved.**

`recommended_retail_price` (РРЦ) and a cached base price are variant-level reference data, not
per-price-list or per-customer data — a manufacturer's suggested retail price does not logically
vary by which customer is asking. `ProductVariant` gains:

- `recommended_retail_price_cache` (Decimal, nullable): reference/informational price shown to
  customers for context. Never treated as the resolved sale price.
- `base_price_cache` (Decimal, nullable): the final fallback tier of the documented
  `PriceResolver` priority, used only when no `PriceListItem` matches for either the customer's
  assigned list or the workspace default list.

`PriceListItem` does not carry `recommended_retail_price`. If a future need arises for RRP to
vary by price list, that is a separate, explicit documentation-level decision.

This decision is closed and must not be reopened without a documentation-level decision.

### VAT handling in PriceListItem

**Resolved.**

`PriceListItem.price` is a net/base price, VAT-exclusive. `PriceListItem.vat_rate` (Decimal,
nullable — null means "use `Workspace.default_vat_rate`"; `config('pricing.default_vat_rate')`
is used only to seed a new workspace's initial value via `Workspace::creating()`, never as a
runtime fallback for resolving an individual price — see `App\Services\Pricing\WorkspaceTaxDefaults`,
closed via PR #63) is added to the documented
schema. Gross/VAT-inclusive price is always a computed display value
(`price * (1 + vat_rate/100)`), never a stored column. `PriceResolver`'s output (`ResolvedPrice`)
includes `regular_net_price`, `sale_price` (nullable), `effective_net_price`, `vat_rate`,
`gross_price`, `currency`, and `source`. `effective_net_price` is the actual net price used for
charge/display calculations: `PriceListItem.sale_price` overrides `PriceListItem.price` when
present; otherwise the regular tier price is used.

This decision is closed and must not be reopened without a documentation-level decision.

### Effective MVP priority order (steps 3, 5, 6 of the documented 6-level priority)

**Resolved.**

Because `CustomerGroup` and `PricingRule` are deferred (see GAP-010), Pricing MVP Foundation
implements a 3-step subset of the documented 6-level `PriceResolver` priority:

1. Contractor's assigned `PriceList` (via `Contractor.default_price_list_id`) → matching
   `PriceListItem` (highest `quantity_min` ≤ requested quantity, respecting `valid_from`/
   `valid_until`/`status`).
2. Workspace default `PriceList` (`is_default = true`) → matching `PriceListItem` tier.
3. `ProductVariant.base_price_cache` fallback.

Steps 1, 2, 4 (customer-specific `PricingRule`, `CustomerGroup` rule, `CustomerGroup`-assigned
list) activate later without requiring `PriceResolver`'s structure to change.

This decision is closed and must not be reopened without a documentation-level decision.

### Exactly one default PriceList per workspace

**Resolved.**

Exactly one `PriceList` per workspace may have `is_default = true`. This must be enforced at the
database level, not left to application discipline — the same category of bug that caused two
production incidents in Availability Foundation (MySQL-specific NULL/uniqueness and ENUM
ordering behavior not caught by SQLite-based tests). A plain `unique(workspace_id, is_default)`
index does not work in MySQL, since it would also limit `is_default = false` rows to one per
workspace. The implementation must use a MySQL-safe technique (e.g. a generated column that
only takes a real value when `is_default` is true, indexed uniquely) — this is an implementation
detail for Cursor to get right and test against MySQL specifically, not something to leave
ungoverned. `PriceResolver` must throw a clear domain exception if it finds zero or more than
one active default list for a workspace, rather than silently picking one.

This decision is closed and must not be reopened without a documentation-level decision.

### InventoryReservation status vocabulary

**Resolved.**

Canonical reservation statuses are:

- `pending` — active soft reservation / temporary hold, counted against net availability while
  not expired.
- `confirmed` — reservation was converted into a permanent stock deduction.
- `cancelled` — reservation was explicitly released because the order/cart/manual process was
  cancelled.
- `expired` — reservation was released automatically after TTL.

`pending`, not `active`, is the canonical name for an active soft hold — this document's earlier
use of "active" (and the pre-existing `ReservationStatus::Active` enum case in code) is renamed
to `pending` as part of this task, not kept as a parallel synonym.

Availability calculations count only:
`status = pending AND (expires_at IS NULL OR expires_at > now())`.

`cancelled` and `expired` are distinct end states: `cancelled` means explicit release,
`expired` means TTL-based automatic release. Both existed informally in code before this
decision; `cancelled` is retained as a genuinely distinct, useful state, not merged into
`expired`.

This decision is closed and must not be reopened without a documentation-level decision.

### Location-ready inventory foundation

**Resolved.**

Full WMS, warehouse routing, location-specific checkout allocation, and merchant-facing
multi-location management remain excluded from MVP (per the existing "Multi-warehouse and
multi-location stock are excluded from MVP" decision elsewhere in this document).

However, Availability Foundation introduces an internal `inventory_locations` entity — not a
narrower "Warehouse" entity — so that future showroom, retail-store, and pickup-point scenarios
don't require a second migration later. This follows the same pattern as established commerce
platforms (e.g. Shopify's "Location" concept: "any physical place where you sell products,
fulfill orders, or stock inventory" — deliberately not limited to warehouses).

In MVP:

- `stocks` are linked to `inventory_locations` via `inventory_location_id`, replacing the
  previous free-text `warehouse_name` column.
- Existing `stocks.warehouse_name` values are migrated into `inventory_locations.name` records
  (one location row per distinct existing name).
- `available_quantity_cache` on `ProductVariant` remains a variant-level aggregate across all
  locations — `AvailabilityResolver` returns aggregate variant availability, not per-location
  availability.
- `InventoryReservation` (the `reservations` table) remains variant-level and does not allocate
  a specific location.
- `InventoryRecord` may store `inventory_location_id` (nullable) and `location_name_snapshot`
  (nullable, historical label as it was named at the time of the event — not a live lookup) for
  audit purposes, in addition to the fields already documented (`source_type`,
  `source_reference_id`, `quantity_change`, `resulting_quantity`, `reason`).
- Merchant-facing UI must not expose WMS terminology, location-routing logic, or any new
  location-selection screens.
- Pickup-point selection, per-location checkout allocation, per-location reservation, and
  location-aware delivery rules are explicitly future, separate work — not part of this task.

This decision is closed and must not be reopened without a documentation-level decision.

### Existing integer primary keys for ProductVariant / Order references

**Resolved.**

Although earlier domain-model field descriptions used UUID language generically for
`product_variant_id`, `order_id`, and `order_item_id` (in the `PriceListItem`,
`InventoryReservation`, and Orders Context sections), the current application schema on
`develop` uses Laravel default bigint auto-increment IDs for `product_variants.id`, `orders.id`,
and `order_items.id`.

Availability Foundation (and any future Pricing Foundation work referencing the same columns)
therefore uses bigint foreign keys for:
- `inventory_records.product_variant_id`
- `reservations.variant_id`
- `reservations.order_id`
- `reservations.order_item_id`

Only `workspace_id` and `inventory_location_id` are UUID foreign keys in this and future
Availability/Pricing work.

This is an implementation-alignment decision, not permission to convert existing core
`ProductVariant`/`Order`/`OrderItem` IDs to UUID — a future global UUID migration, if ever
needed, would be its own explicit, separate architecture decision.

This decision is closed and must not be reopened without a documentation-level decision.

### B2B storefront MVP depth

**Resolved.**

The MVP B2B storefront domain must support:

- category navigation;

- search;

- sorting;

- table view;

- grid/card view;

- cart;

- order submission.

The MVP must not include:

- website themes;

- full page builder;

- CMS pages;

- blog;

- marketplace-style seller discovery;

- advanced storefront customization.

These capabilities belong to B2BChannel settings and storefront presentation rules.

They must not create a separate product database or turn the platform into a CMS or website builder.

This decision is closed and must not be changed without a documentation-level decision.

### Product classification model — Merchant Category / Standard Category / Merchant Type / Tags

**Resolved.**

**Naming note, checked against this document's existing content:** this document already has a
`### Product Type` section describing `ProductType` as an internal template controlling which
fields are shown/recommended/required for a product's structure (hidden in MVP, default "Basic
Product"). **The new concept introduced here is deliberately named `Merchant Type`, not
`Type`, to avoid colliding with that existing, unrelated concept.** `Merchant Type` does not
control fields, variants, required attributes, readiness rules, or attribute suggestions —
that remains `ProductType`'s role, unchanged by this patch.

Based on how Shopify's Standard Product Taxonomy, Google's Product Taxonomy, Magento's
Attribute Sets, and commercetools' Product Types all converge on the same pattern, product
classification eventually involves **four** distinct, independently-purposed concepts — not a
replacement of what already exists, but an addition alongside it:

- **Merchant/Catalogue Category** (`categories`, already exists, unchanged): the existing
  workspace-owned navigation tree. Per the already-Resolved "Category" and "B2B storefront
  category" decisions elsewhere in this document, this remains workspace-owned, and the
  platform continues to not require a global taxonomy for storefront navigation in MVP. **This
  patch does not change that decision.**
- **Standard Category** (new concept, not yet implemented, not required for MVP): a
  standardized taxonomy node (Google Product Taxonomy / Shopify's open-source Standard Product
  Taxonomy — both freely available, ~10,000 categories), used for *readiness/export/attribute-
  suggestion* purposes only — not storefront navigation. This is what unlocks category-specific
  attribute suggestions and near-zero-effort mapping to Google Shopping / Meta / Bing / Pinterest
  exports later. Per the existing "no global taxonomy in MVP" decision, this is **not built now**
  — it is tracked as a future concept (see GAP-011) that will eventually sit *alongside*
  Merchant/Catalogue Category, not replace it, and will most naturally live in the
  connector/channel-mapping layer already anticipated for marketplace taxonomy mapping (GAP-006),
  not as a change to the core `categories` table.
- **Merchant Type** (new, free-form, optional — inspired by Shopify's custom "product type"
  field, distinct from this document's existing `ProductType` template concept as explained
  above): an unstructured internal label a merchant can set for their own organization, with no
  taxonomy backing and no attribute-unlocking behavior. Suggested future storage name:
  `products.merchant_type` or `products.custom_type` — deliberately not a generic `type` column,
  to keep it unambiguous in code as well as in docs.
- **Tags** (new, free-form, optional, multiple per product): the loosest layer, for filtering/
  collections on top of Merchant/Catalogue Category — never a substitute for it.

**When Standard Category is eventually built** (not now), it becomes mandatory for product
readiness/channel-export/publishing flows specifically — not for draft-product existence, and
not a replacement for Merchant/Catalogue Category's storefront-navigation role.

This is a planning decision, not yet implemented — see **GAP-011** for the `Merchant Type`/`Tags`
schema task (ready to implement now) and the Standard Category concept (tracked, deferred,
connects to GAP-006's connector/channel-mapping layer when built).

This decision is closed and must not be reopened without a documentation-level decision. It
does not reopen, override, or contradict the existing "Categories are workspace-owned" / "no
global taxonomy in MVP" decisions, nor the existing `ProductType` template concept — it adds
new, separate concepts alongside them.

### Payment implementation timing

**Resolved.**

The domain model includes `Payment` as a future-ready concept.

The MVP does not include full payment gateway UI unless online payment becomes a commercial priority before MVP release.

Payment gateway integration must be added later as a separate feature without changing the order model.

This decision is closed and must not be reopened without a documentation-level decision.

### Payment status automation

**Resolved.**

Payment updates `payment_status` only.

Any resulting change to `order_status` is determined exclusively by `payment_triggers_json` inside `WorkspaceOrderStatusMatrix`.

Hardcoded controller-level status changes triggered by payment events are strictly forbidden.

This decision is closed and must not be reopened without a documentation-level decision.

### Field Foundation (cross-object fields)

**Resolved.**

The Attribute Dictionary described in `02-ATTRIBUTE_DICTIONARY.md` and the previous
`AttributeDefinition` / `ProductAttributeValue` / `VariantAttributeValue` model were
built for the Product/Variant domain only (see GAP-003, closed for that original
scope). Extending the same governance to `Customer` (and, in the future, other
entities such as Order or Supplier) requires a cross-object foundation. This is
new scope, not a reopening of the "Attribute storage model" decision above.

**Chosen architecture — Option C (shared field registry, separate typed value
storage), rejecting both Option A (generalize `AttributeDefinition` via an
`entity_type` column) and Option B (a fully separate, parallel
`CustomerAttributeDefinition` mechanism).**

For the full, current field lists of `FieldDefinition`, `FieldBinding`, and the
three `*_field_values` tables — including `workspace_id` placement, the
"one binding = one object_type" rule replacing `value_level`, and the exact
value-table structure — see **"Field Dictionary Context"** earlier in this
document. This section does not repeat those definitions; it records the
decision rationale, what was rejected and why, and the sequencing.

**Why not A:** a single `AttributeDefinition.value_level` enum (`product` /
`variant` / `both`) has no natural value for `customer` bindings (no
variant-equivalent concept exists) — it would force either an unnatural enum
extension or an ignored/null field on every Customer-scoped definition.

**Why not B:** a fully separate `CustomerAttributeDefinition` mechanism
duplicates the anti-duplication wizard, import alias engine, and validation
logic. At the first additional entity (Order, Supplier), this becomes N
near-identical, independently-drifting mechanisms instead of one shared
registry.

**Evidence used, honestly scoped:**
- Shopify's `MetafieldDefinition.ownerType` confirms **object-scoped field
  definitions** as a real, shipped pattern — but Shopify itself uses one
  definition entity with an owner-type field, not a separate
  `FieldDefinition`/`FieldBinding` table split. The two-table split is this
  platform's own architectural choice (for value-table type-safety in
  Postgres/Laravel), not a literal copy of Shopify's implementation.
- HubSpot's Properties UI (one page, object selector) and Data Sync field
  mappings (`direction`, "Always use X" conflict rule) are useful product UX
  evidence for bidirectional ownership questions — but they do **not** force
  mandatory per-field `direction`/`authority` persistence onto this platform's
  minimum FieldMapping model. Persistent per-record/field override workflow
  (earlier draft name `FieldSyncOverride`) remains deferred until a verified
  product requirement exists; it is not an externally mandated entity.

**Sync ownership is explicitly out of scope for the field registry itself** —
`FieldDefinition`/`FieldBinding` must never know about 1C, Odoo, CSV, or any
other external system, per Mandate 7 (Connector Independence). Synchronization
concerns are modeled by the Sync Domain Rebaseline (above) and sequenced with
Connector Foundation (GAP-006).

Current normative sync entities (minimum):

- `SyncConfiguration` — account + data_domain + external_context; owns enabled
  semantic operations, selection, schedule state, effective mappings, and
  stable configuration revision.
- `FieldMapping` — direction-neutral semantic correspondence
  (`internal target` ↔ `external logical identity`) owned by
  SyncConfiguration. For Field Foundation-backed targets, reference
  `field_binding_id` (not a bare field code). Minimum FieldMapping does **not**
  require mandatory `direction`, per-field `authority`, snapshot-field FK
  identity, schema-source namespace, or one bidirectional transformation.
- `SyncRun` / `SyncRunItem` — historical preview/live execution evidence for a
  SyncConfiguration revision; SyncRunItem is business-record outcome, not a
  transport attempt.
- `ExternalRecordLink` — account-scoped external record id ↔ internal
  Product/Customer/PriceList id, used for safe upsert instead of fuzzy/
  name-based matching. Not SyncConfiguration-scoped.

Historical / deferred (not minimum sync domain):

- Earlier draft placed mandatory `FieldMapping.direction` /
  `FieldMapping.authority` and a `FieldSyncOverride` entity here. Per-field
  authority and per-record/field override workflow remain **deferred** until a
  verified product requirement exists. Domain-level ownership wording for
  bidirectional sync remains a Product Owner question (see Sync Domain
  Rebaseline open questions). Do not treat those earlier draft fields as
  current mandatory persistence.

**UI direction (Resolved as part of the same decision):** a single settings
area, not one sidebar item per entity type:

```
Налаштування → Поля → [ Товари ] [ Клієнти ]
```

New tabs (Orders, Suppliers, ...) are added only when a real feature for that
entity type exists — not preemptively. Connector/sync field mapping lives on
merchant sync/data-management surfaces (not inside the field registry UI). Exact
navigation/IA may remain transitional; `Інтеграції` remains the connection-
management entry and must not become the technical sync builder. The `scope`
column's UI label changes from "Джерело" to **"Походження поля"** (values:
"Системне" / "З бібліотеки" / "Власне поле") so it does not collide with the
distinct concept of sync source (Вручну / Odoo / 1С / CSV / Google Sheets /
API).

**Sequencing (Resolved):**

1. Contractor → Customer terminology/auth migration (model, table, FK, Filament
   resource, `config/auth.php` guard/provider, routes, services, tests — see
   Customers Context above and GAP-017).
2. Field Foundation migration itself (`FieldDefinition`/`FieldBinding`, three
   value tables, `workspace_import_aliases.field_binding_id` — see GAP-016).
3. Customer Fields UI (`Налаштування → Поля → [Клієнти]`).
4. Connector Foundation (GAP-006) — Sync Domain (`SyncConfiguration`,
   `FieldMapping`, `SyncRun` / `SyncRunItem`, `ExternalRecordLink`) built
   against `field_binding_id` for Field Foundation-backed internal targets
   from the start, not against the old `attribute_definition_id` shape.

**Workspace isolation note:** the full workspace-isolation coverage audit
tracked under GAP-004 is a **separate task and does not block** steps 1–4
above. It is a prerequisite only for onboarding a second workspace, not for
this migration. However, every new table created in step 2 (`field_definitions`,
`field_bindings`, `customer_field_values`) must still include `workspace_id`
from its first migration and be covered by a cross-workspace-leakage test for
that specific new table — that is a normal part of building the table
correctly, not the same thing as the full GAP-004 audit.

This decision is closed and must not be reopened without a documentation-level decision. It
does not reopen, and is not blocked by, the "Attribute storage model" / "unified
polymorphic table forbidden" decision above — it extends the same discipline
to a new entity.

### Connector scope (Resolved)

Task 4B-2a's first production profile is Adobe Commerce PaaS/on-prem, using
OAuth 1.0a integration credentials (`adobe_commerce_paas_oauth1_integration`).
Adobe Commerce as a Cloud Service (IMS/SaaS) remains a separate, later follow-up
until its required discovery capability and endpoint contract are verified
(see Task 4B-2-0 runtime proposal). The generic connector core remains
deployment-family- and vendor-extensible — this is a starting profile, not a
hardcoded assumption elsewhere in the domain model.

Excel/CSV, Google Sheets, and ERP/1C import remain plausible *future* connector
targets but are not scheduled ahead of Adobe.

**Decision authority:** project-owner approval dated 2026-07-22, carried into
the repository by this docs-only Stop-and-Amend task. Existing Adobe-oriented
schema and prototype work are supporting technical context, not the source of
approval by themselves.

Connector sync work must use the Sync Domain Rebaseline and the Field
Foundation registry (`FieldDefinition` / `FieldBinding`) from the beginning —
see "Sync Domain Rebaseline", "Field Foundation (cross-object fields)" above,
and GAP-006. For Field Foundation-backed internal targets, `FieldMapping` must
reference `field_binding_id`, not a bare field code, since the same external
column name can be ambiguous across entity types (e.g. Product vs Customer).

### Billing scope


Billing is a future context.

The MVP may use simple workspace plan flags.

Full subscription billing should not block product, B2B and order MVP.

## Final Principle


The domain model must make the platform powerful without making the product feel complicated.

The user should be able to create a product with one name.

The system should quietly create the workspace-scoped product, default product type, default variant and clean internal structure.

The user should be able to publish a B2B storefront, receive an order and process it without understanding the internal model.

A small merchant who previously worked only with Google Sheets should be able to get a focused product sales space without building a separate website, using a marketplace or competing with other sellers inside the platform.

The architecture must support future growth without forcing enterprise complexity into the first user experience.

### CustomerGroup


A CustomerGroup groups customers for pricing and visibility.

- retail

- wholesale

- VIP

- distributor

- partner

A customer group may be connected to a price list, a discount rule, B2B visibility rules, and an access mode. For MVP, customer groups may remain simple.

### PricingRule


A PricingRule represents a pricing adjustment layered on top of the resolved PriceListItem tier (customer discount, customer group discount, fixed customer price, margin-based adjustment, future quantity-based rule). The MVP does not need a complex pricing engine, but pricing logic must remain isolated inside the PriceResolver service rather than scattered across controllers. The result of price resolution should be stored as a snapshot in order items.
