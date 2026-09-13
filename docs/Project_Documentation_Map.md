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
- reference-client validation (pilots validate; they do not define the platform);
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
- Workspace RBAC physical architecture (Resolved — GAP-026-0): WorkspaceUser-centric
  custom RBAC; explicit `Workspace` authorization; five physical tables; seven atomic
  permissions; anti-lockout on `Workspace` row lock — see `03-DOMAIN_MODEL.md`.
- Workspace RBAC authority cutover (Resolved — GAP-026B-0): narrow Connector/Tax/
  Mapping/Access permission cutover; capability-based connector presentation;
  existing-memberships-only Access; one-time maintenance cutover — see
  `03-DOMAIN_MODEL.md`.

### Product Catalogue Context

- Product (shared product identity and common information);
- ProductVariant (concrete sellable SKU-level unit — 0..N; hidden default in MVP UI);
- ProductType (default: Basic Product, hidden in MVP);
- Category (workspace-owned tree; no global taxonomy in MVP);
- MediaAsset / ProductMedia / VariantMedia (conceptual first-class assets; current runtime is `products.images` JSON);
- Platform Product Capability Baseline (Resolved): heterogeneous catalogues, configurable/variant families, bundle/kit composition as a distinct future capability.

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

- ConnectorDefinition (global platform connector type; `direction` is coarse
  platform envelope only);
- ConnectorAccount (workspace-specific connected account);
- SyncConfiguration (account + data_domain + external_context; owns enabled
  semantic operations, selection, schedule state, mappings, revision);
- FieldMapping (direction-neutral semantic correspondence owned by
  SyncConfiguration; first persistence contract resolved — Task 4C-1a, 2026-08-12;
  `field_binding_id` + `external_field_key` minimum slice for `products` domain);
- SyncRun / SyncRunItem (preview/live execution evidence; SyncRunItem =
  business-record outcome; first physical contract resolved — Task 4C-2a);
- ExternalRecordLink (account-scoped internal ↔ external record identity).

Historical note: earlier drafts listed `ImportJob` / `ExportJob` / `SyncJob`
as primary sync entities; superseded by the Sync Domain Rebaseline in
`03-DOMAIN_MODEL.md`.

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
- Billing scope for MVP.

This file guides database design, Laravel models, service boundaries and domain ownership.

---

## IMPLEMENTATION_GAPS.md

Records known, verified gaps between approved project documentation (00–08) and the actual
state of the codebase on `develop`.

This document describes:

- the authoritative current list of verified implementation gaps between approved
  project documentation and `develop` — see `docs/IMPLEMENTATION_GAPS.md` for every
  open, partially closed, and historically closed gap entry;
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
mandates and a 22-item Architecture Review Checklist.

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

22-item checklist covering: tenant isolation, automated scoping, authorization,
attribute dictionary integrity, attribute storage split, JSONB localization,
workspace_import_aliases usage, clean domain separation, variant cardinality rule,
B2B channel projection, order/payment autonomy, WorkspaceOrderStatusMatrix
enforcement, payment webhook routing, InventoryReservation / minimal TTL soft
reservation, net stock calculation through AvailabilityResolver, historical order
immutability, connector encapsulation, no hardcoded clients, payment data safety,
hidden technical complexity, external URL / SSRF safety, connector secret handling.

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

## 08-CONNECTOR_SYNC_RUNTIME_ATLAS.md

Current-state implementation index — not normative architecture.

Product/domain normative docs (`00`–`07`, Canonical Registry, UX contracts)
remain authoritative. This Atlas locates current implementation.

Hierarchy:

```text
[Resolved] normative docs
        ↓
actual current code / migrations / tests
        ↓
Atlas as locator/index
```

The Atlas must never override either normative decisions or verified runtime truth.

An implementation owner listed in the Atlas must still be verified in code before
modification. Atlas is same-PR maintained: only touched Connector/Sync seams are
re-verified. Mechanical tests prove owner-path referential integrity only, not
semantic freshness.

---

## 09-CONNECTOR_DELIVERY_PROTOCOL.md

Mandatory delivery and certification protocol for Magento / Adobe Commerce and every
future external connector.

This document exists to prevent connector work from degrading into an arbitrary
field-by-field or GAP-by-GAP sequence.

It defines the connector campaign as:

`authoritative external inventory → classify all fields into clusters → map every field to a platform owner/representation → implement only proven missing seams → real representative READ/WRITE probe per cluster → capture actual errors → fix root causes → rerun → field-by-field certification`

For mutable fields, the default goal is working change propagation in both directions:
external system → platform and platform → external system, wherever the external
platform actually permits those operations.

Every connector task must be selected from a concrete missing capability, failed probe,
or uncertified field/cluster that blocks the final field-complete result. Individual
fields, stages, GAPs, endpoints and architecture foundations are checkpoints, not the
connector goal.

This file is mandatory reading for any connector implementation, connector planning,
connector certification, import/export transport expansion, or real-target validation task.

---

## Connector inventory research baselines

### ADOBE_COMMERCE_V1_INVENTORY_RESEARCH.md / ADOBE_COMMERCE_V1_INVENTORY_REVIEW_SYNTHESIS.md

Frozen Adobe Commerce / Magento Product field + capability research baseline and its
review arbitration. Read these, plus the related `docs/data/adobe_commerce_v1_*.csv`,
when comparing external commerce vocabularies, assigning platform ownership, or
planning Adobe field/capability certification. The research inventory does not replace
the normative Domain Model or the runtime/certification matrix.

### SHOPIFY_V1_INVENTORY_RESEARCH.md

Current Shopify Product / ProductVariant field + capability research baseline. Read it,
plus the related `docs/data/shopify_v1_*.csv`, for Shopify connector research,
cross-platform field-library synthesis, Shopify schema/version review, and future
Shopify READ/WRITE certification. Shopify remains one external ecosystem and does not
define platform core fields. Until its adversarial review/freeze gate is completed,
treat the document's explicit status marker as authoritative.

### Google Merchant / BigCommerce / Amazon synthesis research inputs

For cross-platform Product vocabulary synthesis, also read the current data artifacts:

- `docs/data/google_merchant_products_v1_attribute_inventory.csv` and
  `google_merchant_products_v1_product_input_inventory.csv` — Google Merchant Products v1
  publication vocabulary and wrapper/context evidence;
- `docs/data/bigcommerce_v3_product_capability_inventory.csv` — current BigCommerce OpenAPI
  Product/Variant/Options/Modifiers/Pricing/Inventory capability evidence;
- `docs/data/amazon_product_type_definitions_meta_model_inventory.csv` and
  `amazon_listings_v1_public_ptd_luggage_inventory.csv` — Amazon PTD meta-model and
  representative Product Type schema challenger; not a static import of every Amazon type;
- `docs/data/cross_platform_product_field_synthesis.csv` — semantic comparison/ownership
  working set across project contracts and external ecosystems.

These artifacts are research evidence, not account discovery state and not merchant-confirmed
`FieldMapping` rows. `CANONICAL_PRODUCT_FIELD_REGISTRY.md` v8 remains the governance contract
for what is promoted into canonical platform/domain/channel knowledge.

### proposals/FIVE_PROVIDER_CANONICAL_VNEXT_2026-09-09.md

Lead five-provider synthesis proposal over Adobe Commerce, Google Merchant, BigCommerce,
Amazon and Shopify. Read it with `docs/data/five_provider_canonical_vnext_decisions.csv`
when reviewing the proposed vNext canonical vocabulary, Missing Concept Union, promotion,
owner/binding gates, or merchant-completeness coverage. It is explicitly **non-authoritative**
until adversarial review and documentation approval; it does not supersede the current
Canonical Product Field Registry or authorize runtime/seed changes.

### CANONICAL_VOCABULARY_COVERAGE_LEDGER.md

Gate 1 research contract for the machine-verifiable external-vocabulary coverage ledger.
Read it with the provider shards and provisional provider concept graphs under
`docs/data/canonical-coverage/` when auditing raw-row provenance, dispositions,
provider-local semantic merges, or the disagreement queue. It does not freeze the
cross-platform vocabulary or define runtime/storage behavior.

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
- 08-CONNECTOR_SYNC_RUNTIME_ATLAS.md — current-state locator only; verify owners in code
- 09-CONNECTOR_DELIVERY_PROTOCOL.md — mandatory for connector/import/export delivery sequencing and certification
- `docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md` — authoritative
  current-base Magento V1 Product field/capability inventory; cluster summaries are
  classification only, not field certification
- `docs/connectors/adobe-commerce/MAGENTO_V1_CONNECTION_UX_CONTRACT.md` — frozen Magento V1 onboarding / connection truth / credential rotation / permission-remediation / recovery contract; mandatory for Magento connection UI and connection-health runtime work
- `docs/connectors/adobe-commerce/MAGENTO_V1_PENDING_CERTIFICATION_ITEMS.md` — durable queue of Magento V1 surfaces deliberately deferred during certification; mandatory pre-read before resuming Magento field/surface work so pending blockers are not lost or silently reclassified
- `docs/connectors/adobe-commerce/MAGENTO_V1_FIELD_PROGRESS.md` + `magento_v1_stage1_real_target_evidence_2026_09_13.json` — Magento Stage 1 runtime-derived resume index and generated per-field evidence export (106/106 current-target identities). Persisted runtime classification is authoritative; the older 2026-09-12 CSV is historical mutation/certification evidence only and must not become a second editable classification truth
- `docs/connectors/adobe-commerce/MAGENTO_V1_RESEARCH_QUEUE.md` — durable fail-closed queue for Magento provider fields/surfaces/semantics that lack sufficient authoritative evidence; unresolved items must not be promoted to provider-standard/dedicated-owner runtime claims and must remain `review_needed` until evidence is recorded
- `docs/connectors/adobe-commerce/MAGENTO_V1_STAGE2_CUSTOM_ATTRIBUTES_SETS_GROUPS_HANDOFF.md` — Stage 2 handoff for workspace custom-field materialization and Magento attribute set/group research/implementation; consumes Stage 1 runtime classification rather than re-inventorying Product attributes
- `docs/connectors/adobe-commerce/MAGENTO_V1_RECEIVE_R4_DYNAMIC_SELECT_STOP_AND_AMEND_2026_09_13.md` — additive R4 Receive contract for mapped workspace custom Dynamic single-select fields; exact option reverse mapping, CAS Apply, no implicit remote-absent clear; public Import support remains false.
- `docs/connectors/adobe-commerce/magento_v1_receive_r4_dynamic_select_evidence_2026_09_13.json` — R4 code/test closure evidence: exact option reverse resolution, Dynamic CAS, stale-state guards, RemoteAbsent≠Clear, broad Connector/Sync gates; real-target destructive certification remains P-07.
- `docs/connectors/adobe-commerce/MAGENTO_V1_STAGE2_STRUCTURE_RESEARCH_SYNTHESIS.md` — frozen Stage 2 provider-structure contract: stable `attribute_id` lineage, Product Attribute Set applicability, provider-only groups, option identity/localized labels, and the ordered foundation → reconciler → materializer implementation slices
- `docs/connectors/adobe-commerce/magento_v1_stage2_structure_reconciliation_evidence_2026_09_13.json` — live read-only Stage 2 structure proof: two consecutive reconciliations with stable provider identities and counts (106 attributes / 4 Product sets / 42 Product groups / 284 memberships / 1387 options), no Product writes
- `docs/connectors/adobe-commerce/magento_v1_stage2c_materialization_evidence_2026_09_13.json` — live Stage 2-C workspace materialization proof: 18 trusted Variant single-select custom attributes in configured Attribute Set 9 materialized idempotently into 18 workspace definitions/bindings/mappings and 924 option mappings; second run preserved all identities and created no duplicate definitions/bindings
- `docs/connectors/adobe-commerce/MAGENTO_V1_PROVIDER_IDENTITY_EVIDENCE_2026_09_13.md` — authoritative source log used to close Stage 1 real-target provider-identity questions without widening write capability
- `docs/connectors/adobe-commerce/magento_v1_custom_behavior_coverage_2026_09_12.json` — read-only real-catalog evidence for the 21 current-target workspace-custom fields not individually mutated: 16 `select/global` + 5 `money/website`, with live-value presence counts and reusable behavior-class onboarding classification
- `docs/connectors/adobe-commerce/magento_v1_store_scope_inheritance_probe_2026_09_12.json` — P-10 read-only real-target evidence: store topology, scoped-attribute metadata, unavailable authoritative raw-EAV seams, and the deliberate zero-PUT decision pending DB/Admin or target-side diagnostic proof
- `ADOBE_COMMERCE_V1_INVENTORY_RESEARCH.md` + review synthesis + related Adobe CSVs —
  frozen cross-platform Adobe research baseline
- `SHOPIFY_V1_INVENTORY_RESEARCH.md` + related Shopify CSVs — frozen Shopify 2026-07 Product/capability research baseline; obey its frozen/not-frozen status marker
- Google Merchant Products v1, BigCommerce OpenAPI, Amazon PTD and `cross_platform_product_field_synthesis.csv` artifacts above — current cross-platform canonical-synthesis evidence
- `docs/prototypes/task-4b0-connector-account/` — Task 4B-0 visual contract
  (when implementing connector operational UI)

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
- 08-CONNECTOR_SYNC_RUNTIME_ATLAS.md when the task touches Connector/Sync seams
  (locator only; verify current owner in code before modification)
- 09-CONNECTOR_DELIVERY_PROTOCOL.md when the task touches connector implementation,
  import/export transport, real-target validation, or connector certification
- `docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md` when the task
  touches Magento Product inventory, certification, stock Product reads, or entity-bound
  Safe Sync Product writes
- `docs/connectors/adobe-commerce/MAGENTO_V1_CONNECTION_UX_CONTRACT.md` when the task
  touches Magento onboarding, connection checks, credentials, store scope, permission remediation, connection recovery, or WRITE-readiness presentation
- `ADOBE_COMMERCE_V1_INVENTORY_RESEARCH.md` and `SHOPIFY_V1_INVENTORY_RESEARCH.md`
  when the task touches cross-platform Product field/capability research or either
  connector inventory baseline

**For AI-assisted implementation (UI and frontend tasks):**

- 05-AI_WORKING_AGREEMENT.md
- 06-UI_DESIGN_SYSTEM.md
- 07-TECH_STACK.md
- relevant sections of 03-DOMAIN_MODEL.md where domain data is displayed
- `docs/connectors/adobe-commerce/MAGENTO_V1_CONNECTION_UX_CONTRACT.md` for Magento onboarding / connection / credential / permission / recovery surfaces

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
- which tech stack and patterns to use for implementation;
- where current Connector/Sync implementation actually lives (Atlas locator);
- how connectors are driven from complete field inventory through real error-driven
  validation to field-by-field certification.

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
| Default variant in MVP | Every simple product gets a hidden default variant for MVP UI simplicity; user never sees variant mechanics unless needed. This is UX hiding, not Magento simplification. Product + 0..N ProductVariants remains the architecture invariant |
| Configurable / variant product family | First-class platform capability, distinct from bundle/kit/composite composition |
| Bundle / kit / composite products | Legitimate future Product composition capability; not equated with variants; not in Magento Product Export V1 |
| Pilot versus SaaS MVP | Reference clients validate the platform; they do not define the platform. Pilot needs may influence connector priority. No named-customer hardcoding |
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
| Company vs Workspace naming | Database: workspaces; code: Workspace; UI: Company / My Company; tenant remains technical-only terminology |
| Workspace RBAC physical model | WorkspaceUser-centric custom RBAC; explicit `Workspace` authorization; DB-enforced same-workspace role assignment; additive workspace roles; anti-lockout serialized on `Workspace` row — see `03-DOMAIN_MODEL.md` → Workspace RBAC physical architecture (Resolved — GAP-026-0) |
| Workspace RBAC authority cutover (GAP-026B-0) | Permission-authoritative Connector/Tax/Mapping/Access scopes; capability-based safe connector presentation; existing-memberships-only Access management; guarded User lifecycle; one-time maintenance cutover; new-membership onboarding deferred to GAP-027 — see `03-DOMAIN_MODEL.md` → Workspace RBAC authority cutover (Resolved — GAP-026B-0) |

---

## Open Decisions Requiring Resolution Before Implementation

The following decisions are not yet formally closed. Resolution is required
before the relevant domain area is implemented.

| Decision | Relevant Files | Status |
|---|---|---|
| Catalogue URL model | 01 | Must be resolved before B2B channel routing is implemented |
| Wholesale pricing meaning | 01 | Must be resolved before PricingRule is implemented |
| Connector scope for MVP | 03 | **Resolved** — Adobe Commerce PaaS/on-prem first (`03-DOMAIN_MODEL.md`, Connector scope (Resolved)) |
| Billing scope for MVP | 03 | Deferred; simple workspace plan flags until resolved |
