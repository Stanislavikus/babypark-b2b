# 02-ATTRIBUTE_DICTIONARY.md


## Attribute Dictionary


### Purpose


The Attribute Dictionary is the controlled system for defining product fields. Its purpose is to prevent the platform from becoming a chaotic collection of hardcoded columns, import-specific fields, marketplace-specific fields and one-off customer-specific logic. 

The platform must allow powerful product data management while keeping the user experience simple enough for a non-technical business owner. The internal architecture may be enterprise-grade. The user experience must remain simple, familiar and understandable without training. 

### Naming


The platform uses different names depending on context. In documentation, architecture and code, the concept is called **Attribute Dictionary**. In the user interface, the concept should be called **Product Fields** (Ukrainian/Russian equivalent: **Поля товара**). 

The word *attribute* should generally not be exposed to ordinary users unless necessary. Users from small businesses usually think in terms of spreadsheet columns and product fields, not enterprise PIM attributes. 

### Core Principle


Business product fields must be defined before they are used. A product field must not be created randomly inside product cards, import scripts, marketplace connectors, B2B-specific code, export logic, SEO modules, or customer-specific customizations. 

If a feature needs a product field, it must use the Attribute Dictionary. If the field does not exist, the platform must decide whether to create it as: 

- a system attribute; 

- a platform library attribute; 

- a workspace custom attribute; 

- a channel-specific mapping; 

- a calculated value; 

- configuration instead of a product field. 

### User Experience Principle


The Attribute Dictionary must be powerful inside but almost invisible to the user. The user should experience it as simple, practical actions like adding popular fields, creating custom fields, or relying on automated import recognition and channel readiness checks. 

The base platform must work without AI. AI may later help with mapping, field suggestions, attribute extraction and data enrichment, but AI must remain optional. The user must understand and control every important action. 

### Attribute Levels ****&**** Assignment


The platform has three levels of product fields. Additionally, every attribute must explicitly define its **Assignment Level** to govern how values are stored in the database model (Product level vs. Variant level). 

### Assignment Level Rules


- **Product-Level**: The value is global and shared across all variations of the product (e.g., Brand, Description, Category Tree Node).

- **Variant-Level**: The value is specific to each distinct variation/SKU (e.g., Size, Color, Price, Stock).

- **Both**: The attribute can be defined at the Product level as a default value, but overridden at the Variant level if needed.

### Level 1: System Attributes


System attributes are core product fields provided by the platform. They are based on authoritative standards, common commerce requirements and platform architecture. System attributes cannot be deleted by a workspace. 

### Initial System Attributes Seed


The system must explicitly split system attributes by their architectural assignment level:

- **Product-Level Attributes** (Shared across all variants):

- internal_product_id — Internal system-generated unique product identifier (UUID). 

- product_name — Base product name. 

- brand — Brand or manufacturer name. 

- category — Category reference. **Crucial Rule**: This is not a flat text field. It is a system relation mapping the product to the Workspace Category Tree entity. During import, if a category path doesn't exist, the platform automatically creates the corresponding nodes in the tree structure. 

- description — Detailed product description. 

- status — Product lifecycle status (e.g., draft, active, archived). **Crucial Rule**: This field belongs strictly to the product lifecycle and channel visibility. It has absolutely no operational relation to order_status or payment_status. 

- product_url — Unique slug used for customer-facing B2B storefront routing. 

- **Variant-Level Attributes** (Specific to each product variant):

- sku — SKU / article number. 

- gtin — GTIN / EAN / Barcode. 

- price — Public / base price in workspace currency. 

- sale_price — Sale or promotional price where applicable. 

- cost_price — Internal business cost price used for margin calculations. *Must never be exposed to customers on the B2B storefront.* 

- availability — Cached available stock quantity used for fast storefront and checkout availability checks.

- image — Main variant image or media reference. 

- condition — Standard product condition enum with a default value of new (supports used, refurbished). 

- unit — Unit of measurement (e.g., pcs, kg, meters). 

### Level 2: Platform Attribute Library


The Platform Attribute Library is a prepared library of common product fields. These fields are not mandatory for every product, but they are useful across many categories, channels and businesses. 

- **Seed Library Attributes**: color, size, material, weight, length, width, height, volume, age_group, gender, country_of_origin, manufacturer, model, compatibility, package_quantity, battery_type. 

- In the UI, when the user clicks *Add field*, the platform searches the Platform Attribute Library first. If the user types *Цвет*, *Колір* or *Color*, the platform should suggest the existing library field instead of creating a duplicate custom field. 

### Level 3: Workspace Custom Attributes


Workspace custom attributes are fields created by a specific company when neither a system attribute nor a platform library attribute fits the business need. 

- **Examples**: cable_length, bowl_attachment_type, paint_resistance, supplier_specific_compatibility. 

- For MVP, workspace custom attributes are global for the workspace. This means that if a company creates *Cable Length*, that field can be used for any product in that company. Category-specific custom fields may be added later. 

- When a custom field is created via the product card mini-wizard, the system must prompt or automatically detect whether it should be assigned at the **Product** or **Variant** level to prevent data structure corruption.

### Adding a Field from the Product Card


Users should be allowed to create or add fields while editing a product through a controlled mini-wizard, not through uncontrolled free-form inputs. 

- User opens a product card and clicks **+ Add field**. 

- User types a field name (e.g., *Цвет*). 

- The platform searches system attributes, library attributes, existing custom attributes, import aliases, and localized names. 

- If a match exists, the platform suggests using the existing field. If no match exists, the platform offers to create a new workspace custom field with a chosen default type and assignment level. 

- Advanced settings (data type validation, localization tabs, channel visibility) can be managed later in the Field Settings dashboard to keep the initial flow fast. 

### Field Creation Governance (Anti-Duplication Wizard)


To prevent the dictionary from turning into a graveyard of duplicate fields (e.g., color_1, color_custom, cvet), the interface enforces a strict mini-wizard when clicking + Add field:

- As the user types, real-time lookups query the dictionary's codes, labels, translations, and global aliases.

- If a match or close synonym is detected (e.g., typing “Колір” when color is available), the wizard blocks blind creation and states: «The field “Color” already exists in the system library. Would you like to activate it for your products instead?»

- A custom field is generated only if the user explicitly rejects the library suggestion.

### Product Types and Templates


In documentation and architecture, they are called **Product Templates** or **Product Types**; in the UI, the preferred term is **Product Type** (Ukrainian/Russian: **Тип товара**). 

- For MVP, product types should be mostly invisible to the user. The default product type should be **Basic Product** (Ukrainian/Russian: **Обычный товар**). 

- Every product uses this default type by default so the user isn't forced to configure complex sets during onboarding. 

### Required Fields ****&**** Readiness Logic


The platform must clearly separate fields required for basic system operations from constraints imposed by specific sales channels. 

### Required for Product Creation


Only **one** field is strictly required to save a product record:

- product_name

If the user drops an entry with just a name, the system generates an internal product ID / internal SKU and saves it. All other metadata can be progressively enriched later. 

### Required for B2B Publication


A product is valid for the native customer-facing B2B storefront projection when it satisfies the minimum transaction rules: 

- product_name must be present. 

- price must be present. 

- availability must be present. 

- status must be active. 

Images and descriptions are highly recommended but do not block publishing by default, as B2B buyers often order using raw lists or article codes. 

### Required for External Channels


Channel requirements (e.g., Google Merchant requiring gtin and brand) must never be mapped as strict database validation rules for saving a core product.

A product that is incomplete for external channels can still be safely saved in the system and published to the native B2B storefront if it satisfies the basic B2B Ready profile. External channel blocks are evaluated downstream inside the export/connector layer, not inside the core catalog API.

### Product Readiness Instead of Gamification


The system rejects decorative completeness percentages (e.g., *Product card completed: 65%*) because they provide zero operational context. Instead, it uses **Product Readiness Profiles**. 

Readiness answers a practical question: **Can this product be safely used for this specific action right now?**

- **B2B Ready**: Checked before publishing to the storefront. If missing: "Cannot publish to B2B: price is missing". 

- **Google Feed Ready**: Checked before marketplace connector sync. If missing: "Cannot export to Google Merchant: GTIN or brand is missing". 

- **SEO Ready**: Evaluation for search engine data extraction. If weak: "SEO content can be improved: description is missing". 

Readiness evaluation must not act as a permanent visual alert or noise on the default product card view. It appears conditionally during user operations (imports, exports, channel activation tabs). 

### Attribute Structure Definition


Each attribute record in the dictionary contains the following schema data:

- internal_code (e.g., cost_price, material) 

- assignment_level (product, variant, both)

- ui_label & localized_labels

- data_type

- attribute_level (system, library, custom) 

- attribute_group

- import_aliases (stored as database array/json, not hardcoded in code) 

- is_localizable (boolean) 

- visibility_settings (admin card, admin table, B2B storefront, exports) 

- status (draft, active, deprecated, archived) 

### Attribute Code Specifications


Every attribute in the dictionary must have an immutable, unique system identifier (internal_code).

**Generation Rules: **System and library codes are hardcoded (e.g., product_name, brand, color). Workspace custom attributes must automatically generate an English-safe lowercase slug based on the user's initial input (e.g., user types “Длина кабеля” → system generates c_dlina_kabelya or prompts for an English fallback).

Code values must be web-safe, API-friendly, and completely decoupled from any changes made to user-facing UI labels.

### Attribute Data Types


- **MVP Supported**: text, long text, number, decimal, money, boolean, date, select,
  multi-select, image, URL, computed (system-defined read-only fields only — merchants cannot
  create custom computed fields in MVP).

- **Future Extension**: file, JSON, measurement, rich text.

Note: `relation`-backed fields (e.g. `category`) are represented through
`storage_type = relation` in `attribute_definitions`, not through a `data_type` value. `relation`
is not a `data_type`.

Note: `value_jsonb` in the dynamic value tables (see 03-DOMAIN_MODEL.md) is a storage column for
structured/internal/localized/multi-value payloads. It does not mean merchant-created JSON
attribute fields are supported in MVP. Adding `json` as a user-facing `data_type` requires a
separate documentation-level decision.

### Localization Architectural Rules


### Localizable vs Non-Localizable Split: 


- Fields assigned at the Product-Level that handle user-facing content (product_name, description, short_description, seo_title, seo_description) must be marked as is_localizable = true. Custom text attributes can also toggle this setting.

- Operational and identity fields assigned at the Variant-Level (sku, gtin, price, sale_price, cost_price, availability) are strictly is_localizable = false.

**MVP UI Constraint: **The database schema must support multilingual structures from day one (e.g., via JSONB translation objects), but the MVP admin interface will only render a single primary workspace language selected during onboarding. No complex language tabs or toggles on the primary product card view for MVP. Dedicated translation tables are a future migration path only, subject to explicit architecture review before implementation.

### Attribute Groups


For UI rendering layout, attributes are filtered into logical tabs: *Basic information*, *Identifiers*, *Pricing*, *Availability*, *Images and media*, *Descriptions*, *Characteristics*, *B2B*, *SEO*, *Logistics*, *Internal*. Groups serve visualization organization only and must not inject hidden business logic. 

### Attribute Scope Definition


- **System Scope: **Read-only structure definitions. Available globally.

- **Platform Library Scope: **Pre-defined structure definitions. Can be activated or deactivated per workspace.

- **Workspace Custom Scope: **Created inside a specific workspace. Isolated completely to that workspace_id.

**Future-Proofing: **The architecture must allow these scopes to later accept category_id or channel_id constraints, but for MVP, all custom scopes are flatly global across the entire workspace.

### Smart Import and Normalization


### Import Header Normalization


The ImportHeaderNormalizer service strips formatting discrepancies before comparing column headers against the dictionary aliases. Normalization steps: 

- Trim leading/trailing whitespaces and strip punctuation. 

- Convert characters to lowercase / casefold form. 

- Collapse repeated or non-breaking spaces into single normal spaces. 

- Normalize Unicode strings. 

### Import Aliases Setup


Aliases are checked after normalization. The default seed maps common localized synonyms: 

- product_name: *Назва, Название, Наименование, Name, Title*

- sku: *Артикул, Код, SKU, Code, Item Code*

- gtin: *EAN, GTIN, Barcode, Штрихкод*

- price: *Цена, Ціна, Price, РРЦ*

- availability: *Остаток, Залишок, Stock, Availability*

The mapping matching engine executes via a strict priority chain: Exact code match $\rightarrow$ Normalized global alias match $\rightarrow$ Normalized localized label match $\rightarrow$ Saved workspace-specific historic mapping source rule. 

### Smart Import Matching: Fuzzy ****&**** Manual Steps


When a file is uploaded, the matching engine runs down the priority chain. If automated matching fails to hit an exact internal code or existing alias, the system triggers the fallback pipeline:

- **Fuzzy Suggestion: **If a column header normalization yields a high confidence score (e.g., «Колор» vs «Color»), the UI displays a suggestion: «We think this column is “Color”. Confirm?»

- **Manual User Mapping: **If confidence is low, the user must manually select the appropriate attribute from a searchable dropdown of the current Workspace Dictionary.

- **Automated Memory: **Once a user manually maps an unknown header or confirms a fuzzy suggestion, the platform automatically saves this exact raw string into the import_aliases array for that specific attribute within that workspace's context. Future imports of the same file structure will map automatically.

### Workspace-Specific Aliases


Custom import mappings created by a specific company must live strictly inside a tenant-isolated storage layer (e.g., workspace_import_aliases).

They must never modify or pollute the global Platform Attribute Library configuration data, ensuring that one company’s internal naming conventions or typos do not affect other workspaces on the SaaS platform.

### Channel ****&**** Calculated Fields Governance


### Channel Mappings Protection


To avoid polluting the platform core with dynamic marketplace structures, channel-specific variations must use mapping layers. Core tables must never contain temporary attributes like google_title, rozetka_price, or prom_description. Instead, transformations are handled inside the respective output connector runtime logic. 

### Operational Fields vs Product Attributes


Calculated operational states must not be dumped into the dictionary as user-editable fields. 

- product_name and cost_price are manageable fields. 

- margin_percentage, stock_warning_status, and b2b_readiness_status are transient or calculated database projections and must declare their specific computing logic transparently. 

### Calculated Fields Implementation Boundary


Calculated properties like margin_percentage (computed from price and cost_price) or b2b_readiness_status must never be stored as standard editable rows inside the attribute_values tables.

They are defined within the dictionary schema as metadata records with a data_type: computed property and a documented calculation formula strategy. The application layer handles them as dynamic runtime properties or read-only database view projections.

### Visibility ****&**** Permissions Governance


- **Cost Price Safety**: cost_price is strictly classified as internal business information. It is excluded from the customer-facing storefront projection layers by default and can never be leaked unless an explicit toggle is turned on in the workspace settings. 

- System attributes can have their values edited by workspace managers, but their structure definition, field codes, and data types cannot be modified or deleted. Deprecation is always preferred over deletion to protect historical log data integrity. 

### Editing Permissions ****&**** Roles


**Definition Permissions: **Only system administrators can modify System and Library attribute structures. Workspace managers can create, archive, or deprecate Workspace Custom attributes, but cannot alter core system fields.

**Value Permissions: **Workspace users with Catalog Editor roles can modify the values of any active attribute inside a product card. Financial attributes like cost_price must be gated behind specific managerial role permissions.

### MVP Scope


### Included in MVP


- Full system attributes seed separated by Product and Variant assignment levels.

- Platform Attribute Library seed with automated search-lookup on product creation.

- Workspace custom attributes stored globally within the workspace.

- Import header normalization engine and automated alias dictionary mapping suggestions.

- Basic Product Type default setup.

- Contextual Readiness checks for B2B storefront publication.

- Cost price visibility masking rules.

### Excluded from MVP


- Category-specific attribute visibility conditions. 

- Custom Product Type creation interfaces and inheritance rules. 

- AI-driven unstructured text attribute parsing workflows. 

- Multi-layered enterprise asset approval workflows. 

## Open Decisions


The following architectural decisions must be finalized before implementation, keeping the system ready for future scales:

- **Attribute storage model:** Status: Resolved. See `03-DOMAIN_MODEL.md → Domain Decisions →
  Attribute storage model`. This item is no longer open; it must not be re-litigated here.

- **Translation storage:**

- **Direction:** Final Domain Model Decision: Localizable attribute values are strictly stored as JSONB translation objects inside the database. If is_localizable = true for an attribute definition, flat string overwrites upon the value record are strictly prohibited. The MVP administration interface will expose and accept only the primary workspace language to minimize onboarding friction. However, the underlying database structure must enforce this JSONB localized layout from day one. Dedicated translation tables are a future migration path only, subject to explicit architecture review before implementation.

- **Readiness profiles configuration:**

- **Direction:** The validation rules for readiness profiles (B2B Ready, Google Ready, etc.) must be stored as configuration or seed data rather than being permanently hardcoded into the platform core. This keeps the validation logic flexible as channel requirements evolve.

- **Product type UI:**

- **Direction:** While the database architecture must support product types and attribute sets from the beginning, the MVP UI will completely hide advanced product type management from ordinary users to keep onboarding friction-free.

- **Category-specific attributes:**

- **Direction:** For the MVP, custom attributes remain strictly workspace-level (global for the company). Category-specific attribute visibility will be introduced later, and the core dynamic attribute model must be designed so it does not block this future extension.

## Final Principle


The Attribute Dictionary must make product data structured without making the product feel complicated to the end-user.

A business owner or manager should be able to start with just one simple product name to get up and running. The platform should then gradually, contextually help the user improve, enrich, map, publish, and reuse that product data across any channel or external system. The underlying architecture must strictly prevent data chaos under the hood while keeping the administration interface clean, familiar, and simple enough for a non-technical user.