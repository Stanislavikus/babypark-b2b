# Stage 2A-2 — Merchant Preview Work Surface (visual contract)

Static fixture-backed reference for the merchant Preview workflow implemented in
`ManageAdobeProductsExportPreview` and the evolved `ListSyncDataSetup` landing.

## Renderable prototype

Open **`index.html`** in a browser (or serve the folder statically). Tabs cover the
merchant lifecycle states, remediation actionability matrix, and product identity rules
described below.

Files:

- `index.html` — interactive non-runtime screens
- `styles.css` — shared styling (light/dark toggle)

## Covered merchant states

1. Preview ready to start / no previous run (`ready_to_preview`)
2. Setup required + actor can configure (`configuration_absent` + setup action)
3. Setup required + actor cannot configure (`configuration_absent` + permission copy)
4. Account unavailable (`account_unavailable`)
5. Queued — «Перевірка готується»
6. Running — «Перевірка виконується»
7. Failed run with rerun
8. Failed run with physically persisted partial items — **no product rows rendered**
9. Completed / all ready
10. Completed / blocked products, warning count zero
11. Completed / mixed ready-warning-blocked
12. Completed + configuration changed after run (rerun banner)
13. Mapping actionable (`ACTION_AVAILABLE` → ManageSyncFieldMappings)
14. Mapping view-only
15. Mapping permission-required
16. Product/Variant `NO_EDIT_SURFACE`
17. Option Mapping `NO_EDIT_SURFACE` (Stage 2B owner)
18. Connector Setup actionable / permission-required
19. Single-variant Product identity with canonical `ProductVariant.sku`
20. Multi-variant Product identity — «N артикулів»
21. Multi-variant findings with distinct finding-level SKU context
22. `MissingSku` — «Артикул не вказано» without raw variant identity

## Explicit Stage 2A-2 exception

There is **no** safe common bulk remediation destination today (no Product/Variant
editor, no pricing editor, Option Mapping belongs to Stage 2B). The worklist
does not expose a fake «Fix selected» bulk action.

## Product identity truth

- Product-row identity uses **current** Product name / brand / sellable-variant SKU context.
- Canonical SKU is **ProductVariant-level**; legacy `products.sku` is not promoted.
- Multi-variant rows show «N артикулів» rather than picking one arbitrary SKU.

## Verification

Automated coverage:

- `tests/Feature/Sync/Stage2A2MerchantPreviewWorkSurfaceTest.php`
- `tests/Feature/Sync/Stage2A2MerchantPreviewConformanceTest.php`
- `tests/Unit/Sync/Preview/SyncPreviewFindingReferenceResolverTest.php`
