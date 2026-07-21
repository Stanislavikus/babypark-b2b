# 04-ARCHITECTURE_PRINCIPLES.md

# Architecture Principles

## Purpose

This document establishes the architectural laws, decision-making protocols and implementation constraints for all developers and Artificial Intelligence code generation systems working on the platform.

The purpose of this document is to prevent architectural decay, unvalidated code generation, tenant isolation failures, duplicated product logic, hardcoded customer-specific behavior and unnecessary enterprise complexity leaking into the user interface.

AI systems and developers MUST treat this document as a strict architectural filter.

Any proposal, pull request, migration, generated code block or implementation plan that violates these principles MUST be rejected, rewritten or escalated for architectural review.

The core guiding product philosophy is:

Enterprise-grade architecture must be hidden behind a simple, understandable user experience.

The internal structures may use serious enterprise-level mechanics:

- Domain-Driven Design boundaries;

- separated domain contexts;

- strict workspace isolation;

- attribute dictionaries;

- pricing resolvers;

- inventory records;

- order snapshots;

- connector mappings;

- adapter layers;

- payment webhooks;

- future ledger-style records where needed.

However, the user-facing interface MUST remain simple, practical and understandable for a non-technical small business owner, product manager, sales manager or employee.

The user must not be forced to understand internal architecture in order to use the product.

## Decision-Making Protocol: The 5-Layer Filter

Before proposing any database schema modification, new API endpoint, dynamic feature, connector behavior, storefront behavior, order/payment logic or code refactoring, the AI/developer MUST run the concept through the following 5-Layer Filter.

If a proposal fails at any single layer, it MUST be discarded, rewritten or escalated for review.

The sequence is:

Layer 1: Project Docs Check
-> Layer 2: Primary Source / Standard Check
-> Layer 3: Best Practice Filter
-> Layer 4: Architecture Match
-> Layer 5: UI Simplicity Verification

### Layer 1: Project Documentation Alignment

The solution MUST align with the core project documents:

- 00-WHY.md

- 01-PRODUCT_VISION.md

- 02-ATTRIBUTE_DICTIONARY.md

- 03-DOMAIN_MODEL.md

- 04-ARCHITECTURE_PRINCIPLES.md

The AI/developer MUST NOT introduce a new structural concept that contradicts these files.

If a proposed feature requires changing the product vision, attribute model or domain model, the implementation MUST stop until the documentation decision is made explicitly.

### Layer 2: Primary Source and Standards Verification

If an official industry standard, primary source or authoritative specification governs the task, it MUST be checked before inventing a custom structure.

Possible primary sources include:

- GS1 standards for GTIN / EAN / barcode concepts;

- ISO standards for currencies, countries, units and similar universal references;

- UN/CEFACT where relevant for trade data structures;

- schema.org for public product schema concepts;

- Google Merchant Center product data specifications where relevant;

- OWASP guidance for web application and multi-tenant security;

- PCI DSS requirements where payment data is involved;

- official Laravel documentation for framework-level behavior;

- official PostgreSQL documentation for database-level behavior.

Local state regulatory standards, such as ДСТУ or ГОСТ, MUST be referenced only if a specific target market, legal context or business requirement explicitly requires them.

The system MUST NOT blindly force local standards into features where a more relevant international or platform-specific standard is the correct primary source.

### Layer 3: Industry Best Practice Filter

The solution MUST be evaluated against recognized architectural and engineering practices.

Relevant patterns may include:

- Domain-Driven Design boundaries;

- modular monolith architecture;

- ports and adapters;

- connector isolation;

- mapping over hardcoding;

- transaction and snapshot consistency;

- immutable historical order data;

- secure webhook processing;

- clean service-layer boundaries;

- framework-native authorization through policies and gates.

The AI/developer MUST NOT use “quick implementation” as a reason to break core architectural boundaries.

Short-term speed is acceptable only when it does not create long-term structural damage.

### Layer 4: Architecture Match

The solution MUST match the existing architecture.

It MUST NOT:

- pollute one model with another domain’s responsibilities;

- add random columns to product tables;

- duplicate B2B product data;

- bypass Attribute Dictionary;

- bypass workspace scoping;

- mix order lifecycle with payment lifecycle;

- introduce company-specific hardcoded logic;

- make connectors dictate the internal domain model;

- turn B2B storefront into a CMS or marketplace;

- expose enterprise technical concepts directly to the user.

The solution MUST preserve the separation between:

- Product;

- ProductVariant;

- AttributeDefinition;

- ProductAttributeValue (product-level dynamic values only);

- VariantAttributeValue (variant-level dynamic values only — never merged with ProductAttributeValue);

- WorkspaceImportAlias;

- PriceList;

- PriceListItem;

- PricingRule;

- Availability;

- InventoryRecord;

- InventoryReservation;

- Customer;

- CustomerGroup;

- B2BChannel;

- Order;

- OrderItem;

- WorkspaceOrderStatusMatrix;

- Payment;

- PaymentGatewayAccount;

- ConnectorDefinition;

- ConnectorAccount;

- FieldMapping;

- ImportJob / ExportJob / SyncJob.

### Layer 5: UI Simplicity Verification

The final check is user simplicity.

A technically correct solution still fails if it forces a non-technical merchant or employee to configure low-level technical concepts manually.

The user interface MUST use practical business terms such as:

- My Company;

- Products;

- Product Fields;

- Prices;

- Availability;

- Customers;

- Orders;

- B2B Catalogue;

- Payments;

- Import;

- Export.

The UI MUST NOT expose terms such as:

- tenant;

- EAV;

- aggregate;

- bounded context;

- resolver;

- webhook secret;

- TTL reservation;

- state machine matrix;

- projection cache;

- database policy;

- row-level security.

Technical complexity MUST be safely encapsulated under the service layer, settings layer, import flow or contextual advanced screens.

The product must remain usable by trial and error for a non-technical user.

## Source Priority: Hierarchy of Truth

When implementing features or resolving technical ambiguity, the AI/developer MUST follow this hierarchy of truth.

Lower-priority sources MUST NOT override higher-priority sources.

### Priority 1: Core Project Specification Files

The highest priority source is the current project documentation:

- 00-WHY.md

- 01-PRODUCT_VISION.md

- 02-ATTRIBUTE_DICTIONARY.md

- 03-DOMAIN_MODEL.md

- 04-ARCHITECTURE_PRINCIPLES.md

- future approved reference docs.

These files define the product direction, domain model and architectural constraints.

AI inference MUST NOT override them.

### Priority 2: Official Regulatory, Technical and Industry Standards

Where applicable, the AI/developer MUST consult official standards and primary sources.

Examples:

- ISO;

- GS1;

- UN/CEFACT;

- schema.org;

- Google Merchant Center specifications;

- OWASP;

- PCI DSS;

- official payment provider security documentation.

These sources guide correctness when the platform interacts with standardized product data, trade data, security, payments or public channel requirements.

### Priority 3: Official Framework and Technology Documentation

For implementation details, the AI/developer MUST prefer official documentation of the selected stack.

Examples:

- Laravel framework documentation;

- PostgreSQL documentation;

- PHP documentation;

- official SDK documentation for selected services;

- official payment gateway documentation.

Framework-native mechanisms should be preferred over improvised custom patterns when they solve the problem properly.

### Priority 4: Established Architectural Blueprints

Recognized architecture patterns may guide structural decisions.

Examples:

- Domain-Driven Design;

- modular monolith;

- ports and adapters;

- service layer;

- repository/scoping patterns;

- Fowler-style enterprise application patterns.

These patterns are useful, but they MUST be applied pragmatically.

The platform must not become over-engineered just because a pattern exists.

### Priority 5: Verified Real-World SaaS / PIM / E-commerce Benchmarks

The AI/developer MAY use proven real-world SaaS, PIM, commerce and payment platforms as reference points.

Examples:

- Stripe-style payment objects and webhook flows;

- Shopify-style channel projections where useful;

- Akeneo-style product information concepts where useful;

- Magento / Adobe Commerce attribute patterns as a non-normative reference.

These references MUST NOT blindly dictate the platform model.

They are benchmarks, not final authority.

### Priority 6: AI Inference and Opinion

AI inference is the lowest priority source.

The AI may suggest, summarize, compare or propose.

However, AI MUST NOT introduce undocumented structural paradigms based on intuition alone.

If the AI is unsure, it MUST ask for clarification or propose options with architectural consequences.

# Core Architectural Mandates

## 1. SaaS and Workspace Isolation

### Mandate

Data security inside a multi-tenant environment is paramount.

Every workspace MUST remain an impenetrable silo from an engineering perspective.

Cross-tenant data leaks constitute a critical system failure.

This is an internal engineering standard, not a public legal promise.

### Rules

Every database entity that holds workspace-owned, workspace-specific or customer-transaction data MUST explicitly include a workspace_id column as a foreign key.

The system MUST NOT permit orphaned records for:

- products;

- product variants;

- product field values;

- categories;

- customers;

- orders;

- payments;

- prices;

- custom attributes;

- B2B channel settings;

- connector accounts;

- field mappings;

- import jobs;

- export jobs;

- sync jobs.

Multi-tenant scoping MUST be enforced through automated framework-level or application-level mechanisms such as:

- global model scopes;

- repository-level scoping;

- service-layer workspace context;

- query builders that require workspace context.

Relying on developers to manually add .where('workspace_id', ...) in every controller query is STRICTLY FORBIDDEN.

Authorization MUST be enforced through dedicated framework mechanisms such as:

- policies;

- gates;

- roles;

- permissions;

- service-level authorization checks where needed.

Authorization checks MUST NOT be scattered as messy conditional if/else logic across controllers.

### PostgreSQL RLS Note

PostgreSQL Row-Level Security may be considered later as a defense-in-depth mechanism to enforce isolation at the database engine level.

However, the MVP MUST NOT depend on PostgreSQL RLS.

The MVP should avoid premature deployment and query complexity.

The primary MVP isolation strategy is:

- workspace_id on every workspace-owned table;

- automatic application-level workspace scoping;

- authorization through policies/gates;

- tests that detect cross-workspace leakage.

RLS may be added later when the system is mature enough to justify database-level policy complexity.

## 2. Attribute Dictionary First and Storage Split Rule

### Mandate

The product catalogue schema MUST be protected against uncontrolled field growth.

Adding loose descriptive product fields directly into core tables to support one connector, one customer, one marketplace or one import file is prohibited.

Storage Split Mandate: The MVP MUST use separate, isolated tables for dynamic attribute values. product_attribute_values stores product-level dynamic fields. variant_attribute_values stores variant-level dynamic fields. Merging product-level and variant-level dynamic values into a single generic polymorphic table is STRICTLY FORBIDDEN. This is a closed architectural decision.

JSONB Localization Mandate: For all attributes where is_localizable = true, values MUST be stored as JSONB translation objects. Flat string overwrites are prohibited. The MVP UI will display only the primary workspace language, but the storage engine must enforce JSONB layout consistency from day one.

### Rules

If a feature, connector, import, export, storefront view or customer requirement needs a new product field, the system MUST check the Attribute Dictionary first.

The implementation MUST determine whether the field is:

- an existing system attribute;

- an existing platform library attribute;

- an existing workspace custom attribute;

- a new workspace custom attribute;

- a channel mapping;

- an operational field;

- a calculated value;

- configuration instead of product data.

If the attribute does not exist and is truly needed, it MUST be registered as an AttributeDefinition according to 02-ATTRIBUTE_DICTIONARY.md.

Descriptive product values MUST be stored in the approved attribute value storage defined by the Domain Model, such as:

- product_attribute_values;

- variant_attribute_values;

- approved column-backed core fields where explicitly defined.

The system MUST NOT add random descriptive columns directly to:

- products;

- product_variants;

- B2B-specific tables;

- connector-specific tables.

The Attribute Dictionary is the gatekeeper for product field meaning.

## 3. No Product God Object

### Mandate

The Product entity MUST NOT become an inflated object that tracks every possible business concern.

Product must not become a warehouse, price engine, order history, storefront configuration, payment record or connector payload.

### Rules

The platform MUST maintain separation between domain responsibilities:

- Product = shared product identity and common product information;

- ProductVariant = sellable SKU-level unit;

- AttributeDefinition = product field meaning;

- ProductAttributeValue and VariantAttributeValue = dynamic product-level and variant-level field values stored separately according to assignment level;

- PriceList / PriceListItem = pricing context and price records;

- InventoryRecord = availability movement or availability-related record;

- B2BChannel = storefront/catalogue configuration and access rules;

- Order / OrderItem = submitted transaction and historical snapshots;

- Payment = payment attempt or transaction reference;

- Connector / FieldMapping = external system adaptation.

Domain models SHOULD communicate through:

- service-layer methods;

- clear reference IDs;

- domain services;

- dedicated resolvers;

- explicit DTOs where useful.

The system SHOULD avoid deep ORM relation chains that accidentally pull unrelated operational domains into memory or business logic.

## 4. B2B Storefront Is a Channel, Not a CMS

### Mandate

The native B2B storefront is a customer-facing presentation and order-capture channel over shared product data.

It is not a website builder, full e-commerce CMS, page builder, blog platform or marketplace.

### Rules

The B2B storefront MUST NOT implement:

- heavy CMS engines;

- page builders;

- drag-and-drop layout engines;

- blog frameworks;

- theme marketplaces;

- platform-wide seller discovery;

- marketplace-style search across companies;

- duplicated product publishing flows separate from the core catalogue.

The B2B storefront MAY support:

- grid/card view;

- list view;

- table view;

- category navigation;

- search;

- sorting;

- basic filters;

- cart;

- order submission;

- future hosted payment button;

- simple workspace branding settings.

B2B storefront configuration belongs to B2BChannel.

It should be limited to practical channel settings such as:

- access mode;

- default display mode;

- allowed display modes;

- category navigation enabled;

- search enabled;

- sorting settings;

- filter settings;

- price visibility;

- availability visibility;

- order submission enabled;

- future payment enabled.

The B2B storefront MUST use shared product, variant, price and availability models.

## 5. No Duplicate B2B Product Model

### Mandate

The system MUST NOT maintain duplicated B2B product tables that copy product information from the core catalogue.

Duplicated product models create synchronization errors, stale data and architectural decay.

### Rules

There is only one source of catalogue truth:

- Product;

- ProductVariant;

- Attribute Dictionary and attribute values;

- shared pricing model;

- shared availability model.

The system MUST NOT create core-copy tables such as:

- b2b_products;

- storefront_products;

- catalogue_products;

if those tables duplicate product information as a second source of truth.

The platform MAY use lightweight projection, cache or index tables for performance.

However, such tables MUST be treated as:

- cache;

- read model;

- projection;

- index;

- derived data.

They MUST NOT become editable product databases.

B2B visibility, access rules and storefront presentation MUST be derived through:

- B2BChannel;

- visibility configuration;

- customer group;

- price list;

- product status;

- variant status;

- availability;

- projection services such as B2BCatalogueProjector.

## 6. Order and Payment Lifecycle Separation

### Mandate

Business order fulfillment and payment collection are separate lifecycles.

Conflating money state with operational order state is prohibited.

### Rules

Orders MUST track operational workflow through a dedicated order_status.

Orders MUST track payment collection through a separate payment_status.

Examples of order_status:

- draft;

- pending;

- confirmed;

- processing;

- completed;

- cancelled.

Examples of payment_status:

- unpaid;

- awaiting_payment;

- paid;

- failed;

- refunded.

A payment webhook that marks a payment as paid MUST NOT arbitrarily mutate order_status through hardcoded controller logic.

All order_status transitions MUST be validated through WorkspaceOrderStatusMatrix using the allowed_transitions_json configuration map. Hardcoded status overrides in controllers or services are strictly forbidden.

Payment webhooks MUST update payment_status first. Any resulting change to order_status MUST be derived from the payment_triggers_json configuration inside WorkspaceOrderStatusMatrix, not from hardcoded controller rules.

WorkspaceOrderStatusMatrix MUST be seeded with a sensible default transition configuration for every new workspace from MVP. No visual matrix editor is required in the MVP UI — the matrix operates silently as an internal safeguard.

Payment failure MUST NOT automatically delete or cancel an order unless explicitly configured in WorkspaceOrderStatusMatrix.

## 7. Connector Independence

### Mandate

External systems are adapters.

They MUST NOT dictate or contaminate the internal core domain model.

Examples of external systems:

- Excel;

- CSV;

- Google Sheets;

- 1C / ERP;

- Magento / Adobe Commerce;

- marketplace feeds;

- supplier feeds;

- external APIs;

- future websites.

### Rules

Connector logic MUST live outside the core domain model.

Connectors MUST translate external data into platform concepts through:

- connector accounts;

- import jobs;

- export jobs;

- sync jobs;

- field mappings;

- attribute aliases;

- transformation rules;

- adapter services.

External IDs, custom file headers, localized ERP schemas and marketplace-specific attributes MUST be parsed, normalized and mapped before reaching core entities.

Core entities MUST remain agnostic of whether data originated from:

- Excel;

- Google Sheets;

- 1C;

- REST API;

- marketplace feed;

- manual admin entry.

The platform core must not adapt itself to every external system.

External systems must adapt to the platform core.

## 8. Mapping Over Hardcoding

### Mandate

Automatic column matching, import processing, export formatting and connector synchronization MUST use mappings and configuration.

They MUST NOT rely on brittle hardcoded assumptions.

### Rules

Code that assumes direct behavior based on hardcoded local language strings is prohibited.

Bad pattern:

if ($columnName === 'РРЦ') {
    $product->price = $value;
}

Correct pattern:

- normalize incoming header;

- check Attribute Dictionary aliases;

- check saved workspace mappings;

- check connector mappings;

- suggest high-confidence match;

- ask user for manual confirmation when confidence is low.

All incoming variable headers, spreadsheet columns or API properties MUST be reconciled through:

- AttributeDefinition;

- attribute aliases;

- FieldMapping;

- connector mapping configuration;

- workspace_import_aliases for tenant-isolated saved mappings.

workspace_import_aliases is the tenant-isolated memory for workspace-specific import aliases and confirmed header mappings. Once a user manually confirms or maps an unknown header, the platform saves the raw string into workspace_import_aliases for that workspace. workspace_import_aliases MUST NEVER modify or pollute global system attributes or Platform Attribute Library aliases. Each workspace's import memory is strictly isolated to its own workspace_id.

If automatic matching cannot reach safe confidence, the pipeline MUST pause and delegate control to a manual mapping interface.

The import flow should feel simple to the user.

The mapping complexity must remain under the hood.

## 9. Configuration Over Custom Code

### Mandate

The platform is a multi-tenant SaaS product.

The core codebase MUST remain the same for all active workspaces.

Hardcoded workspace-specific behavior is prohibited.

### Rules

The system MUST NOT include logic such as:

if ($workspaceId === BABYPARK_ID) {
    // special flow
}

or:

if ($companyName === 'Specific Client') {
    // custom behavior
}

Different business requirements MUST be handled through:

- feature flags;

- workspace settings;

- channel settings;

- Attribute Dictionary;

- custom attributes;

- price lists;

- pricing rules;

- customer groups;

- visibility rules;

- connector mappings;

- import/export profiles;

- WorkspaceOrderStatusMatrix configuration for order lifecycle rules.

Pilot requirements may influence reusable platform features.

Pilot requirements MUST NOT create a private custom system inside the SaaS core.

## 10. Reduction of PCI Scope and Payment Liability

### Mandate

The platform MUST avoid unnecessary payment data liability.

The platform should support payment flows through payment providers without accepting, processing or storing raw card data.

### Rules

The system core MUST NOT accept, process, transmit or store:

- raw credit card numbers;

- CVV/CVC codes;

- full sensitive cardholder data;

- customer financial credentials.

Online card payments MUST use provider-controlled flows such as:

- hosted payment pages;

- payment links;

- QR-code payment flows;

- secure tokenized widgets or scripts provided by the payment processor.

The platform may store:

- payment status;

- payment provider name;

- external payment ID;

- hosted payment URL;

- payment amount;

- currency;

- timestamps;

- webhook event references;

- reconciliation references.

Payment webhooks MUST be handled by dedicated webhook controllers/services.

Webhook processing MUST validate provider signatures or secrets before updating payment state.

Payment status updates MUST go through payment services, not random controller code.

The platform must reduce PCI scope by design.

It must not become a payment processor.

## 11. Simple UX Over Visible Enterprise Complexity

### Mandate

Architectural rigor must never compromise user experience.

Complex software design concepts belong in the backend.

The user interface must remain simple, practical and understandable.

### Rules

The interface MUST use everyday commercial terminology.

Preferred UI terms:

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

The interface MUST NOT expose technical architecture terms such as:

- tenant;

- EAV;

- aggregate;

- bounded context;

- price resolver;

- projection cache;

- state machine;

- database row policy;

- webhook signature secret;

- TTL reservation.

Advanced functionality MUST remain hidden, automated or contextual by default.

Examples:

- variants should be hidden behind a default variant in MVP;

- price lists should not overwhelm users who only need one price;

- multi-currency should not appear unless enabled or needed;

- advanced mappings should not be forced during quick import;

- payment gateway secrets should not appear in normal order screens;

- readiness checks should appear only in action context, not as permanent noise.

The product should remain usable by a non-technical user through simple trial and error.

Enterprise complexity may exist under the hood.

It must not be pushed into the user’s daily workflow.

## Connector operational security (reusable)

These principles apply to any external-system connector, not only Adobe:

- **SSRF-sensitive base URLs:** workspace users may enter external base URLs.
  Validate scheme (HTTPS only in production), block private/link-local/metadata
  targets, restrict redirects, apply DNS-rebinding-safe resolution, timeouts, and
  response size limits before server-side fetch.
- **Secrets encrypted and never logged:** credentials use Laravel `encrypted:array`
  in TEXT columns; secrets, tokens, and Authorization headers must not appear in
  logs, API resources, queue payloads, or exception reports.
- **Jobs carry IDs, not decrypted credentials:** queue jobs reference
  `connector_account_id`; decryption happens only after authorization inside the
  worker with explicit workspace context.
- **Immutable external-schema snapshots:** successful discovery produces append-only
  normalized snapshots; diffs are separate entities — never store before/after on
  snapshot field rows.
- **Operational history ≠ legacy summary logs:** connector connection checks,
  discovery runs, snapshots, and diffs use dedicated workspace-owned tables.
  Legacy `SyncLog` is not extended for connector events.
- **Connectors never dictate FieldDefinition core:** normalized external metadata
  is stored for discovery; canonical field mapping confirmation remains Task 4C.

# AI Implementation Rules and Code Generation Protocol

All AI-generated implementation steps, database migrations, class structures, service plans and code blocks MUST pass the Architecture Review Checklist before implementation, staging or merging.

This document defines the architectural constraints.

The detailed conversational mechanics, response framing templates, token-saving interaction steps, mandatory pre-code statements and immediate execution protocols are defined explicitly in `05-AI_WORKING_AGREEMENT.md`.

# Architecture Review Checklist

Before any code block, migration, pull request, generated file or implementation plan is finalized, merged or committed to the core application repository, it MUST pass this checklist.

Every applicable point MUST be verified.

## 1. Tenant Isolation

Does every newly defined or modified business table contain a workspace_id column where the data is workspace-owned or workspace-specific?

## 2. Automated Scoping

Is the workspace_id constraint enforced automatically through global model scopes or repository-level abstractions rather than manual controller string chaining?

## 3. Authorization and RBAC

Are user permissions governed through framework policies, gates, or dedicated authorization services instead of scattered conditional if/else checks inside controllers?

## 4. Attribute Dictionary Integrity

Are all newly required product fields registered through the dictionary as an AttributeDefinition instead of appending loose columns directly to core tables?

## 5. Attribute Storage Split

Does the dynamic data architecture strictly separate values into product_attribute_values and variant_attribute_values, completely avoiding a single consolidated polymorphic table?

## 6. JSONB Localization

Are all fields marked as is_localizable configured to store data as JSONB translation objects, completely avoiding flat string overwrites?

## 7. Field Duplication and workspace_import_aliases

Does the import mapping workflow utilize workspace_import_aliases to resolve tenant-specific custom headers without polluting global platform dictionary attributes?

## 8. Clean Domain Separation

Is the architecture free of a Product God Object? Are domains (catalog, inventory, pricing, orders, payments) separated into their proper isolated tables and entities?

## 9. Variant Cardinality Rule

Does the system automatically create a hidden default variant for simple single-SKU products, ensuring the user is never forced to understand variant mechanics in the MVP UI?

## 10. B2B Channel Projection

Is the native B2B storefront designed purely as a channel projection/read-model index over shared catalog data, rather than a duplicated editable product database?

## 11. Order and Payment Autonomy

Are order_status and payment_status managed as two distinct fields and separate operational tracks?

## 12. WorkspaceOrderStatusMatrix Enforcement

Are all changes to order_status routed strictly through validation logic provided by the WorkspaceOrderStatusMatrix (allowed_transitions_json), avoiding hardcoded status overrides?

## 13. Payment Webhook Routing

Do incoming payment gateway webhooks update payment_status first, allowing the payment_triggers_json configuration inside WorkspaceOrderStatusMatrix to determine order status changes without controller-level hardcoding?

## 14. InventoryReservation / Minimal TTL Soft Reservation

Does the checkout pipeline enforce minimal TTL-based soft reservations via InventoryReservation during order submission to protect against simultaneous double-selling?

## 15. Net Stock Calculation through AvailabilityResolver

Is the available quantity derived dynamically through AvailabilityResolver by calculating available_quantity_cache minus active unexpired pending InventoryReservation rows?

## 16. Historical Order Immutability

When an order is placed, are all relevant product titles, identifiers, prices, and attribute values snapshotted directly into order-scoped tables (order_items, snapshots), making past orders completely immune to subsequent catalog updates or price changes?

## 17. Connector Encapsulation

Are external API request/response footprints, third-party data shapes, and payload parsers strictly contained within the connector's domain layer, preventing leaky abstractions from polluting core platform entities?

## 18. No Hardcoded Clients

Are custom partner business logic, integration mapping quirks, or workflow overrides handled entirely via dynamic parameters, attributes, or workspace feature-flags, ensuring zero hardcoded buyer or supplier IDs in code?

## 19. Payment Data Safety

Is the architecture built to ensure raw credit card details or sensitive merchant gateway keys never touch or get logged by core application tables, relying strictly on secure tokenized references and isolated environment vaults?

## 20. Hidden Technical Complexity

Does the technical implementation completely hide software architecture jargon (such as EAV, tenant, core resolvers, state matrix, RLS, webhook secrets, or TTL reservation) away from user-facing screens, keeping the UI strictly limited to everyday business terminology?

## 21. External URL and SSRF Safety

When a workspace user supplies an external base URL for connector fetch or health
checks, are scheme, host, port, redirect, DNS, timeout, and size limits enforced
before any server-side request — blocking private networks and metadata endpoints?

## 22. Connector Secret Handling

Are connector credentials encrypted at rest, excluded from logs/queues/API
serialization, and decrypted only after authorization with workspace scope?

## Filament form validation standard

Every Filament panel form must render with `novalidate` on the `<form>` element so browser-native constraint validation never overrides application locale and inline field errors.

After a failed submit, a required-field error for a specific input must disappear once the user supplies a valid value — without resubmitting the whole form. Choose the mechanism per field (`live()` plus `validateOnly()` in `afterStateUpdated`, or an equivalent verified approach); do not apply `live()` blindly to every required `Select`.

The global Laravel `validation.required` message is short and field-name-free (`Заповніть це поле.` / `Заполните это поле.` / `Please fill in this field.`). This is intentional: required errors are always shown inline next to the field or returned together with the field key (for example in API responses), never as a detached summary without field identity.

# Final Principle

The platform must be powerful without feeling complicated.

Architecture must protect data, structure and future scalability.

The interface must protect the user from unnecessary complexity.

The correct solution is not the one with the most enterprise concepts visible.

The correct solution is the one where enterprise-grade structure works silently under the hood while the user can still:

- import products;

- edit product fields;

- publish a B2B catalogue;

- share a storefront link;

- receive orders;

- manage prices;

- manage availability;

- later accept payments;

without understanding the internal architecture.

Any implementation that makes the platform technically impressive but operationally confusing fails the product philosophy.