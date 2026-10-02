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
| Classification: Category / Brand / Tags / Product Type | Product Core | current canonical fields + ProductType | Organization rail | existing feature tests | PARTIAL; category UX/tree and final polish remain |
| Offer presentation | Offer | PriceResolver / ProductPricingSummary | Price section read-only | pricing suite | PARTIAL; Master editing workflow remains |
| Inventory presentation | Inventory & Fulfillment | AvailabilityResolver | Inventory section read-only | availability suite | PARTIAL; Master editing/location workflow remains |
| Physical/shipping facts | Product Core / Fulfillment | current governed columns | collapsible section | current Product tests | PARTIAL; applicability/policy remains |
| Media gallery | Content & Assets | #249 first-class read model + legacy compatibility projection | gallery + Add / Reorder / Remove actions | Livewire + read-path regression; MySQL pending | PARTIAL; final visual/media UX still pending |
| Media Original preservation | Media Policy v1 | #249 stores uploaded Original bytes without resize/re-encode; reusable workspace asset identity | Add media | upload/storage/hash tests; MySQL pending | PARTIAL; external ingest and real-target validation remain |
| Media ingest diagnosis | Media Policy v1 | #249 records MIME, bytes, dimensions, hash and diagnosis envelope | normal gallery hides engineering details | focused tests | PARTIAL; weak-source/effective-quality diagnosis remains |
| Product media associations / order / primary | Content & Assets | #249 ProductMedia, explicit sort, primary=position 0, common locale fallback | gallery + reorder/remove | Livewire + stale/order tests; MySQL pending | PARTIAL until MySQL/real verification |
| Variant-specific media | Content & Assets | #249 VariantMedia persistence + isolation; not mixed into Magento Product media | no authoring UI yet | connector regression | PARTIAL; variant authoring/presentation remains |
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
| SEO fields | Intelligence / Content | meta_title/meta_description/url columns | SEO section | existing form tests | PARTIAL |
| Search Brief / keyword research | Intelligence | absent | absent | absent | NOT STARTED |
| AI proposals with stale revision/provenance | Intelligence | architecture resolved; runtime absent | absent | absent | NOT STARTED |
| Right rail lifecycle/source | Product Core | current Product state/source projection | Status rail | shell tests | PARTIAL |
| Right rail data quality | Intelligence / Derived | basic + structure completeness | Data quality rail | shell tests | PARTIAL; richer profiles remain |
| Right rail attention items | Intelligence / Derived | basic/structure findings | Attention rail | shell tests | PARTIAL |
| Per-target publication/readiness | Channel Overlay | connector-specific readiness exists | channel list / Workbench | connector suites | PARTIAL; universal target surface remains |
| Channel drawer / target-specific settings | Channel Overlay | Magento Workbench/settings exist separately | no unified Master drawer yet | connector tests | PARTIAL |
| SAVE vs PUBLISH | Publication | connector Preview/Live separated; generic ChangeSet absent | separate channel actions | connector suites | PARTIAL |
| Publication ChangeSet / Projection Plan | Publication | connector-specific foundations; generic Master layer absent | absent | absent | NOT STARTED |
| Entity resolution before inbound authority | Identity / Authority | connector Entity Trust exists; universal inbound flow absent | connector-specific | certified Magento evidence | PARTIAL |
| Import entry point converging on Master | Product creation | legacy/import seams exist; universal flow absent | incomplete | incomplete | NOT STARTED |
| Create with AI entry point converging on Master | Product creation / AI | absent | absent | absent | NOT STARTED |
| Final integrated merchant E2E | Whole Workspace | individual slices only | incomplete | not yet run | NOT STARTED |
| Final visual/design acceptance | UX | preliminary direction frozen | intentionally pending | product-owner visual review | INTENTIONALLY DEFERRED until functional coverage |

## Current campaign pointer

Current campaign: PR #249 — first-class Media / Assets foundation and ingest diagnosis.
Current HEAD implements the first-class persistence/read/write foundation, common Product gallery actions, legacy compatibility, and Magento input compatibility.
Remaining campaign gates: authoritative GitHub MySQL CI, Lead diff review, Delivery Matrix reconciliation, and bounded real-target compatibility evidence before merge readiness.
