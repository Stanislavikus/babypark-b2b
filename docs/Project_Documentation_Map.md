# docs/README.md

# Project Documentation Map

This folder contains the minimum set of documents required to guide product decisions,
architecture and AI-assisted development.

The goal of documentation is not to replace product development.

The goal is to prevent wrong architectural decisions and make implementation faster,
clearer and more consistent.

The platform must remain enterprise-grade under the hood while staying simple enough
for a non-technical user to operate without special training.

---

## 00-WHY.md

Explains why the platform exists.

This document defines the core belief:

- businesses should focus on selling products, not adapting product data to different systems;
- product information should be maintained once and reused everywhere;
- even a one-person business should have access to enterprise-grade product management
  without enterprise complexity.

This file should remain high-level and stable.

It should not describe database tables, UI screens or implementation details.

---

## 01-PRODUCT_VISION.md

Explains what the platform is being built to do.

This document defines:

- the first practical value of the platform;
- company workspace;
- native B2B catalogue / sales channel;
- native B2B storefront with grid, list and table views;
- how a business can register, add or import products, share a catalogue link
  and receive orders;
- how a small merchant can move from Excel / Google Sheets to a focused
  customer-facing product storefront without building a separate website;
- category navigation, search, sorting and basic filters as part of the B2B
  catalogue and storefront experience;
- product readiness profiles instead of gamification (B2B Ready, Google Feed Ready,
  SEO Ready, Export Ready, Order Ready);
- smart import with header normalization and guided field mapping;
- quick import mode vs advanced import mode;
- workspace-specific import mapping memory (workspace_import_aliases);
- progressive product onboarding — start with a name, enrich later;
- pricing model including base price, sale price, cost price, margin,
  customer-specific prices, customer group prices and future quantity tiers;
- availability model and soft stock control;
- out-of-stock order policy (allow / allow with warning / block / treat as preorder);
- minimal TTL-based soft reservation engine as an internal architectural safeguard
  during checkout and order submission — hidden from the merchant UI;
- orders with snapshots, order status and payment status as separate fields;
- notification channels for new orders;
- future online payment through hosted payment gateways;
- connector-based growth;
- Babypark pilot scope;
- what belongs to the product;
- what does not belong to the product;
- MVP scope and future direction.

**Open decisions still requiring resolution:**

- Catalogue URL model (platform.com/slug vs slug.platform.com vs custom domain);
- Wholesale pricing meaning (price type vs customer group price vs tier vs discount rule).

This is the main product document.

It explains product value, user journey and product boundaries.

It should not define detailed database tables or implementation rules.

---

## 02-ATTRIBUTE_DICTIONARY.md

Defines the controlled system for product fields.

This document exists to prevent the platform from becoming a chaotic collection
of hardcoded columns, import-specific fields, marketplace-specific fields
and one-off customer-specific logic.

This document describes:

- **Product Fields** as the user-facing UI concept;
- **Attribute Dictionary** as the internal architecture concept;
- three attribute levels: system attributes, platform attribute library,
  workspace custom attributes;
- assignment level rules: Product-Level, Variant-Level, Both;
- initial system attribute seed split by assignment level;
- platform attribute library seed (color, size, material, weight, etc.);
- workspace custom attributes scoped strictly to workspace_id;
- field creation from the product card through an anti-duplication mini-wizard;
- import aliases stored as database data, not hardcoded in import scripts;
- ImportHeaderNormalizer service — normalization steps before alias matching;
- smart import matching priority chain: exact code → normalized global alias →
  normalized localized label → saved workspace-specific historic mapping;
- fuzzy suggestion and manual mapping fallback;
- workspace_import_aliases as tenant-isolated memory for confirmed import headers —
  must never pollute global system or library aliases;
- product types / templates (Basic Product as default, hidden from user in MVP);
- product readiness profiles instead of gamification;
- attribute structure definition schema;
- attribute code specifications and generation rules;
- supported data types for MVP and future extension;
- JSONB localization mandate: if is_localizable = true, values stored as JSONB
  translation objects — flat string overwrites are prohibited;
- attribute groups for UI rendering;
- attribute scope: system, platform library, workspace custom;
- channel mapping protection — core tables must never contain
  google_title, rozetka_price or similar channel-specific columns;
- calculated fields (margin_percentage, b2b_readiness_status) must not be stored
  as editable attribute value rows;
- cost_price strictly classified as internal information — excluded from
  customer-facing storefront by default;
- editing permissions and roles;
- MVP scope and excluded items.

**Closed decisions:**

- Attribute storage model: hybrid (column-backed core fields + EAV dynamic tables).
- Translation storage: JSONB localization from day one; MVP UI shows primary
  workspace language only; dedicated translation tables are a future migration path.
- Readiness profiles: stored as configuration / seed data, not hardcoded.
- Product type UI: hidden in MVP.
- Category-specific attributes: future scope only.

This file must be read before adding any new product field, import column,
connector mapping, export field or B2B-specific product field.

The Attribute Dictionary is the guardrail that prevents product data chaos.

---

## 03-DOMAIN_MODEL.md

Defines the core business entities of the platform.

This document describes:

### Workspace Context

- Workspace (SaaS boundary; user-facing: Company / My Company);
- workspace_id as the technical tenant isolation key;
- workspace isolation rules and application-level scoping requirements.

### Users and Permissions Context

- User;
- WorkspaceUser;
- Role;
- Permission.

### Product Catalogue Context

- Product (shared product identity and common information);
- ProductVariant (concrete sellable SKU-level unit — hidden default in MVP);
- ProductType (default: Basic Product, hidden in MVP);
- Category (workspace-owned tree; no global taxonomy in MVP);
- MediaAsset / ProductMedia / VariantMedia.

### Attribute Dictionary Context

- AttributeDefinition (schema, validation, behavior profiles);
- Hybrid storage: column-backed core fields + dynamic EAV tables;
- product_attribute_values (product-level dynamic values);
- variant_attribute_values (variant-level dynamic values);
- These two tables must never be merged into a single polymorphic table;
- workspace_import_aliases (tenant-isolated synonym registry for import memory).

### Pricing Context

- PriceList;
- PriceListItem with volume tier support (quantity_min matrix);
- PricingRule (adjustments layered on top of PriceListItem);
- PriceResolver (domain service: resolves final price from variant, customer and quantity).

### Availability Context

- Operational availability cache on ProductVariant
  (available_quantity_cache, availability_status);
- InventoryRecord (transaction ledger for all stock movements);
- InventoryReservation — **resolved as mandatory from MVP** — minimal TTL-based
  soft reservation to prevent overbooking during checkout, order submission
  and payment-awaiting flow; strictly internal, never exposed in merchant UI;
- AvailabilityResolver — net stock formula:
  available_quantity_cache − SUM(pending InventoryReservation.quantity where not expired).

### Customers Context

- Customer (main B2B buyer entity; UI term: Customers / Клиенты);
- CustomerGroup (groups customers for pricing and visibility).

### B2B Channel Context

- B2BChannel (customer-facing catalogue/storefront configuration);
- B2B catalogue as a dynamic projection — not a copied product table;
- B2B storefront display modes: grid, list, table;
- B2B visibility rules.

### Orders Context

- Order (permanent document; parent fulfillment record);
- OrderItem (historical line snapshots — immutable after submission);
- WorkspaceOrderStatusMatrix — JSONB configuration map governing valid
  order_status transitions (allowed_transitions_json) and payment-triggered
  order status changes (payment_triggers_json); seeded for every workspace
  from MVP; no UI editor required in MVP.

### Payments Context

- Payment (payment attempt or transaction reference);
- PaymentGatewayAccount (workspace payment configuration; not required for MVP UI);
- Payment status (unpaid, awaiting_payment, paid, failed, refunded) is strictly
  separate from order status;
- No raw card data stored; hosted payment flows only.

### Connectors and Mappings Context

- ConnectorDefinition (global platform connector type);
- ConnectorAccount (workspace-specific connected account);
- FieldMapping (external field → platform attribute mapping);
- ImportJob / ExportJob / SyncJob.

### Billing Context

- Separate future context; does not affect product, order or payment logic in MVP.

### Domain Services

- ProductCreator, DefaultVariantCreator, AttributeValueWriter, PriceResolver,
  AvailabilityResolver, B2BPublicationChecker, B2BCatalogueProjector,
  B2BStorefrontPresenter, OrderCreator, OrderSnapshotBuilder,
  StockWarningEvaluator, PaymentRequestCreator, PaymentWebhookHandler,
  FieldMappingResolver, ImportHeaderNormalizer.

**Closed decisions:**

- Attribute value storage: separate product_attribute_values and
  variant_attribute_values; polymorphic table strictly forbidden.
- JSONB localization: resolved — all is_localizable = true values stored as JSONB.
- Reservation policy: resolved — InventoryReservation is mandatory from MVP.

**Open decisions still requiring resolution before implementation:**

- Catalogue URL model (must align with 01-PRODUCT_VISION.md open decision);
- Connector scope for MVP (which connector comes first);
- Billing scope for MVP.

This file guides database design, Laravel models, service boundaries and domain ownership.

---

## IMPLEMENTATION_GAPS.md

Records known, verified gaps between approved project documentation (00–07) and the actual
state of the codebase on `develop`.

This document describes:

- GAP-001 through GAP-007 — documented mismatches where architectural decisions are
  **Resolved** in docs but code has not yet caught up;
- rules for linking temporary workarounds to their GAP entry;
- explicit scope boundaries for Product Fields Foundation, Pricing, Availability,
  Workspace isolation, and Connector work.

Entries here are NOT open product questions. A gap must not be re-litigated as if it were
an open design question.

This file must be read before starting any Foundation implementation task (Product Fields,
Pricing, Availability, Workspace, Connector).

---

## 04-ARCHITECTURE_PRINCIPLES.md

Defines the technical and architectural rules of the platform.

This document explains how the platform must be built through 11 core architectural
mandates and a 20-item Architecture Review Checklist.

### 11 Core Architectural Mandates

1. **SaaS and Workspace Isolation** — workspace_id on every workspace-owned table;
   automated scoping enforced through model scopes, repositories and service layer;
   no manual where('workspace_id', ...) in controllers.

2. **Attribute Dictionary First and Storage Split Rule** — product_attribute_values
   and variant_attribute_values are separate; merging into a single polymorphic
   table is strictly forbidden; JSONB for all is_localizable = true fields.

3. **No Product God Object** — Product, ProductVariant, AttributeDefinition,
   PriceList/PriceListItem, InventoryRecord, B2BChannel, Order/OrderItem,
   Payment and Connector/FieldMapping must remain separate domain concerns.

4. **B2B Storefront Is a Channel, Not a CMS** — no page builder, no blog,
   no theme marketplace, no platform-wide seller discovery.

5. **No Duplicate B2B Product Model** — no b2b_products, storefront_products
   or catalogue_products as editable second sources of truth.

6. **Order and Payment Lifecycle Separation** — order_status and payment_status
   are separate; all order_status transitions validated through
   WorkspaceOrderStatusMatrix; payment webhooks update payment_status first,
   then payment_triggers_json determines order_status change.

7. **Connector Independence** — connectors adapt to the platform; the platform
   core does not adapt to connectors.

8. **Mapping Over Hardcoding** — no hardcoded language string assumptions in
   import/export/matching code; all header matching through normalization,
   AttributeDefinition aliases and FieldMapping.

9. **Configuration Over Custom Code** — no hardcoded workspace-specific or
   client-specific logic; WorkspaceOrderStatusMatrix governs order lifecycle rules.

10. **Reduction of PCI Scope and Payment Liability** — no raw card data;
    hosted payment flows only; webhook signature validation required.

11. **Simple UX Over Visible Enterprise Complexity** — enterprise terms
    (tenant, EAV, aggregate, resolver, TTL reservation, state matrix,
    webhook secret) must never appear in merchant-facing UI.

### Decision-Making Protocol

5-Layer Filter: Project Docs → Primary Source/Standards → Best Practice →
Architecture Match → UI Simplicity Verification.

### Source Priority: Hierarchy of Truth

1. Core project specification files (00–04);
2. Official regulatory, technical and industry standards;
3. Official framework and technology documentation;
4. Established architectural blueprints;
5. Verified real-world SaaS/PIM/e-commerce benchmarks;
6. AI inference and opinion (lowest priority).

### Architecture Review Checklist

20-item checklist covering: tenant isolation, automated scoping, authorization,
attribute dictionary integrity, attribute storage split, JSONB localization,
workspace_import_aliases usage, clean domain separation, variant cardinality rule,
B2B channel projection, order/payment autonomy, WorkspaceOrderStatusMatrix
enforcement, payment webhook routing, InventoryReservation / minimal TTL soft
reservation, net stock calculation through AvailabilityResolver, historical order
immutability, connector encapsulation, no hardcoded clients, payment data safety,
hidden technical complexity.

**The checklist lives only in 04-ARCHITECTURE_PRINCIPLES.md.**
AI agents must read it from the source file — never reconstruct from memory.

This file must be read before implementing any structural change.

It is the main guardrail for engineering decisions.

---

## 05-AI_WORKING_AGREEMENT.md

Defines how AI assistants must work on this project.

Applies to: Cursor Agent, Claude Code, GitHub Copilot Workspace, ChatGPT,
and any future AI-assisted development tool.

This document defines:

### Mandatory Reading Order

For any strategic, architectural, database, domain, security, payment, connector,
import/export, order, pricing, availability or B2B-related task, the AI must read:

1. 00-WHY.md
2. 01-PRODUCT_VISION.md
3. 02-ATTRIBUTE_DICTIONARY.md
4. 03-DOMAIN_MODEL.md
5. 04-ARCHITECTURE_PRINCIPLES.md
6. 05-AI_WORKING_AGREEMENT.md

The AI must not rely on memorized summaries when files are available.

### Two Execution Pathways

**Safe / Immediate Pathway** — for small, local, non-architectural tasks
(typo fixes, CSS/Tailwind adjustments, presentation-only Blade components,
formatting-only documentation cleanup). The AI may proceed without the
PRE-CODE block but must stop immediately if any architectural area is touched.

**Strict Alignment Pathway** — for all tasks involving database migrations,
models, domain services, controllers with business logic, authorization,
workspace scoping, Attribute Dictionary, pricing, availability, reservation,
orders, payments, connectors, imports/exports, B2B channel logic, or any
new field, entity, relation, enum, state, service or workflow.

### PRE-CODE ARCHITECTURAL ALIGNMENT Block

Required for every Strict Alignment Pathway task before any code or migration.
Contains: Task Type, Docs Checked, Affected Domain Contexts,
Primary Sources & Standards, Architecture Checklist Result,
Architecture Risks Identified, Chosen Technical Approach,
Non-Technical Simplicity Check, Stop & Amend Required.

### Architecture Review Checklist Enforcement

The AI must physically open and read the current Architecture Review Checklist
from 04-ARCHITECTURE_PRINCIPLES.md in the current session.
The AI must not reconstruct the checklist from memory.
Non-applicable checklist items must be justified briefly.
The AI must auto-adapt if the checklist in 04 is updated.

### No Hallucination Rule

The AI must clearly distinguish: explicitly defined in docs / inferred from docs /
proposed option / requires human decision / requires external source verification.

Forbidden phrases unless supported by project documents or shown reasoning:
"this is already supported", "the architecture allows this",
"the system should simply", "we can just add", "standard SaaS practice is".

### Stop and Amend Rule

If a task requires a new domain concept, field, table, relation, enum, status,
service boundary, lifecycle, connector behavior, pricing rule, availability rule,
payment rule or user-facing business concept not already in the approved documents,
the AI must stop, propose the exact Markdown patches to the affected files,
and obtain human approval before generating application code.

### Non-Technical Operational Viability Principle

The AI must verify that any proposed feature can be operated by a non-technical
business user without understanding database structures, EAV, multi-tenant
isolation, state matrices, TTL reservation, price resolvers, connector mappings,
payment webhooks, JSONB localization or internal service boundaries.

Preferred user-facing concepts: My Company, Products, Product Fields, Prices,
Availability, Customers, Orders, B2B Catalogue, Payment, Import, Export.

### Key Rules Covered

- No Hallucination Rule;
- Hierarchy of Truth (same 6-level priority as 04);
- Safe / Immediate Pathway vs Strict Alignment Pathway;
- PRE-CODE ARCHITECTURAL ALIGNMENT block;
- Architecture Review Checklist Enforcement (read from 04, never from memory);
- Primary Sources and Standards Check;
- Best Practice Verification;
- Stop and Amend Rule;
- Non-Technical Operational Viability Principle;
- UI Terminology Protection;
- Code Generation Rules;
- Database and Migration Rules;
- Attribute Dictionary Rules;
- Workspace Isolation and Authorization Rules;
- B2B Channel Rules;
- Pricing Rules;
- Availability and Reservation Rules;
- Order and Payment Rules;
- Payment and Security Rules;
- Connector, Import and Export Rules;
- Regional and Compliance Rules;
- Documentation Update Rules;
- Testing Requirements;
- Output Format for Planning Tasks;
- Output Format for Code Tasks;
- Small Task Exception;
- Failure and Uncertainty Protocol;
- Agentic Tool Use Requirements;
- Forbidden Behaviors list.

This file exists because AI must not rely on conversation memory.

Project decisions must live in files, not in chat history.

---

## 06-UI_DESIGN_SYSTEM.md

Defines the user interface rules, design-system boundaries and AI UI decision protocol.

This document translates enterprise-grade internal architecture into a simple,
familiar, zero-training interface for non-technical small-business users.

This document describes:

- Zero-Training Business Usability Principle and critical UI defaults;
- visual reference: Google Sheets, Gmail, Shopify Admin — not ERP screens;
- admin product table defaults, toolbar, column visibility and row action zones;
- B2B buyer table defaults and buyer visibility policy;
- context drawer patterns for both admin and B2B, with exact content specs;
- quantity selector, cart drawer, checkout flow and order success loop;
- admin order processing pattern and approved action buttons;
- product card and product detail page patterns;
- pricing display rules by role (anonymous, identified buyer, admin);
- availability display policy, human-friendly dates and availability color system;
- theme, branding and accent color token system (raw / accessible / onAccent / soft);
- bulk actions pattern including cross-page select-all;
- empty states, onboarding checklist and progressive disclosure rules;
- toast and notification rules with position and duration;
- form validation, loading states and error microcopy;
- mobile rules including breakpoints and B2B buyer bottom navigation;
- accessibility and cognitive simplicity rules;
- source-of-truth field display and connector mapping UI pattern;
- forbidden UI patterns;
- PRE-UI DESIGN CHECK protocol for AI coding agents.

This file must be read before implementing any UI screen, component, table,
drawer, form, cart, order flow or mobile layout.

---

## 07-TECH_STACK.md

Implementation guardrail for Cursor and AI coding agents.

This document does not replace architecture decisions. It tells the agent
which stack and existing patterns must be used when implementing UI.

This document defines:

- application stack: Laravel, Livewire, Alpine.js, Filament, Tailwind CSS;
- two existing Filament panels: `/admin` and `/cabinet`;
- rules for extending Filament vs. creating new components;
- B2B storefront stack (same Laravel / Filament / Livewire / Tailwind in MVP);
- file and code conventions;
- existing shared patterns to prefer and not duplicate;
- styling rules and design token usage;
- data and domain boundaries — UI must call domain services, not redefine them;
- task prompt template for Cursor;
- recommended implementation order: table → drawer → quantity/cart → mobile → polish.

This file must be read alongside `06-UI_DESIGN_SYSTEM.md` before writing any
frontend code, Filament resource, Livewire component or Blade template.

---

## Reading Order

**For product decisions:**

- 00-WHY.md
- 01-PRODUCT_VISION.md
- 02-ATTRIBUTE_DICTIONARY.md
- 03-DOMAIN_MODEL.md

**For product-field, connector-mapping, seed or import/export decisions:**

- 00-WHY.md
- 01-PRODUCT_VISION.md
- 02-ATTRIBUTE_DICTIONARY.md
- 03-DOMAIN_MODEL.md
- CANONICAL_PRODUCT_FIELD_REGISTRY.md and the related `docs/data/*.csv`
- IMPLEMENTATION_GAPS.md — open gaps affecting the field or mapping in question

**For architecture decisions:**

- 00-WHY.md
- 01-PRODUCT_VISION.md
- 02-ATTRIBUTE_DICTIONARY.md
- 03-DOMAIN_MODEL.md
- 04-ARCHITECTURE_PRINCIPLES.md

**For AI-assisted implementation (architecture and domain tasks):**

- 00-WHY.md
- 01-PRODUCT_VISION.md
- 02-ATTRIBUTE_DICTIONARY.md
- 03-DOMAIN_MODEL.md
- CANONICAL_PRODUCT_FIELD_REGISTRY.md and the related `docs/data/*.csv`
- 04-ARCHITECTURE_PRINCIPLES.md
- 05-AI_WORKING_AGREEMENT.md

**For AI-assisted implementation (UI and frontend tasks):**

- 05-AI_WORKING_AGREEMENT.md
- 06-UI_DESIGN_SYSTEM.md
- 07-TECH_STACK.md
- relevant sections of 03-DOMAIN_MODEL.md where domain data is displayed

---

## Documentation Rule

Documentation should stay minimal but strict.

The project should not create documents for every small feature.

New documents should be added only when they prevent real architectural confusion
or help implementation stay consistent.

The current core documentation set is intentionally complete but not over-documented:

- why the platform exists;
- what the product should do;
- how product fields are governed;
- what the core domain model is;
- how the architecture must behave;
- how AI assistants must work with the project;
- how the user interface must look and behave;
- which tech stack and patterns to use for implementation.

Implementation details may later live in code, migrations, issues or feature specs.

Core architectural and UI decisions must live in these documents.

---

## Closed Architectural Decisions

The following decisions are formally closed and must not be reopened.

| Decision | Resolution |
|---|---|
| Attribute value storage model | Separate product_attribute_values and variant_attribute_values; polymorphic table strictly forbidden |
| JSONB localization | All is_localizable = true values stored as JSONB; flat string overwrites prohibited |
| InventoryReservation in MVP | Mandatory — minimal TTL-based soft reservation required from MVP; hidden from merchant UI |
| Reservation behavior | Soft stock control at policy level + internal TTL-based overbooking protection during checkout; merchant sees only stock warnings and order attention flags; WMS excluded from MVP |
| Default variant in MVP | Every product gets a hidden default variant; user never sees variant mechanics in MVP |
| Product type UI in MVP | Basic Product type only; type configuration hidden from user in MVP |
| B2B as channel, not CMS | B2B storefront uses shared product data; no b2b_products copy tables |
| B2B storefront MVP depth | MVP includes category navigation, search, sorting, table view, grid/card view, cart and order submission; excludes page builder, CMS, blog, marketplace discovery and advanced customization |
| WorkspaceOrderStatusMatrix | Mandatory from MVP; seeded for every workspace; no UI editor required in MVP |
| Connector independence | Connectors adapt to the platform; platform core does not adapt to connectors |
| No hardcoded client logic | Configuration and WorkspaceOrderStatusMatrix govern all workspace-specific behavior |
| No raw payment data | Hosted payment flows only; platform stores only status and external references |
| Price resolver priority | 6-level resolution order; customer-specific rule → CustomerGroup rule → customer-assigned PriceList → CustomerGroup PriceList → default workspace PriceList → cached variant base price |
| Availability source of truth | available_quantity_cache on ProductVariant is the MVP read path; maintained via InventoryRecord; AvailabilityResolver subtracts active InventoryReservation rows |
| Payment status automation | payment_status updated by payment events; order_status changes only via payment_triggers_json in WorkspaceOrderStatusMatrix; hardcoded triggers forbidden |
| Payment implementation timing | Payment domain is future-ready from the beginning; full payment gateway UI is not required for MVP unless it becomes a commercial priority |
| Pilot versus SaaS MVP | Babypark may influence connector priority, but all work must remain reusable; no Babypark-specific hardcoding |
| Company vs Workspace naming | Database: workspaces; code: Workspace; UI: Company / My Company; tenant remains technical-only terminology |

---

## Open Decisions Requiring Resolution Before Implementation

The following decisions are not yet formally closed. Resolution is required
before the relevant domain area is implemented.

| Decision | Relevant Files | Status |
|---|---|---|
| Catalogue URL model | 01 | Must be resolved before B2B channel routing is implemented |
| Wholesale pricing meaning | 01 | Must be resolved before PricingRule is implemented |
| Connector scope for MVP | 03 | Must be resolved before connector development starts |
| Billing scope for MVP | 03 | Deferred; simple workspace plan flags until resolved |
