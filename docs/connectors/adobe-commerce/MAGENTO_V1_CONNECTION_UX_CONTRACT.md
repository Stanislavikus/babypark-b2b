# Magento V1 Connection UX Contract

Status: **FROZEN — FINAL ARBITRATION 2026-09-11**
Date: 2026-09-11
Scope: Magento Open Source / Adobe Commerce PaaS-on-prem V1 OAuth1 Integration profile.

This contract is the final source of truth for Magento V1 merchant onboarding, connection truth, credential rotation, permission remediation, connection recovery, store-scope setup, and WRITE-readiness wording. Final arbitration used independent Opus 5 High and GPT-5.4 attacks plus verification against the actual certification-branch runtime.

## Product principle

Standard Magento V1 is **MODULELESS BY DEFAULT**. Safe Sync is optional Enhanced Safety only and must not appear as a standard-path prerequisite.

The merchant mental model has four real concepts:

1. Magento store — where the SaaS connects.
2. SaaS Integration — the Magento Integration object created for this platform.
3. Integration credentials — Consumer Key, Consumer Secret, Access Token, Access Token Secret.
4. Integration permissions — what that Magento Integration may read or change.

Do **not** introduce a fictitious connector user.

The recommended Magento Integration name comes from configurable product branding. It is only guidance; runtime identity and trust must never depend on that display name, and the SaaS must not assume the merchant kept the recommended name.

## First-connect state machine

Ordinary Magento V1 onboarding asks only for:

- Magento store URL;
- Consumer Key;
- Consumer Secret;
- Access Token;
- Access Token Secret.

Before the fields, show one short concept-first help surface: create a dedicated Magento Integration for this SaaS, grant the Product capabilities required by the connector, activate it, and copy the four credentials. The current Magento Admin path is secondary guidance. A short video may be added later but is never a V1 dependency.

`tenant_context` is not merchant-facing in ordinary Magento V1 onboarding.

`store_code` is single-store V1 context. Default to `default`; expose an optional Advanced override. The reserved scope `all` is forbidden for this V1 profile at account-validation boundary, not merely at a validation harness or WRITE call site.

The first-connect transition is normative:

`FORM -> CHECKING -> CONNECTED | ATTENTION_REQUIRED | TEMPORARILY_UNAVAILABLE`

Saving credentials is not evidence of a healthy connection. The create flow must dispatch a read-only baseline check; a plain success redirect or persisted `Untested` state is not equivalent to verified connection truth.

## Connection truth and evidence

A successful authenticated bounded Product READ is the **only** baseline that establishes healthy Magento V1 connection truth.

Product Attributes, media, discovery, mapping, Preview, WRITE prerequisites, and real WRITE are downstream evidence. None may make a successful Product baseline red, and none may make a failed Product baseline green.

**Implementation status (certification branch, 2026-09-11):** the historical attribute-first runtime and best-effort `probeCatalog()` success fallback are removed. Connection Check now performs one bounded Product READ baseline and derives safe catalogue evidence from that same response. Product Attributes are no longer connection-health authority. A Product READ failure remains authoritative and must be classified/persisted by the existing lifecycle.

Healthy merchant presentation is dated:

`Підключено · перевірено {human-friendly date/time}`

The timestamp is evidence, not a promise of perpetual health. Healthy idle connections do not require arbitrary periodic polling.

If `default` store context fails, only show a store-specific remediation when reliable evidence proves the store context is the cause. Then reveal the Advanced store-code field. Ambiguous 404/configuration outcomes remain conservative and must not invent a cause.

## Error classification and permission remediation

Merchant Layer A/B leads with business impact and one causal CTA. It does not expose HTTP status codes, OAuth/ACL identifiers, endpoint paths, raw bodies, PHP, Composer, module versions, or Safe Sync.

Structured Magento evidence may refine remediation only when machine-reliable. Known structured resource evidence such as a validated `parameters.resources` shape, or a stable machine OAuth identifier where actually available, may distinguish authorization from credential failure. Localized free-form `message` text must not be parsed as the semantic authority.

Ambiguous 401/403 outcomes use conservative copy; raw response data remains support-only. Dead enum vocabulary must not be treated as proof that classification is implemented.
Examples of merchant states:

- Product baseline unavailable: `Підключення потребує уваги` with a causal reconnect/permission action;
- Product baseline healthy but Product WRITE blocked: connection remains Connected while `Передача змін товарів призупинена` is shown as a separate operation state;
- transient provider/network problem: `Тимчасова проблема — повторюємо автоматично`;
- mapping/schema drift: operation-specific remediation, never connection failure.

When a missing permission is proven, merchant copy may refer to `інтеграція для цього магазину` or the SaaS product branding, but must not pretend to know the merchant's actual remote Magento Integration display name unless it was independently and reliably obtained. Do not add a required “Magento Integration name” field solely for copy.

## Credential rotation

Overview exposes connection settings and an explicit full-quartet `Replace integration credentials` flow. Existing secrets are never rendered back to the browser.

Credential rotation preserves Entity Trust when `base_url + store_code` is unchanged because credentials are access material, not target identity.

Replacement uses **test-before-save**:

1. capture the current account/target version or equivalent freshness token;
2. test quartet B transiently against the unchanged target **outside any DB transaction or row lock** using the Product READ baseline;
3. if B fails, discard B and preserve quartet A; the failed replacement attempt does not make a previously healthy A red;
4. if B succeeds, acquire the shared connector-account operation lock, open a short DB transaction, fresh-read the account, verify the target/settings version still matches the tested snapshot, and atomically replace A with B;
5. persist the successful verification evidence/timestamp for B.

If A was already broken, failed validation of B preserves A but does not fabricate Connected. Concurrent mutation that invalidates the tested snapshot aborts/retries rather than committing credentials tested against stale state.
## Target identity and recovery

After merchant-confirmed trusted links exist, changing `base_url` or `store_code` on the same ConnectorAccount remains forbidden.

The merchant must not see an internal target-frozen exception. Use business copy such as:

`Це підключення вже пов'язане з товарами цього магазину. Щоб працювати з іншим магазином, створіть нове підключення.`

Primary CTA: `Створити нове підключення`.

## Monitoring and recovery

Frozen verdict: **EVENT-DRIVEN + RECOVERY IS SUFFICIENT**.

Required triggers:

- immediately after first connect;
- after successful credential replacement or relevant mutable configuration change;
- fresh operation-specific preconditions before consequential operations;
- authentication/authorization/provider failures that may invalidate current truth;
- bounded automatic retry/recovery for transient failures, with backoff and stop condition;
- merchant-visible last-checked evidence.

Do not invent a healthy-idle sweep cadence without new evidence. Recovery polling applies to known unhealthy/transient states, not as perpetual background proof for healthy idle accounts.

## WRITE readiness and operation state

Connection status and Product WRITE readiness are separate state dimensions. Do not create a new connection-status meaning merely to represent WRITE blocked/paused.

Before the first verified consequential WRITE, use wording equivalent to:

`Передумови перевірено — передачу ще не виконували`.

After a real successful WRITE followed by verification, use evidence-scoped wording equivalent to:

`Передачу підтверджено · останній успіх {date/time}`.

A historical verified WRITE does not expire merely with time, but current operation readiness may fall after proven failure or credential/target-relevant change. A successful credential rotation re-establishes connection truth; it does not by itself prove a new WRITE.

Standard moduleless simple WRITE remains the next runtime migration:

`trusted entity_id -> fresh GET expected SKU -> same entity_id + exact SKU -> one PUT -> no blind retry after ambiguous outcome -> reconciliation GET -> post-write verification`.

The standard path must not silently fall back to Safe Sync.

## Implementation acceptance anchors

The Connection Truth & Monitoring slice is not complete until tests prove at least:

- Product READ success + attributes denial remains Connected;
- attributes success + Product READ denial never becomes Connected;
- first connect automatically enters checking and resolves to a truthful terminal/retry state;
- structured permission evidence produces permission remediation while ambiguous 401/403 stays conservative;
- `store_code=all` is rejected for the Magento V1 profile before runtime requests are built;
- failed quartet-B validation preserves quartet A and its existing connection truth;
- successful quartet-B validation commits only after freshness/concurrency re-check;
- no external Magento HTTP call occurs inside the credential-replacement DB transaction;
- Product WRITE permission loss produces a separate paused operation state without repainting a healthy Product baseline red;
- target mutation after Entity Trust yields business remediation and `Створити нове підключення`;
- healthy presentation includes last-verified time and known transient failure uses bounded automatic recovery.

Broad Magento connection/onboarding UX research is closed by this freeze. Reopen only for bounded contradictory runtime or real-target evidence.