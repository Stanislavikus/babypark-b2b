# Task 4B-2-0 Runtime Decisions — Proposal

- **Status:** Reviewed; approved decisions promoted to core documents
- **Normative authority:** core documents only — this file is now a historical
  record of the research, not a source of truth
- **Created:** 2026-07-22
- **Applies to:** proposed Task 4B-2a–4B-2d runtime implementation
- **Source of truth:** this file is not a Resolved project document and must not
  override `03-DOMAIN_MODEL.md`, `04-ARCHITECTURE_PRINCIPLES.md`,
  `05-AI_WORKING_AGREEMENT.md`, `06-UI_DESIGN_SYSTEM.md`, or
  `07-TECH_STACK.md`.
- **Application code status:** blocked until the approved decisions are patched
  into the named core documents and merged.

---

## Evidence legend

| Label | Meaning |
|---|---|
| **officially documented fact** | Stated in a primary vendor/protocol/framework source cited below |
| **repository fact** | Confirmed by reading this repository in the 4B-2-0 session |
| **reasoned recommendation** | Proposed design choice with options and rationale |
| **open option requiring approval** | Genuinely unresolved; human must choose |

---

## B1 — Deployment-family capabilities and port shape

### Current project constraint

**repository fact:** Task 4A/4B-0/4B-1 assume Adobe as the first connector profile.
`ConnectorSchemaSource` rows reference `/V1/products/attributes` for live account
discovery on PaaS. The generic core must remain deployment-family- and
vendor-extensible (`03-DOMAIN_MODEL.md`, Connector scope (Resolved)).

**officially documented fact:** Adobe Commerce PaaS/on-prem REST uses OAuth 1.0a
integration credentials and store-scoped URLs such as
`https://{host}/rest/{store_code}/V1/...` (Adobe, *OAuth-based authentication*,
accessed 2026-07-22,
https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-oauth).

**officially documented fact:** Adobe Commerce as a Cloud Service (SaaS) uses IMS
OAuth 2.0 bearer tokens, omits `/rest/{store}` from URLs, and specifies store
scope via the `Store` HTTP header instead (Adobe, *REST API Overview*, accessed
2026-07-22, https://developer.adobe.com/commerce/webapi/rest/).

**officially documented fact:** SaaS endpoint availability differs from PaaS; the
REST overview explicitly states endpoints available on SaaS differ from on-prem/Cloud
deployments (same source).

### Primary-source evidence

| Source | URL | Accessed | Fact supported |
|---|---|---|---|
| Adobe REST API Overview | https://developer.adobe.com/commerce/webapi/rest/ | 2026-07-22 | PaaS vs SaaS auth and URL shape differ; SaaS uses `Store` header |
| Adobe OAuth-based authentication (PaaS) | https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-oauth | 2026-07-22 | PaaS uses OAuth 1.0a `HMAC-SHA256` |
| Adobe products/attributes (PaaS) | https://adobe-commerce.redoc.ly/2.4.6-admin/tag/productsattributes/ | 2026-07-22 | `GET /V1/products/attributes` exists on PaaS admin REST |
| Adobe SaaS authentication | https://developer.adobe.com/commerce/webapi/rest/authentication/server-to-server | 2026-07-22 | SaaS uses IMS `client_credentials` + bearer token |

### Options considered

1. **Monolithic adapter interface** — every profile implements
   `checkConnection()`, `discoverSchema()`, etc.
2. **Capability ports** — shared base + optional capability interfaces
   (`ConnectionCheckCapability`, `SchemaDiscoveryCapability`).
3. **Capability bitmask on registry entry** — adapter class plus declared
   `supported_capabilities: ['connection_check', 'schema_discovery']`.

### Recommendation

**reasoned recommendation:** Adopt **option 2 (capability ports)** with a thin
shared `ConnectorAdapter` base that handles profile resolution, credential
decryption boundaries, SSRF-safe transport, and error mapping — plus explicit
capability ports for the minimum read capabilities through 4B-2c:

```text
ConnectorAdapter (base: profile metadata, transport, error mapper hook)
├── ConnectionCheckCapability::probe(ConnectionCheckContext): ConnectionCheckResult
└── SchemaDiscoveryCapability::discover(DiscoveryContext): DiscoveryPageResult
```

- Registry declares which ports each `auth_profile` supports.
- Runtime dispatch checks capability before enqueueing discovery; unsupported
  profiles fail with stable internal error `connectors.errors.capability_unsupported`.
- No write/import/export/FieldMapping methods in this slice.

### Rejected alternatives

- **Monolithic interface (option 1):** forces SaaS adapters to implement no-op or
  throw discovery methods, hiding unsupported behavior until runtime.
- **Bitmask only (option 3):** insufficient without typed ports; still needs
  per-capability method contracts.

### Architecture/security risk

Unsupported capability invoked → misleading "running" UI or adapter-specific
leakage in error messages. Mitigate via registry guard + capability assertion in
enqueue services.

### Exact target document and section

`docs/03-DOMAIN_MODEL.md` — new subsection under Connectors and Mappings:
**Connector adapter capabilities (proposed)**.

### Exact proposed Markdown patch

```markdown
### Connector adapter capabilities (proposed)

Connector runtime uses a shared adapter base plus explicit capability ports.
Profiles declare supported capabilities in the adapter registry; unsupported
capabilities must fail before enqueue with a stable internal error — never with a
fallback adapter.

Minimum read capabilities through Task 4B-2c:
- `connection_check` — prove auth and permission for the next capability
- `schema_discovery` — paginated fetch and normalization of external product-attribute metadata

Write/import/export and FieldMapping are out of scope until Task 4C+.
```

### Future implementation tests

- Unknown profile cannot instantiate adapter.
- Profile without `schema_discovery` rejects discovery dispatch.
- Profile with `connection_check` only can save settings and run checks.

---

## B2 — Adapter/profile registry

### Current project constraint

**repository fact:** `connector_accounts.auth_profile` is a string column
(`database/migrations/2026_07_21_100000_connector_account_foundation.php`).
Seeded Adobe profile codes include `adobe_commerce_paas_oauth1_integration` and
`adobe_commerce_saas_ims_server_to_server` (`docs/03-DOMAIN_MODEL.md`).

**repository fact:** Existing connector services live under
`app/Services/Connectors/` (e.g. `ConnectorDefinitionGovernanceService`) —
config + service container, not a database plugin framework.

### Primary-source evidence

| Source | Fact |
|---|---|
| Laravel service container | https://laravel.com/docs/11.x/container | Bind interfaces to implementations in `AppServiceProvider` or dedicated provider |
| Repository conventions | `app/Services/Connectors/` | Matches existing connector service layout |

### Options considered

1. **PHP config file** `config/connectors.php` mapping profile → metadata.
2. **Database-driven plugin registry** with runtime class loading.
3. **Enum-backed registry** colocated with `auth_profile` validation.

### Recommendation

**reasoned recommendation:** **Option 1 — `config/connectors.php`** resolved at
container boot:

```php
'profiles' => [
    'adobe_commerce_paas_oauth1_integration' => [
        'deployment_family' => 'adobe_commerce_paas',
        'adapter' => AdobeCommercePaasAdapter::class,
        'capabilities' => ['connection_check', 'schema_discovery'],
        'settings_schema' => AdobePaasSettingsSchema::class,
        'error_mapper' => AdobeCommerceErrorMapper::class,
        'enabled' => true,
    ],
],
```

- `ConnectorProfileRegistry` service resolves profile code → DTO.
- Unknown code → `ConnectorProfileNotFoundException` → stable
  `connectors.errors.unknown_profile` (no adapter instantiation).
- `enabled: false` → `connectors.errors.profile_disabled` (same fail-safe rule).
- SaaS profile entry may exist with `capabilities: []` or discovery disabled
  until B1/B3 research closes.

### Rejected alternatives

- **Database plugin framework:** over-engineered for 1–2 profiles; increases
  attack surface and deployment complexity.
- **Enum-only registry:** insufficient for class bindings and capability lists
  without parallel config anyway.

### Architecture/security risk

Silent fallback to a generic HTTP adapter could mask misconfiguration and bypass
profile-specific SSRF/error rules.

### Exact target document and section

`docs/07-TECH_STACK.md` — **Connector profile registry**.

### Exact proposed Markdown patch

```markdown
### Connector profile registry

`connector_accounts.auth_profile` resolves through `config/connectors.php` and
`ConnectorProfileRegistry` — not through database plugins or implicit class
guessing. Unknown or disabled profile codes fail before adapter instantiation
with stable internal translation keys.
```

### Future implementation tests

- Unknown profile code returns stable error; no adapter constructed.
- Disabled profile code returns stable error.
- Registry DTO exposes capabilities list used by enqueue guards.

---

## B3 — Account field classification and profile schema

### Current project constraint

**repository fact:** Typed columns on `connector_accounts`:
`base_url`, `store_code`, `tenant_context`; `settings` JSON; `credentials` TEXT
with `encrypted:array` cast (`app/Models/ConnectorAccount.php`,
`database/migrations/2026_07_21_100000_connector_account_foundation.php`).

### Adobe PaaS OAuth 1.0a mapping (fixed — not open research)

| Field | Storage boundary | Classification |
|---|---|---|
| Merchant store base URL | `connector_accounts.base_url` | Non-secret typed column |
| Store view code | `connector_accounts.store_code` | Non-secret typed column (**not** JSON `settings`) |
| Tenant context (if needed) | `connector_accounts.tenant_context` | Non-secret typed column; null when unused |
| Consumer key | `credentials['consumer_key']` | Secret |
| Consumer secret | `credentials['consumer_secret']` | Secret |
| Access token | `credentials['access_token']` | Secret |
| Access token secret | `credentials['access_token_secret']` | Secret |
| Other non-secret PaaS options | `settings` JSON | Non-secret configuration only |

**reasoned recommendation:** Existing schema is **sufficient for PaaS** — no new
persistent columns required for 4B-2a.

### Adobe SaaS / IMS (open research)

**officially documented fact:** SaaS server-to-server auth uses `client_id`,
`client_secret`, IMS org ID, and bearer access tokens obtained from
`https://ims-na1.adobelogin.com/ims/token/v3` with `commerce.accs` scope
(Adobe, *Server-to-server Authentication*, accessed 2026-07-22,
https://developer.adobe.com/commerce/webapi/rest/authentication/server-to-server).

**officially documented fact:** Store scope uses `Store` HTTP header, not URL path
(Adobe, *REST API Overview*, accessed 2026-07-22).

| IMS field | Proposed classification | Notes |
|---|---|---|
| `client_id` | `credentials` secret | Used as `x-api-key` |
| `client_secret` | `credentials` secret | Token exchange secret |
| IMS organization ID | `tenant_context` or `settings['ims_org_id']` | **open option requiring approval** — non-secret identifier; `tenant_context` is semantically closest |
| REST API base / tenant host | `base_url` | SaaS host differs from PaaS (`{server}.api.commerce.adobe.com/{tenant-id}`) |
| Store view code for `Store` header | `store_code` typed column | **open option requiring approval** — same string semantics as PaaS store view code, but transported as header not URL segment; document convention rather than new column |
| Ephemeral IMS access token | encrypted cache or worker memory only | Never persistent column; TTL per token response |

**open option requiring approval:** Reuse `store_code` for SaaS `Store` header
value vs add documented `settings['store_header']` convention. Primary sources
do not mandate a separate field — recommend reusing `store_code` with profile
documentation that PaaS embeds it in URL while SaaS sends it as `Store` header.

Any **new** persistent column for IMS → **Stop-and-Amend**; no silent JSON
workaround for secrets.

### Rejected alternatives

- Storing `store_code` in `settings` JSON — contradicts resolved typed-column
  schema and weakens validation.
- Putting `base_url` in `settings` — same issue.

### Architecture/security risk

Misclassified secrets in `settings` or logs; blank form fields erasing secrets
(addressed in B14).

### Exact target document and section

`docs/03-DOMAIN_MODEL.md` — **ConnectorAccount credential and settings
classification**.

### Exact proposed Markdown patch

```markdown
#### Credential and settings classification (proposed)

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
```

### Future implementation tests

- PaaS save stores secrets only in `credentials`; `store_code` not in `settings`.
- Blank credential form fields do not erase stored secrets.
- Settings JSON never contains `consumer_secret` keys after save.

---

## B4 — First production deployment family

### Current project constraint

**repository fact (Part A Resolved):** Task 4B-2a's first production profile is
Adobe Commerce PaaS/on-prem with OAuth 1.0a integration credentials
(`adobe_commerce_paas_oauth1_integration`). See `03-DOMAIN_MODEL.md` — Connector
scope (Resolved).

IMS/SaaS remains a separate follow-up until discovery capability and endpoint
contract are verified.

This section records the fixed input — **not open for re-litigation in Part B**.

Generic core code must not introduce Adobe-specific columns; all Adobe behavior
lives in profile adapter + registry entries.

---

## B5 — OAuth 1.0a signing and dependency strategy

### Current project constraint

**repository fact:** `composer.lock` includes `guzzlehttp/guzzle` ^7.8 but **no**
OAuth 1.0a signing library. Laravel HTTP client does not sign OAuth 1.0a
requests (Laravel, *HTTP Client*, accessed 2026-07-22,
https://laravel.com/docs/11.x/http-client).

### Primary-source evidence

| Source | URL | Accessed | Fact |
|---|---|---|---|
| Adobe OAuth (PaaS) | https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-oauth | 2026-07-22 | `oauth_signature_method` must be `HMAC-SHA256` |
| RFC 5849 | https://datatracker.ietf.org/doc/html/rfc5849#section-3.4 | 2026-07-22 | Signature base string construction and encoding rules |
| api-clients/psr7-oauth1 | https://github.com/php-api-clients/psr7-oauth1 | 2026-07-22 | Provides `HmacSha256Signature` for PSR-7 request signing |
| horde/oauth | https://packagist.org/packages/horde/oauth | 2026-07-22 | OAuth 1.0a client with `HmacSha256` signature class |

### Options considered

1. **`api-clients/psr7-oauth1`** — sign PSR-7 request, pass to Laravel HTTP.
2. **`horde/oauth`** — fuller OAuth client; heavier dependency tree.
3. **Isolated `AdobeOAuth1Signer`** — internal class using `hash_hmac('sha256', ...)`
   per RFC 5849 + Adobe rules; no new dependency.
4. **`league/oauth1-client`** — **rejected:** README/examples center on HMAC-SHA1;
   Adobe requires SHA256.

### Recommendation

**reasoned recommendation:** Primary: add **`api-clients/psr7-oauth1`** (requires
dependency-approval per project rules). Fallback if approval delayed: isolated
`App\Support\Connectors\OAuth1\AdobeOAuth1Signer` (~150 LOC) with RFC 5849
normalization and `hash_hmac('sha256', ...)`, covered by deterministic fixtures.

Implementation contract:
- **HMAC algorithm:** `HMAC-SHA256` only (Adobe-mandated).
- **Nonce:** cryptographically random per request (`random_bytes` + base64/hex).
- **Timestamp:** Unix seconds UTC; reject local clock skew > 5 minutes in signer
  self-check tests.
- **URL normalization:** RFC 5849 percent-encoding; include query parameters in
  signature base string; use final request URL without fragment.
- **Fixture tests:** golden `Authorization` header for fixed nonce/timestamp/url
  inputs; never use live Adobe in CI.

### Rejected alternatives

- Copying tutorial signers without RFC normalization tests.
- `league/oauth1-client` without verified SHA256 support.

### Architecture/security risk

Incorrect normalization → intermittent 401s; signing key logged during debug.

### Exact target document and section

`docs/07-TECH_STACK.md` — **Adobe PaaS OAuth 1.0a signing**.

### Exact proposed Markdown patch

```markdown
### Adobe PaaS OAuth 1.0a signing

PaaS outbound REST uses OAuth 1.0a `HMAC-SHA256` (Adobe official docs). Prefer
`api-clients/psr7-oauth1` with `HmacSha256Signature` after dependency approval;
otherwise an isolated internal signer with RFC 5849 fixture tests. No tutorial
copies without reviewed normalization tests.
```

### Future implementation tests

- Deterministic signature fixture vectors (method, URL, params, secrets).
- Signer never logs signing key or secrets.
- `Http::fake()` integration: signed `Authorization` header present on outbound.

---

## B6 — Authorization and secret-management permissions

### Current project constraint

**repository fact:** Staff auth uses `App\Enums\UserRole` (admin, manager,
warehouse, merchandiser, director, programmer). Granular Spatie permissions exist
for some workspace features (`WorkspacePermissions::MANAGE_TAX_SETTINGS` pattern
in `app/Support/Workspace/WorkspaceTaxSettingsAuthorization.php`). No
`ConnectorAccountPolicy` exists yet.

Connector UI is workspace-scoped Filament admin (Task 4B-0 visual contract).

### Options considered

1. **Role-only matrix** — fast, matches many existing resources.
2. **Role + Spatie permissions** — `manage_connector_accounts`,
   `run_connector_checks`, etc.
3. **Director/Admin only for all connector operations** — too restrictive for
   merchandiser-operated pilots.

### Recommendation

**reasoned recommendation:** **Option 2** — `ConnectorAccountPolicy` +
`ConnectorAccountAuthorization` support class mirroring tax settings pattern:

| Action | Admin | Director | Manager | Merchandiser | Warehouse | Programmer |
|---|---|---|---|---|---|---|
| List/view connections | ✓ | ✓ | ✓ | ✓ | view only | ✓ |
| View masked settings (non-secret fields) | ✓ | ✓ | ✓ | ✓ | — | ✓ |
| Create/edit connection metadata | ✓ | ✓ | ✓ | — | — | ✓ |
| Replace/remove credentials | ✓ | ✓ | permission | — | — | ✓ |
| Run connection check | ✓ | ✓ | ✓ | ✓ | — | ✓ |
| Run discovery | ✓ | ✓ | ✓ | ✓ | — | ✓ |
| Disable/archive connection | ✓ | ✓ | permission | — | — | ✓ |
| View safe technical details / `vendor_request_id` | ✓ | ✓ | ✓ | — | — | ✓ |

Proposed permissions (Spatie):
- `manage_connector_accounts` — create/edit/archive, credential replace
- `view_connector_accounts` — list/read for Manager/Merchandiser default

**open option requiring approval:** Whether Merchandiser may run discovery or only
view results — recommend **allow run** (operational need) but not credential edit.

No user, API resource, event, queue payload, log, or exception may serialize
decrypted credentials.

### Architecture/security risk

Credential exposure via Filament API/JSON; cross-workspace IDOR on check/run IDs.

### Exact target document and section

`docs/03-DOMAIN_MODEL.md` — **ConnectorAccount authorization (proposed)**.

### Exact proposed Markdown patch

```markdown
### ConnectorAccount authorization (proposed)

Connector operations require `ConnectorAccountPolicy` checks on every read and
mutating action. Credential view/edit is limited to Admin, Director, and users
with `manage_connector_accounts`. Connection checks and discovery may be run by
Manager and Merchandiser where policy allows; Warehouse has read-only list access.

Decrypted credentials must never appear in API resources, logs, events, queue
payloads, or exception reports.
```

### Future implementation tests

- Manager cannot replace credentials without permission.
- Cross-workspace `connector_account_id` rejected in policy and jobs.
- Filament JSON responses exclude `credentials` key.

---

## B7 — Connection-check capability and error mapping

### Current project constraint

**repository fact:** Dual-axis enums exist: `ConnectorErrorCause`,
`ConnectorErrorActionability`. History tables store `user_message_key`, not raw
vendor text (`app/Enums/*`, `docs/03-DOMAIN_MODEL.md`).

### Primary-source evidence

| Source | Fact |
|---|---|
| Adobe products/attributes | `GET /V1/products/attributes` — proves catalog metadata ACL needed for discovery |
| Adobe OAuth | 401 for invalid token/signature; Adobe docs describe OAuth parameter requirements |
| RFC 5849 | Signature/timestamp/nonce failures return 401-class failures at vendor |

### Recommendation

**reasoned recommendation:** PaaS connection check = **single staged call**:

`GET {base_url}/rest/{store_code}/V1/products/attributes?searchCriteria[pageSize]=1`

This proves OAuth signature **and** product-attribute read permission (not merely
`GET /store/storeViews` which could pass with narrower ACL).

Optional **two-stage** check only if field testing shows attribute list blocked
while lighter endpoint passes — not default.

#### Error mapping table (PaaS)

| Vendor signal | HTTP | Cause | Actionability | User message key |
|---|---|---|---|---|
| Invalid/revoked token or consumer key | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_credentials` |
| OAuth signature/nonce/timestamp | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_signature` |
| Authenticated, ACL denied on attributes | 403 | `authorization` | `user_action_required` | `connectors.errors.insufficient_permissions` |
| Bad base URL / store / host | 404 / connection error | `configuration` | `user_action_required` | `connectors.errors.invalid_endpoint` |
| Unknown route on valid host | 404 | `configuration` | `user_action_required` | `connectors.errors.unsupported_endpoint` |
| Timeout | 408 / curl timeout | `network` | `automatic_retry` | `connectors.errors.timeout` |
| Rate limited | 429 | `rate_limit` | `automatic_retry` | `connectors.errors.rate_limited` |
| 5xx / gateway | 5xx | `vendor_unavailable` | `automatic_retry` | `connectors.errors.vendor_unavailable` |
| JSON/schema mismatch | 200 + bad body | `schema_validation` | `support_required` | `connectors.errors.unexpected_response` |

Raw vendor response bodies are **never** user-facing.

### Future implementation tests

- 401 → authentication + user_action_required.
- 403 → authorization + insufficient_permissions key (distinct from 401).
- 429 respects `Retry-After` mapping.
- Mapper never returns vendor HTML in `user_message_key`.

---

## B8 — Execution model and operator surface

### Current project constraint

**repository fact:** Task 4B-0 visual contract defines six operator surfaces;
`05-AI_WORKING_AGREEMENT.md` requires visual contract before persistence; backend-only
delivery is unacceptable.

### Recommendation

**reasoned recommendation:**

| Operation | Execution | UI coupling |
|---|---|---|
| Save/replace/remove settings | Synchronous `ConnectorAccountSettingsService` (authorized, DB transaction, no raw Filament Eloquent write) | Settings surface in 4B-2a |
| Connection check | Queued job + polling UI | List + check result in 4B-2a |
| Discovery | Queued job + polling UI | Discovery Overview in 4B-2b |

No queue/job terminology in merchant UI — use existing copy from
`06-UI_DESIGN_SYSTEM.md` ("Перевірка з'єднання", "Оновлення полів", etc.).

### Exact target document and section

`docs/06-UI_DESIGN_SYSTEM.md` — clarify polling states only if needed (no redesign).

### Exact proposed Markdown patch

```markdown
#### Connector runtime polling (proposed)

Connection check and discovery are asynchronous operator workflows. UI shows
human states (queued/waiting, running, succeeded, failed) without queue/job
terminology. Task 4B-2a ships connection surfaces together with check runtime;
Task 4B-2b ships Discovery Overview together with discovery runtime.
```

---

## B9 — Queue and lock infrastructure readiness

### Evidence table

| Conclusion | Evidence level |
|---|---|
| Default `QUEUE_CONNECTION` is `database` | **repository-verified** — `config/queue.php`, `.env.example` |
| `jobs` and `failed_jobs` tables exist | **repository-verified** — `database/migrations/0001_01_01_000002_create_jobs_table.php` |
| Default `CACHE_STORE` is `database` | **repository-verified** — `config/cache.php`, `.env.example` |
| Redis available in docker-compose | **repository-verified** — `docker-compose.yml` |
| docker-compose defines `queue` service running `queue:work` | **repository-verified** — `docker-compose.yml` lines 73–89 |
| docker-compose defines `scheduler` loop | **repository-verified** — `docker-compose.yml` lines 91–111 |
| `routes/console.php` has **no** scheduled connector tasks yet | **repository-verified** |
| `deploy.sh` pulls code, composer, npm build — **no** `queue:restart` or worker setup | **repository-verified** — `deploy.sh` |
| Queue `after_commit` is `false` on all connections | **repository-verified** — `config/queue.php` |
| No `ShouldQueue` jobs in `app/` yet | **repository-verified** — ripgrep session |
| GitHub Actions MySQL workflow runs tests with `QUEUE_CONNECTION=sync` implied via phpunit | **CI-verified** — `phpunit.xml` sets `QUEUE_CONNECTION=sync` |
| Production worker process running | **unknown / not observable in this session** |
| Production lock store (Redis vs database) | **unknown / not observable in this session** |

### Recommendation

**reasoned recommendation:** Treat queued connection check/discovery as
**design-approved but operationally gated**:

> production state not agent-verified; prerequisite verification/deployment
> action required before 4B-2a can rely on queued execution

**Smallest deployment addition (proposed for 4B-2a prerequisite checklist):**
1. Document production worker requirement (supervisor/systemd or docker `queue`
   service) in `07-TECH_STACK.md`.
2. Add `php artisan queue:restart` to `deploy.sh` **after** worker provisioning
   is confirmed.
3. Prefer **Redis** cache/lock store in production when multiple workers exist;
   database cache locks work for single-worker MVP but document expiry behavior.

Do **not** silently run production checks on `sync` queue while UI presents
asynchronous polling.

### Future implementation tests

- Feature tests use `Queue::fake()` / sync driver; integration test with
  `database` queue + worker optional in CI later.

---

## B10 — Logical idempotency and overlap locking

### Current project constraint

**repository fact:** No connector jobs exist. Laravel provides `ShouldBeUnique`,
`WithoutOverlapping`, `shared()` (Laravel, *Queues*, accessed 2026-07-22,
https://laravel.com/docs/11.x/queues).

### Recommendation

**reasoned recommendation:** Layered contract:

1. **Duplicate dispatch prevention:** `ShouldBeUnique` per
   `(connector_account_id, operation_kind)` with short TTL (e.g. 30s) on dispatch
   button/API.
2. **Concurrent execution prevention:** `WithoutOverlapping("connector-account:{id}")`
   with `shared()` **across check and discovery job classes** — **one account-level
   lock**; check and discovery **must not overlap** for the same account (avoid
   credential decrypt races and projection writes).
3. **Logical row creation:** create `connector_connection_checks` /
   `connector_discovery_runs` row in DB **before** dispatch (see B11).
4. **Retry:** new queue attempt reuses same history row ID; terminal update uses
   `lockForUpdate()` on that row.

Lock driver: `Cache::lock` using default store; document Redis preference for
production multi-worker (**open deployment verification**).

Lock expiry: `WithoutOverlapping` expiry > job timeout (e.g. 600s); release on
completion/failure.

### Rejected alternatives

- `WithoutOverlapping` alone as "idempotency" — insufficient; does not define
  logical history row semantics.

### Future implementation tests

- Double-click dispatch creates one logical check row.
- Discovery blocked while check lock held.
- Manual + scheduled triggers respect same lock key.

---

## B11 — Lifecycle and transaction boundaries

### Three-phase contract

#### Phase 1 — Start (DB transaction, short)

1. Authorize + validate workspace/account/profile/capability.
2. Create logical history row (`queued` or `running` per decision below).
3. Update account projection only for "check/discovery requested" if needed.
4. `DB::afterCommit()` dispatch job (enable `after_commit` on connector queue
   connection in 4B-2a).

#### Phase 2 — External work (no open DB transaction)

- Worker reloads account with workspace scope.
- Decrypt credentials in worker only.
- Bounded HTTP via SSRF-safe transport (B13).
- No secret-bearing bodies in logs.

#### Phase 3 — Terminal (DB transaction)

- `lockForUpdate()` on existing check/run row by ID from payload.
- Transition to `succeeded` / `failed` / `cancelled`.
- Update `ConnectorAccount` current projection atomically.
- Publish snapshot pointers only after full normalization (discovery).
- Ignore stale retries: compare `updated_at` or `attempt` counter; older job
  cannot overwrite newer terminal state.

**Do not** insert a second history row at terminal completion.

### Connection-check enqueue-state gap (schema conflict)

**repository fact:** `ConnectorConnectionCheckStatus` has only `running`,
`succeeded`, `failed` — **no `queued`**. `connector_connection_checks.started_at`
is **non-nullable** (`app/Enums/ConnectorConnectionCheckStatus.php`,
`database/migrations/2026_07_21_100000_connector_account_foundation.php`).

`ConnectorDiscoveryRunStatus` includes `queued` with nullable `started_at`.

#### Options

| # | Option | Pros | Cons |
|---|---|---|---|
| 1 | Add `Queued` enum value; make `started_at` nullable | Mirrors discovery; honest queue-wait metrics; stable poll ID at dispatch | Requires migration in 4B-2a |
| 2 | Create row only when worker starts | No migration | **No stable ID for immediate UI poll after dispatch** |
| 3 | Treat `running` as including queue wait | No migration | Misleading duration metrics; conflates queue latency with HTTP time |

### Recommendation

**reasoned recommendation:** **Option 1** — add `Queued` to
`ConnectorConnectionCheckStatus` and make `started_at` nullable (set when worker
actually begins HTTP).

Time semantics:
- `created_at` — operator requested / enqueued
- `started_at` — worker began external work (null while `queued`)
- `finished_at` — terminal
- `duration_ms` — HTTP/work duration only (`started_at` → `finished_at`), **excludes**
  queue wait

#### Exact future migration/enum patch (for Task 4B-2a — not applied in 4B-2-0)

```php
// app/Enums/ConnectorConnectionCheckStatus.php
case Queued = 'queued';
// ... existing cases ...

// migration (new file in 4B-2a)
$table->timestamp('started_at')->nullable()->change();
```

### Final-failure and crash behavior

- Job timeout → terminal `failed`, cause `network` or `unknown`, actionability
  `automatic_retry` only if attempts remain.
- Exhausted retries → `failed()` hook sets terminal state, releases overlap lock.
- Stale `running`/`queued` reconciliation command (4B-2d): mark abandoned rows
  failed after lock expiry + job timeout budget; surface recovery CTA in UI.

---

## B12 — Timeout, retry, rate-limit and response policy

### Connection check (single GET)

| Parameter | Value |
|---|---|
| Connect timeout | 5s |
| Total/read timeout | 30s |
| Job timeout | 45s |
| Max queue attempts | 3 |
| Backoff | 30s, 120s (no jitter on 4xx) |
| Retryable | timeout, 408, 429, 5xx, connection reset |
| Non-retryable | 401, 403, 404 (endpoint), schema_validation |
| 429 | honor `Retry-After` capped at 300s; counts toward attempt budget |
| Redirects | 0 (disabled) |
| Max response bytes | 256 KB |
| HTTP client retries | 0 (queue handles retry; avoid multiplication) |

### Paginated discovery

| Parameter | Value |
|---|---|
| Per-page timeout | 60s connect 10s |
| Job timeout | 900s (15 min) |
| Max pages per run | 50 |
| Max fields per run | 10 000 |
| Max queue attempts | 2 |
| Backoff | 60s, 300s with jitter |
| Same non-retryable 4xx rules | 401/403 not retried |

### Token acquisition (IMS — later)

| Parameter | Value |
|---|---|
| Token HTTP timeout | 15s |
| Cache TTL | `expires_in - 60s` floor 60s |
| Max attempts | 2 |

---

## B13 — SSRF-safe outbound request contract

### Primary-source evidence

| Source | URL | Accessed | Fact |
|---|---|---|---|
| OWASP SSRF Prevention | https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html | 2026-07-22 | Validate URL, block internal ranges, DNS rebinding awareness |
| Laravel HTTP client | https://laravel.com/docs/11.x/http-client | 2026-07-22 | Timeouts, `withOptions`, Guzzle underneath |
| Guzzle redirect | https://docs.guzzlephp.org/en/stable/request-options.html#allow-redirects | 2026-07-22 | `allow_redirects => false` |

### Recommendation

**reasoned recommendation:** `SsrfSafeHttpTransport` (isolated service) wrapping
Laravel HTTP/Guzzle:

1. **HTTPS only** in production (`http` allowed only in `local`/`testing`).
2. **Ports:** 443 only (production).
3. Parse URL; reject userinfo, fragments; normalize hostname to lowercase punycode.
4. Resolve DNS A/AAAA; block private/link-local/loopback/multicast/metadata
   (169.254.169.254, ::1, 10/8, 172.16/12, 192.168/16, fc00::/7, etc.).
5. **DNS rebinding:** resolve the hostname to an IP before connecting, then
   pin the connection to that exact IP for this request using Guzzle's raw
   `curl` option array — `['curl' => [CURLOPT_RESOLVE => ["{host}:{port}:{ip}"]]]`
   — and verify the TLS certificate SAN still matches the original hostname.
   **Do not use `force_ip_resolve`** for this purpose — it only selects an
   IPv4/IPv6 address family (`CURLOPT_IPRESOLVE`) and does not pin to a
   specific IP, so it provides no DNS-rebinding protection on its own.

   **This contract requires Guzzle's cURL handler** (`CurlHandler` or
   `CurlMultiHandler`) — the raw `curl` option array has no effect on
   `StreamHandler`, which uses a separate `stream_context` option instead and
   would silently ignore the `CURLOPT_RESOLVE` pinning. Guzzle can select
   `StreamHandler` under some PHP/cURL combinations without the caller asking
   for it. The transport factory must verify the cURL handler is actually in
   use and **fail closed** (refuse to make the request) rather than silently
   proceeding on `StreamHandler` without IP pinning.

   **IPv6 formatting:** per libcurl's own `CURLOPT_RESOLVE` documentation,
   IPv6 literals in resolve entries must be wrapped in square brackets, e.g.
   `example.com:443:[2001:db8::1]` — plain `example.com:443:2001:db8::1` is
   invalid syntax (the address's own colons collide with the entry format).
   Build resolve entries through one tested formatter function, not by
   string-concatenating unvalidated host/port/IP values at each call site.

   If Guzzle's public API proves awkward for this per-request pinning and
   fail-closed handler check in practice, treat that as **open option
   requiring approval** and document the need for an isolated transport layer
   instead.
6. **Redirects:** disabled by default; if ever enabled, revalidate each hop.
7. **Response:** stream with max bytes; abort over limit.
8. **No user-provided proxy** configuration.

URL validation alone is **not** sufficient (OWASP).

### Exact target document and section

`docs/07-TECH_STACK.md` — **SSRF-safe connector outbound transport**.

### Exact proposed Markdown patch

```markdown
### SSRF-safe connector outbound transport

Connector outbound HTTP must use an isolated SSRF-safe transport that:
- allows HTTPS only in production (port 443);
- blocks private/link-local/loopback/metadata targets after DNS resolution;
- pins each request to the pre-validated resolved IP using Guzzle's raw
  `curl` option array — `['curl' => [CURLOPT_RESOLVE => ["{host}:{port}:{ip}"]]]`
  — with TLS certificate SAN verification against the original hostname;
- requires Guzzle's cURL handler (`CurlHandler` / `CurlMultiHandler`) and
  **fails closed** if `StreamHandler` would be used (raw `curl` options are
  silently ignored there);
- formats IPv6 resolve entries with bracketed literals per libcurl
  (`example.com:443:[2001:db8::1]`) via one tested formatter function.

Do **not** use `force_ip_resolve` for DNS-rebinding defense — it only sets
`CURLOPT_IPRESOLVE` (IPv4/IPv6 family preference) and does not pin a specific IP.
```

### Future implementation tests

- `169.254.169.254`, `10.0.0.1`, `[::1]`, decimal/hex/octal IP encodings blocked.
- Redirect to internal URL blocked.
- Oversized response aborted.
- `Http::preventStrayRequests()` in tests.
- transport fails closed when the cURL handler / ext-curl is unavailable;
- the approved IP-pinning option reaches the actual cURL handler (not silently
  no-op'd under StreamHandler);
- no StreamHandler fallback is permitted for SSRF-sensitive connector calls;
- IPv6 resolve entries are correctly bracket-formatted (test both IPv4 and
  IPv6 pinning targets).

---

## B14 — Secret lifecycle and ephemeral token handling

### Confirmations

| Rule | Status |
|---|---|
| Persistent secrets only in `encrypted:array` credentials | **repository fact** — model cast |
| Non-secret identifiers in typed columns / settings | **reasoned recommendation** |
| Queue jobs carry IDs only | aligns with `04-ARCHITECTURE_PRINCIPLES.md` checklist #22 |
| No decrypted credentials in logs/events/exceptions | required |
| IMS tokens in cache with TTL | later SaaS profile |
| Secret replace/remove explicit; blank fields preserve stored secrets | UI + service contract |
| `APP_KEY` rotation | Laravel `encrypted:array` compatible — document re-save credentials workflow |

No raw `Authorization` header in `technical_summary` or evidence tables.

---

## B15 — Future implementation test contract

| Area | Tests |
|---|---|
| Adapter registry | unknown/disabled profile |
| OAuth signing | deterministic fixtures |
| Credentials | serialization hidden; log redaction |
| Authorization | matrix by `UserRole` + permissions |
| Workspace isolation | cross-workspace account/job rejection |
| Settings service | replace/remove semantics; blank secret fields |
| Connection check | status/error mapping; 401 vs 403 |
| Retries | timeout/429/5xx retry; 401/403 no retry |
| Concurrency | duplicate dispatch; overlap lock shared across check/discovery |
| Transactions | start/terminal consistency; no second history row |
| Stale retry | cannot overwrite newer projection |
| Queue failure | `failed()` terminal state; stale running recovery |
| SSRF | IPv4/IPv6/DNS/redirect/metadata |
| Limits | response size; pagination caps |
| HTTP testing | `Http::fake()` + `Http::preventStrayRequests()` |
| CI | no live Adobe calls |
| Visual | acceptance vs `docs/prototypes/task-4b0-connector-account/` fixtures |

---

## Proposed core-document patches

### docs/03-DOMAIN_MODEL.md

Add sections (after ConnectorAccount Resolved blocks):
- Connector adapter capabilities (proposed) — B1 patch text
- Credential and settings classification (proposed) — B3 patch text
- ConnectorAccount authorization (proposed) — B6 patch text
- Connection-check enqueue state — document `Queued` status + nullable
  `started_at` as approved future 4B-2a migration

### docs/04-ARCHITECTURE_PRINCIPLES.md

No checklist item changes required (items 21–22 already cover SSRF and secrets).
Optional clarification under **Connector operational security**:

```markdown
- **Capability-gated adapters:** registry declares supported read capabilities;
  unsupported capabilities fail before enqueue.
- **Account-level execution lock:** connection check and discovery for the same
  `connector_account_id` must not overlap.
```

### docs/05-AI_WORKING_AGREEMENT.md

```markdown
### Connector runtime Stop-and-Amend gate

Tasks 4B-2a–4B-2d are Strict Alignment Pathway work. Application code remains
blocked until Task 4B-2-0 approved decisions are promoted from
`docs/proposals/task-4b2-0-runtime-decisions.md` into core docs and merged.
```

### docs/06-UI_DESIGN_SYSTEM.md

Connector runtime polling patch from B8 (no visual redesign).

### docs/07-TECH_STACK.md

Add:
- Connector profile registry (B2)
- Adobe PaaS OAuth 1.0a signing (B5)
- SSRF-safe connector outbound transport (B13):
  ```markdown
  ### SSRF-safe connector outbound transport

  Connector outbound HTTP must use an isolated SSRF-safe transport that:
  - allows HTTPS only in production (port 443);
  - blocks private/link-local/loopback/metadata targets after DNS resolution;
  - pins each request to the pre-validated resolved IP using Guzzle's raw
    `curl` option array — `['curl' => [CURLOPT_RESOLVE => ["{host}:{port}:{ip}"]]]`
    — with TLS certificate SAN verification against the original hostname;
  - requires Guzzle's cURL handler (`CurlHandler` / `CurlMultiHandler`) and
    **fails closed** if `StreamHandler` would be used (raw `curl` options are
    silently ignored there);
  - formats IPv6 resolve entries with bracketed literals per libcurl
    (`example.com:443:[2001:db8::1]`) via one tested formatter function.

  Do **not** use `force_ip_resolve` for DNS-rebinding defense — it only sets
  `CURLOPT_IPRESOLVE` (IPv4/IPv6 family preference) and does not pin a specific IP.
  ```
- Queue worker production prerequisite (B9):
  ```markdown
  ### Connector queue workers (production)

  Connection check and discovery require a running `queue:work` process and
  reachable lock/cache store. docker-compose includes a `queue` service for local
  full-stack; production must verify worker + deploy restart separately —
  `deploy.sh` alone does not start workers.
  ```

### docs/IMPLEMENTATION_GAPS.md

After 4B-2-0 merges approved patches, add note under GAP-006:

```markdown
**Task 4B-2-0 note:** Runtime decisions proposed in
`docs/proposals/task-4b2-0-runtime-decisions.md`. Task 4B-2a blocked until
approved patches land in core docs.
```

---

## Approval checklist

Human approval required per decision:

- [x] B1 — Capability ports shape
- [x] B2 — Config-based `ConnectorProfileRegistry`
- [x] B3 — PaaS field mapping; SaaS `store_code` reuse for `Store` header remains open, non-blocking for 4B-2a
- [x] B4 — First production deployment family (Adobe Commerce PaaS/on-prem — fixed input from Part A; see `03-DOMAIN_MODEL.md` Connector scope (Resolved))
- [x] B5 — signing architecture approved; concrete implementation remains Stop-and-Amend (see `07-TECH_STACK.md`)
- [x] B6 — Authorization matrix and Spatie permissions (Merchandiser manual discovery settled)
- [x] B7 — Connection check endpoint and error mapping table
- [x] B8 — Sync settings service + queued check/discovery
- [x] B9 — Production queue-worker verification remains a deployment prerequisite, non-blocking for 4B-2a local/CI development
- [x] B10 — Shared account-level lock; no check/discovery overlap; no MVP `ShouldBeUnique`
- [x] B11 — Option 1: `Queued` + nullable `started_at` for connection checks
- [x] B12 — Timeout/retry numeric policies
- [x] B13 — SSRF transport design (incl. DNS pinning via `CURLOPT_RESOLVE`)
- [x] B14 — Secret lifecycle rules (`APP_PREVIOUS_KEYS`)
- [x] B15 — Test matrix acceptance (normative bridge in `05-AI_WORKING_AGREEMENT.md`)

## Application-code gate

Application code for Task 4B-2a remains blocked until the approved patches above
are applied to the core docs and merged.
