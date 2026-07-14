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

Verified against `develop` as of the GAP-016 Field Foundation migration (PR pending):
`app/Models/` contains `Category, Customer,
DeliverySetting, FieldBinding, FieldDefinition, InventoryLocation, InventoryRecord, Order, OrderItem, Price,
PriceList, PriceListItem, Product, ProductFieldValue, ProductTag,
ProductVariant, Reservation, Stock, SyncLog, Tag, User, VariantFieldValue,
Workspace, WorkspaceImportAlias` (24 models). `workspace_id` appears in 9
migrations and via `BelongsToWorkspace`/`BelongsToWorkspaceOrGlobal` on 14
models — see GAP-004 for the caveat that this is a sampling check, not a full
audit. Field Foundation naming is implemented — see GAP-016 (closed).

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

**Current code (historical — see Status below for resolution):**
- `app/Models/Price.php` was a flat model where `contractor_id` was a mandatory foreign key,
  with no `PriceList`, no `PriceListItem`, no `is_default` concept, and no cached base price on
  `ProductVariant`. The legacy `prices` table and `Price` model are retained, read-only, for
  historical/compatibility reasons (not deleted).

**Impact (historical):**
- There was no way to answer "what is this product's price" without already knowing which
  contractor was asking, and the admin product table had no source for a neutral `Ціна` column.

**Decision (still applies):**
- `РРЦ` (recommended_retail_price_cache) and resolved sale price remain distinct concepts — do
  not conflate them.

**Status:** Closed in code. Implemented via Pricing MVP Foundation (PR #44 — schema,
`PriceList`/`PriceListItem`, `PriceResolver`, MySQL-safe default-list constraint, safe legacy
data migration; PR #45 — replacement of all legacy pricing call sites, `products.cost_price`
finally dropped in favor of variant-level `cost_price`, order-creation price snapshot
integration via `OrderCreator`). The admin `Ціна`/РРЦ/margin columns are now populated from
`PriceResolver`/`ProductPricingSummary` rather than rendering `—`. `CustomerGroup`/`PricingRule`
remain deferred — see GAP-010.

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

**Next task:** None — closed. (Note: this entry previously referenced "proposed
task order below", a section that does not exist in this document — removed as
part of this Documentation Truth Reset pass.)

**Status:** Closed in code. Implemented via Availability Foundation and follow-up fixes
(PRs #39, #40, #41, #42 — schema, `AvailabilityResolver`,
`ReservationCreator`/`Confirmer`/`Releaser`, `inventory_records` ledger, scheduler registration,
MySQL-safe migration recovery, and UI delegation away from direct `stocks.reserved`
calculations). Two intentionally deferred product items remain open separately — see GAP-008
and GAP-009.

---

## GAP-003 — Attribute Dictionary not implemented

**Approved docs at the time of the original GAP — now superseded in
naming/shape by GAP-016 (Field Foundation); kept verbatim below for
historical record, not as a description of current approved docs:**
- `03-DOMAIN_MODEL.md`, Attribute value storage (**Resolved**): the platform must
  use separate `product_attribute_values` and `variant_attribute_values` tables.
  "A unified polymorphic attribute value table is strictly forbidden."
- `03-DOMAIN_MODEL.md`, MVP Domain Scope: `AttributeDefinition`,
  `ProductAttributeValue / VariantAttributeValue` are explicit MVP-scope entities.
- `02-ATTRIBUTE_DICTIONARY.md`: defines System Attributes (Level 1) and Platform
  Attribute Library (Level 2), each with Product-Level / Variant-Level /
  Both assignment rules.

**Current code (re-verified against `develop`):**
- `AttributeDefinition`, `ProductAttributeValue`, and `VariantAttributeValue`
  models and tables all exist, with `workspace_id`, foreign keys, and unique
  constraints. `AttributeDefinitionResource` exists in Filament
  (`canCreate(): false`; `canDelete()` only for `workspace_custom` scope).
- System Attributes remain first-class typed columns on `products` /
  `product_variants` (e.g. `brand`, `sku`, `cost_price`); only Platform Attribute
  Library / workspace-custom fields use `product_attribute_values` /
  `variant_attribute_values`, per the "Attribute storage model" Domain Decision.
- A workspace **can** add a custom product field today via this mechanism.

**Impact:**
- The original impact (no foundation for import mapping / connector work) no
  longer applies for the Product/Variant domain.
- This GAP's original scope was explicitly Product/Variant-only (see
  "Approved docs" above). Extending the same governance to `Customer` fields is
  **new scope**, not a reopening of this GAP — see the "Field Foundation
  (cross-object fields)" Domain Decision in `03-DOMAIN_MODEL.md` (that Domain
  Decision, not a separate ADR file, is the canonical record — no separate ADR
  document is checked into `docs/`).

**Decision:**
- Do not build a one-off custom-field mechanism anywhere as a stopgap.
- Do not let any connector/import work hardcode column-to-field mapping outside
  a proper `FieldMapping` mechanism once it exists.
- Do not treat this GAP's closure as covering `Customer`/other future entities —
  that is tracked separately (see `03-DOMAIN_MODEL.md`, "Field Foundation").

**Next task:** None for the original Product/Variant scope. Cross-object
extension is tracked as its own Field Foundation migration (GAP-016), sequenced
after the Contractor → Customer terminology migration (GAP-017) and before
GAP-006. **Not** blocked by GAP-004's full coverage audit — that audit is a
separate prerequisite for onboarding a second workspace only, not for this
migration (see "Field Foundation", Workspace isolation note, in
`03-DOMAIN_MODEL.md`).

**Status:** Closed for original (Product/Variant) scope.

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

**Current code (re-verified against `develop`):**
- `workspace_id` now appears in 9 migrations; `BelongsToWorkspace` /
  `BelongsToWorkspaceOrGlobal` traits are applied to 14 models, including
  `Product`, `AttributeDefinition`, `Customer`, `PriceList`, `Category`.
- This check was a **sampling audit**, not a full inventory of every
  workspace-owned table, model, background job, and raw query in the codebase.

**Impact:**
- Broad workspace isolation is demonstrably implemented, contrary to the
  previous "zero occurrences" note. However, the previous note's caution about
  not onboarding a second workspace before full verification still applies —
  a sampling audit finding isolation everywhere it looked is not the same as
  proof of no gaps anywhere.

**Decision:**
- Do not onboard a second workspace before a full audit (not sampling) is
  completed: every workspace-owned table, every Eloquent query path, every
  background job, plus a cross-workspace-leakage test suite.
- Any new table created for Field Foundation / Connector Foundation work must
  include `workspace_id` from its first migration.

**Next task:** Full workspace-isolation coverage audit (inventory + tests), not
a rewrite — the mechanism already exists broadly, this is a verification task.

**Status:** Partially closed — broad workspace isolation implemented; full
table/model/query/job coverage audit still required before onboarding a second
workspace. Do not mark this Closed on the basis of a sampling check.

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
- Do not build a one-off, hardcoded 1C-to-database field mapping as a shortcut —
  this is explicitly the "Babypark-specific hardcoded logic" that
  `04-ARCHITECTURE_PRINCIPLES.md` Mandate 9 forbids.
- Do not resume Connector Foundation work until the Field Foundation migration
  (FieldDefinition / FieldBinding split, `field_binding_id` on aliases) lands —
  building `FieldMapping` against the current `AttributeDefinition` shape would
  require rework immediately after.
- **`ImportedPriceTaxBasis`** (whether an imported row is net or gross) must be
  captured during connector import design — see GAP-018 cross-reference.

**Next task:** Connector Foundation — sequenced after GAP-017 (Contractor →
Customer terminology migration) and GAP-016 (Field Foundation migration), per
the approved phased plan.

**Status:** Open, blocked on GAP-016 (Field Foundation migration), not GAP-003.

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

---

## GAP-008 — Per-location pickup/checkout allocation not implemented

**Approved docs:**
- `03-DOMAIN_MODEL.md`, "Location-ready inventory foundation" (**Resolved**, added by
  Availability Foundation): `inventory_locations` exists as a foundation entity, but explicitly
  states "Pickup-point selection, per-location checkout allocation, per-location reservation,
  and location-aware delivery rules are explicitly future, separate work."

**Current code:**
- `inventory_locations` exists and `stocks` are linked to it, but `AvailabilityResolver` and
  `InventoryReservation` both operate at the variant level only, aggregated across all
  locations. There is no UI anywhere (admin or B2B cabinet) for a customer to choose a specific
  pickup location, and no reservation ever allocates against a specific location.

**Impact:**
- A merchant with a showroom and a separate warehouse (or multiple physical locations) cannot
  yet offer "choose your pickup point" to B2B customers, even though the underlying data model
  is already location-aware. This is a real, deliberately deferred product feature, not a bug.

**Decision:**
- Do not build ad-hoc per-location logic anywhere as a stopgap. When this is prioritized, it
  needs its own domain design pass (per-location availability formula, checkout UI, staff
  fulfillment workflow, delivery-setting interaction) — not a quick patch on top of the current
  variant-level resolver.

**Next task:** Not scheduled. Revisit when a merchant with multiple pickup-capable locations is
onboarded, or when explicitly prioritized in product planning.

**Status:** Open, low urgency (foundation exists, feature does not).

---

## GAP-009 — `low_stock` / `pre_order` availability thresholds not defined

**Approved docs:**
- `03-DOMAIN_MODEL.md`, "Operational Inventory Cache": `availability_status` is documented as
  an enum with four values — `in_stock`, `low_stock`, `out_of_stock`, `pre_order`.

**Current code:**
- `product_variants.availability_status` (added by Availability Foundation) is only ever
  backfilled/set to `in_stock` or `out_of_stock` — a simple `available_quantity_cache > 0` check.
  `low_stock` and `pre_order` are valid enum values that no code path ever assigns, by explicit
  decision during Availability Foundation, to avoid inventing an un-approved business threshold
  (e.g. "what quantity counts as running low?") or pre-order policy.

**Impact:**
- The UI cannot yet show a "Закінчується" ("running low") badge or support pre-order workflows,
  even though the enum already has room for both. This is intentional, not an oversight — the
  actual threshold/policy is a business decision that hasn't been made yet.

**Decision:**
- Do not invent a `low_stock` threshold or `pre_order` policy in code without an explicit
  documentation-level decision first (e.g. "low_stock = quantity below N" or "below N% of a
  typical restock level," and whatever `pre_order` should mean operationally for this business).

**Next task:** Not scheduled. Revisit when the business defines what "running low" and
"pre-order" should concretely mean for Babypark's catalog.

**Related finding (does not close this gap):** Shopify's `Variant Inventory Policy`
(`deny`/`continue` — whether a variant can still be ordered at zero stock) is the standard
mechanism that would populate `availability_status = pre_order`. This clarifies *how* pre-order
would work mechanically once a business decision is made on *when* to allow it — it does not
by itself decide the business threshold, which remains open per this gap.

**Status:** Open, low urgency.

---

## GAP-010 — CustomerGroup / PricingRule not implemented

**Approved docs:**
- `03-DOMAIN_MODEL.md`, "CustomerGroup" and "PricingRule" sections (descriptive, not yet
  formally Resolved): customer groups may connect to a price list, discount rule, visibility
  rules, and access mode; `PricingRule` represents a pricing adjustment layered on top of a
  resolved `PriceListItem` tier.

**Current code:**
- `Contractor` has no group/segment concept at all. Pricing MVP Foundation implements direct
  `Contractor.default_price_list_id` assignment only — many contractors can share one
  `PriceList`, which covers simple grouping-by-price, but there is no entity for bundling
  additional segment-level rules (catalog visibility, payment terms) together.

**Impact:**
- A merchant whose B2B customers need more than shared pricing cannot yet configure that as a
  single reusable "profile."

**Decision:**
- Do not build ad-hoc segment logic anywhere as a stopgap. Design `CustomerGroup` as its own
  entity that composes with the existing `PriceList` assignment, when actually needed.

**Next task:** Not scheduled.

**Status:** Open, low urgency.

---

## GAP-011 — Product classification structure: `Merchant Type`/`Tags` schema, and tracking of a future Standard Category concept

**Approved docs:**
- `03-DOMAIN_MODEL.md`, "Product classification model — Merchant Category / Standard Category /
  Merchant Type / Tags" (Patch 1 above, Resolved): four distinct concepts. Merchant/Catalogue
  `Category` (existing `categories` table) is unchanged. This document's existing `ProductType`
  template concept (internal field/variant structure control, hidden in MVP) is also unchanged
  and unrelated to the new `Merchant Type` concept. `Merchant Type` and `Tags` do not exist as
  schema yet and are ready to implement. Standard Category (standardized public taxonomy) is a
  tracked future concept, deliberately not built now, consistent with the existing "no global
  taxonomy in MVP" decision.

**Current code:**
- `categories` table/relationship already exists and is used for storefront navigation — no
  change needed here. Schema for `Merchant Type` and `Tags` is implemented (Task 5).
- **Implemented (Task 6A):** `TagResource` (standalone admin CRUD with guarded delete);
  `Merchant Type` and `Tags` in a dedicated `"Класифікація"` section on `ProductResource`
  form and infolist; internal admin table columns (`merchant_type`, `tags.name`) and filters;
  eager loading of `tags` on the product list query; `TagManager` for shared validation across
  standalone and inline tag creation; atomic locked delete guard preventing silent cascade when
  a tag is still attached to products.
- **Still deferred:** Standard Category (tracked alongside GAP-006, unchanged); B2B/cabinet
  exposure of `merchant_type` or `Tags` (not decided, not built).

**Implemented (as of Task 6B):**
- Bulk "Додати теги" and "Видалити теги" operations, with preview/apply metrics distinguishing
  products from links.
- Selected-rows and all-matching-filter support for bulk tag operations.

**Still deferred:**
- Standard Category (tracked alongside GAP-006, unchanged).
- B2B/cabinet exposure of Merchant Type/Tags (not decided, not built).

**Impact:**
- Managers can now assign the free internal organizational label (`Merchant Type`) and
  filtering tags (`Tags`) on products in the admin panel. Merchant/Catalogue `Category`
  alone was already functional and is unchanged. Standard Category's absence has no MVP impact
  — it becomes relevant once channel/marketplace export (GAP-006) is actually built.

**Decision:**
- Implement `Merchant Type` (nullable string, e.g. `products.merchant_type` — not a generic
  `type` column, to stay unambiguous relative to the existing `ProductType` concept) and `Tags`
  (separate table, many-to-many with `Product`) as their own small schema task now — this does
  not require re-touching `Category` (stays workspace-owned) or `ProductType` (stays hidden,
  unrelated).
- Standard Category is explicitly **not** part of this task's scope — revisit only alongside
  GAP-006 (connector/channel-mapping infrastructure), not as a core catalog change.

**Next task:** Product classification structure implementation (schema task, separate from the
Phase 2 field backlog in GAP-013).

**Status:** Partially closed in code. Implemented: `products.merchant_type` (nullable string column),
its column-backed `AttributeDefinition`, `tags` table, `product_tag` pivot with `workspace_id`
consistency enforcement (Eloquent `ProductTag` pivot guard + MySQL composite foreign keys),
`Tag` model, and `Product`/`Tag` `belongsToMany` relations; admin UI for assigning
`Merchant Type` and `Tags` to products (`ProductResource` `"Класифікація"` section, table columns,
filters, eager loading); standalone `TagResource` with guarded delete via `TagManager`;
bulk add/remove tag operations with preview/apply metrics (`TagBulkAssignmentService`).
Standard Category remains explicitly deferred/tracked alongside GAP-006 — this GAP is not fully
closed until that future concept is built. B2B/cabinet exposure remains open.

---

## GAP-012 — Multi-currency pricing not implemented

**Approved docs:**
- `03-DOMAIN_MODEL.md`, Pricing Foundation blocks: `price_lists.currency` field exists in the
  schema (Task 3C-1), defaulting to `'UAH'`, but no conversion/exchange-rate/multi-currency
  display logic was built — deliberately deferred at the time.

**Current code:**
- `PriceList.currency` is a UAH-only select in the admin UI (Task 3D-1); `PriceResolver` assumes
  a single currency throughout.

**Impact:**
- This SaaS is intended to be sellable beyond a single Ukrainian pilot merchant. A merchant
  selling in EUR/USD/other currencies cannot be onboarded without this. Given the realistic
  commercial ambition (a global-capable product, not a Ukraine-only tool), this must not be
  silently forgotten — it is tracked here explicitly so it surfaces again when the first
  non-UAH merchant scenario becomes real, rather than being rediscovered under time pressure.

**Decision:**
- Do not build ad-hoc currency conversion as a side effect of some other task. When a real
  multi-currency need appears, it needs its own domain design pass (exchange rate source,
  rounding rules, display format per locale, whether `PriceListItem` needs per-currency rows or
  a conversion layer).

**Next task:** Not scheduled. Revisit when the first non-UAH merchant scenario is real.

**Status:** Open, tracked (not urgent, but must not be dropped from this document).

---

## GAP-013 — Product Fields Phase 2: remaining standard fields not yet registered

**Approved docs:**
- `02-ATTRIBUTE_DICTIONARY.md`'s Phase 1 seed scope explicitly deferred a Phase 2 list.
- Cross-referenced against Shopify's real product CSV template and Magento's product
  attribute/CSV documentation (compiled reference, not reproduced verbatim per copyright — see
  the comparison table already shared with the project owner).

**Current code:**
- Phase 1 fields (name, brand, category, description, status, url, sku, gtin, price, RRP,
  cost_price, availability, color, size) are registered and working (Tasks 1-2, 3B, 3C).
- Phase 2 field registrations implemented (Task 5): `weight_netto`, `weight_brutto`, `volume_m3`,
  `shipping_required`, `backorder_policy`, `technical_characteristics`, `instructions` — all
  registered as `AttributeDefinition` records via `AttributeDefinitionSeeder`.
- Not yet registered: gift card flag (deferred).
  `image_alt_text` remains deferred to future Media entities (`MediaAsset`/`ProductMedia`/`VariantMedia`
  — alt text is a per-image property, not a product/variant-level attribute). Tags is tracked
  separately under GAP-011 (classification structure), not here.

**Previous decision:** Tax class deferred until a real product need appeared.
**Reopened:** The need is now confirmed by workspace tax defaults (this
task), and by the anticipated product-card inheritance, bulk assignment
and import mapping requirements that follow from it.
**Status:** Open — near-term prerequisite for full product card and
import UX.

**Impact:**
- These are ordinary Phase 2 registrations — no architectural blocker, unlike the Category/Type/
  Tags model (GAP-011) which needs its own schema work first.

**Decision:**
- Register these via the normal `AttributeDefinition` seeding mechanism already established
  (Task 1/2), grouped into existing `attribute_group` codes where they fit
  (`characteristics`, `logistics`, `images_media`, `b2b`) — no new mechanism needed.
- "Технічні характеристики" and "Інструкція" are **mandatory for B2B-ready/customer-facing
  publication readiness, not necessarily required at initial draft creation** — consistent with
  progressive product onboarding (start with a name, enrich later). Do not treat them as
  skippable nice-to-haves when a product is being prepared for publishing, but also do not make
  them a hard database-level requirement that blocks creating a draft product row.
- Tax class remains explicitly deferred as a **registered field** (not yet in
  `AttributeDefinition` seed) — revisit via GAP-013 reopening above. Gift card flag
  remain explicitly deferred (not registered now) per product owner decision — revisit
  only if a real need appears.

**Next task:** Product Fields Phase 2 implementation (schema/seed task, separate from the
Merchant Category/Standard Category/Merchant Type/Tags structural task in GAP-011).

**Status:** Partially closed in code. Phase 2 `AttributeDefinition` registrations are
implemented for `weight_netto`, `weight_brutto`, `volume_m3`, `shipping_required`,
`backorder_policy`, `technical_characteristics`, and `instructions`. Tax class reopened
(see above). Gift card flag remains explicitly deferred (unchanged). `image_alt_text` remains
deferred to future Media entities (unchanged). `backorder_policy` registration does not change
`AvailabilityResolver` behavior and does not close GAP-009. Publication-readiness enforcement
for `technical_characteristics` and `instructions` remains the future responsibility of
`B2BPublicationChecker` (not yet built).

---

## GAP-018 — Multi-jurisdiction Tax Engine

Jurisdiction за адресою клієнта; кілька податкових реєстрацій; reverse
charge; Stripe Tax/Avalara; автоматичне оновлення міжнародних ставок.

**Cross-references:**
- `ImportedPriceTaxBasis` — доповнення до **GAP-006** (Connector Foundation):
  1С already may send prices with or without tax; import mapping must record the
  declared tax basis per row.
- `ChannelPricePolicy` — доповнення до channel mapping/export work (see GAP-007
  and future connector channel-mapping GAP): e.g. Google Merchant feed requires
  explicit tax-inclusive vs tax-exclusive semantics per channel.

**Status:** Open, tracked (not urgent) — same pattern as GAP-012.

---

## GAP-014 — `sale_price >= regular price` data-integrity gap on non-Filament write paths

**Approved docs:**
- `03-DOMAIN_MODEL.md`, VAT handling in `PriceListItem` (**Resolved**): `effective_net_price`
  is the actual net price used for charge/display calculations — `PriceListItem.sale_price`
  overrides `PriceListItem.price` when present; otherwise the regular tier price is used.

**Current code:**
- `ResolvedPrice::fromListItem()` uses any non-null `sale_price` as `effectiveNetPrice`,
  regardless of whether it is lower than the regular `price`. Filament's admin form prevents
  entering `sale_price >= price` via `->lt('price')`, but non-Filament write paths (e.g. a
  future import/connector) can still persist such values.
- Task 3D-2B adds `isOnSale` metadata (`salePrice < regularNetPrice`) that correctly reports
  `false` for this data-error case, but does **not** change `effectiveNetPrice`/`grossPrice`
  algorithm behavior — that remains a separate pricing-integrity concern.

**Impact:**
- A `sale_price` that is not actually lower than the regular price could still be charged as the
  effective price, while provenance metadata would correctly show the item is not "on sale."
  Future admin tooling that shows struck-through regular vs effective prices must not assume
  `isOnSale` and `effectiveNetPrice` are always consistent until this gap is closed.

**Decision:**
- Do not silently alter `PriceResolver`'s effective-price selection in metadata-only work.
- When a real non-Filament write path exists (import/connector), add validation or normalization
  there, and/or teach `PriceResolver` to ignore non-discount `sale_price` values — as an explicit
  pricing-integrity task, not as a side effect of provenance metadata.

**Next task:** Pricing integrity pass — scheduled when import/connector write paths for
`PriceListItem` are built, or when product requirements demand stricter sale-price enforcement.

**Status:** Open, tracked.

---

## GAP-015 — Bulk tag operations have no undo/operation history

**Approved docs:** Task 6B implements bulk add/remove tag operations with an accurate
pre-application preview (per-product and per-link counts), which substantially reduces the risk
of an unintended bulk change — but this is prevention, not recovery.

**Current code:** No activity-log/audit package exists in this project (confirmed absent from
`composer.json`). Bulk tag operations have no way to be reversed after the fact beyond a manual,
mirror-image bulk operation performed by hand.

**Impact:** A genuine "undo my last bulk tag operation" capability requires storing the exact
pivot delta per operation (which specific product-tag links were actually added/removed, not
just the operation's inputs), an operation identifier, a retention policy, and a restore UI —
this is real, additional scope, not a simple flag to add later.

**Decision:** Do not build a partial/fake undo (e.g. "just re-run the opposite operation" is not
equivalent to a true undo, since it would also affect any links that existed before the original
operation for unrelated reasons). When this is prioritized, design it as its own feature — likely
alongside introducing a proper activity-log foundation for the platform generally, not just for
tags.

**Next task:** Not scheduled.

**Status:** Open, low urgency (mitigated in practice by the accurate preview from Task 6B).

---

## GAP-016 — Field Foundation code migration not yet done

**Approved docs:**
- `03-DOMAIN_MODEL.md`, "Field Dictionary Context" and "Field Foundation
  (cross-object fields)" Domain Decision (**Resolved**): the canonical entity
  names are `FieldDefinition`, `FieldBinding`, `product_field_values`,
  `variant_field_values`, `customer_field_values`, and
  `workspace_import_aliases.field_binding_id`.

**Current code:**
- `FieldDefinition`, `FieldBinding`, `ProductFieldValue`, `VariantFieldValue`,
  `CustomerFieldValue` models and tables exist. `FieldDefinitionResource` manages
  product/variant fields. `FieldDefinitionSeeder` is idempotent. Legacy
  `product-fields:migrate-legacy-attributes` command updated to target
  `variant_field_values` (deletion deferred until production-representative
  dry-run per §L).

**Impact:**
- Do not read `03-DOMAIN_MODEL.md`'s Field Foundation naming as a description
  of current code — it is the target. Any Cursor task that touches this area
  must check actual current code (this GAP), not assume the renamed entities
  already exist.
- `GAP-006` (Connector Foundation) is blocked on this migration landing first.

**Decision:**
- Do not build any new feature (e.g. Customer Fields UI, Connector Foundation)
  against the old `AttributeDefinition`/`value_level` shape — it would need
  immediate rework once this migration lands.
- This is a schema + model + Filament resource + service rename/restructure,
  not a pure find-and-replace — see "Field Dictionary Context" for the full
  target shape, including the new `FieldBinding` entity and the
  one-binding-per-object_type rule replacing `value_level`.

**Next task:** Field Foundation migration — GAP-017 prerequisite blocker removed;
sequenced before GAP-006 (Connector Foundation) resumes.

**Status:** Closed in code. Implemented via Field Foundation migration (`FieldDefinition`/`FieldBinding`, `product_field_values`/`variant_field_values`/`customer_field_values`, `workspace_import_aliases.field_binding_id`, idempotent `FieldDefinitionSeeder`, `FieldDefinitionResource` with product/variant query filter). GAP-006 (Connector Foundation) is unblocked.

---

## GAP-017 — Contractor → Customer terminology/auth migration not yet done

**Approved docs:**
- `03-DOMAIN_MODEL.md`, Customers Context (**Resolved**): `Customer`/`Клієнти`
  is the only acceptable user-facing and domain term; `contractor` may appear
  only inside a connector adapter that itself uses that external term (e.g.
  the 1C connector).

**Current code (historical — state before this migration landed):**
- The codebase still names the model, table, Filament resource, pages, and
  related services/tests after `Contractor`, not `Customer`
  (`app/Models/Contractor.php`, `ContractorResource`, `ListContractors`,
  `ContractorPriceListAssignmentService`, etc. — 45 files reference
  `Contractor` as of this writing).
- `config/auth.php` defines a `contractor` guard and `contractors` provider;
  `routes/web.php` uses `guest:contractor` and `Auth::guard('contractor')`;
  `ContractorAuthenticated` middleware exists. These are part of the B2B
  cabinet's live authentication path, not just naming — renaming the model
  without updating these would break `/cabinet` login silently.

**Impact:**
- Every new task in this area currently has to reconcile `Customer` in docs/UI
  with `Contractor` in code, which is a standing source of confusion for both
  developers and AI-assisted sessions.

**Decision:**
- Pre-launch, one-time terminology migration, not a permanent compatibility
  alias — SaaS is not yet launched, so there is no external integration
  depending on the old names today.
- Must be its own self-contained migration task (model, table, FK, Filament
  resource + pages, services, exceptions, tests, `config/auth.php`
  guard/provider, routes, middleware) — not folded into the Field Foundation
  migration (GAP-016), and not left as a side effect of some other task.

**Next task:** None — closed. GAP-016 (Field Foundation migration) is now
unblocked as the next sequenced task.

**Status:** Closed in code.

### Post-mortem: production deploy incident

- **Date / commit:** deploy of PR #55 (`432b7c6`), `migrate-safe.sh` on production.
- **Symptom:** `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'type'`
  at `migrateSyncLogType()` during the Contractor → Customer migration.
- **Root cause:** operation order in `migrateSyncLogType()` — `UPDATE` ran before
  `ALTER TABLE ... MODIFY COLUMN type ENUM(...)`, so MySQL strict mode rejected
  writing `'customers'` into a column whose ENUM still allowed only the old
  value set (`..., 'contractors', ...`).
- **Why the test missed it:** `CustomerRenameMigrationTest` had no fixture row
  in `sync_logs` with `type='contractors'` before rollback; production had such
  a row.
- **Manual recovery on production:** the confirmed 3-step
  ALTER→UPDATE→ALTER sequence applied manually via `mysql` CLI, followed by a
  clean `php artisan migrate` (which marked the migration `Ran`, since `up()`
  begins with `if (! Schema::hasTable('contractors')) return;`).
- **Fix:** correct operation order in the migration file plus a regression
  fixture in `CustomerRenameMigrationTest` (this task).
- **Lesson:** MySQL migration tests must cover **data** that actually exists on
  production — including "rare" lookup tables like `sync_logs` — not only the
  primary entities under test.
