# 07-TECH_STACK.md

## Purpose

This document is a short implementation guardrail for Cursor / AI coding agents.

It does not replace the architecture documents. It tells the agent which stack and existing patterns must be used when implementing UI from `06-UI_DESIGN_SYSTEM.md`.

The goal is simple: extend the current Laravel / Filament application. Do not invent a second frontend architecture.

---

## Application Stack

- Backend framework: Laravel.
- UI runtime: Livewire + Alpine.js where needed.
- Admin UI: Filament.
- B2B buyer UI: Filament panel / Filament-based pages for the current MVP.
- CSS: Tailwind CSS utility classes through the existing Filament / Tailwind setup.
- Icons: Heroicons through Filament by default.
- Theme: Filament theming, CSS variables and approved design tokens.
- Appearance: Light / Dark / System mode through Filament and Tailwind dark-mode support.

Do not introduce React, Vue, Next.js, Nuxt, Inertia, a custom SPA, a second design system or a separate storefront frontend unless a later architecture document explicitly approves it.

---

## Current Panels

The current application has two primary Filament panels:

- `/admin` — merchant / staff administration panel.
- `/cabinet` — B2B buyer cabinet / storefront panel.

These panels have different users, permissions and visibility rules.

Do not mix admin-only fields into the buyer UI.
Do not expose cost, profitability, internal status, source-of-truth or connector details to B2B buyers.

---

## Rules for AI / Cursor

Before writing UI code, read:

- `05-AI_WORKING_AGREEMENT.md`
- `06-UI_DESIGN_SYSTEM.md`
- this file
- the existing Filament resource/page/component that is being modified

When implementing UI:

- Extend existing Filament components and patterns first.
- Use Filament tables, forms, actions, infolists, panels, modals/drawers and notifications where they fit.
- Use Tailwind utility classes for layout and spacing.
- Use Alpine.js only for lightweight local interactions such as small toggles, focus behavior or quantity stepper UI.
- Use Livewire / Filament actions for server-round-trip operations such as search, filters, cart updates, order submission and persistence.
- Add new Livewire components only when Filament cannot reasonably express the interaction.
- Do not create a parallel custom component system.
- Do not write large custom CSS files.
- Do not use inline styles except for unavoidable token-driven values.
- Do not introduce new npm packages without human approval.
- Do not move business logic into Blade, Alpine or JavaScript.
- Do not duplicate Pricing, Availability, Order, Connector or Attribute Dictionary logic inside UI components.

---

## B2B Storefront Stack

For the MVP, the B2B storefront/cabinet uses the same Laravel + Filament + Livewire + Tailwind stack.

The B2B buyer experience may look simpler and more storefront-like, but it must still be implemented by extending approved Filament / Livewire patterns.

The B2B storefront is not a separate React/Vue storefront, not a marketplace, not a page builder and not a CMS theme system.

Public/anonymous catalogue behavior, custom storefront frontend or headless storefront rendering requires a separate approved architecture decision before implementation.

---

## File and Code Conventions

Use existing project structure before creating new directories.

Expected conventions:

- Filament admin resources/pages/actions stay under the existing admin Filament namespace.
- Filament cabinet resources/pages/actions stay under the existing cabinet Filament namespace.
- Shared domain/UI helper logic goes into existing support classes where appropriate.
- Shared Blade partials stay under existing `resources/views/filament/...` conventions.
- Reusable UI behavior should be centralized; do not copy/paste table, lightbox, availability or cart logic between panels.
- Legacy cabinet Livewire code must not be revived or extended unless the human explicitly approves it.

If the exact existing path is unclear, inspect the project before creating files.

---

## Existing Shared Patterns to Prefer

Prefer existing shared project patterns for:

- product table toolbar overrides;
- data list search/filter toolbar for non-Eloquent read models
  (`resources/views/components/filament/data-list-toolbar.blade.php`) —
  see "Data List Search & Filter Pattern" in `06-UI_DESIGN_SYSTEM.md`;
- shared data-list toolbar uses a one-row `md` responsive contract:
  desktop controls remain inline; below `md`, secondary controls move
  into one public-Filament overflow panel. The main header row must not
  use `flex-col`, `flex-wrap`, or a different mode-switch breakpoint.
  Vertical overflow-panel content may use `flex-col`, and removable
  indicator chips below the header may wrap. Do not use runtime width
  detection, vendor table views, or duplicate Filament form containers
  for this behavior.
- product image thumbnail and lightbox behavior;
- product column visibility;
- product panel visibility;
- catalogue row data preparation;
- session cart behavior;
- brand/theme tokens.

If a required shared pattern is missing, propose the smallest shared abstraction. Do not create one-off duplicated logic inside admin and cabinet separately.

---

## Styling Rules

- Use Filament and Tailwind defaults first.
- Use design tokens from `06-UI_DESIGN_SYSTEM.md` for accent, availability, status and theme behavior.
- Do not apply raw user-selected accent colors directly to buttons, text, links or focus states.
- Do not hardcode light-only UI surfaces; dark mode must remain readable.
- Do not introduce decorative visual noise, AI sparkle icons, animated dashboards or marketing-style UI.

---

## Data and Domain Boundaries

UI code may display domain data. It must not redefine domain rules.

The UI must call or reuse approved domain/application logic for:

- price display;
- buyer-specific price resolution;
- profitability / markup calculation;
- availability display;
- hidden stock protection;
- order status transitions;
- cart/order submission;
- source-of-truth field locking;
- connector field mapping;
- attribute dictionary anti-duplication.

If a UI task requires a new field, new persisted preference, new status, new calculation, new order action or new buyer-facing capability, stop and ask for a domain/documentation patch before coding.

---

## Connector implementation guardrails (Task 4B+)

Confirmed by Task 4B-0 research — apply when implementing connector persistence:

- Use Laravel HTTP client and public framework encryption APIs (`encrypted:array`
  cast; credentials column `TEXT` or larger; not searchable).
- Introduce a connector **adapter interface/port** per vendor deployment family;
  no Adobe-specific columns on generic tables.
- Queue jobs carry `connector_account_id` (and workspace context), never
  decrypted credentials in payload.
- Automated tests use `Http::fake()` — no live vendor calls in CI.
- Operational UI: Filament + Livewire + Alpine + Tailwind per existing stack; no
  new frontend framework.
- Non-runtime visual prototypes live under `docs/prototypes/` and are not imported
  by Laravel runtime.

Do not finalize PHP class names in this document — physical design concludes in
Task 4B-1/4B-2 implementation PRs after this Stop-and-Amend merges.

---

## Task Prompt Template for Cursor

Use this structure for implementation tasks:

```text
Read these files before writing any code:
- 05-AI_WORKING_AGREEMENT.md
- 06-UI_DESIGN_SYSTEM.md (sections: <specific sections>)
- 07-TECH_STACK.md

Task:
<one concrete implementation task>

Definition of done:
- <what must work>
- <what must remain unchanged>
- <what must not appear>

Do not:
- <specific prohibitions for this task>
```

Tasks should be small enough to test visually after each step.

Recommended implementation order:

1. Admin product table defaults / toolbar / column visibility.
2. Product context drawer.
3. Quantity selector and cart behavior.
4. B2B buyer table/card view.
5. Mobile adaptation.
6. Polish, accessibility and edge states.

---

## Connector runtime (Resolved — Task 4B-2-0)

### Connector profile registry

`connector_accounts.auth_profile` resolves through `config/connectors.php` and
`ConnectorProfileRegistry` — not through database plugins or implicit class
guessing. Unknown or disabled profile codes fail before adapter instantiation
with stable internal translation keys.

### Adobe PaaS OAuth 1.0a signing (Resolved)

OAuth1 signing is accessed only through an internal narrow port,
`App\Support\Connectors\OAuth1\OAuth1RequestSigner` — application code never
depends on a third-party OAuth1 library's classes directly, only on this port.

The previously researched `api-clients/psr7-oauth1` package is not currently
installable without dependency conflict: its stable release line requires
`psr/http-message ^1.0.1`, while this project uses `psr/http-message 2.0`
(confirmed in `composer.lock`, alongside `guzzlehttp/psr7` 2.10.4). It must
not be added by downgrading the project's PSR-7 stack, and it must not be
promoted here as a pre-approved dependency.

Task 4B-2a must perform a Stop-and-Amend comparison between:
1. a maintained, PSR-7-v2-compatible OAuth1 signer dependency (if one can be
   found that actually installs cleanly against the current lockfile); and
2. a small, isolated, project-owned signer implementing only the required
   Adobe HMAC-SHA256 flow.

Whichever is chosen stays behind `OAuth1RequestSigner` and requires
deterministic RFC 5849 fixture tests before any live Adobe request. Tutorial
code must not be copied into production without review.

### Connector queue workers (production)

Connection check and discovery require a running `queue:work` process and
reachable lock/cache store. docker-compose includes a `queue` service for local
full-stack; production must verify worker + deploy restart separately —
`deploy.sh` alone does not start workers.

### Connector idempotency and overlap locking (Resolved)

- **Duplicate logical-operation prevention:** the authorized application
  service acquires a short database/application lock for
  `(workspace_id, connector_account_id, operation_kind)` and checks for an
  existing active history row before creating a new one. If an equivalent
  `queued` or `running` operation already exists, return its existing
  history-row ID to the UI — do not create another row. This lock-and-check,
  not any queue-level mechanism, is the source of logical idempotency. Only
  after a new logical row has been committed is its job dispatched
  (`afterCommit`).

- **Queue dispatch:** MVP connector jobs do **not** implement `ShouldBeUnique`.
  Laravel suppresses dispatch when the unique lock cannot be acquired; no job
  is enqueued. Application code cannot treat that suppression as proof that
  the newly-created logical operation has a corresponding queued job — and
  this cannot be allowed to happen after a new persistent `queued` history
  row has already been created and committed. Adding `ShouldBeUnique`
  on top of the already-atomic logical-operation lock above does not add
  safety; it adds a second, harder-to-observe failure mode (a stale unique
  lock from a crashed prior attempt silently blocking dispatch of a brand
  new, legitimately-created row).

- **Dispatch failure compensation:** if dispatch after commit throws, or
  otherwise cannot enqueue the job, the service must transition the newly
  created row out of `queued` in a compensating transaction and expose a safe
  retry action to the user. No history row may remain `queued` without a
  corresponding queued or executing job.

- **Concurrent execution prevention:** `WithoutOverlapping("connector-account:{id}")`
  with `->shared()` and `->releaseAfter(30)` across the connection-check and
  discovery job classes — one account-level lock; check and discovery must not
  overlap for the same account. On the `database` cache driver, `expireAfter(0)`
  falls back to ~24h; use a bounded TTL above each job's timeout; its relationship
  to `retry_after` is lane-specific and must follow the Queue timeout alignment
  table below. Connection check and discovery use the same shared account-level lock key
  and the same `releaseAfter(30)`, but each job uses an expiry appropriate
  to its maximum runtime: 120 seconds for the 45-second connection check,
  and 1100 seconds for the future 900-second discovery job.

- **Connection-check retry (Task 4B-2a-2c):** classified `AutomaticRetry`
  results use manual `release($delay)` — not `backoff()`. Unhandled exceptions
  use `maxExceptions = 1`; the job wraps all of `handle()` in a sanitized
  `ConnectorConnectionCheckJobExecutionException` (fixed message, no `$previous`)
  because Laravel's `failed_jobs.exception` stores raw exception text regardless
  of `#[\SensitiveParameter]`. Retry deadline is `retryUntil()` 15 minutes from
  dispatch (not numeric `$tries`). Three vendor-execution slots are tracked via
  `execution_attempts` on the history row. Lock order for every transaction
  touching both tables: `connector_accounts` then `connector_connection_checks`.

- **Retry-After normalization:** single-valued header only; delta-seconds must
  be ASCII digits; HTTP-date per RFC 9110 with ceiling-rounded delta; cap 300s;
  only for `AdobeRateLimited` + HTTP 429; raw header never stored.

- **Stale-row recovery:** inline in dispatch before creating a new row;
  `Queued` rows past `retry_until_at`; `Running` rows past `retry_until_at + 120s`;
  vendor-result precedence applies (projection updated when a real vendor
  classification exists).

- **Retry:** a new queue attempt reuses the same history row ID; the terminal
  update uses `lockForUpdate()` on that row.

- Lock driver (for the logical-operation lock and `WithoutOverlapping`):
  `Cache::lock` on the default store (the project's `cache_locks` table
  already supports this on the `database` driver); Redis preferred for
  production multi-worker, not required for MVP.

- `ShouldBeUnique` may be reconsidered later, but only alongside an
  observable dispatch-failure or outbox/reconciliation contract that proves
  its lock-suppression behavior cannot create an orphan `queued` row — not
  as a default MVP mechanism.

### Connector timeout and retry policy (Resolved)

**Connection check (single GET):** connect timeout 5s; request total timeout 30s;
job timeout 45s; max **3 claimed vendor-execution slots** (`execution_attempts`);
classified retry delays 30s/120s via `release()` (no jitter on 4xx);
retryable: timeout, 408, 429, 5xx, connection reset; non-retryable: 401, 403,
404, schema_validation; 429 honors `Retry-After` capped at 300s and
counts toward the attempt budget; 0 redirects; max response 256 KB; HTTP
client itself does not retry (queue handles retry, to avoid multiplying
attempts). Job uses `retryUntil()` 15 minutes from dispatch instead of numeric
`$tries`; worker `--tries=3` does not override `retryUntil()`.

**Paginated discovery:** per-page connect timeout 10s, per-page request total
timeout 60s; job timeout 900s; max 50 pages per run; max 10,000 fields per
run; max 2 queue attempts; backoff 60s/300s with jitter; same non-retryable
4xx rules.

**Token acquisition (IMS, later):** HTTP timeout 15s; cache TTL
`expires_in - 60s` floor 60s; max 2 attempts.

### Queue timeout alignment (Resolved)

Two queue **lanes** share one physical `jobs` table (Laravel `database` driver
filters by the `queue` column) but use separate connections/workers so long
discovery runs do not block short connection checks:

| Lane | Connection | Queue name | Job timeout | Lock `expireAfter` | `retry_after` | Worker `--timeout` |
|------|------------|------------|-------------|-------------------|---------------|-------------------|
| Connection check (shipped) | `database` (default) | `default` | 45s | 120s | 90s | (default worker; no extra `--timeout`) |
| Discovery (Task 4B-2b-1+) | `database_connectors` | `connectors` | 900s | 1100s | 1200s | 900s |

The `900` / `1100` / `1200` ordering is a deliberately chosen operational margin
(not an industry standard): `retry_after`'s clock starts when the queue reserves
the job; `WithoutOverlapping` acquires its lock slightly later in the middleware
pipeline — setting `expireAfter` equal to `retry_after` would create a narrow race
where the lock could still be held when `retry_after` makes the job available again.

For every connector queue lane:
- job `$timeout` must be shorter than the queue connection's `retry_after`;
- worker `--timeout` must also be shorter than `retry_after`;
- `retry_after` must exceed the longest supported connector job timeout by a
  deliberate safety margin;
- production workers must have the `pcntl` PHP extension installed, or
  connector jobs must fail deployment-readiness checks (Laravel requires
  `pcntl` to enforce job timeouts at all);
- Task 4B-2b must not enable the 900-second discovery job while the
  connector queue connection still uses a shorter `retry_after` — verify and,
  if needed, raise `retry_after` for the connector queue connection before
  enabling discovery. Task 4B-2a establishes and verifies the queue/worker
  foundation required for connection checks; Task 4B-2b-0 adds the
  `database_connectors` / `connectors` lane prepared for discovery execution.

The exact production values must be verified against `config/queue.php` and
the process-manager (`supervisor`/`docker-compose`) worker command — do not
assume defaults are already aligned.

**Verified (Task 4B-2a-2c):** `config/queue.php` `database.retry_after` = 90s;
connection-check job timeout = 45s; lock `expireAfter` = 120s; default-lane
`docker-compose.yml` queue worker =
`php artisan queue:work --sleep=3 --tries=3 --max-time=3600`; `pcntl` present
in `docker/php/Dockerfile`; `cache_locks` table from standard cache migration.

**Prepared (Task 4B-2b-0):** `database_connectors` connection with
`retry_after` = 1200s (`CONNECTOR_QUEUE_RETRY_AFTER`); dedicated worker
`php artisan queue:work database_connectors --queue=connectors --sleep=3 --tries=3 --timeout=900 --max-time=3600`
(`connector-queue` service in `docker-compose.yml`; planned
`babypark-connector-queue` Supervisor program for the pilot host).
Production Supervisor, PHP path, pcntl availability, and the active
`database` cache/lock store were verified on the pilot host. The
dedicated `babypark-connector-queue` worker is intentionally not created
yet — it is deferred until Task 4B-2b-1 introduces a discovery job that
actually needs it. Connection-check connection, queue,
timeout (45s), and lock `expireAfter` (120s) are **unchanged**. Repo-root
`deploy.sh` runs `php artisan queue:restart` after `optimize:clear`; that signal
requires the verified shared `database` cache store and
Supervisor `autorestart=true` on each worker program.

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

### Connector secret lifecycle (Resolved)

Persistent secrets live only in `connector_accounts.credentials`
(`encrypted:array`). Non-secret identifiers/settings are never encrypted.
Queue job payloads carry only account/check/run IDs — never decrypted
secrets. Decrypted credentials must never appear in logs, events, exceptions,
or serialized jobs. Secret replace/remove uses explicit semantics; a blank
credential form field does not erase a stored secret.

`APP_KEY` rotation uses Laravel's `APP_PREVIOUS_KEYS` contract:
1. retain the former key in `APP_PREVIOUS_KEYS`;
2. deploy the new current `APP_KEY`;
3. verify existing connector credentials still decrypt (Laravel automatically
   falls back through `APP_PREVIOUS_KEYS` on decrypt failure with the current
   key — this already works without any re-save);
4. re-save/re-encrypt all connector credentials under the current key through
   an audited maintenance operation;
5. verify completion before removing the former key from `APP_PREVIOUS_KEYS`.

Re-save is required to retire the old key, **not** because Laravel is unable
to decrypt legacy ciphertext while the previous key remains configured.

---

## Final Rule

Use the existing Laravel / Filament product. Do not build a new frontend inside it.
