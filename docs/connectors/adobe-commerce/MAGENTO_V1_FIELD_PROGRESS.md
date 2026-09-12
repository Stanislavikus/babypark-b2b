# Magento V1 Real-Target Per-Field Progress

**Status:** mandatory campaign resume ledger/index
**Updated:** 2026-09-12
**Real-target discovery snapshot:** `a2a3edfa-025f-49cd-ab5b-4e6af71b79e6`
**Machine-readable rows:** `magento_v1_real_target_field_progress_2026_09_12.csv`

This file exists so Magento field work never has to be reconstructed from chat memory or from aggregated cluster prose.

The CSV is the current real-target per-field progress ledger: exactly one row per discovered field. `MAGENTO_V1_PRODUCT_FIELD_MATRIX.md` remains the connector mechanics/capability matrix; `MAGENTO_V1_PENDING_CERTIFICATION_ITEMS.md` remains the blocker queue. These files have different jobs and must not replace one another.

## Current snapshot

| Progress bucket | Discovered | Exact WRITE→READ→RESTORE | Behavior-class covered | Other explicit checked result | Unresolved engineering rows | Onboarding-ready custom |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `canonical_platform` | 12 | 6 | 0 | 3 | 3 | n/a |
| `magento_standard` | 45 | 8 | 0 | 6 | 31 | n/a |
| `workspace_custom` | 45 | 24 | 21 | 0 | **0** | **45 / 45** |
| **Total** | **102** | **38** | **21** | **9** | **34** | **45 / 45 custom** |

`Behavior-class covered` is deliberately not counted as exact per-code bidirectional certification. It means the discovered custom field matches a transport behavior already proven on the same real target and can use the reusable onboarding path. `Other explicit checked result` covers terminal classifications such as identity read-only, system-owned, relation pending, URL side-effect pending, or media preservation.

## Canonical mapping state

The 12 `canonical_platform` rows have a neutral platform representation today. Six already have a verified Adobe automatic mapping rule in the canonical mapping registry: `category_ids → category`, `description`, `name`, `short_description`, `sku`, and transformed `status`.

The remaining canonical rows are explicit mapping work, not hidden knowledge: `color`, `image`, `manufacturer`, `meta_description`, `meta_title`, and `price`. Some already passed real-target transport, but their Adobe canonical mapping rule still has to be materialized/verified where applicable.

## Workspace-custom scale state

The current target exposes 45 workspace-custom `c_*` / `x_*` fields. Twenty-four already passed reversible real-target WRITE→READ→RESTORE certification. The other 21 collapse into only two behavior signatures already exact-field certified on this same target:

- 16 × `select / global`;
- 5 × `money / website`.

A 2026-09-12 read-only scan of all 19 products on the certification Magento target found live values for 18 of those 21 rows; three (`c_carseats_child_gender`, `c_furniture_color`, `c_strollers_color_joolz_day_5`) are present in discovered schema but unused by the current catalogue. Evidence is `magento_v1_custom_behavior_coverage_2026_09_12.json`.

All 21 are now `BEHAVIOR_CLASS_COVERED_NO_EXACT_WRITE`, so **all 45/45 BabyPark custom attributes are onboarding-ready**. This does not claim 45 exact remote mutations: exact WRITE→READ→RESTORE remains 24/45. A new destructive PUT is required only if an attribute introduces a new behavior class, special/clear semantics, or an actual runtime failure. This avoids multiplying P-10 store-scope side-effect risk merely to retest an already-proven transport mechanism.

## Mandatory resume algorithm

When resuming Magento field work:

1. read this file and the CSV;
2. read `MAGENTO_V1_PENDING_CERTIFICATION_ITEMS.md`;
3. verify the current runtime owner in `08-CONNECTOR_SYNC_RUNTIME_ATLAS.md` and code only for the seam being touched;
4. select rows whose `next_action` is unresolved, grouping rows by shared behavior/owner;
5. run the smallest representative or batch certification that can advance those rows;
6. write every resulting status and `evidence_ref` back to the ledger in the same campaign change;
7. never re-run the complete external inventory/research merely because a chat/session ended.

Re-inventory is justified only when the external connector/API version or discovery snapshot materially changes, or when an explicit ledger inconsistency is found.

## Customer onboarding rule

This real-target engineering certification is not a merchant onboarding workflow. A new Magento merchant with hundreds or thousands of custom attributes must not wait for manual per-field research.

Discovery records the fields immediately. Known canonical mappings are reused; known Magento-standard semantics are reused; custom attributes are attached to known behavior classes and proposed as workspace fields/mappings. Only unknown behavior classes, ambiguous semantic promotion, or actual runtime failures enter engineering review.
