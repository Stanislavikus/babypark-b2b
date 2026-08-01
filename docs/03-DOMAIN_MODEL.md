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

For MVP, permissions may be simple.

Initial roles may include:

- owner;

- manager;

- viewer.

Future roles may include:

- product manager;

- sales manager;

- accountant;

- warehouse user;

- integration manager;

- admin.

The MVP should not overbuild role-based access control.

However, the model should not block future permissions.

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
- direction (enum: import | export | both)
- status (enum: draft | active | deprecated)
- notes (text, nullable)
- created_at / updated_at

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

`SyncLog` remains a **legacy summary log** for existing Babypark import/export
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
non-deleted rows.

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
| `started_at` | timestamp nullable | Null while `status: queued` |
| `finished_at` | timestamp nullable | Set only on terminal state (`succeeded`/`failed`/`cancelled`) |
| `duration_ms` | unsigned int nullable | |
| `fields_received` | unsigned int nullable | |
| `fields_normalized` | unsigned int nullable | |
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
| `field_count` | unsigned int | |
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

### ConnectorSchemaDiff / ConnectorSchemaDiffItem (Resolved)

`connector_schema_diffs` compares `from_snapshot_id` → `to_snapshot_id` with
aggregate counts. **First snapshot:** UI label `Перший знімок` — baseline, not
misleading “додано N” without explanation.

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
| **4C** | `FieldMapping` suggestions, confidence, confirmation, manual resolution |

Task 4B snapshots are **input** to Task 4C. Discovery must **not** auto-create
`FieldMapping` rows. Six Task 3 golden Adobe mappings (`sku`, `name`,
`description`, `short_description`, `category`, `status` in
`docs/data/canonical_product_field_mappings.csv`) are acceptance evidence only.

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

### ConnectorAccount authorization (Resolved)

Connector operations require `ConnectorAccountPolicy` checks on every read and
mutating action. Credential view/edit is limited to Admin, Director, and users
with `manage_connector_accounts`.

Merchandiser may run **manual** discovery for an active `ConnectorAccount` in
their own workspace, and may view its progress, result, safe error messages,
and discovered data. Merchandiser may **not**: view decrypted credentials;
create, edit, remove, or replace credentials; change `base_url`, `store_code`,
or `auth_profile`; or disable/archive a connection. Discovery dispatch goes
through policy and an application service, never a direct Filament/Eloquent
action; it records `initiated_by_user_id`, trigger, and a history row, and
respects the same account-level lock/overlap/rate-limit rules as any other
trigger. When Merchandiser needs a configuration or credential change, the UI
shows a safe recommendation to contact Admin/Director — it does not expose the
underlying restriction as a raw permission error.

Scheduled discovery remains a system-initiated operation; configuring it
(enabling/disabling, changing schedule) is an administrative-role action only.
This authorization decision does not implicitly extend any other Merchandiser
permission.

Decrypted credentials must never appear in API resources, logs, events, queue
payloads, or exception reports.

### Connection-check capability and error mapping (Resolved)

PaaS connection check is a single staged call:
`GET {base_url}/rest/{store_code}/V1/products/attributes?searchCriteria[pageSize]=1` —
this proves OAuth signature validity **and** product-attribute read permission
in one round trip. A two-stage check (lighter probe first) is only added later
if field testing shows the attribute-list endpoint is blocked while a lighter
endpoint passes.

| Vendor signal | HTTP | Cause | Actionability | User message key |
|---|---|---|---|---|
| Invalid/revoked token or consumer key | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_credentials` |
| OAuth signature/nonce/timestamp | 401 | `authentication` | `user_action_required` | `connectors.errors.invalid_signature` |
| Authenticated, ACL denied on attributes | 403 | `authorization` | `user_action_required` | `connectors.errors.insufficient_permissions` |
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

Raw vendor response bodies are never user-facing.

#### Adobe OAuth identifier vocabulary (Task 4B-2a-2b)

Protected REST API calls on Magento/Adobe Commerce use the Web API `ErrorProcessor`,
which serializes authentication and authorization failures as JSON
(`{"message": "..."}`), not the `oauth_problem=<identifier>` form-style body used
only on OAuth token endpoints (`/oauth/token/request`, `/oauth/token/access`).
Because no stable, machine-readable OAuth identifier can be extracted from
protected-REST error responses, connection-check execution uses HTTP-status-only
fallback for 401/403. The identifier vocabulary below is retained for enum
completeness and future use if Adobe exposes a reliable extraction path.

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
| `401` unrecognized | B7 row: `authentication`/`user_action_required`/`invalid_credentials` |
| `403` unrecognized | B7 row: `authorization`/`user_action_required`/`insufficient_permissions` |
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

- `ConnectorAccountPolicy::runConnectionCheck()` — dedicated ability (currently
  delegates to the same workspace/role rules as `view()`); dispatch uses
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

### FieldMapping


A FieldMapping maps external fields to platform attributes.

It may contain:

- workspace;

- connector account;

- source field;

- canonical mapping target.

  For Field Foundation-backed targets, the mapping references
  `canonical_field_binding_id`, not a bare FieldDefinition reference (see
  Field Foundation rename, GAP-016). Named "canonical", not "target",
  because ConnectorDefinition.direction can be import, export, or both: on
  import the canonical binding is the destination; on export it is the
  source. "Target" would be directionally wrong for export connectors.

  Domain-owned targets such as pricing, availability, media, or other
  service-resolved concepts are NOT represented by
  `canonical_field_binding_id` — they require a registered domain
  target/handler whose physical FieldMapping representation is not decided
  by this Stop-and-Amend and must be finalized before Task 4C. Task 4A does
  not create any `field_mappings` rows, so no representation is invented
  here.

- target level;

- transformation rule;

- direction;

- confidence;

- confirmed by user;

- status.

Field mappings must reference Attribute Dictionary definitions.

They must not map directly to random code fields unless those fields are documented as system attributes.

This is critical for import/export consistency.

### ImportJob and ExportJob


Imports and exports should be represented as jobs.

Possible entities:

- ImportJob

- ExportJob

- SyncJob

A job may contain:

- workspace;

- connector account;

- status;

- source file;

- started at;

- completed at;

- created by;

- summary;

- error log.

The MVP may implement only the minimum needed for spreadsheet import.

However, the domain model should support future scheduled sync.

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

Connector owns external system configuration.

Field Mapping owns external-to-platform field translation.

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

- FieldMapping;

- ImportJob.

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

Migration order: create `workspaces` → create default Babypark workspace → add nullable
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

Existing `products` columns not covered above (`onec_guid`, `barcode_box`,
`min_order_quantity`, `order_step`, `package_quantity`, `package_type`, `units_per_box`,
`boxes_per_pallet`, `lead_time_days`, `net_weight`, `gross_weight`, `volume_m3`, `depth_mm`,
`width_mm`, `height_mm`, `synced_at`) are intentionally out of scope for Phase 1 and are
registered in a later Phase 2 pass — this is an explicit, documented scope boundary, not an
oversight.

`rozetka_category_id`, `meta_title`, `meta_description` on `products` are channel-specific
fields that violate the Channel Mappings Protection rule in `02-ATTRIBUTE_DICTIONARY.md`. They
are NOT registered as System Attributes here. See `GAP-007` in `IMPLEMENTATION_GAPS.md`.

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
  mappings (`direction`, "Always use X" conflict rule) confirm the general
  shape of `FieldMapping.direction`/`authority` below — but HubSpot's public
  documentation does **not** confirm a persistent, record+field-level
  `FieldSyncOverride`. That entity is this platform's own design choice for a
  manual-first SaaS (users create records by hand before connecting an ERP),
  not an externally-validated pattern.

**Sync ownership is explicitly out of scope for the field registry itself** —
`FieldDefinition`/`FieldBinding` must never know about 1C, Odoo, CSV, or any
other external system, per Mandate 7 (Connector Independence). Synchronization
concerns are modeled as separate future entities, sequenced with Connector
Foundation (GAP-006). There is no separate "Sync Policy" entity — direction and
conflict authority live directly on `FieldMapping`, below:

- `FieldMapping` — external field ↔ internal `field_binding_id`, with
  `direction` (`external_to_saas` / `saas_to_external` / `bidirectional`) and
  `authority` (`external_system` / `saas` / `manual_review`).
- `ExternalRecordLink` — external record id ↔ internal Product/Customer/
  PriceList id, used for safe upsert instead of fuzzy/name-based matching.
- `FieldSyncOverride` — a per-record, per-field manual exception so a user who
  created records manually before connecting an ERP is never silently
  overwritten.

**UI direction (Resolved as part of the same decision):** a single settings
area, not one sidebar item per entity type:

```
Налаштування → Поля → [ Товари ] [ Клієнти ]
```

New tabs (Orders, Suppliers, ...) are added only when a real feature for that
entity type exists — not preemptively. Connector-specific field mapping lives
in a separate area (`Інтеграції → <integration> → Зіставлення полів`) and must
not be merged into the field registry UI. The `scope` column's UI label changes
from "Джерело" to **"Походження поля"** (values: "Системне" / "З бібліотеки" /
"Власне поле") so it does not collide with the future, distinct concept of
sync source (Вручну / Odoo / 1С / CSV / Google Sheets / API).

**Sequencing (Resolved):**

1. Contractor → Customer terminology/auth migration (model, table, FK, Filament
   resource, `config/auth.php` guard/provider, routes, services, tests — see
   Customers Context above and GAP-017).
2. Field Foundation migration itself (`FieldDefinition`/`FieldBinding`, three
   value tables, `workspace_import_aliases.field_binding_id` — see GAP-016).
3. Customer Fields UI (`Налаштування → Поля → [Клієнти]`).
4. Connector Foundation (GAP-006) — `FieldMapping` built against
   `field_binding_id` from the start, not against the old
   `attribute_definition_id` shape.

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

Connector work must use `FieldMapping` and the Field Foundation registry
(`FieldDefinition` / `FieldBinding`) from the beginning — see "Field Foundation
(cross-object fields)" above and GAP-006. `FieldMapping` must reference
`field_binding_id`, not a bare field code, since the same external column name
can be ambiguous across entity types (e.g. Product vs Customer).

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