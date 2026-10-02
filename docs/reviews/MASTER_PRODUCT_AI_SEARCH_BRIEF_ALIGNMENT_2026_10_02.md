# Master Product AI proposals + Search Brief implementation alignment — 2026-10-02

STATUS: Proposed implementation contract. Application code is blocked pending Product Owner approval.

## Goal

From the Master Product Workspace a merchant can:
1. click **«Отримати ключові слова»**;
2. receive a persistent market/language-specific Search Brief backed by real keyword data;
3. select keywords that should guide content;
4. request an AI content proposal for an existing governed Product field (first target: `description`);
5. review, accept/edit-and-accept, or reject it;
6. have acceptance go through the existing governed field writer/CAS path;
7. never allow a stale proposal to overwrite newer accepted Product truth.

This is an Intelligence layer. AI never becomes Product-field authority and never creates a second Product model.

## Authoritative inputs

- `MASTER_PRODUCT_WORKSPACE_IMPLEMENTATION_CONTRACT_2026_09_30.md`: accepted values and proposals are separate; proposals stale on revision change; AI acceptance never silently owns ERP-authoritative data; implementation step 5 is AI proposals + Search Brief.
- `PRODUCT_STRUCTURE_UX_AI_FINAL_SYNTHESIS_2026_09_13.md`: proposal-first, persistent/reviewable/auditable/stale-able AI and run/batch audit.
- The earlier AI implementation contract remains research input only for Slice C persistence; Sonnet/Gemini approved Slice A structure, not this physical schema.
- Current runtime confirms `description` is an existing governed Product FieldBinding backed by `products.description`; governed writers/CAS already exist.
- Connector `ReceiveProposal` is connector evidence flow, not reusable AI persistence.
## Provider decision

Keyword research is provider-neutral at domain level.

First adapter: **DataForSEO**. It provides keyword suggestions/search-volume data with market/language inputs and simple service API credentials. Direct Google Ads KeywordPlanIdeaService remains a future adapter behind the same interface because it requires Google Ads OAuth, developer-token and account/access setup.

Provider location IDs, task IDs and raw payload details are evidence/adaptor metadata, never Product fields.

## Physical contract — Search Brief

### `product_search_briefs`

Workspace-owned research artifact for one Product + locale/market context:
- UUID `id`, `workspace_id`, unsigned BIGINT `product_id`, initiating `user_id`;
- `language_code`, nullable ISO `country_code`, additive `market_context_json`;
- `provider_key` (evidence only), nullable `provider_request_ref`;
- `seed_snapshot_json`, `source_fingerprint`;
- status `pending | completed | failed | stale`;
- nullable safe `failure_code` / failure detail; timestamps.

A Search Brief is historical evidence. Product changes do not rewrite an old brief; they may make it stale.

### `product_search_brief_keywords`

Normalized candidates belonging to one brief:
- UUID `id`, `workspace_id`, `search_brief_id`;
- keyword text, deterministic `sort_order`, `is_selected`;
- nullable normalized `search_volume`;
- `metrics_json` for provider evidence such as monthly trend, paid competition/CPC and later SEO difficulty;
- timestamps.

Keyword identity is brief-scoped. Selection alone never mutates Product content and never silently becomes Product tags.
## Physical contract — AI proposals

### `ai_proposal_runs`

Audit envelope for one generation request:
- UUID `id`, `workspace_id`, initiating `user_id`;
- trigger (`product_workspace` first), input/selection snapshot, requested proposal kinds;
- AI provider/model/model-version/prompt-config version;
- status `pending | running | completed | completed_with_failures | failed | cancelled`;
- aggregate counts and nullable usage/cost metadata; timestamps.

No hidden chain-of-thought is persisted.

### `ai_proposals`

First implementation supports governed **field-value/content proposals** only:
- UUID `id`, `workspace_id`, `ai_proposal_run_id`;
- `product_id`, nullable `variant_id`, `field_binding_id`;
- `proposal_kind = field_value`;
- expected current value snapshot/fingerprint;
- proposed typed value JSON, nullable confidence, concise `evidence_json`;
- `source_fingerprint`, `target_schema_fingerprint`;
- status `pending | accepted | edited_and_accepted | rejected | stale | failed`;
- nullable resolving `user_id`, `resolved_at`; timestamps.

For content generated from a Search Brief, evidence links the brief and selected keyword IDs.

Do not add a weak generic polymorphic “AI target” merely to anticipate future ProductType/category/group proposals. Those may add typed target columns later in a reviewed additive migration.
## Acceptance and staleness

Before accept or edit-and-accept:
1. authorize `manage_products`;
2. lock/reload Product/Variant and target FieldBinding;
3. verify proposal is still pending;
4. recompute source fingerprint;
5. verify FieldBinding/schema fingerprint and applicability;
6. compare expected current value through existing governed CAS semantics;
7. validate the final merchant-approved value with the existing typed field policy/writer;
8. perform that existing governed mutation;
9. atomically resolve proposal with actor/time/final accepted-value evidence.

Any mismatch -> stale/conflict; **no Product write**. Reject changes proposal state only. AI generation never writes Product/Variant value tables directly.

Search Brief source fingerprint covers the accepted Product facts used as keyword seeds (at minimum title/name, brand, category/type context and relevant accepted descriptive facts). When those change, prior brief remains historical but is no longer the default current brief.

## Provider abstractions and UX

Introduce `KeywordResearchProvider`; first implementation `DataForSeoKeywordResearchProvider`. LLM generation uses a separate `AIContentProvider` interface; domain persistence does not depend on OpenAI, Anthropic, Gemini or another vendor.

Product Workspace quick action: **«Отримати ключові слова»**. The review surface shows keyword, search volume and concise trend/competition signal; merchant selects target keywords. **«Створити опис з AI»** generates a proposal, never silently saves. Review actions: **Прийняти / Редагувати й прийняти / Відхилити**. Stale proposal asks to regenerate/review.

Paid provider calls are explicit merchant actions in this slice; no background bulk spend.

## Acceptance evidence

Required before merge readiness: MySQL workspace-safe FKs; cross-workspace rejection; stale source/schema/current-value acceptance rejection; governed edited acceptance; reject = zero Product mutation; Search Brief staleness; selection = zero Product mutation; safe provider failures; Workspace Livewire E2E; existing Master Product + Magento REST V1 suites green; real DataForSEO validation when credentials are available; real LLM validation when credentials are available.

## Explicit non-goals

No auto-accept, bulk AI, automatic ProductType/category/group assignment, schema creation, generic Readiness engine, GSC performance agent, or provider-specific IDs in Product/FieldBinding tables.

## Stop & Amend

Required because the 2026-09 structure work approved Slice A while AI Slice C persistence remained conceptual research. The 2026-09-30 Master Product contract resolves proposal behavior but not this physical schema.

Once Product Owner approves this document, implementation proceeds without another architecture review unless real code/provider evidence exposes a new authority, concurrency or transaction ambiguity.
