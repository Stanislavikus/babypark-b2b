# Magento V1 Connection UX Candidate

Status: **REVIEW CANDIDATE — NOT FROZEN**
Date: 2026-09-11
Purpose: preserve the consolidated onboarding / connection-truth / credential-rotation / permission-remediation UX before final Opus + GPT review.

## Product principle

Standard Magento V1 is moduleless by default. The merchant should not need to understand PHP, Composer, REST endpoint paths, OAuth internals, ACL identifiers, or Safe Sync to connect and operate the standard connector.

The merchant mental model is:

1. Magento store — where the SaaS connects.
2. SaaS Integration — the Magento Integration object created for this platform.
3. Integration credentials — Consumer Key, Consumer Secret, Access Token, Access Token Secret.
4. Integration permissions — what the SaaS Integration may read or change.

Do **not** introduce a fictitious connector "user" when Magento's actual OAuth1 object is an Integration.

## Naming

The Magento Integration name must be tenant-neutral and product-neutral enough for a multi-tenant SaaS. Do not hard-code a customer name such as BabyPark.

Until product branding is frozen, documentation may use a neutral placeholder such as `SaaS Connector` / `SaaS Integration`. Merchant-facing copy must obtain the recommended Integration name from configurable product branding, not from a customer/workspace name.
## First-connect UX candidate

The ordinary Magento V1 onboarding should ask only for the minimum merchant concepts needed for the standard OAuth1 Integration path:

- Magento store URL;
- Consumer Key;
- Consumer Secret;
- Access Token;
- Access Token Secret.

The page should first explain, in one short help surface, that the merchant must create a dedicated Magento Integration for the SaaS and give it the permissions required for the connector's supported Product capabilities.

Primary help should be resilient to Magento menu changes: explain the concept first, then show the current Admin path as secondary guidance. A short video may be added later as optional help; V1 must not depend on a video.

`tenant_context` must not appear in ordinary Magento V1 onboarding.

`store_code` remains single-store V1 context. Candidate UX: default to `default`, but allow an optional Advanced override for installations whose Store View code differs. `all` remains forbidden for consequential V1 WRITE.

Saving a new connection must not immediately imply healthy green state. It should dispatch a read-only baseline check and present a truthful checking state until evidence exists.

## Connection truth

Baseline connection health is based on an authenticated bounded Product READ, not on Product Attribute permission. Optional/downstream capability failures must not make an otherwise successful Product baseline look disconnected.
Merchant Layer A/B messages should lead with business state, not generic permission jargon.

Examples:

- baseline Product READ lost: `Підключення потребує уваги` with causal CTA;
- Product READ healthy but Product WRITE blocked: `Передача змін призупинена` while connection stays Connected;
- transient provider/network problem: `Тимчасова проблема — повторюємо автоматично`;
- mapping/schema drift: operation-specific remediation, not connection failure.

The remediation surface may then explain that the Magento Integration needs a specific permission and show a current Admin path. Raw HTTP status, response body, ACL identifiers, endpoint paths, PHP, Composer, and Safe Sync remain support diagnostics, not primary merchant copy.

Where reliable structured Magento evidence identifies a missing resource, use it to choose the remediation. Do not depend on localized free-form Magento message text. Conservative fallback is required when evidence is ambiguous.

## Credential rotation / reconnect

Overview must expose an understandable connection-settings action and a dedicated `Replace integration credentials` flow.

Stored secrets are never rendered back to the browser. Replacement is the complete OAuth1 quartet, not field-by-field editing of a partially known old secret set.

Entity Trust survives credential rotation because credentials are access material, not target identity. Existing merchant-confirmed ExternalRecordLinks remain valid when `base_url + store_code` is unchanged.

Changing target identity (`base_url` or `store_code`) after trusted links exist remains forbidden on the same ConnectorAccount; the merchant gets business copy explaining that another Magento target requires a new connection.

Candidate preference for final review: test replacement credentials against the same target before committing them. If validation fails, preserve the previously working credential set. Final Opus/GPT arbitration must explicitly confirm or correct this choice.
## Monitoring candidate

Required event-driven checks:

- immediately after first connection;
- after credential replacement or relevant target/configuration mutation;
- before consequential operations through fresh operation-specific preconditions;
- after authentication/authorization/provider failures that may invalidate current truth;
- bounded automatic recovery after transient failures, with backoff and stop condition;
- visible last-checked evidence.

Current review candidate is `EVENT-DRIVEN + RECOVERY IS SUFFICIENT`; do not add an arbitrary healthy-idle polling cadence unless final external arbitration produces evidence that it is required.

## WRITE readiness wording

Do not claim that Product WRITE has been proven before a real verified WRITE.

Distinguish prerequisite/access evidence (`Готово — передумови перевірено`) from real transport proof (`Підтверджено — передача успішно виконана`).

Standard moduleless simple WRITE remains a separate runtime migration: preserve merchant-confirmed Entity Trust, exact SKU/entity preconditions, one consequential PUT, no blind retry after ambiguous outcome, reconciliation GET, and post-write verification. Standard path must not silently fall back to Safe Sync.

## Final review gate

Before implementation, run this candidate through one final narrow Opus 5 High and GPT-5.4 adversarial review. Review only merchant onboarding, Integration naming/model, permission remediation, credential rotation, connection truth, monitoring, store-code UX, and readiness wording. Do not reopen connector architecture, canonical mapping, field research, or Safe Sync as a baseline prerequisite.

After arbitration: apply only bounded corrections, mark the resulting contract frozen, then implement. This candidate exists specifically so the UX/logistics work from the 2026-09-11 discussion is not reconstructed from memory later.
