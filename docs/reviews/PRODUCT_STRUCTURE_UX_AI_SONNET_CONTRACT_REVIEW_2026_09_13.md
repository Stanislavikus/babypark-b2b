# Product Structure Contract — Sonnet Adversarial Review Resolution

**Date:** 2026-09-13
**Reviewed contract HEAD:** `0f0f783fecd1e254e4c959bafc117c2cb29f1c66`
**Review verdict:** `APPROVE PRODUCT STRUCTURE CONTRACT FOR SLICE A`

This record preserves the concrete Sonnet implementation-level findings received after the final synthesis/contract and the lead-agent resolution of each finding before Slice A implementation.

## Accepted MUST-FIX findings

1. **Bootstrap object-type filter.** Basic Product bootstrap must explicitly include only `FieldObjectType::Product` and `FieldObjectType::ProductVariant`. `Customer` bindings must never produce ProductType placements, even when sharing the same legacy `field_group` string.
2. **Physical Product FK type.** `product_active_optional_groups.product_id` must be `unsigned BIGINT`, matching `products.id` and existing product-value tables. New structure entities may remain UUID-keyed.
3. **Authorization gap.** Current workspace permission catalogue has no product-structure mutation authority. Slice A must add and seed `manage_product_structure`; role names are not authority.
4. **Out-of-type sync semantics.** Slice A does not alter current connector Send/Receive eligibility. Retained out-of-type values that remain FieldMapped may still sync. Any suppression rule belongs to later Readiness/sync-policy work and must be decided explicitly there.
5. **Real MySQL gate.** Slice A schema/tenant/constraint tests must run against real MySQL before the slice is considered complete; SQLite-only success is insufficient.
## Accepted additional contract corrections

6. **Post-Slice-A new bindings.** Basic Product becomes the compatibility envelope. A single idempotent reconciler must place every new admin-visible Product/ProductVariant binding into that workspace's Basic Product; global bindings are reconciled into all existing workspace Basic Products by a controlled seed/deploy path. Custom ProductTypes never gain fields implicitly.
7. **Legacy group dedupe is intentional.** Basic bootstrap/reconciliation dedupes internal AttributeGroup identity by `(workspace_id, legacy_field_group_code)`. Provider provenance does not fork groups that legacy UI already treated as one grouping label.
8. **Structure revision semantics.** ProductType `structure_revision` is authoritative: every Group/Field placement structural mutation bumps it transactionally. This closes the future AI/impact-preview staleness seam before data exists.
9. **Architecture terminology note.** `docs/04-ARCHITECTURE_PRINCIPLES.md` receives an explicit translation note from historical `AttributeDefinition`/old value-table terms to current Field Foundation terminology.

## Optional findings intentionally not expanded in Slice A

- Variant completeness may later need a cached/materialized projection for large bulk filters; correctness-first live calculation remains the v1 default until profiling demonstrates the need.
- No provider-specific special case is introduced for Stage2-C's current `characteristics` value; the legacy string is treated as the same bootstrap grouping hint as any other Product/ProductVariant binding.

## Architecture status

None of these corrections change the frozen ProductType/AttributeGroup model, table decomposition, optional-group semantics, ProductType-change preservation rules, or slice boundaries. They only make physical types, authority, compatibility, sync scope and verification explicit.

Gemini 3.1 may still review the previous contract SHA if already in flight. Its findings should be reconciled against this correction record and the amended contract rather than triggering a new broad research loop.
