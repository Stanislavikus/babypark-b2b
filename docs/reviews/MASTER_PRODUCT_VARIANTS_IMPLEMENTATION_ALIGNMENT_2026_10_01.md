# Master Product Variants implementation alignment — 2026-10-01

STATUS: Implementation alignment only. No new Product Decision.

## PRE-CODE ARCHITECTURAL ALIGNMENT

**Task Type:** Strict Alignment — Product Structure / ProductVariant lifecycle / DB invariant / Filament merchant workflow.

**Goal:** A source-neutral Master Product can be promoted from one hidden Variant to an explicit variant family without replacing the original VariantID. The merchant can declare variant axes from governed ProductType Variant fields, assign the first-axis value to the existing variant, create additional explicit variants, add another axis without Cartesian expansion, and add explicit variants by axis combination.

**Docs Checked:** `00-WHY.md`, `01-PRODUCT_VISION.md`, `02-ATTRIBUTE_DICTIONARY.md`, `03-DOMAIN_MODEL.md`, `04-ARCHITECTURE_PRINCIPLES.md`, `05-AI_WORKING_AGREEMENT.md`, `Project_Documentation_Map.md`, `[Resolved] MASTER_PRODUCT_WORKSPACE_IMPLEMENTATION_CONTRACT_2026_09_30.md`, current Product Structure code/tests on `develop`.

**Affected Domain Contexts:** Product Catalogue, Product Structure, Field Foundation. Pricing / Inventory / Media are read-only presentation dependencies in this slice and remain owned by their existing domains.

**Primary Sources & Standards:** Current repository contracts are sufficient. No external provider behavior is needed because this is Master Catalogue structure, not connector projection.

**Architecture Checklist Result:**
1. Tenant isolation — new workspace-owned axis rows carry `workspace_id` and a composite workspace/product FK.
2. Automated scoping — new model uses `BelongsToWorkspace`; mutation service always receives explicit Workspace and re-reads locked rows inside it.
3. Authorization — mutations require `manage_products` through `WorkspaceAuthorization` with explicit target Workspace.
4. Attribute Dictionary — no descriptive product columns are added; axes reference existing governed Variant `FieldBinding`s.
5. Storage split — axis values remain ordinary `VariantFieldValue`s; no polymorphic value table.
6. Localization — unchanged; axis candidates are single-value Select fields and persist stable codes through the governed writer.
7. Import aliases — not applicable.
8. Domain separation — Product Structure owns axis declaration; Pricing/Inventory/Media are not mutated.
9. Variant cardinality — the existing default Variant is preserved and becomes the first family member; no delete/recreate.
10–19. B2B/orders/payments/reservations/connector/payment safety — not changed. Availability remains read through `AvailabilityResolver`.
20. Hidden complexity — UI says Variants / Options, never EAV/axis tables/CAS.
21–22. External URL / connector secrets — not applicable.
Filament validation — application validation remains authoritative; no browser-only business invariant.

**Architecture Risks Identified:** accidental VariantID replacement; implicit Cartesian product generation; duplicate option combinations; cross-workspace axis binding; ProductType drift; manual structure edits on 1C-owned products; silent copying of SKU/GTIN/stock/price/media; stale concurrent variant edits.

**Chosen Technical Approach:**
- Add `product_variant_axes` as structural metadata: workspace + Product + Variant `FieldBinding` + deterministic order.
- Axis eligibility is derived from the current ProductType: active admitted `ProductVariant` binding, Dynamic storage, active single-value Select definition. ProductType therefore owns which governed fields may be selected; Product owns which admitted fields are declared for this family.
- Existing Variant dynamic values remain the only axis-value storage.
- `ProductVariantStructureService` owns all shape mutation under transaction + locks.
- Promotion preserves the exact existing Variant row; additional variants start source-neutral with no copied SKU/GTIN/price/stock/media.
- Second-axis addition assigns one explicit value per existing active Variant; no Cartesian expansion.
- New variants require an explicit value for every declared axis and reject duplicate combinations.
- Manual structure mutation is initially source-neutral only (`Product.onec_guid IS NULL`) until the authority/change-intent workflow exists for ERP-owned structure.
- ProductType changes and ProductType field-placement removal must fail closed when they would invalidate declared axes.

**Non-Technical Simplicity Check:** A merchant sees `Додати варіанти`, chooses e.g. Color and values, then sees a compact variant table. The internal first Variant is never exposed as a technical default record.

**Stop & Amend Required:** No. The resolved Master Product contract already defines immutable VariantIDs, declared axes, explicit non-Cartesian Variant lists, typed governed axis fields, and ProductType ownership of admissible structure. This slice adds the missing physical implementation without introducing a new business concept.
