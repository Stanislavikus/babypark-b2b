# Magento V1 Research Queue

**Status:** durable fail-closed research queue for Magento V1 field/surface questions.

Use this file whenever runtime or implementation work encounters a provider object, field, behavior, owner, or semantic rule that cannot be proven from frozen authoritative evidence with sufficient confidence.

Rules:

- no 100% provider evidence means no positive provider-standard/dedicated-owner claim;
- unresolved fields remain inventoried but classify `review_needed` in runtime;
- current-target appearance, labels, or `is_user_defined` flags are not sufficient provider-level proof;
- do not reconstruct unresolved questions from chat memory;
- close an item only by recording the authoritative evidence and the resulting contract/runtime decision.

## Open items

| ID | Object / field | Why it is suspicious | Evidence already checked | Evidence still required | Runtime while open |
| --- | --- | --- | --- | --- | --- |
| RQ-002 | `old_id` | Present on current target and looks system-owned, but no direct frozen provider-registry evidence was found for this literal Product attribute identity. | Current-target discovery/progress ledger; legacy Magento 1 evidence is not sufficient for Magento V1. | Current Magento/Adobe source establishing the literal Product attribute, scope, and supported ownership semantics. | `review_needed`; no global provider-standard/system claim. |
| RQ-003 | `custom_layout_update_file` | Present on current target and resembles Magento presentation/layout mechanics, but the literal key is absent from the frozen inventory master/alias registry/product field matrix. | Current-target discovery/progress ledger; live provider metadata includes Magento layout-update source/backend models. | Authoritative current Magento/Adobe source proving the literal Product attribute identity and exact ownership/behavior. | `review_needed`; no global provider-standard claim. |
| RQ-006 | `links_exist` | Live discovery identifies an internal Downloadable-looking field (`frontend_input=null`, `apply_to=downloadable`), but current authoritative evidence checked does not establish this literal attribute strongly enough for the frozen registry. | Live Magento metadata; current-target fixture; non-authoritative implementation references. | Authoritative current Magento/Adobe source proving the literal Product attribute identity and dedicated Downloadable ownership. | `review_needed`; no positive dedicated-owner claim. |
| RQ-007 | `samples_title` | Live discovery identifies an internal Downloadable-looking field (`frontend_input=null`, `apply_to=downloadable`). Adobe docs prove the Downloadable default sample-title configuration, but not yet the literal Product EAV attribute identity to the required standard. | Live Magento metadata; Adobe configuration reference; historical setup traces. | Authoritative current Magento/Adobe source proving the literal Product attribute identity and dedicated Downloadable ownership. | `review_needed`; no positive dedicated-owner claim. |

## Closed items

| ID | Field | Evidence / decision | Closed |
| --- | --- | --- | --- |
| RQ-001 | `quantity_and_stock_status` | Official Magento Product-admin configuration and Magento stock-backend issue evidence prove provider inventory ownership. Classified dedicated `inventory`. | 2026-09-13 |
| RQ-004 | `tier_price` | Official Magento `ProductAttributeInterface::CODE_TIER_PRICE` and tier-price handlers prove provider pricing ownership. Classified dedicated `pricing`. | 2026-09-13 |
| RQ-005 | `custom_layout` | Official Magento Catalog GraphQL schema exposes the literal Product attribute. Classified `provider_standard`. | 2026-09-13 |
| LIVE-001 | `links_purchased_separately` | Adobe 2.4.9 GraphQL Product contract plus Magento Downloadable setup evidence prove provider ownership. Classified dedicated `downloadable`. | 2026-09-13 |
| LIVE-002 | `links_title` | Adobe 2.4.9 GraphQL Product contract and Downloadable configuration contract prove provider ownership. Classified dedicated `downloadable`. | 2026-09-13 |

Detailed source URLs and conclusions: `MAGENTO_V1_PROVIDER_IDENTITY_EVIDENCE_2026_09_13.md`.
