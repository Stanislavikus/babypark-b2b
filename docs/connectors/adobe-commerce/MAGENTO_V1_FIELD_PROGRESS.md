# Magento V1 Real-Target Per-Field Progress

**Status:** mandatory campaign resume ledger/index
**Updated:** 2026-09-12
**Real-target discovery snapshot:** `a2a3edfa-025f-49cd-ab5b-4e6af71b79e6`
**Machine-readable rows:** `magento_v1_real_target_field_progress_2026_09_12.csv`

This file exists so Magento field work never has to be reconstructed from chat memory or from aggregated cluster prose.

The CSV is the current real-target per-field progress ledger: exactly one row per discovered field. `MAGENTO_V1_PRODUCT_FIELD_MATRIX.md` remains the connector mechanics/capability matrix; `MAGENTO_V1_PENDING_CERTIFICATION_ITEMS.md` remains the blocker queue. These files have different jobs and must not replace one another.

## Current snapshot

| Progress bucket | Discovered | Full WRITE→READ→RESTORE pass | Other explicit checked result | Not yet certified |
| --- | ---: | ---: | ---: | ---: |
| `canonical_platform` | 12 | 6 | 3 | 3 |
| `magento_standard` | 45 | 8 | 6 | 31 |
| `workspace_custom` | 45 | 24 | 0 | 21 |
| **Total** | **102** | **38** | **9** | **55** |

`Other explicit checked result` means a real terminal classification/proof such as identity read-only, system-owned, relation pending, URL side-effect pending, or media preservation. It must not be counted as bidirectional field certification.

## Canonical mapping state

The 12 `canonical_platform` rows have a neutral platform representation today. Six already have a verified Adobe automatic mapping rule in the canonical mapping registry: `category_ids → category`, `description`, `name`, `short_description`, `sku`, and transformed `status`.

The remaining canonical rows are explicit mapping work, not hidden knowledge: `color`, `image`, `manufacturer`, `meta_description`, `meta_title`, and `price`. Some already passed real-target transport, but their Adobe canonical mapping rule still has to be materialized/verified where applicable.

## Workspace-custom scale state

The current target exposes 45 workspace-custom `c_*` / `x_*` fields. Twenty-four already passed reversible real-target WRITE→READ→RESTORE certification.

The remaining 21 are not 21 new transport designs. They collapse into two behavior signatures already exercised by certified fields:

- 16 × `select / global`;
- 5 × `money / website`.

They should therefore be batch-certified through the existing proven mechanism. New vendor research is required only if a concrete field exposes a new behavior, semantics conflict, or runtime failure.

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
