# docs/IMPLEMENTATION_GAPS.md

## Purpose

This document records known, verified gaps between approved project documentation
(00–07) and the actual state of the codebase on `develop`.

Entries here are NOT open product questions. The architectural decision is already
**Resolved** in the referenced document — the gap is purely that the code has not
caught up yet.

Rules for using this document:

- A gap listed here must not be re-litigated as if it were an open design question.
- A temporary workaround built around a gap (e.g. a placeholder, a simplified
  presenter) must be explicitly linked to its GAP entry, both in code comments and
  in the relevant PR/task description.
- When a gap is closed, update its Status and keep the entry for history — do not
  delete it.
- No Babypark-specific hardcoding is permitted as a "solution" to any gap (per
  `04-ARCHITECTURE_PRINCIPLES.md`, Configuration Over Custom Code mandate).

Verified against `develop` as of this writing: `app/Models/` contains only
`Category, Contractor, DeliverySetting, Order, OrderItem, Price, Product,
ProductVariant, Reservation, Stock, SyncLog, User`. No `workspace_id` column exists
in any migration.

---

## GAP-001 — Pricing model mismatch

**Approved docs:**
- `02-ATTRIBUTE_DICTIONARY.md`: `price` is a Variant-Level System Attribute —
  "Public / base price in workspace currency", distinct from `sale_price` and
  `cost_price`.
- `03-DOMAIN_MODEL.md`, Pricing Context: pricing must be resolved through
  `PriceList` → `PriceListItem` → `PriceResolver`, with a closed, **Resolved**
  priority order ending in "Cached variant base price on ProductVariant as a final
  fallback."
- `03-DOMAIN_MODEL.md`, MVP Domain Scope: `PriceList`, `PriceListItem or simple
  ProductPrice`, and cached variant prices are explicitly part of MVP scope, not
  future scope.

**Current code:**
- `app/Models/Price.php` — a flat model where `contractor_id` is a **mandatory**
  (non-nullable) foreign key. Every price row belongs to exactly one contractor.
  There is no `PriceList`, no `PriceListItem`, no `is_default` concept, and no
  cached base price field on `ProductVariant`.
- No `PriceResolver` class exists anywhere in `app/`.

**Impact:**
- There is no way to answer "what is this product's price" without already knowing
  which contractor is asking. The admin product table has no source for a neutral
  `Ціна` column.

**Decision:**
- Do not rename `РРЦ` (recommended_retail_price) into `Ціна` — they are different
  concepts.
- Do not invent UI-only price-resolution logic to fake a base price.
- Interim state: admin `Ціна` column renders `—` until this gap is closed.

**Next task:** Pricing MVP Foundation (see proposed task order below).

**Status:** Open.

---

## GAP-002 — Availability model mismatch

**Approved docs:**
- `03-DOMAIN_MODEL.md`, Availability source of truth (**Resolved**): net sellable
  stock must be computed via `AvailabilityResolver`, using
  `available_quantity_cache` minus active, unexpired `InventoryReservation`
  entries. Explicitly called "mandatory MVP architecture."
- `03-DOMAIN_MODEL.md`, MVP Domain Scope: `inventory_records`,
  `inventory_reservations` are explicit MVP-scope tables.

**Current code:**
- `app/Models/Stock.php` and `app/Models/Reservation.php` are a simpler, ad-hoc
  pair — not the documented `InventoryRecord` (ledger) / `InventoryReservation`
  (TTL-based, order-linked) shape.
- No class named `AvailabilityResolver` exists anywhere in `app/`.
- `app/Support/AdminAvailabilityPresenter.php` (added during the admin product
  table task) computes net quantity directly from `stocks.quantity -
  stocks.reserved`, with no TTL expiry logic and no ledger. It was explicitly
  built as "a small local presenter, not a full AvailabilityResolver" and
  documented as needing migration later.

**Impact:**
- Availability shown in admin table/filter/infolist does not go through a real
  reservation-expiry-aware resolver. It is a reasonable short-term approximation,
  not the architecturally intended source of truth.

**Decision:**
- Keep `AdminAvailabilityPresenter` as the single, unified source for admin-side
  availability display (already de-duplicated across column/filter/infolist —
  see PR #29/#30/#32) until this gap is closed.
- Do not build a second, different availability calculation anywhere else in the
  meantime.

**Next task:** Availability Foundation (see proposed task order below).

**Status:** Open (partially mitigated — see `AdminAvailabilityPresenter`).

---

## GAP-003 — Attribute Dictionary not implemented

**Approved docs:**
- `03-DOMAIN_MODEL.md`, Attribute value storage (**Resolved**): the platform must
  use separate `product_attribute_values` and `variant_attribute_values` tables.
  "A unified polymorphic attribute value table is strictly forbidden."
- `03-DOMAIN_MODEL.md`, MVP Domain Scope: `AttributeDefinition`,
  `ProductAttributeValue / VariantAttributeValue` are explicit MVP-scope entities.
- `02-ATTRIBUTE_DICTIONARY.md`: defines System Attributes (Level 1) and Platform
  Attribute Library (Level 2), each with Product-Level / Variant-Level /
  Both assignment rules.

**Current code:**
- No `AttributeDefinition`, `ProductAttributeValue`, or `VariantAttributeValue`
  model or table exists.
- Core fields (`brand`, `category_id`, `sku`, `barcode_ean`, `cost_price`, etc.)
  are plain columns directly on the `products` / `product_variants` tables.
- No dynamic/custom attribute mechanism exists at all — a workspace cannot add a
  custom product field today.

**Impact:**
- Import mapping, connector work, and any future custom/extensible field cannot be
  built correctly without this foundation — there is nowhere to map an unknown
  spreadsheet column to.
- **Open question, now Resolved via the "System Attribute seed scope" and
  "Attribute storage model" Domain Decisions added to `03-DOMAIN_MODEL.md`** (see
  the docs patch that introduced this note): System Attributes remain first-class
  typed columns; only Platform Attribute Library / workspace-custom fields use
  `product_attribute_values` / `variant_attribute_values`.

**Decision:**
- Do not build a one-off custom-field mechanism anywhere as a stopgap.
- Do not let any connector/import work hardcode column-to-field mapping outside
  a proper `FieldMapping` mechanism once it exists.

**Next task:** Product Fields Foundation (see proposed task order below).

**Status:** Open.

---

## GAP-004 — Workspace isolation absent

**Approved docs:**
- `00-WHY.md`: "Each business should have its own isolated workspace... No
  company-specific logic should be hardcoded into the core."
- `03-DOMAIN_MODEL.md`, Company vs Workspace naming (**Resolved**): the technical
  SaaS boundary is `workspace_id`; every workspace-owned table must include it.
- `04-ARCHITECTURE_PRINCIPLES.md`, Mandate 1: workspace isolation is described as
  a critical, non-negotiable requirement — cross-tenant data leaks are a critical
  system failure.

**Current code:**
- Verified: zero occurrences of `workspace_id` in any model or migration across
  the entire codebase.
- The application is currently single-tenant in practice (Babypark only), despite
  being architected on paper as multi-tenant SaaS.

**Impact:**
- Onboarding a second paying workspace today would require retrofitting
  `workspace_id` onto every existing table and every query — exactly the
  "rebuild instead of extend" scenario `00-WHY.md` explicitly wants the platform
  to avoid for its own customers.

**Decision:**
- Do not onboard a second workspace before this gap is closed.
- Any new table created for Product Fields / Pricing / Availability Foundation
  work (GAP-001/002/003) must include `workspace_id` from its first migration,
  even while Babypark remains the only workspace — retrofitting it twice would be
  worse than including it now.

**Next task:** Workspace Isolation Foundation — should be sequenced together with
GAP-001/002/003 schema work, not as a separate later pass, precisely because new
tables must be born with `workspace_id` already present.

**Status:** Open.

---

## GAP-005 — Order / payment status not separated

**Approved docs:**
- `03-DOMAIN_MODEL.md`, Payment status automation (**Resolved**): "Payment updates
  `payment_status` only. Any resulting change to `order_status` is determined
  exclusively by `payment_triggers_json` inside `WorkspaceOrderStatusMatrix`.
  Hardcoded controller-level status changes triggered by payment events are
  strictly forbidden."
- `01-PRODUCT_VISION.md`, MVP Scope: "the order model should include payment
  status so that payment gateway integration can be added later without
  rewriting the order model."

**Current code:**
- `app/Models/Order.php` has a single `status` field (cast to `OrderStatus` enum).
  There is no separate `payment_status` field and no `WorkspaceOrderStatusMatrix`.

**Impact:**
- Adding payment gateway integration later, as `01-PRODUCT_VISION.md` assumes will
  be possible "without rewriting the order model," is not actually possible yet —
  the model would need to change.

**Decision:**
- Do not add any payment-triggered status-change logic directly in
  controllers/actions as a shortcut.

**Next task:** Not urgent for the current pilot phase (no live payment gateway
yet), but should be scheduled before any payment gateway integration work starts.

**Status:** Open, low urgency.

---

## GAP-006 — Connector / Import / FieldMapping infrastructure absent

**Approved docs:**
- `00-WHY.md`: platform must be connector-independent; "no connector should
  define the core product model."
- `01-PRODUCT_VISION.md`, Babypark Pilot Scope: explicitly lists "ERP / 1C data
  input" and "Google Sheets output" as valid, expected pilot requirements.
- `03-DOMAIN_MODEL.md`, MVP Domain Scope: `ConnectorDefinition`, `ConnectorAccount`,
  `FieldMapping`, `ImportJob` are explicit MVP-scope entities.

**Current code:**
- Only `app/Models/SyncLog.php` exists — a simple log, not a connector/mapping
  system. No `ConnectorDefinition`, `ConnectorAccount`, `FieldMapping`, or
  `ImportJob` model exists.

**Impact:**
- The 1C sync and Google Sheets export the pilot actually needs to be useful (per
  the user's own stated requirement) cannot be built correctly without
  `FieldMapping`, and `FieldMapping` itself depends on GAP-003 (Attribute
  Dictionary) being resolved first — this gap is blocked on GAP-003.

**Decision:**
- Do not build a one-off, hardcoded 1C-to-database field mapping as a shortcut —
  this is explicitly the "Babypark-specific hardcoded logic" that
  `04-ARCHITECTURE_PRINCIPLES.md` Mandate 9 forbids.

**Next task:** Connector Foundation — sequenced after GAP-003 (Attribute
Dictionary), since FieldMapping needs AttributeDefinition to map onto.

**Status:** Open, blocked on GAP-003.

---

## GAP-007 — Channel-specific fields leaked into core `products` table

**Approved docs:**
- `02-ATTRIBUTE_DICTIONARY.md`, Channel Mappings Protection: "Core tables must never contain
  temporary attributes like google_title, rozetka_price, or prom_description."

**Current code:**
- The `products` table (base migration `create_products_table`) contains `rozetka_category_id`,
  `meta_title`, `meta_description` as native columns — a direct instance of the pattern the
  Channel Mappings Protection rule forbids.

**Impact:** direct violation of the documented rule; blocks a clean Connector Foundation
(GAP-006) implementation later if left unaddressed.

**Decision:** these three columns are not registered as System Attributes in Product Fields
Foundation, and no further channel-specific columns should be added to core tables going
forward.

**Next task:** Connector Foundation (sequenced after GAP-003 closes) migrates these into a
proper channel-mapping layer and deprecates the raw columns.

**Status:** Open, low priority (no active Rozetka export in the current pilot scope).
