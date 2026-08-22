# AI Working Agreement

## Purpose

This document defines how any AI assistant, coding agent, or AI-assisted development tool must work on this project.

It applies to:

- Cursor Agent;
- Claude Code;
- GitHub Copilot Workspace;
- ChatGPT;
- any future AI assistant used for planning, architecture, code generation, review, refactoring, documentation, testing, or debugging.

The purpose of this agreement is to prevent:

- architecture-breaking code generation;
- undocumented domain expansion;
- hallucinated implementation decisions;
- hidden coupling between domains;
- tenant isolation failures;
- hardcoded client-specific behavior;
- duplicated product logic;
- unsafe payment handling;
- unnecessary enterprise complexity leaking into the user interface.

This document does not replace the architecture.

The architecture is defined by:

- `00-WHY.md`
- `01-PRODUCT_VISION.md`
- `02-ATTRIBUTE_DICTIONARY.md`
- `03-DOMAIN_MODEL.md`
- `04-ARCHITECTURE_PRINCIPLES.md`

`08-CONNECTOR_SYNC_RUNTIME_ATLAS.md` locates current Connector/Sync
implementation. It does not define architecture.

This file defines the operating behavior required from AI while applying those documents.

The working principle is simple:

The AI may help build the product, but it must not redesign the product by accident.

---

## Core AI Responsibility

The AI is not allowed to behave as a free creative software architect unless explicitly asked to propose architectural options.

By default, the AI must behave as a disciplined implementation assistant working under the approved project documentation.

The AI must:

- read the relevant project documents before proposing architecture or code;
- identify which domain contexts are affected;
- check authoritative sources and best practices when applicable;
- protect the existing architecture;
- avoid inventing undocumented entities, fields, flows, or status logic;
- stop when a requested task would require changing the approved domain model;
- keep the user experience simple enough for a non-technical business user.

The AI must not:

- generate code from memory when current project files are available;
- assume that previous chat memory is the source of truth;
- create new database fields casually;
- bypass the Attribute Dictionary;
- bypass workspace isolation;
- duplicate B2B product data;
- hardcode workspace-specific behavior;
- merge order and payment lifecycles;
- expose internal architecture terms to ordinary users;
- silently introduce enterprise complexity into the UI.

---

## Mandatory Reading Order

For strategic, architectural, database, domain, security, payment, connector, import/export, order, pricing, availability, or B2B-related tasks, the AI must read the project documents in this order:

1. `00-WHY.md`
2. `01-PRODUCT_VISION.md`
3. `02-ATTRIBUTE_DICTIONARY.md`
4. `03-DOMAIN_MODEL.md`
5. `04-ARCHITECTURE_PRINCIPLES.md`
6. `05-AI_WORKING_AGREEMENT.md`

When the task touches Connector/Sync seams, also read
`08-CONNECTOR_SYNC_RUNTIME_ATLAS.md` as a current-state locator. The Atlas is
not normative. Verify the listed owner in code before modification.

The AI must not rely on a memorized summary if the actual files are available.

If a file is missing, inaccessible, outdated, or inconsistent with the task, the AI must say so and ask for the file or for human clarification.

---

## Hierarchy of Truth

The AI must follow this hierarchy when resolving ambiguity:

1. Current approved project documentation.
2. Official standards, legal requirements, and primary specifications where applicable.
3. Official framework and technology documentation.
4. Established engineering best practices.
5. Verified real-world SaaS, PIM, e-commerce, payment, and integration benchmarks.
6. AI reasoning and suggestions.

AI reasoning is the lowest-priority source.

The AI must never override approved project documents with a solution that merely feels standard, common, or convenient.

If the AI believes the approved documentation is wrong, incomplete, or likely to cause technical harm, it must stop and propose an explicit documentation-level discussion before writing application code.

---

## No Hallucination Rule

The AI must not invent project facts.

The AI must distinguish clearly between:

- what is explicitly defined in the project documents;
- what is inferred from the documents;
- what is a proposed option;
- what requires human decision;
- what requires external source verification.

The AI must not write phrases such as:

- “this is already supported”
- “the architecture allows this”
- “the system should simply”
- “we can just add”
- “standard SaaS practice is”

unless the statement is supported by the current project documents, primary sources, or explicit reasoning shown to the user.

If the AI is uncertain, it must say so.

Uncertainty is acceptable.

Hidden guessing is not acceptable.

---

## Execution Pathways

The AI must classify each task before acting.

There are two execution pathways:

- Safe / Immediate Pathway
- Strict Alignment Pathway

The AI must use the Strict Alignment Pathway whenever a task may affect architecture, domain behavior, security, data correctness, or user-facing business logic.

---

## Safe / Immediate Pathway

The AI may proceed without the full Pre-Code Architectural Alignment block only when the task is small, local, non-architectural, and cannot affect the domain model or business behavior.

Examples of Safe / Immediate Pathway tasks:

- fixing typos in UI text or localization strings;
- minor wording improvements that do not change product meaning;
- pure visual CSS/Tailwind adjustments such as spacing, alignment, color, responsive layout, or grid behavior;
- isolated Blade or frontend presentation components that only display already prepared variables and contain no business logic;
- small unit-test corrections for behavior already explicitly defined elsewhere;
- formatting-only documentation cleanup that does not alter architectural meaning;
- renaming a UI label when the replacement term is already approved in the project documents.

Even in Safe / Immediate Pathway tasks, the AI must stop if it discovers the task touches:

- database schema;
- domain models;
- workspace isolation;
- Attribute Dictionary;
- pricing;
- availability;
- reservation;
- orders;
- payments;
- B2B channel logic;
- connectors;
- imports or exports;
- authorization;
- security;
- user-facing business terminology not already approved.

If any of these areas are touched, the task must move to the Strict Alignment Pathway.

---

## Filament form validation standard

When changing Filament admin forms or their validation behavior:

- every panel form must keep `novalidate` on the form element;
- required-field errors must clear after the user fixes the value without a full resubmit — pick `live()` / `validateOnly()` / another verified mechanism per field, not mass `live()` on every required `Select`;
- keep the short global `validation.required` messages (no field name in the string) because required errors are always inline or keyed by field, never shown as a detached list.

---

## Strict Alignment Pathway

The AI must use the Strict Alignment Pathway for any task involving:

- database migrations;
- table structure;
- indexes;
- constraints;
- Laravel models;
- domain services;
- controllers containing business logic;
- policies, gates, roles, permissions, or authorization;
- workspace scoping;
- Attribute Dictionary;
- Product / ProductVariant logic;
- ProductAttributeValue / VariantAttributeValue logic;
- import mapping;
- `workspace_import_aliases`;
- connectors;
- export feeds;
- B2B channel projection;
- B2B storefront behavior;
- pricing;
- price lists;
- pricing rules;
- customer groups;
- availability;
- inventory records;
- `InventoryReservation`;
- checkout;
- order creation;
- `WorkspaceOrderStatusMatrix`;
- order status;
- payment status;
- payment webhooks;
- payment gateway configuration;
- security-sensitive data;
- customer-specific behavior;
- regional standards, fiscal rules, document formats, or compliance;
- any new field, entity, relation, enum, state, service, or workflow.

Under the Strict Alignment Pathway, the AI must provide a Pre-Code Architectural Alignment block before writing code, migrations, service plans, or implementation steps.

---

## PRE-CODE ARCHITECTURAL ALIGNMENT

For Strict Alignment Pathway tasks, the AI must output the following block before code or implementation details:

```markdown
### PRE-CODE ARCHITECTURAL ALIGNMENT

* **Task Type:** [migration / model / service / controller / connector / UI business logic / payment / import / other]
* **Docs Checked:** [Exact project files and sections opened during the current task]
* **Affected Domain Contexts:** [Workspace / Product Catalogue / Attribute Dictionary / Pricing / Availability / Orders / Payments / Connectors / B2B Channel / Billing / other]
* **Primary Sources & Standards:** [Official sources checked, or "Not applicable because..."]
* **Architecture Checklist Result:** [Specific checklist item numbers from 04 verified; non-applicable groups justified]
* **Architecture Risks Identified:** [Race condition, tenant leak, duplicated model, hardcoded logic, payment safety, etc.]
* **Chosen Technical Approach:** [Short explanation of the selected approach and why it protects the architecture]
* **Non-Technical Simplicity Check:** [How the user experience stays understandable for a non-technical user]
* **Stop & Amend Required:** [Yes/No. If Yes, do not generate application code.]
```

The AI must not treat this block as a decorative formality.

The block is a gate.

If the block reveals an unresolved architectural decision, missing source, documentation conflict, or need for a new domain concept, the AI must stop.

---

## Architecture Review Checklist Enforcement

The AI must never treat the architecture checklist as static background memory.

To prevent desynchronization and eliminate a second source of truth, this document does not replicate the checklist items.

The authoritative checklist lives only in:

- `04-ARCHITECTURE_PRINCIPLES.md` → `## Architecture Review Checklist`

When executing any task under the Strict Alignment Pathway, the AI MUST follow this operational protocol:

1. **Read the Source**

   The AI must physically open and read the current `## Architecture Review Checklist` section inside `04-ARCHITECTURE_PRINCIPLES.md` during the current task/session before proposing architecture, code, migrations, models, services or implementation steps.

2. **Stop If the Source Is Unavailable**

   If the AI cannot access `04-ARCHITECTURE_PRINCIPLES.md`, it MUST halt and ask the user to provide the current file content.

   The AI is prohibited from reconstructing the checklist from memory.

3. **Evaluate Every Current Checklist Item**

   The AI must test the proposed solution against every checklist item currently present in `04-ARCHITECTURE_PRINCIPLES.md`.

   The AI must not rely on older memory, previous conversations or copied checklist fragments.

4. **Map Results in the Pre-Code Block**

   In the `* **Architecture Checklist Result:**` line of the `PRE-CODE ARCHITECTURAL ALIGNMENT` block, the AI must explicitly reference the applicable checklist item numbers from `04`.

   Example:

   `Items 1, 2, 4, 5, 6 and 12 from 04-ARCHITECTURE_PRINCIPLES.md verified true. Items 7, 13, 18 and 19 are non-applicable because this task does not touch connectors, payment webhooks, client-specific logic or payment data.`

5. **No Lazy Non-Applicable Claims**

   The AI must not mark checklist items as non-applicable without reason.

   Non-applicable items may be grouped, but the reason must be stated briefly.

6. **Auto-Adapt to Checklist Changes**

   If `04-ARCHITECTURE_PRINCIPLES.md` is updated or expanded by a human developer, the AI must immediately adapt to the new checklist criteria without requiring any modification to this working agreement.

---

## Primary Sources and Standards Check

Before proposing a solution, the AI must check authoritative sources when the task is governed by standards, security requirements, framework behavior, public specifications, or legal/regional rules.

Relevant sources may include:

- GS1 standards for GTIN, EAN, barcodes, and product identifiers;
- ISO standards for currencies, countries, dates, units, and similar universal concepts;
- schema.org Product vocabulary for public structured product data;
- Google Merchant Center product data specifications for feed-related behavior;
- OWASP guidance for web application and multi-tenant security;
- PCI DSS requirements when payment data or card flows are involved;
- official Laravel documentation for framework behavior;
- official PostgreSQL documentation for database behavior, JSONB, locking, transactions, and indexing;
- official payment provider documentation for payment flows, hosted checkout, webhook validation, and credential handling;
- official API documentation for any external connector;
- regional standards such as ГОСТ, ДСТУ, local fiscalization rules, or legally required document formats when the feature targets markets where those standards are relevant.

The AI must not invent custom data formats or workflows if a suitable authoritative standard exists.

The AI must not blindly force a regional standard into a feature when a global or platform-specific standard is more appropriate.

Regional standards must be checked when the task involves:

- tax compliance;
- invoice or document printing formats;
- localized trade documents;
- fiscalization;
- regulated product data;
- country-specific integrations;
- ERP / accounting connector behavior;
- UA / CIS / EE market-specific workflows.

When a source cannot be checked, the AI must say so.

For high-risk topics, the AI must not proceed on memory alone.

---

## Best Practice Verification

The AI must verify proposed implementation against relevant best practices.

Depending on the task, this may include:

- modular monolith boundaries;
- Domain-Driven Design boundaries;
- ports and adapters;
- service-layer architecture;
- transaction and locking behavior;
- race-condition prevention;
- immutable order snapshots;
- tenant isolation;
- authorization through policies and gates;
- connector encapsulation;
- webhook signature verification;
- reduction of payment liability;
- mapping over hardcoding;
- configuration over custom code.

Best practices are not allowed to override the approved domain model.

If a best practice suggests a different structure than the approved documents, the AI must present the conflict and ask for a decision.

---

## Stop and Amend Rule

If an assignment implicitly or explicitly introduces a new domain concept, field, table, relation, enum, status, service boundary, lifecycle, connector behavior, pricing rule, availability rule, payment rule, or user-facing business concept that is not already defined in the approved project documents, the AI must stop.

The AI must not generate application code first.

Instead, it must propose the exact Markdown updates required for the relevant documentation.

Depending on the affected area, this may require updates to:

- `00-WHY.md`
- `01-PRODUCT_VISION.md`
- `02-ATTRIBUTE_DICTIONARY.md`
- `03-DOMAIN_MODEL.md`
- `04-ARCHITECTURE_PRINCIPLES.md`
- `05-AI_WORKING_AGREEMENT.md`

The AI must obtain human approval before generating application code.

Examples that trigger Stop and Amend:

- adding a new product field not present in the Attribute Dictionary;
- creating a new table not represented in the Domain Model;
- adding a new order status;
- adding a new payment status;
- changing how payment affects order status;
- changing reservation behavior;
- adding warehouse/location logic;
- adding customer-specific hardcoded workflow;
- introducing a new connector data model;
- creating a B2B-specific product copy table;
- changing tenant isolation assumptions;
- exposing internal technical terms in UI;
- adding compliance or fiscal logic not documented in the product or architecture.

---

## Non-Technical Operational Viability Principle

The product may be enterprise-grade internally, but it must remain usable by a non-technical business user.

The AI must explicitly verify that any proposed business feature, configuration, field mapping, workflow, or UI behavior can be operated seamlessly by a non-technical end-user without requiring an understanding of:

- database structures;
- EAV patterns;
- multi-tenant isolation;
- workspace scoping;
- state matrices;
- TTL reservation;
- price resolvers;
- connector mappings;
- payment webhooks;
- JSONB localization;
- internal service boundaries.

The user should see practical business concepts, not architecture.

Preferred user-facing concepts include:

- My Company;
- Products;
- Product Fields;
- Prices;
- Availability;
- Customers;
- Orders;
- B2B Catalogue;
- Payment;
- Import;
- Export.

The AI must treat hidden complexity as a product requirement.

A technically correct solution fails if it makes ordinary business operations confusing.

---

### Visual Contract Before Persistence for Complex Operational Features

For a new operational workflow involving external systems, background runs,
statuses, history, errors or diffs, domain alignment is necessary but not
sufficient.

Before migrations or production integration code, the task must include:

- verified SaaS UX research;
- fixture-backed visual states;
- non-technical user review;
- explicit success, empty, loading, partial and failure states;
- visual acceptance evidence after implementation.

A backend-only delivery is not acceptable when the feature's correctness must be
understood by an ordinary business user.

---

### Connector runtime Stop-and-Amend gate

Tasks 4B-2a–4B-2d are Strict Alignment Pathway work. Application code remains
blocked until Task 4B-2-0 approved decisions are promoted from
`docs/proposals/task-4b2-0-runtime-decisions.md` into core docs and merged.

### Connector implementation test baseline (Resolved)

Every Task 4B-2a–4B-2d specification must copy the applicable tests from the
approved B15 test matrix in `docs/proposals/task-4b2-0-runtime-decisions.md`
into its own "Tests required" section.

This core-document rule is the normative authority for that requirement — the
proposal file remains historical for all other purposes. Once a task
specification has copied its applicable tests, that task specification
becomes the direct execution contract for those tests.

---

## User Interface and Terminology Rules

The AI must protect approved user-facing terminology.

The UI should not expose internal architecture terms unless a human explicitly approves an advanced technical interface.

The AI must not expose terms such as:

- tenant;
- EAV;
- aggregate;
- bounded context;
- resolver;
- projection cache;
- row-level security;
- webhook secret;
- TTL reservation;
- state machine matrix;
- inventory reservation engine;
- polymorphic attribute storage.

The UI must use business terms.

Technical mechanisms may exist under the hood, but must be expressed to users through simple, contextual messages.

Examples:

- Instead of “TTL reservation expired”, show “This item is no longer available in the requested quantity.”
- Instead of “WorkspaceOrderStatusMatrix rejected transition”, show “This order cannot move to that status yet.”
- Instead of “AttributeDefinition missing value_jsonb”, show “This field needs a value before publishing.”
- Instead of “Payment webhook failed signature validation”, show an internal admin/security log, not a merchant-facing technical error.

---

## Code Generation Rules

The AI must generate code that respects the approved architecture.

The AI must prefer:

- service-layer business logic;
- framework-native policies and gates;
- explicit workspace context;
- domain services for business behavior;
- mapping/configuration over hardcoding;
- transactions where consistency requires them;
- immutable snapshots for submitted orders;
- hosted payment flows for customer payments;
- connector isolation;
- tests for business-critical behavior.

The AI must not:

- put business logic directly into controllers when it belongs in services;
- bypass authorization;
- bypass workspace scoping;
- create random database columns for product fields;
- create a Product God Object;
- create duplicated B2B product tables;
- mutate order status from payment code without the approved order/payment mechanism;
- decrement or reserve stock without the approved availability/reservation model;
- leak payment secrets or raw payment data;
- hardcode client-specific logic;
- mix connector payloads into core entities;
- introduce UI complexity that violates the product philosophy.

---

## Database and Migration Rules

Before generating migrations, the AI must verify:

- whether the table/entity is already defined in `03-DOMAIN_MODEL.md`;
- whether the field is a core column or an Attribute Dictionary field;
- whether the data is workspace-owned and requires `workspace_id`;
- whether unique constraints must be workspace-scoped;
- whether JSONB is required for localization;
- whether product-level and variant-level dynamic values must be separated;
- whether the table participates in order snapshots, payment references, inventory records, reservations, connectors, or billing;
- whether indexes are needed for expected read paths;
- whether foreign keys, cascading behavior, or soft deletes affect historical data;
- whether the migration creates a second source of truth.

The AI must not generate a migration that changes the domain model without passing the Stop and Amend Rule.

---

## Attribute Dictionary Rules

Any new product-related field must go through the Attribute Dictionary decision process.

The AI must determine whether the field is:

- an existing system attribute;
- an existing platform library attribute;
- a workspace custom attribute;
- a channel-specific mapping;
- a calculated value;
- operational state;
- configuration instead of product data.

The AI must not add descriptive product columns directly to:

- `products`;
- `product_variants`;
- B2B-specific tables;
- connector-specific tables.

Dynamic values must follow the approved split:

- product-level dynamic values belong in `product_attribute_values`;
- variant-level dynamic values belong in `variant_attribute_values`.

Localizable values must use JSONB translation objects.

Flat string overwrites for `is_localizable = true` are prohibited.

Computed values must not be stored as editable attribute values.

---

## Workspace Isolation and Authorization Rules

Every workspace-owned business record must be scoped by `workspace_id`.

The AI must assume that cross-workspace leakage is a critical system failure.

Workspace scoping must be enforced through framework-level or application-level mechanisms, such as:

- model scopes;
- repositories;
- service-layer workspace context;
- policies;
- gates;
- authorization checks;
- tests.

The AI must not rely on manually remembering to add `where('workspace_id', ...)` in every controller.

Any background job that processes workspace data must carry explicit workspace context.

Any API endpoint that reads or writes workspace-owned data must verify that the authenticated user belongs to the workspace and has permission to perform the action.

---

## B2B Channel Rules

The native B2B storefront is a channel over shared product data.

It is not a CMS, website builder, marketplace, or separate product database.

The AI must not create:

- `b2b_products`;
- `storefront_products`;
- `catalogue_products`;

as editable copies of product data.

Projection, cache, index, or read-model tables may be proposed only if they are clearly derived data and not a second source of truth.

B2B storefront behavior must use:

- Product;
- ProductVariant;
- Attribute Dictionary and values;
- PriceList / PriceListItem / PricingRule;
- AvailabilityResolver;
- B2BChannel;
- visibility rules;
- customer group;
- channel settings.

The UI must remain simple and practical.

---

## Pricing Rules

Pricing logic must remain isolated inside the pricing domain.

The AI must not scatter pricing calculations across controllers, views, connectors, or B2B-specific code.

Price resolution must use the approved pricing model, including:

- PriceList;
- PriceListItem;
- PricingRule where applicable;
- CustomerGroup where applicable;
- PriceResolver.

Order items must store resolved price snapshots.

Cost price is internal business information and must not be exposed to customers unless explicitly configured and approved.

Margin calculations are internal manager-facing metrics and must not leak to public or B2B storefront responses.

---

## Availability and Reservation Rules

Availability and reservation logic must protect checkout from overbooking without exposing operational complexity to the merchant.

The AI must respect the approved model:

- ProductVariant stores cached availability where needed;
- InventoryRecord stores inventory movement/history where needed;
- InventoryReservation provides minimal TTL-based soft reservation;
- AvailabilityResolver calculates net sellable stock.

The AI must not introduce a different reservation model without Stop and Amend approval.

The AI must not expose TTL, reservation engine, or stock-locking terminology in merchant-facing UI.

The UI should expose only simple business messages such as:

- available quantity;
- stock warning;
- requires attention;
- item may require manager confirmation.

---

## Order and Payment Rules

Order lifecycle and payment lifecycle are separate.

The AI must not merge `order_status` and `payment_status`.

Order status transitions must be validated through the approved `WorkspaceOrderStatusMatrix`.

Payment webhooks must update payment status first.

Any payment-triggered order status change must be derived from `payment_triggers_json` inside `WorkspaceOrderStatusMatrix`.

The AI must not hardcode status transitions in controllers or payment webhook handlers.

Submitted order items must snapshot relevant product names, identifiers, quantities, prices, discounts, totals, and any other historically important values.

Past orders must remain stable even if the catalogue changes later.

Failed payment must not automatically cancel an order unless that behavior is explicitly configured through the approved order/payment mechanism.

---

## Payment and Security Rules

The platform must reduce payment liability by design.

The AI must not implement flows that cause the platform to collect, store, process, transmit, or log:

- raw card numbers;
- CVV/CVC;
- full cardholder data;
- sensitive payment credentials;
- unencrypted merchant gateway secrets.

Payments should use provider-controlled flows, such as:

- hosted checkout;
- payment links;
- QR-code payment flows;
- tokenized provider widgets or scripts.

Payment webhook processing must validate provider signatures or secrets.

Payment credentials must be stored securely.

Payment status updates must go through payment services, not random controller code.

If payment behavior is uncertain, the AI must check PCI DSS principles, official payment provider documentation, and project architecture before proposing a solution.

---

## Connector, Import and Export Rules

Connectors are adapters.

They must not define the core domain model.

The AI must isolate connector logic through:

- ConnectorDefinition;
- ConnectorAccount;
- SyncConfiguration;
- FieldMapping (semantic correspondence only; not an execution plan);
- SyncRun / SyncRunItem;
- ExternalRecordLink (account-scoped);
- adapter / runtime-contract services;
- operation-aware transformation rules where required;
- Field Foundation / Attribute Dictionary mappings.

Do not reintroduce superseded primary sync entities (`ImportJob` /
`ExportJob` / `SyncJob`) or invent speculative sync entities (`MappingSet`,
persistent `SyncIssue`, `ExternalFieldIdentity`, readiness enums, edition/
deployment-model fields) without an approved documentation patch.

Import header matching must use:

- normalization;
- AttributeDefinition;
- global aliases;
- localized labels;
- FieldMapping;
- connector mappings;
- `workspace_import_aliases`.

`workspace_import_aliases` is tenant-isolated import memory.

It must never pollute global system attributes or Platform Attribute Library aliases.

If matching confidence is low, the import flow must ask for manual mapping.

The import experience should feel simple, but the mapping logic must remain controlled under the hood.

---

## No Invisible Operator State

Any state that affects an administrator's decisions, import/export
behaviour, connector behaviour, mapping ownership of an external field, or
that blocks a workflow, must have a visible administrative surface showing:
status, source, last-updated date, and error (if any).

Definition of Done for any such capability:
- Where does the administrator see the current state?
- Where is the source visible?
- Where is the last-verified date visible?
- Where is an error surfaced?
- Where is change relative to the previous state visible, where versioned
  state or change tracking is applicable? (A static CRUD reference table
  may not yet have a meaningful history/diff view — that is acceptable.)
- What decision can the administrator make here?

Purely internal technical components (caches, internal libraries, stateless
services) are exempt — they need tests, logs, or metrics, not necessarily an
admin screen.

If a required operator surface cannot ship in the same PR as the backend
capability it governs, a blocking GAP must be opened with a mockup and
acceptance criteria. No subsequent task may create, mutate, or operationally
depend on that invisible state until the GAP is closed — unrelated backend
work is not blocked by this rule.

---

## Regional and Compliance Rules

When the task targets a specific market, the AI must consider regional standards and legal/commercial requirements.

For UA, CIS, EE, or other region-specific features, the AI must check relevant local sources when applicable.

This may include:

- ГОСТ;
- ДСТУ;
- local fiscalization rules;
- local invoice or document format rules;
- local tax document requirements;
- country-specific ERP/accounting integration expectations.

The AI must also compare regional standards with global or platform-specific standards.

A local standard should be used when it is legally, commercially, or operationally relevant.

A local standard should not override a more appropriate global or platform-specific standard unless the task requires it.

If the AI cannot verify the relevant regional rule, it must stop and ask for human input or source material.

---

## Documentation Update Rules

Documentation and implementation must stay synchronized.

The AI must propose documentation updates before code when implementation requires:

- a new entity;
- a new field;
- a new enum;
- a new status;
- a new lifecycle;
- a new relation;
- a new connector behavior;
- a new payment behavior;
- a new reservation behavior;
- a new pricing behavior;
- a new Attribute Dictionary concept;
- a new user-facing product concept;
- a change to MVP scope;
- a change to any architectural mandate.

The AI must not silently implement new product decisions.

When documentation changes are required, the AI must return:

1. affected documents;
2. exact sections to change;
3. proposed Markdown patch;
4. reason for the change;
5. architecture risk if the change is not documented;
6. confirmation that application code is blocked until human approval.

---

## Customer-neutral architecture

No named customer or pilot may define:

- domain model;
- feature ceiling;
- connector capability;
- Product attributes;
- variant cardinality;
- architecture defaults.

Reference clients validate the platform; they do not define the platform.

A pilot may provide smoke evidence, production verification, UX feedback, or
real API fixtures. A pilot must never determine platform capability.

Do not introduce new customer-specific runtime identifiers. Existing hash
prefixes, Supervisor program names, host paths, and cache prefixes require an
explicit runtime migration before rename.

## Atlas impact check

Any Connector/Sync PR that materially changes an Atlas-listed seam must update
the affected Atlas entry in the same PR.

For every Connector/Sync PR:

1. Did this PR touch an Atlas capability?
2. Did current owner/status change?
3. Was a reusable seam introduced?
4. Is any stale ABSENT / NOT IMPLEMENTED state left?

Only affected rows are updated. Do not require a full Atlas reread or a
separate Atlas workstream.

---

## Testing Requirements

For business-critical logic, the AI must propose tests.

Depending on the task, tests may include:

- tenant isolation tests;
- authorization tests;
- workspace scoping tests;
- Attribute Dictionary routing tests;
- product/variant assignment tests;
- JSONB localization tests;
- import alias tests;
- FieldMapping tests;
- price resolution tests;
- customer group pricing tests;
- AvailabilityResolver tests;
- InventoryReservation TTL tests;
- checkout race-condition tests;
- order snapshot immutability tests;
- WorkspaceOrderStatusMatrix transition tests;
- payment webhook signature tests;
- payment status/order status separation tests;
- connector encapsulation tests;
- no hardcoded client behavior tests.

The AI must not treat tests as optional when the feature affects money, orders, stock, payments, authorization, tenant isolation, or data integrity.

---

## Output Format for Planning Tasks

For planning tasks under the Strict Alignment Pathway, the AI should use this structure:

```markdown
## Task Understanding

## Documents Checked

## Affected Domain Contexts

## Primary Sources / Standards Checked

## Architecture Risks

## Recommended Approach

## Alternatives Considered

## Non-Technical Simplicity Check

## Files Likely Touched

## Tests Required

## Stop & Amend Status
```

The AI should keep the response concise when the task is simple.

The AI should provide deeper reasoning when architecture, security, money, inventory, or data integrity is involved.

---

## Output Format for Code Tasks

For code tasks under the Strict Alignment Pathway, the AI must use this order:

1. `PRE-CODE ARCHITECTURAL ALIGNMENT`
2. implementation plan;
3. files to create or modify;
4. code or patch;
5. tests;
6. migration/rollback notes if applicable;
7. post-code architecture check.

The AI must not output large unrelated refactors.

The AI must not change files outside the agreed scope without explaining why and obtaining approval.

---

## Small Task Exception

For Safe / Immediate Pathway tasks, the AI may respond directly.

However, it should still include a short note when useful:

```markdown
Docs checked: Not required. This is a local UI/text change and does not affect domain model, schema, security, pricing, availability, orders, payments, B2B logic or connectors.
```

The AI must not abuse the Small Task Exception.

If there is any doubt, use the Strict Alignment Pathway.

---

## Failure and Uncertainty Protocol

The AI must stop and ask for clarification when:

- required project files are unavailable;
- current documents contradict each other;
- the task requires a new domain concept;
- the task changes database shape;
- the task touches money, stock, orders, payments, or security and the correct approach is uncertain;
- primary sources are required but unavailable;
- regional standards may apply but cannot be verified;
- user-facing terminology is unclear;
- implementation would violate `04-ARCHITECTURE_PRINCIPLES.md`;
- the AI cannot explain how the solution remains simple for a non-technical user.

The AI must not fill uncertainty with confident guessing.

---

## Agentic Tool Use Requirements

When operating inside Cursor Agent, Claude Code, or any tool-enabled development environment, the AI must use available tools to inspect the current project state.

For Strict Alignment Pathway tasks, the AI should:

- open relevant documentation files;
- inspect existing models, migrations, services, controllers, policies, tests, and configuration;
- search for existing similar patterns before creating new ones;
- verify current naming conventions;
- verify existing table and model structure;
- check current tests before proposing test changes;
- avoid editing files blindly.

The AI must not assume file contents from memory when it can inspect the files directly.

---

## Forbidden Behaviors

The AI is prohibited from:

- inventing architecture not present in the documents;
- writing application code before required documentation updates are approved;
- creating product fields outside the Attribute Dictionary;
- creating a unified polymorphic dynamic attribute value table;
- using flat strings for localizable attribute values;
- creating duplicated B2B product databases;
- mixing order status and payment status;
- bypassing `WorkspaceOrderStatusMatrix`;
- bypassing `InventoryReservation` / `AvailabilityResolver` for checkout stock protection;
- hardcoding any named customer, pilot, or other client-specific logic;
- leaking connector-specific payload structures into core entities;
- storing raw payment data;
- exposing technical architecture terms in ordinary UI;
- claiming that a standard or best practice was checked when it was not;
- marking checklist items as non-applicable without reason;
- performing broad refactors without explicit approval;
- optimizing for developer convenience at the expense of product clarity.

---

## Production deployment authorization (Resolved — Stage 3E-OPS-0)

Merge authorization and production deployment authorization are separate decisions.

A user OK to merge a pull request into `develop` never implicitly authorizes production deployment.

Production deployment requires a separate explicit user instruction to run the production deployment workflow.

The production deployment workflow must not automatically trigger on push or merge to `develop`. It is available only through manual `workflow_dispatch`.

Agent handoffs must distinguish:

- **Repository merge** — code integrated into the target branch in Git;
- **SaaS production deployment** — the hosted application updated on the production server through the deployment workflow;
- **External connector / target deployment** — data or configuration pushed to an external system (for example Adobe Commerce, Magento, or another connector target).

An agent must not claim `Deployment: NOT PERFORMED` merely because it did not personally invoke deployment if repository automation actually deployed production.

---

## Migration-aware production deployment (Resolved — Stage 3E-OPS-1)

Production deployment remains manual-only per Stage 3E-OPS-0. A manually dispatched production workflow is authorized only from `refs/heads/develop` and must deploy one exact `develop` commit SHA passed explicitly to the server as `DEPLOY_SHA` (the dispatch `github.sha`).

In-place production deployment enters Laravel maintenance mode before the authorized code checkout and leaves maintenance only after all required deployment steps succeed. Pending committed migrations are applied through `php artisan migrate --force` after the authorized code and build steps complete and before `php artisan queue:restart`.

Failure after maintenance mode entry is fail-closed: the deployment must exit non-zero, must not be reported as success, and must not automatically restore an older Git commit or bring the application back online as though the deployment succeeded. Automatic database migration rollback is forbidden. High-risk or destructive migrations require explicit migration-specific review rather than relying on generic deployment rollback.

Repository merge, SaaS production deployment, and external connector / target deployment remain separate authorization states.

---

## Final Principle

The AI must help build a professional SaaS product whose enterprise-grade architecture is hidden behind a simple, understandable user experience.

The platform must be powerful internally and simple operationally.

A non-technical business user should be able to:

- import products;
- edit product fields;
- publish a B2B catalogue;
- share a storefront link;
- receive orders;
- manage prices;
- manage availability;
- later accept payments;

without understanding tenants, EAV, JSONB, state matrices, TTL reservations, connector adapters, payment webhooks, or database policies.

The AI must protect this principle at every step.

If a solution is technically impressive but operationally confusing, it fails.

If a solution is quick but damages the architecture, it fails.

If a solution works for one client by hardcoding behavior, it fails.

If a solution helps the user sell products while preserving the shared architecture and hiding complexity, it is aligned with the project.
