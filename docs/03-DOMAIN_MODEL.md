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

The platform should not introduce global taxonomy in MVP.

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

## Attribute Dictionary Context


The Attribute Dictionary manages field metadata definitions, distinct from the storage of actual values. It acts as the structural registry for both core system fields and custom vendor properties, enforcing data integrity before any product updates reach the database.

### Hybrid Attribute Storage Implementation


To balance high performance with infinite extensibility, the platform utilizes a hybrid storage engine:

- **Column-Backed Fields:** Core operational and transaction-critical metrics (product_name, sku, gtin, status, cached prices, and quantities) are kept as standard database columns for indexing, rapid sorting, and foreign key integrity.

- **Dynamic Fields:** Extensible, tenant-specific properties (e.g., color, material, or custom vendor attributes) are stored in Entity-Attribute-Value (EAV) structures.

- **The Registry Rule:** The Attribute Dictionary tracks all available fields. Every registered
  attribute must define its storage_type (**column, relation, or dynamic**) to prevent structural
  duplication and instruct data-access services where to read or write the data payload.
  `computed` is a `data_type` value only (see Computed Fields Operational Boundary) and is never
  a valid `storage_type`.

### Core Entity: AttributeDefinition


Defines the schema, validation rules, and behavior profiles for a product field.

- id (UUID)
- workspace_id (UUID, nullable for system/platform-wide definitions)
- code (String/Slug, immutable)
- data_type (Enum): text, long_text, number, decimal, money, boolean, date, select, multi_select,
  image, url, computed
- scope (Enum): system, platform_library, workspace_custom
- value_level (Enum): product, variant, both
- storage_type (Enum): column, relation, dynamic
- storage_path (String, nullable): e.g. `product_variants.barcode_ean`; null for dynamic fields
- attribute_group (String, stable snake_case code: basic_information, identifiers, pricing,
  availability, images_media, descriptions, characteristics, b2b, seo, logistics, internal);
  UI labels for groups are translated via Laravel lang/config files, not stored per-definition
- is_required (Boolean)
- is_filterable (Boolean)
- is_sortable (Boolean)
- visibility_settings (JSONB): e.g. {"admin": true, "b2b": false, "channels": {}}
- validation_rules (JSONB, nullable)
- is_localizable (Boolean)
- is_multi_value (Boolean)
- status (Enum): active, archived
- sort_order (Integer)
- localized_labels (JSONB)

### Strict Architectural Rules for Localization and Values


- **JSONB Storage Mandate:** If an AttributeDefinition has is_localizable = true, the application and database must store its values strictly within a **JSONB structure** inside the dynamic value tables or column entries. Flat string overwrites are prohibited.

- **Separated Value Tables:** Dynamic values are strictly isolated based on their hierarchy:

- product_attribute_values: Contains (id, workspace_id, product_id, attribute_definition_id,
  value_text, value_num, value_jsonb).
- variant_attribute_values: Contains (id, workspace_id, variant_id, attribute_definition_id,
  value_text, value_num, value_jsonb).

- Write Routing: If is_localizable is true, strings are formatted as language dictionaries and committed to value_jsonb. If false, data goes to value_text or value_num based on the configuration.

### Anti-Duplication and Smart Import Layer


To power the Anti-Duplication Wizard and prevent users or sloppy import spreadsheets from generating redundant fields (e.g., creating "Цвет", "Color", and "Колір" as three separate definitions), the dictionary includes a tenant-isolated synonym registry.

- Entity: workspace_import_aliases

- id (UUID): Primary key.

- workspace_id (UUID): Binds the alias scope to a specific tenant.

- attribute_definition_id (UUID): Foreign key mapping back to the true immutable system or custom definition.

- alias_name (String): Normalized string token (e.g., колор, цвет, colour).

- source (String, nullable): Import/connector origin of this alias (e.g. "1c",
  "google_sheets"), for future Connector Foundation (GAP-006) disambiguation.
  Null means manually registered / source-agnostic — do not store "manual" as a literal value.

- Validation Rule: Before the system creates a new custom attribute, the Anti-Duplication Wizard checks the input name against existing code entries, localized_labels, and workspace_import_aliases. If a match is found, the system blocks creation and suggests mapping to the existing field instead.

### Computed Fields Operational Boundary


Attributes registered with data_type = 'computed' (such as margin_percentage or b2b_readiness_status) represent derived calculations.

- **No Physical Persistence Rule:** The platform is strictly forbidden from allocating physical rows or strings within the product_attribute_values or variant_attribute_values tables for computed types.

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

### B2B Catalogue Projection


A B2B catalogue is not a copied product table.

It is a projection built from:

- products;

- variants;

- categories;

- product selection rules;

- customer group;

- price list;

- pricing rules;

- availability;

- visibility rules;

- channel settings.

The platform may use helper tables for performance.

However, those tables must be treated as cache or configuration, not as a separate product model.

The B2B channel must always use the shared product model, shared pricing model and shared availability model.

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

The platform should not require a global taxonomy for storefront navigation.

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

### ConnectorDefinition


A ConnectorDefinition is a global platform definition of a connector type.

Examples:

- Google Sheets;

- CSV import;

- Excel import;

- 1C;

- Magento;

- API;

- marketplace feed.

It may contain:

- code;

- name;

- type;

- direction;

- capabilities;

- status.

This is global platform data.

### ConnectorAccount


A ConnectorAccount is a workspace-specific connected account or configured source.

It may contain:

- workspace;

- connector definition;

- name;

- credentials;

- settings;

- status;

- last sync at.

Credentials must be stored securely.

Connector accounts belong to a workspace.

### FieldMapping


A FieldMapping maps external fields to platform attributes.

It may contain:

- workspace;

- connector account;

- source field;

- target attribute definition;

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

Attribute Definition owns field meaning.

ProductAttributeValue and VariantAttributeValue own dynamic field values according to product-level or variant-level assignment.

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

- AttributeDefinition;

- ProductAttributeValue / VariantAttributeValue;

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

Attributes:

- attribute_definitions

- workspace_import_aliases

- product_attribute_values

- variant_attribute_values

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

**Resolved.**

The platform uses separate isolated tables:

- `product_attribute_values` for product-level dynamic fields;
- `variant_attribute_values` for variant-level dynamic fields.

A unified polymorphic attribute value table is strictly forbidden by the Storage Split Mandate in `04-ARCHITECTURE_PRINCIPLES.md`.

This decision is closed and must not be reopened without a documentation-level decision.

### Attribute storage model

**Resolved.**

The platform uses a hybrid attribute storage model:

- System/core operational fields (product_name, brand, category, sku, gtin, status, cost_price,
  etc.) remain column-backed or relation-backed on `products` / `product_variants`, for indexing,
  sorting and FK integrity.
- Dynamic/custom/tenant-specific fields are stored in `product_attribute_values` /
  `variant_attribute_values`.
- Every field, regardless of storage location, is registered in `attribute_definitions`, which
  tracks its `storage_type` (`column | relation | dynamic`) and, for column/relation fields, its
  `storage_path`.
- `computed` is a `data_type`, never a `storage_type`; computed attributes have no physical
  persistence (see Computed Fields Operational Boundary), and in MVP are limited to
  system-defined read-only fields — merchants cannot create custom computed fields.
- Dynamic value tables store only `value_text`, `value_num`, `value_jsonb`. Boolean values use
  `value_num` (0/1) with an explicit convention; date values use `value_text` in ISO-8601 or
  `value_jsonb`. Adding dedicated `value_boolean` / `value_date` columns requires a separate,
  explicit documentation-level decision.

This decision is closed and must not be reopened without a documentation-level decision.

### Workspace_id minimum rollout scope for Product Fields Foundation

**Resolved.**

The combined Workspace Foundation Lite + Product Fields Foundation implementation task must add
`workspace_id` to, at minimum:

- `products`
- `product_variants`
- `categories`
- `attribute_definitions`
- `product_attribute_values`
- `variant_attribute_values`
- `workspace_import_aliases`

Migration order: create `workspaces` → create default Babypark workspace → add nullable
`workspace_id` to `products` / `product_variants` / `categories` → backfill existing rows to the
default workspace → make `workspace_id` not-nullable where safe → create the new Product Fields
tables with `workspace_id` present from their first migration.

Tables not listed above (`orders`, `contractors`, `prices`, `stocks`, `reservations`,
`sync_logs`) remain explicitly out of scope for this task and stay tracked under GAP-004 as
separate backlog items. This task must not silently skip them nor silently include them.

This decision is closed and must not be reopened without a documentation-level decision.

### System Attribute seed scope for Product Fields Foundation

**Resolved.**

The initial `attribute_definitions` seed for Product Fields Foundation (Phase 1) registers only
System Attributes whose storage is verified stable on `develop` today and whose storage path
does not contradict the documented value level.

Product-level Phase 1 seed:

- `internal_product_id` — storage_path: `products.id`, data_type: number. Note: 02 describes
  this attribute as a UUID; the current implementation uses a Laravel auto-increment integer
  primary key, not a UUID. This mismatch is documented here and does not block Phase 1; it may
  be revisited separately.
- `product_name` — storage_path: `products.name`
- `brand` — storage_path: `products.brand`
- `category` — storage_type: relation, storage_path: `products.category_id`
- `description` — storage_path: `products.description`
- `status` — storage_path: `products.is_active`; interim convention:
  `is_active=true → active`, `is_active=false → archived`; `draft` is not distinguishable until
  a real product lifecycle status field exists.
- `product_url` — storage_path: `products.product_url` (added via a dedicated migration after
  the base `products` table was created; verified present on `develop`).

Variant-level Phase 1 seed:

- `sku` — storage_path: `product_variants.sku`; canonical. The duplicate `products.sku` column
  is legacy and is not used as a storage path; tracked as backlog technical debt.
- `gtin` — storage_path: `product_variants.barcode_ean`; canonical. The duplicate
  `products.barcode_ean` column is legacy and is not used as a storage path; tracked as backlog
  technical debt.

Explicitly excluded from Phase 1 seed, with no placeholder record created:

- `price`, `sale_price`, `cost_price` — deferred to Pricing MVP Foundation (GAP-001). `price`
  and `cost_price` are jointly required by the `margin_percentage` computed field and must be
  resolved together, at the correct value_level, once PriceResolver-backed storage exists.
  `cost_price` currently physically exists only on `products` (added via a dedicated later
  migration), while 02 classifies it as Variant-Level — this mismatch is intentionally not
  resolved by registering it prematurely.
- `availability` — deferred to Availability Foundation (GAP-002).
- `image` — deferred. Current `products.images` (JSON) is product-level legacy storage; 02
  classifies `image` as Variant-Level. Registering it now would lock in a value-level mismatch.
  Deferred until product/variant media storage is explicitly resolved.
- `unit` — deferred. Current `products.unit` is product-level; 02 classifies `unit` as
  Variant-Level. Same class of mismatch as `image`; deferred until explicitly resolved.
- `condition` — deferred. No physical storage column exists for `condition` anywhere in the
  current schema (verified: absent from both `products` and `product_variants`).

Existing `products` columns not covered above (`onec_guid`, `barcode_box`,
`min_order_quantity`, `order_step`, `package_quantity`, `package_type`, `units_per_box`,
`boxes_per_pallet`, `lead_time_days`, `weight_netto`, `weight_brutto`, `volume_m3`, `depth_mm`,
`width_mm`, `height_mm`, `synced_at`) are intentionally out of scope for Phase 1 and are
registered in a later Phase 2 pass — this is an explicit, documented scope boundary, not an
oversight.

`rozetka_category_id`, `meta_title`, `meta_description` on `products` are channel-specific
fields that violate the Channel Mappings Protection rule in `02-ATTRIBUTE_DICTIONARY.md`. They
are NOT registered as System Attributes here. See `GAP-007` in `IMPLEMENTATION_GAPS.md`.

The pre-existing `product_variants.attributes` JSON column (cast as `array` on the
`ProductVariant` model) is a legacy ad-hoc dynamic-attribute mechanism. The Product Fields
Foundation implementation task must first inspect which keys actually occur in production data
and produce a migration plan. Actual migration of this data into `variant_attribute_values` is
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
nullable — null means "use `config('pricing.default_vat_rate')`") is added to the documented
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

### Connector scope


The MVP should define which connector comes first.

Likely candidates:

- Excel / CSV import;

- Google Sheets;

- ERP / 1C for Babypark pilot.

Connector work must use FieldMapping and Attribute Dictionary from the beginning.

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