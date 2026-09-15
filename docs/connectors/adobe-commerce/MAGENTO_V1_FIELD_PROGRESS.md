# Magento V1 Field Progress

**Status:** Stage 1 runtime-derived resume index
**Updated:** 2026-09-13
**Authoritative real-target evidence:** `magento_v1_stage1_real_target_evidence_2026_09_13.json`
**Historical engineering ledger:** `magento_v1_real_target_field_progress_2026_09_12.csv` (historical only; not runtime classification truth)

Runtime classification is now authoritative. The historical CSV remains evidence of earlier exact WRITE→READ→RESTORE and behavior-class work, but it must not be edited as a competing field-classification source.

## Current Stage 1 snapshot

The latest successful real-target v2 discovery accounts for **106 / 106** trustworthy Magento Product attribute identities with **0 silent drops**.

| Runtime fact | Count |
| --- | ---: |
| Received / persisted identities | 106 / 106 |
| Normalized | 102 |
| Identified but unclassified | 4 |
| `canonical_platform` | 6 |
| `provider_standard` | 22 |
| `workspace_custom` | 47 |
| `system_or_dedicated_owner` | 27 |
| `review_needed` | 4 |
| `unsupported` | 0 |
| Distinct non-null behavior classes | 49 |
| Actual verified generic `FieldMapping` rows | 4 |

The four open review fields are `custom_layout_update_file`, `links_exist`, `old_id`, and `samples_title`. They remain fail-closed in `MAGENTO_V1_RESEARCH_QUEUE.md`; their presence does not invalidate the otherwise trustworthy snapshot.

## Canonical-relevant real-target audit

| Magento key | Platform concept | Runtime outcome | Generic mapping | Why |
| --- | --- | --- | --- | --- |
| `category_ids` | `category` | dedicated relation owner | no | Category relation is not a generic field |
| `color` | `color` | workspace custom | no | verified Adobe channel decision is account-specific |
| `description` | `description` | canonical | **yes** | verified Adobe rule + active/verified internal field |
| `image` | `image` | dedicated media owner | no | media domain owns the value |
| `manufacturer` | `manufacturer` | workspace custom | no | account-specific; internal concept is still proposed/partially verified |
| `meta_description` | `meta_description` | canonical, deferred | no | verified Adobe channel decision is deferred |
| `meta_title` | `meta_title` | canonical, deferred | no | verified Adobe channel decision is deferred |
| `name` | `name` | canonical | **yes** | verified Adobe rule + active/verified internal field |
| `price` | `price` | dedicated pricing owner | no | pricing domain owns the value |
| `short_description` | `short_description` | provider standard | no | internal canonical target is proposed/partially verified |
| `sku` | `sku` | canonical | **yes** | verified Adobe rule + active/verified internal field |
| `status` | `status` | canonical | **yes** | verified Adobe transformed rule + active/verified internal field |

Therefore the current Products configuration has exactly four automatic generic mappings: `description`, `name`, `sku`, and `status`. Missing mappings are explicit architecture decisions, not silent gaps.

## Workspace-custom scale state

The real target now classifies **47** Product attributes as `workspace_custom`. Stage 1 deliberately preserves and behavior-classifies them without creating workspace `FieldDefinition` rows. Literal customer keys are not engineering units: different keys that share provider behavior reuse the same behavior-class mechanism. Full custom materialization, options, grouping and custom Receive belong to Stage 2.

## Mandatory resume algorithm

1. read this file and the runtime evidence JSON;
2. read `MAGENTO_V1_RESEARCH_QUEUE.md` and `MAGENTO_V1_PENDING_CERTIFICATION_ITEMS.md`;
3. treat DB classification as source of truth and generated evidence as a snapshot/export only;
4. never revive the historical CSV as a manually maintained classification source;
5. group unresolved work by behavior/owner, not literal external key;
6. do not re-inventory Magento merely because a chat/session ended.

## Stage boundary

Stage 1 is complete when the runtime and tests remain green with the evidence above. Stage 2 starts from these persisted classifications and covers workspace custom-field materialization plus Magento attribute sets/groups. It must not rediscover or reclassify the Product attribute surface from zero.
