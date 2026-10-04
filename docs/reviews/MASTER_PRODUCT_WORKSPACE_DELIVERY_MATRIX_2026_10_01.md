# Master Product Workspace Delivery Matrix — 2026-10-01

STATUS: Delivery control artifact. No new Product Decision.

This matrix derives from the approved `MASTER_PRODUCT_WORKSPACE_IMPLEMENTATION_CONTRACT_2026_09_30.md`.
It must not replace that contract. Its purpose is to prevent approved functionality from being lost
between implementation campaigns.

## Rules

- Rows are never deleted because implementation is incomplete.
- Status is one of: DONE, PARTIAL, NOT STARTED, BLOCKED, INTENTIONALLY DEFERRED.
- A row may be marked DONE only with code/runtime evidence, tests, and the required merchant surface.
- Final visual review is a separate gate after functional contract coverage is complete enough to judge the whole Workspace.
- Channel-specific behavior remains owned by channel surfaces; this matrix tracks the universal Master Product capability.

## Coverage matrix

| Capability | Contract / owner | Runtime evidence on develop | Merchant surface | Verification | Status / next seam |
|---|---|---|---|---|---|
| Source-neutral manual Product creation | Product Core | nullable source identity + default Variant + Basic Product assignment | Create Product | feature tests + merged foundation | DONE |
| Workspace progressive shell | Product experience | ProductResource sections + right rail | Master Product Workspace | shell tests | DONE; final visual polish pending |
| Product Type / grouped structure | Product Structure | ProductType, groups, placements, completeness | type/group actions + Characteristics | structure tests | DONE |
| Governed Product/Variant fields | Field Foundation | FieldDefinition/Binding + governed writers | Product fields action | typed/CAS/stale tests | DONE |
| Explicit variant families | Product Core / Structure | ProductVariantAxis + structure service | Add variants / option / variant | 20 focused tests + MySQL full gate | DONE |
| Immutable Variant identity during promotion | Product Core | existing Variant row preserved | hidden from merchant | focused regression | DONE |
| Classification: Category / Brand / Tags / Product Type | Product Core | current canonical fields + ProductType; Master Card campaign adds workspace-scoped ancestor-aware Category labels | Organization rail | classification + Master category-tree feature tests | PARTIAL; hierarchy-aware Category selection implemented in current campaign, final whole-card polish remains |
| Offer presentation/editing | Offer | PriceResolver + ProductPricingSummary + MasterOfferReadService + MasterOfferMutationService + dedicated `manage_product_cost` RBAC | Price section + real semantic SELL / optional COMPARE_AT / permission-gated COST editor over workspace default PriceList qty=1 | Master Offer/RBAC/UI + PriceResolver/order focused gate: 61 tests / 457 assertions PASS | DONE for Master MVP; customer/tier/scheduled pricing remains intentionally outside the basic card |
| Inventory presentation/editing | Inventory & Fulfillment | AvailabilityResolver + MasterInventoryReadService + MasterInventoryMutationService + InventoryRecord | Inventory section + working per-Variant editor for safe single-location state | focused Inventory/UI suite + real MySQL concurrency proof PASS | DONE for Master MVP; multi-location and source-owned/1C mutation intentionally fail-closed/read-only pending separate authority/location workflow |
| Physical/shipping facts | Product Core / Fulfillment | canonical Product weights/dimensions + package/logistics columns; Variant shipping/backorder stays in governed characteristics | one collapsible physical/shipping section | physical/shipping + Master shell tests PASS | DONE for Master card facts; no duplicate shipping policy owner introduced |
| Media gallery | Content & Assets | #249 first-class read model + legacy compatibility projection | gallery + Add / Reorder / Remove; #251 also exposes pending Enhance/Remove background/Prepare for channel | MySQL #536 + Livewire/read-path regression PASS | PARTIAL; media processing/final visual UX remain |
| Media Original preservation | Media Policy v1 | #249 stores uploaded Original bytes without resize/re-encode; reusable workspace asset identity | Add media | MySQL #536 + upload/storage/hash tests PASS | PARTIAL; external ingest and real-target validation remain |
| Media ingest diagnosis | Media Policy v1 | #249 records MIME, bytes, dimensions, hash and diagnosis envelope | normal gallery hides engineering details | focused tests | PARTIAL; weak-source/effective-quality diagnosis remains |
| Product media associations / order / primary | Content & Assets | #249 ProductMedia, explicit sort, primary=position 0, common locale fallback | gallery + reorder/remove | MySQL #536 + Livewire/stale/order tests PASS | DONE for common Product gallery foundation |
| Variant-specific media | Content & Assets | #249 persistence/isolation + #252 association uniqueness, read/mutation services and explicit-primary semantics; not mixed into Magento Product media | reusable Variant Media assignment drawer + row-state presentation | MySQL #549 + focused VariantMedia/UI + Magento media regression PASS | DONE for Master authoring foundation; processing/channel-specific media remains separate |
| Semantic derivative lineage | Media Policy v1 | #249 parent_media_asset_id; gallery rejects derivative rows | not merchant-facing yet | lineage/original guard tests | PARTIAL; Improve/processing pipeline not implemented |
| Transform only on destination or explicit intent | Media Policy v1 | no transform is performed on ingest | no transform UI yet | Original upload regression | PARTIAL; destination/Improve transforms remain |
| Perceptual-quality optimization | Media Policy v1 | policy frozen; no optimizer runtime yet | absent | absent | NOT STARTED |
| Destination profiles: format/dimensions/background/color/metadata | Media Policy v1 / Channel Overlay | absent generic profile | absent | absent | NOT STARTED |
| Background manipulation | Media Policy v1 | defined as pixel transform; runtime absent | absent | absent | NOT STARTED |
| Merchant Improve action | Media Policy v1 | absent; reserved for weak/restoration/creative cases | absent | absent | NOT STARTED |
| Media provenance / AI provenance | Content & Assets | #249 basic source/upload provenance envelope | hidden technical detail | focused tests | PARTIAL; AI/edit lineage consumers remain |
| Stable/versioned channel media artifacts | Channel Overlay / Publication | Magento runtime output exists; generic Master artifact model absent | channel-owned | Magento Stage 3D evidence | PARTIAL |
| No technical-output duplicates in Master gallery | Media Policy v1 | #249 Master gallery associations accept Originals only; channel artifacts remain separate | Master gallery | derivative guard tests | PARTIAL; generic channel-artifact model remains |
| 360 as one logical media presentation | Media Policy v1 | architecture rule frozen; runtime absent | absent | absent | NOT STARTED |
| Responsive widths/formats remain delivery/cache concerns | Media Policy v1 | architecture rule frozen; no Product rendition rows created | not merchant-facing | structural review | DONE as boundary; delivery implementation belongs elsewhere |
| SEO fields | Intelligence / Content | legacy meta_title/meta_description/url columns exist; governed SEO contract not connected | #251 visible read-only SEO section + pending actions | Workspace UI test proves deferred fields do not save | PARTIAL; intentionally deferred to final SEO module |
| Search Brief / keyword research | Intelligence | absent | #251 visible «Отримати ключові слова» pending action | pending-action zero-mutation UI contract | NOT STARTED; intentionally deferred to final SEO module |
| AI proposals with stale revision/provenance | Intelligence | architecture resolved; runtime absent | #251 visible AI-content/file-enrichment pending actions | pending-action UI contract | NOT STARTED; intentionally deferred to final AI/SEO module |
| Right rail lifecycle/source | Product Core | current Product state/source projection; SAVE/PUBLISH boundary explicit | Status rail uses merchant wording and separate per-channel publication state | shell tests | DONE for Master card |
| Right rail data quality | Intelligence / Derived | basic + governed structure completeness | Data quality rail explicitly scoped to Master completeness | shell tests | DONE for Master card; target-specific checks remain channel-owned |
| Right rail attention items | Intelligence / Derived | basic/structure findings | merchant-facing missing-data list | shell tests | DONE for Master card |
| Per-target publication/readiness | Channel Overlay | connector-specific readiness exists; Master card now reuses Magento per-product classification projection | channel rail shows classification ready/setup/review and links to Magento Workbench without claiming publication readiness | connector + Master UI tests PASS | PARTIAL by design; full publication readiness remains channel-owned |
| Channel drawer / target-specific settings | Channel Overlay | Magento Workbench/settings exist separately | no unified Master drawer yet | connector tests | PARTIAL |
| SAVE vs PUBLISH | Publication | connector Preview/Live separated; generic ChangeSet absent | separate channel actions | connector suites | PARTIAL |
| Publication ChangeSet / Projection Plan | Publication | connector-specific foundations; generic Master layer absent | absent | absent | NOT STARTED |
| Entity resolution before inbound authority | Identity / Authority | connector Entity Trust exists; universal inbound flow absent | connector-specific | certified Magento evidence | PARTIAL |
| Import entry point converging on Master | Product creation | workspace_import_aliases exists; universal spreadsheet runtime absent | #251 visible Excel/CSV Smart Import pending action | pending modal + zero-mutation test | PARTIAL surface only; runtime remains a later non-AI import campaign |
| Create with AI entry point converging on Master | Product creation / AI | absent | absent | absent | NOT STARTED |
| Final integrated merchant E2E | Whole Workspace | individual slices only | incomplete | not yet run | NOT STARTED |
| Final visual/design acceptance | UX | preliminary direction frozen | intentionally pending | product-owner visual review | INTENTIONALLY DEFERRED until functional coverage |

## Current campaign pointer

Completed campaign: PR #249 — first-class Media / Assets foundation and ingest diagnosis — merged at `0578629fd4880cf16022409eb9af2689a7b810ee`; MySQL #536 PASS (3678 tests / 83,856 assertions).
Completed campaign: PR #251 — expose and exercise the full non-SEO Master Product Workspace — merged at `dfa9a44bacc86b58f881063f242fa871bb399238`; MySQL #540 PASS (3681 tests / 83,890 assertions).
Completed campaign: PR #252 — Master Variant Media authoring — merged at `d4c93d34317e7596709b8c66a1b5c07329a3e32b`; MySQL #549 PASS (3701 tests / 84,035 assertions).
Current campaign: `feat/master-product-card-completion` — complete the approved non-SEO Master Product Card in one campaign; durable control/resume state lives in `MASTER_PRODUCT_CARD_COMPLETION_CAMPAIGN_2026_10_04.md`.
