# 01-PRODUCT_VISION.md

# Product Vision

## Product Summary

The platform is a universal multi-tenant SaaS e-commerce Product Data Platform for small and growing product businesses. It is not limited to one assortment, industry, catalogue structure, or first connected commerce account.

It allows a business to manage product information once and use it across its own B2B sales channel, product storefront, catalogues, feeds, APIs, marketplace connectors and future integrations.

The first practical value of the platform is not only data management.

The first practical value is that a business can register, add or import products, publish its own B2B catalogue / storefront, share a link with customers and receive orders through the platform.

For small merchants, the platform may later also allow customers to pay online through a connected payment gateway.

The platform must combine:

- product data management;

- native B2B sales;

- a focused B2B storefront;

- connector-based growth;

- simple onboarding;

- standards-based product structure;

- usability suitable even for one-person businesses.

The platform should feel simple enough for a non-technical user who previously worked only in Excel or Google Sheets.

Internally, the platform may be enterprise-grade.

Externally, it must remain simple, clear and controllable.

## Primary User

The first target user is the owner or manager of a small product business who manages products and sales without a programmer.

This user may be:

- a wholesaler;

- an importer;

- a distributor;

- a small online seller;

- a shop owner;

- a supplier;

- a business that currently works through Excel, Google Sheets, ERP, messengers or manual catalogues.

The platform should be understandable to a person who is used to spreadsheets, email and basic online tools.

It should not require a developer, analyst, content manager or integration specialist to get initial value.

The product must assume that a small business user may leave immediately if the interface becomes too complex.

The system should therefore hide internal complexity and expose only practical actions.

## First User Journey

The ideal first journey should be simple:

- The user registers.

- The platform creates a company workspace.

- The workspace receives its own catalogue / storefront URL.

- The user adds or imports products.

- The user starts with a minimal product dataset and may enrich it later with full product information.

- The user enables the native B2B catalogue.

- The platform publishes a customer-facing B2B storefront.

- The user shares the catalogue link with customers.

- Customers browse products, categories, cards, lists or tables depending on channel settings.

- Customers see the information allowed for them.

- Customers add products to an order and submit the order.

- The platform saves the order and notifies the business through configured communication channels.

- If external connectors are configured, the order may be sent to an external system such as ERP.

- In the future, if online payment is enabled, the customer may pay through a hosted payment gateway.

The first “wow moment” should be:

I added products and within minutes I can send a customer a working B2B catalogue link and receive an order.

For small merchants, the stronger future “wow moment” should be:

I imported products from a spreadsheet and received my own focused B2B storefront without building a website, using a marketplace or competing with other sellers.

A measurable onboarding goal:

A user with a simple spreadsheet containing 5 products should be able to create a workspace, import products, publish a B2B catalogue and get a shareable link in under 5 minutes.

This metric is a product direction, not a strict technical guarantee for every real-world case.

## Progressive Product Onboarding

The platform must not require a complete product data model before the user receives value.

A one-person business must be able to start with a minimal product dataset and enrich it later.

The platform should support progressive disclosure of product data:

- Create or import basic products.

- Publish a basic B2B catalogue / storefront.

- Receive the first order or request.

- Improve product cards with descriptions, images, identifiers, product fields, SEO fields and channel-specific data over time.

For the first import, the platform should not force the user to map twenty fields manually.

A simple spreadsheet should be enough to start.

The minimum dataset for a quick B2B catalogue may include:

- product name;

- price or pricing mode;

- availability or availability mode;

- SKU / article number where available.

The platform may generate internal identifiers automatically when SKU is missing.

Product description should be recommended, but not globally required.

Product images should be recommended, but not globally required.

Product condition should exist as a standard product field with a default value of new, because condition is required by many commerce standards and channels.

Other condition values such as used or refurbished should be supported where applicable.

The platform should guide the user to enrich product data after the first value moment instead of blocking the first value moment.

The platform should not use arbitrary decorative completeness percentages as a motivational tool.

Instead, it should show clear readiness information only when it helps the user complete a specific action.

## Product Readiness Instead of Gamification

The platform should avoid meaningless gamification.

The system should not show arbitrary product completeness percentages just to motivate the user.

A percentage such as Product card completed: 65% is not useful if the user does not understand what action it affects.

The platform should focus on readiness for specific actions.

Examples:

- B2B Ready;

- Google Feed Ready;

- SEO Ready;

- Export Ready;

- Order Ready.

Readiness should answer a practical question:

Can this product be used for this action now?

If the answer is no, the platform should explain what is missing.

Examples:

- Cannot publish to B2B: price is missing.

- Can publish to B2B, but image is recommended.

- Cannot export to Google Merchant: GTIN or brand is missing.

- SEO content can be improved: description is missing.

The interface should remain clean by default.

Readiness should not be shown as permanent visual noise.

The platform should show readiness information only in the context of a user action:

- during import;

- before publishing;

- before exporting;

- inside channel setup;

- inside channel readiness checks;

- when a product cannot be used for the intended action;

- when a missing field blocks or meaningfully weakens the intended action.

The default product card should stay clean.

The platform should not pressure the user to complete fields that are not required for the current goal.

Product data quality should be improved through clear, contextual and actionable guidance, not through decorative scores.

If a readiness score or profile is introduced later, it must be based on explicit channel requirements or workspace configuration, not arbitrary hardcoded weights.

## Smart Import and Mapping

The import flow must be optimized for fast onboarding.

The platform should support smart field recognition for common spreadsheet column names.

For example:

- Назва, Название, Наименование, Name, Title may map to product name;

- Артикул, Код, SKU, Code, Item Code may map to SKU / article number;

- EAN, GTIN, Barcode, Штрихкод may map to EAN / GTIN;

- Цена, Ціна, Price, РРЦ may map to a price field;

- Остаток, Залишок, Stock, Availability may map to availability.

The MVP should support guided mapping with suggested matches.

AI-assisted mapping may be added later, but the core import logic must not depend on AI.

The platform should save import mappings per workspace and source so that repeated imports become easier over time.

The import process should support two modes:

- Quick Import — minimal fields, fast catalogue creation;

- Advanced Import — full mapping through Attribute Dictionary and connector rules.

The goal is that the first import feels closer to uploading a simple spreadsheet than configuring an enterprise PIM system.

## Import Header Normalization

The import flow must not depend on exact column spelling.

When importing spreadsheets or CSV files, the platform should normalize column headers before matching them to product fields.

Header matching should be:

- case-insensitive;

- whitespace-insensitive;

- tolerant to leading and trailing spaces;

- tolerant to repeated spaces;

- tolerant to non-breaking spaces;

- tolerant to common punctuation differences;

- tolerant to common localized column names;

- tolerant to common synonyms used in spreadsheets.

For example:

- Цена, цена, Цена, price, Price should be recognized as possible price columns;

- Назва, назва, Название, Name, Title should be recognized as possible product name columns;

- Артикул, артикул, SKU, Code should be recognized as possible SKU / article number columns.

The goal is that a user can upload a real-world spreadsheet without cleaning every column name manually before import.

## Company Workspace

Each business has its own isolated workspace.

A workspace represents one company or seller and contains its own:

- products;

- product catalogues;

- B2B storefronts;

- users;

- customers;

- prices;

- cost prices;

- availability;

- orders;

- future payments;

- B2B channel settings;

- contact information;

- communication channels;

- source connectors;

- output connectors;

- billing / subscription settings.

The platform uses Customer as the main business-facing term for buyers and B2B counterparties.

A customer may represent:

- a retail buyer;

- a wholesale buyer;

- a dealer;

- a reseller;

- a distributor;

- a partner account;

- another business that can access the B2B catalogue, receive prices and place orders.

The platform should not use Contractor as the main product term because it is too accounting-specific and may confuse non-technical users.

External systems such as ERP or 1C may use the term contractor.

Connectors may map external contractors to platform customers.

If supplier management is needed later, suppliers should be modeled separately and should not be mixed into the customer model.

The platform must support multiple independent businesses from the beginning.

No company-specific logic should be hardcoded into the platform core.

Company-specific behavior must be handled through:

- configuration;

- permissions;

- mappings;

- channel settings;

- pricing rules;

- customer rules;

- reusable platform abstractions.

## Native B2B Catalogue

B2B is the first native sales channel of the platform.

It is not just an export connector and not just a mapped feed.

The B2B catalogue is a customer-facing sales channel that uses the same core product data, prices, availability and customer rules as the rest of the platform.

The native B2B catalogue should allow a business to:

- publish product cards;

- show product images;

- show product descriptions;

- show availability;

- show prices according to access rules;

- allow customers to browse products;

- allow customers to search, sort and filter products where enabled;

- allow customers to create orders;

- notify the business about new orders;

- optionally send orders to connected external systems;

- support future hosted online payments.

The B2B catalogue must not have a separate product model, separate price model or separate availability model.

B2B-specific behavior should be implemented through:

- channel configuration;

- customer access rules;

- pricing rules;

- visibility rules;

- permissions;

- templates where needed.

## Native B2B Storefront and Product Views

The native B2B catalogue should not be limited to a flat product table.

It should also work as a simple customer-facing product storefront.

This is especially important for small businesses that do not have their own website and currently manage products only in Excel or Google Sheets.

The platform should allow a company to present its products in a more convenient way without building a separate online store.

The B2B storefront may support different product views:

- product cards / grid view;

- list view;

- table view;

- category-based navigation.

This allows different businesses to use the catalogue in different ways.

For a wholesale business, a compact table view may be more useful.

For a small merchant without a website, product cards and categories may make the catalogue feel like a simple online storefront.

The customer should be able to browse products, filter or sort them, open product cards, add items to an order and submit the order.

In the future, if online payment is enabled, the customer may also pay directly through the B2B storefront.

The storefront should support:

- workspace-owned categories;

- product cards;

- product list/table;

- search;

- sorting;

- basic filters;

- cart / order creation;

- future payment button.

This does not mean that the platform becomes a website builder, e-commerce CMS or marketplace.

Each workspace has its own isolated storefront.

Only that company’s products are shown.

There is no platform-wide marketplace search.

There is no competition between sellers inside the platform.

The B2B storefront is a native sales channel on top of the Product Data Platform.

The goal is simple:

A business that previously had only a Google Sheet should be able to import products, publish a catalogue, share a link, receive orders and later accept payment without hiring a developer or building a separate website.

## B2B Catalogue as a Dynamic Projection

The B2B catalogue must not be implemented as a static copy of products.

A customer-facing B2B catalogue is a dynamic projection of the shared product data.

It is formed by combining:

- product selection;

- product categories or product groups;

- price list or pricing policy;

- customer group;

- customer-specific rules;

- visibility rules;

- access mode;

- B2B channel configuration;

- storefront display settings where applicable.

This means that different customers may see different prices, different product availability, different visibility rules or different product groups without duplicating products.

The platform may use the word catalogue or storefront for the customer-facing experience.

However, internally the catalogue should behave like a configurable B2B view or price-list-driven sales projection.

The platform should support the business mindset of “price lists” without turning price lists into duplicated product tables.

A price list should represent pricing context, not a separate product database.

The B2B catalogue must always use the shared product model, shared pricing model and shared availability model.

## B2B Access Model

The platform should support configurable B2B access.

Possible access modes:

- public catalogue with visible prices;

- public catalogue with hidden prices until login;

- catalogue available only by invitation;

- customer login with personalized prices;

- mixed modes depending on company settings.

The architecture must support all of these modes.

The MVP may implement a simpler version first, but it must not block future access modes.

## Product Data Scope

The platform should manage structured product information.

This document does not define the complete list of product fields.

The complete product field list must be defined in the Attribute Dictionary.

Canonical fields are defaults and known concepts, not the maximum allowed product model. The platform Product vocabulary is extensible through FieldDefinition, FieldBinding, workspace custom fields, mapping, and dynamic values.

Product + 0..N ProductVariants is the normal platform cardinality. See `03-DOMAIN_MODEL.md` → Platform Product Capability Baseline.

The Attribute Dictionary must normalize and document product fields from:

- authoritative product and commerce standards;

- Google Merchant product attributes where applicable;

- schema.org Product vocabulary where applicable;

- GS1 / GTIN / EAN-related concepts where applicable;

- ISO standards for currencies, countries, units and similar universal values;

- common e-commerce and PIM practices;

- Magento / Adobe Commerce product attribute patterns as a non-normative reference;

- B2B and wholesale-specific requirements;

- heterogeneous e-commerce catalogues across product verticals (illustrative only: apparel, footwear, electronics, home/furniture, toys, beauty, automotive parts, industrial products, food/non-food packaged goods, sports, specialty retail, B2B supplies). Vertical examples are not a closed enum and must not be encoded as generic Product-core logic.

Product information includes groups such as:

- product identity;

- SKU / article number;

- EAN / GTIN where available;

- product names;

- categories;

- brands;

- descriptions;

- images;

- product condition;

- product status;

- product URLs;

- prices;

- cost prices;

- margin-related data;

- availability;

- B2B visibility;

- customer-specific product rules;

- channel-specific attributes;

- SEO-related fields;

- logistics-related fields where needed;

- any other reusable product attributes defined in the Attribute Dictionary.

Business product fields must not be created randomly in database tables, import scripts, connectors or B2B-specific logic.

If a feature requires a new product field, the field must first be checked against the Attribute Dictionary.

If the field does not exist, the implementation must decide whether it should be:

- a standard product attribute;

- a custom product attribute;

- a channel-specific mapping field;

- a B2B-specific attribute;

- an internal operational field;

- a calculated value;

- or configuration rather than a product field.

The platform should prefer established standards and familiar field terminology whenever they provide a good solution.

Custom fields are allowed, but they must be explicitly documented in the Attribute Dictionary.

## Attribute Dictionary Requirement

The Attribute Dictionary is a core part of the platform.

It is the place where business product fields are defined, described, typed and connected to standards, imports, exports, channels and UI.

The Attribute Dictionary should define for each product field:

- internal field code;

- human-readable name;

- description;

- data type;

- unit where applicable;

- whether it is localizable;

- whether it is required;

- whether it supports multiple values;

- whether it is visible in admin;

- whether it is visible in B2B;

- whether it is used in imports;

- whether it is used in exports;

- related standard or source reference;

- related channel mappings;

- import aliases;

- status: draft, active, deprecated or archived.

The Attribute Dictionary prevents the platform from becoming a collection of hardcoded fields for different channels.

Connectors, imports, exports, feeds, B2B catalogue, SEO module and future integrations should use the Attribute Dictionary instead of inventing their own product fields.

## Attribute Aliases for Import

Each attribute may define import aliases.

Import aliases are alternative column names that may appear in Excel, CSV, Google Sheets, ERP exports or supplier files.

Examples:

- name may have aliases: Назва, Название, Наименование, Name, Title;

- sku may have aliases: Артикул, Код, SKU, Code, Item Code;

- gtin may have aliases: EAN, GTIN, Barcode, Штрихкод;

- price may have aliases: Цена, Ціна, Price, РРЦ;

- availability may have aliases: Остаток, Залишок, Stock, Availability.

Aliases should support different languages, sources and common business terminology.

The import engine should use these aliases to suggest field mappings automatically.

Aliases should be normalized before matching.

Normalization should include:

- trimming leading and trailing spaces;

- converting to lowercase / casefold form;

- replacing non-breaking spaces with normal spaces;

- collapsing repeated spaces;

- normalizing Unicode where applicable;

- ignoring harmless punctuation differences where safe.

Import aliases must be stored as data, not hardcoded only inside import scripts.

Workspace-specific aliases may be added later when a company repeatedly imports files with its own column naming conventions.

The detailed attribute model must be defined in 02-ATTRIBUTE_DICTIONARY.md.

## Pricing Vision

Pricing must be flexible enough for real B2B usage but simple enough for the first version.

The platform should distinguish between:

- public / base price;

- sale price where applicable;

- cost price;

- margin in absolute value and percentage;

- customer-specific price;

- customer discount;

- customer group price;

- customer group discount;

- future quantity-based price rules.

Cost price is internal business information.

It is used for margin calculation, analytics and decision-making.

It must not be exposed to customers unless explicitly allowed by configuration.

B2B pricing must be handled through a shared pricing model / pricing engine.

The platform must not create separate price logic only for B2B, only for marketplace feeds or only for one customer.

## Availability and Reservation Vision

The platform should manage product availability as part of the product sales process.

Availability may come from:

- manual entry;

- import;

- ERP connector;

- API connector;

- supplier feed;

- future warehouse-related integration.

The platform may show availability in the B2B catalogue according to company settings.

Orders should be saved in the platform.

When an order is created, the platform should support configurable behavior:

- save the order without stock reservation;

- create a soft reservation;

- wait for manager confirmation;

- wait for confirmation from an external system;

- update availability after confirmation.

For the first version, an order may be treated as a request until confirmed by the business or an external system.

The architecture must allow future reservation logic without rewriting the order model.

## Stock Control and Order Policy

Product visibility and stock behavior are separate concerns.

Product status controls whether a product is active and visible.

Availability controls how much stock is available or how availability should be displayed.

Order policy controls what happens when a customer orders more than is available.

The platform should support configurable out-of-stock behavior:

- allow order;

- allow order with warning;

- block order;

- treat as request / preorder.

For the MVP, the preferred default behavior is soft control.

If a customer orders more than available, the platform may allow the order but should clearly warn the customer that part of the order may require manager confirmation.

In the admin area, the order should be marked as requiring attention.

Example:

Ordered: 10 pcs. Available: 4 pcs.

This supports both types of sellers:

- sellers with strict stock control;

- sellers who work under order or manager confirmation.

The order model stores availability snapshots and stock warning flags at the moment of order submission.

To prevent simultaneous double-selling (overbooking) without introducing operational complexity for the merchant, the platform enforces a minimal implicit TTL-based soft reservation engine under the hood.

This soft reservation is strictly enforced during the checkout pipeline, order submission, and the payment-awaiting flow. It does not apply to casual cart additions or abandoned sessions, preventing premature inventory locking during early-stage browsing.

Full warehouse management systems (WMS), complex multi-location logistics routing, and administrative inventory reservation screens are excluded from MVP.

This internal mechanism must not expose TTL, reservation engine, state matrix or stock-locking terminology in the UI. The merchant sees only stock warnings and order attention flags.

## Orders

Orders created through the native B2B channel must be stored in the platform.

A new order should contain:

- company workspace;

- customer;

- ordered products;

- quantities;

- prices used at the moment of order;

- totals;

- order status;

- payment status;

- customer contact data;

- optional comment;

- source channel;

- notification status;

- external system sync status where applicable;

- availability snapshot where applicable;

- stock warning status where applicable.

Order status and payment status must be separate.

Order status describes the business lifecycle of the order.

Payment status describes whether money has been collected, is expected or has failed.

After order creation, the platform should notify the business through configured communication channels.

Possible notification channels:

- email;

- Telegram;

- Viber;

- WhatsApp;

- other future channels.

If an external connector is configured, the order may be transferred to that system.

If no external connector is configured, the order remains available inside the platform.

## Online Payment Vision

Online payments are not required for the first MVP UI.

However, the product should be architecturally ready to support them later.

This is especially important for small merchants who want to sell directly from their B2B storefront without building a separate website or using a marketplace.

The platform should support two business realities:

- B2B companies may work through invoice and bank transfer.

- Small businesses may want online payment through payment gateways.

For B2B companies, payment may mean:

- generate invoice;

- send invoice to customer;

- customer pays by bank transfer;

- external ERP/accounting system reconciles payment.

For small businesses, payment may mean:

- customer submits order;

- platform creates a hosted payment request;

- payment provider returns payment link or QR code;

- customer pays on provider side;

- provider sends webhook to the platform;

- platform updates payment status;

- platform may update order status according to workspace rules.

The platform should not collect or store card numbers.

Payment gateway integrations should use hosted payment pages, payment links, QR codes or similar provider-owned flows.

The platform should store only the payment status and external payment references needed for reconciliation, support and order processing.

Payment behavior must be configurable by workspace.

A successful payment may automatically confirm an order only if the workspace enables that behavior.

## Connectors and Data Flow

The platform must support connector-based growth.

Product information may enter the platform through source connectors:

- manual entry;

- Excel import;

- CSV import;

- Google Sheets import;

- ERP integration;

- API integration;

- supplier feeds;

- website import or migration;

- future external systems.

Product information may leave the platform through output connectors and channels:

- native B2B catalogue;

- B2B storefront;

- marketplace feeds;

- website feeds;

- API;

- product links;

- catalogues;

- SEO content;

- Google Sheets export;

- email export;

- scheduled file export;

- future sales channels and integrations.

Connectors are part of the product vision.

However, no connector should define the core product model.

Connectors must adapt external systems to the platform.

The platform core must not adapt itself to each external system through hardcoded fields and custom one-off logic.

## MVP Scope

The MVP should focus on the first usable product loop:

- Company workspace.

- Product creation.

- Quick product import.

- Smart import field suggestions.

- Basic product catalogue.

- Product images and descriptions as recommended enrichment.

- Prices.

- Cost price and margin visibility in admin.

- Availability.

- Soft stock control.

- Customer records.

- B2B catalogue access.

- Dynamic B2B catalogue projection.

- Basic B2B storefront.

- Workspace category navigation.

- Product table view.

- Product card / grid view.

- Basic search and sorting.

- Customer order creation.

- Order storage with availability snapshots and stock warning status.

- Order notifications.

- Basic export or file delivery where needed.

Explicitly excluded from MVP:

- full WMS, multi-warehouse logistics and user-facing reservation management screens;

- administrative reservation screens or stock-locking UI of any kind.

Under the hood, a minimal TTL-based soft reservation mechanism prevents overbooking during checkout and order submission. This is an internal architectural safeguard — TTL, reservation engine, state matrix and stock-locking terminology must never appear in the merchant-facing UI.

The MVP should prove that a small business can manage products, publish a focused B2B catalogue / storefront and receive orders without building a separate website or custom portal.

The MVP should not require full online payment integration.

However, the order model should include payment status so that payment gateway integration can be added later without rewriting the order model.

## Reference Clients Do Not Define the Platform

**Resolved.**

The platform is a customer-neutral multi-tenant SaaS Product Data Platform. It is not limited by the assortment, industry, catalogue structure, or connector needs of the first connected commerce account, pilot environment, or reference environment.

A reference client / pilot environment may:

- provide smoke evidence;
- provide production verification;
- provide UX feedback;
- provide real API fixtures.

A reference client / pilot environment must never:

- be the architecture target;
- define product scope;
- be the source of Product requirements;
- be the reason a connector capability exists;
- bound supported catalogue complexity;
- define Magento V1 completeness.

Reference clients validate the platform; they do not define the platform.

Connector families such as 1C, Adobe Commerce, Shopify, BigCommerce, Google Merchant, Rozetka, Google Sheets, and CSV are reusable platform integrations. Their priority may be influenced by a pilot schedule. Their capability must remain reusable platform capability.

## Admin Product Table and Views

The admin product area should provide practical operational views for product management.

For the first B2B/admin use case, useful table columns may include:

- photo;

- SKU / article number;

- EAN / GTIN;

- product name;

- category;

- brand;

- public / recommended price;

- actual selling price;

- cost price;

- margin percentage;

- margin amount;

- availability;

- product URL;

- product status.

The admin product area should support different views over the same product data.

Initial views may include:

- table view;

- card view.

Table view is useful for managing many products quickly.

Card view is useful for checking how product cards look in the B2B storefront.

Both views must use the same underlying product, variant, price, availability and attribute data.

Switching between table and card view must not create separate product records or separate catalogue records.

The admin product area should support:

- category filtering;

- status filtering;

- availability filtering;

- price sorting;

- search by product name, SKU or GTIN.

This table is an operational interface.

It must not define the entire product data model.

Additional fields should come from the Attribute Dictionary, configuration and channel-specific settings.

## Delivery and Shipping

Delivery settings may be useful for B2B sales but should not become a full logistics system in the first version.

The platform may later support:

- city-based delivery cost;

- free delivery threshold;

- simple delivery rules;

- delivery comments;

- future delivery connectors.

Delivery should be treated as B2B channel configuration or order-related configuration, not as full warehouse or logistics management.

Full shipping integrations can be added later as connectors.

## Future Modules

The platform should be designed so that additional modules can be added later without rewriting the core.

Future modules may include:

- marketplace connectors;

- website connectors;

- API access;

- scheduled exports;

- Google Sheets sync;

- delivery integrations;

- online payments;

- hosted payment gateway integrations;

- CRM-like customer workflows;

- simple internal tasks;

- simple communication features;

- analytics;

- AI SEO content generation.

AI SEO is an important future paid add-on.

It should be architecturally possible to connect it to product data, attributes, categories, keywords, descriptions, feeds and SEO fields.

AI SEO must be implemented as an optional module, not as a required part of the core product model.

Each customer should be able to configure their own AI/API credentials and usage limits where applicable.

## What Is Not the Product

The platform is not intended to become:

- a full ERP system;

- a full CRM system;

- an accounting system;

- a payroll system;

- a production management system;

- a full warehouse management system;

- a marketplace;

- a website builder;

- a full e-commerce CMS;

- a CMS page builder;

- a blog platform;

- a marketplace-style seller discovery platform.

The platform may integrate with these systems.

It may also provide selected lightweight features when they directly support product management, B2B sales or connector-based product distribution.

The native B2B storefront does not make the platform a website builder.

The storefront is a focused sales channel over the workspace’s product data.

Only the workspace’s products are shown.

There is no platform-wide marketplace search and no competition between sellers inside the platform.

The core product must remain focused on:

- product information;

- product availability;

- product prices;

- product content;

- product channels;

- B2B storefront;

- product orders created through the platform;

- connector-based growth.

## Product Principle

The correct product direction is balance.

B2B should be the first useful sales channel, but it must not become a separate B2B-only architecture.

The B2B storefront should make the product immediately useful, but it must not turn the platform into a full e-commerce CMS.

The platform should be built as a reusable Product Data Platform with B2B as the first native channel.

Every new capability should strengthen the shared core instead of creating isolated modules with duplicated logic.

The product should help a small business move from spreadsheet-based product management to a working customer-facing sales flow without forcing enterprise complexity into the user interface.

## Product Decisions

The following section records product-level decisions. Items marked **Resolved** are closed and must not be reopened without a documentation-level decision. Items without **Resolved** remain open and must be finalized before the relevant implementation starts.

### Catalogue URL model

Possible options:

- platform.com/company_slug

- company_slug.platform.com

- custom domain in the future.

The product vision requires each company to have its own customer-facing catalogue / storefront URL.

The exact technical URL strategy should be decided in architecture.

### B2B storefront MVP depth

**Resolved.**

The MVP B2B storefront includes:

- category navigation;

- search;

- sorting;

- table view;

- grid/card view;

- cart;

- order submission.

The MVP B2B storefront excludes:

- website themes;

- full page builder;

- CMS pages;

- blog;

- marketplace-style seller discovery;

- advanced storefront customization.

Simple workspace branding and approved display settings may be handled through B2BChannel configuration and future UI design system rules.

This decision is closed and must not be changed without a documentation-level decision.

### Reservation behavior

**Resolved.**

For MVP, the platform uses soft stock control at the product/order policy level and a minimal implicit TTL-based soft reservation safeguard under the hood during checkout, order submission and payment-awaiting flow.

Orders may still be treated as business requests until manager confirmation, payment confirmation or external system confirmation, depending on workspace configuration.

This does not remove the internal overbooking protection requirement.

The merchant-facing UI must not expose TTL, reservation engine, state matrix or stock-locking terminology.

The merchant should see only simple availability information, stock warnings and order attention flags.

Full WMS, multi-warehouse logistics and administrative reservation screens remain excluded from MVP.

Future changes to reservation behavior must be handled through a documentation-level decision and must not silently disable the approved overbooking protection model.

This decision is closed and must not be reopened without a documentation-level decision.

### Payment implementation timing

**Resolved.**

The domain model must include future-ready payment concepts from the beginning.

The MVP does not include full payment gateway UI unless online payment becomes a commercial priority before MVP release.

Payment gateway integration must be added later as a separate feature without changing the order model.

This decision is closed and must not be reopened without a documentation-level decision.

### Payment status automation

**Resolved.**

Payment updates `payment_status` only.

Any resulting change to `order_status` must be controlled by workspace-configured order transition rules.

A successful payment may automatically confirm an order only if the workspace enables that behavior.

Hardcoded automatic order status changes triggered directly by payment events are forbidden by architecture.

This decision is closed and must not be reopened without a documentation-level decision.

### Wholesale pricing meaning

The platform must clarify whether “wholesale price” means:

- a separate price type;

- a customer group price;

- a quantity-based tier price;

- a discount rule;

- or a combination of these.

The pricing model should support these concepts without hardcoding one interpretation.

### Pilot versus SaaS MVP

**Resolved.**

A pilot or reference environment may need particular connectors earlier than a generic SaaS MVP. Those needs may influence implementation priority.

All such work must be implemented as reusable connector, mapping, pricing or configuration functionality.

Named-customer or pilot-specific logic must never be hardcoded into the SaaS core.

Reference clients validate the platform; they do not define the platform.

This decision is closed and must not be reopened without a documentation-level decision.

## Long-Term Product Vision

The platform should allow a business to start simple:

- create a workspace;

- add or import products;

- publish a B2B catalogue / storefront;

- receive orders.

Then the business should be able to grow:

- connect external data sources;

- export feeds;

- add marketplace connectors;

- add SEO automation;

- add analytics;

- add payments;

- add delivery;

- add more users and permissions.

The customer should not need to replace the platform when the business grows.

The platform should grow with the customer.

A small merchant who previously worked only with Google Sheets should be able to get a focused product sales space without building a separate website, using a marketplace or competing with other sellers inside the platform.

A growing B2B company should be able to connect ERP, price lists, customer groups and external systems without replacing the platform.

The long-term goal is to let businesses focus on products and sales, while the platform handles structure, channels, data reuse and integrations under the hood.