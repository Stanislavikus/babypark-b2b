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
| RQ-001 | `quantity_and_stock_status` | Present on current Magento target and plausibly inventory-owned, but no direct row exists in the frozen Adobe inventory master, alias registry, or product field matrix under this literal key. | Current-target discovery/progress ledger; Magento source model/backend model metadata indicates stock semantics. | Authoritative Adobe/Magento provider source proving this Product attribute identity and its inventory ownership/support contract for the declared Magento V1 scope. | `review_needed`; do not claim `provider_standard` or dedicated inventory owner from literal key alone. |
| RQ-002 | `old_id` | Present on current target and looks system-owned, but no direct frozen provider-registry evidence was found for this literal Product attribute identity. | Current-target discovery/progress ledger only. | Authoritative Adobe/Magento source establishing whether `old_id` is a stable provider-owned Product attribute, its scope, and whether it is system/read-only/deprecated. | `review_needed`; no global provider-standard/system claim. |
| RQ-003 | `custom_layout_update_file` | Present on current target and resembles Magento presentation/layout mechanics, but the literal key is absent from the frozen inventory master/alias registry/product field matrix. | Current-target discovery/progress ledger; live provider metadata includes Magento layout-update model semantics. | Authoritative Adobe/Magento source proving stable provider ownership and exact presentation/layout behavior for the declared Magento V1 scope. | `review_needed`; no global provider-standard claim. |
| RQ-004 | `tier_price` | Current target exposes a literal EAV attribute `tier_price`, while frozen provider evidence proves tier-pricing structures/capabilities but not this literal Product attribute identity as a stable provider field. | Current-target discovery/progress ledger; official matrix covers `product.tier_prices` structure. | Authoritative Adobe/Magento source proving the literal `tier_price` Product attribute identity and its ownership/compatibility contract. | `review_needed`; do not infer provider-standard from related tier-price structures. |
| RQ-005 | `custom_layout` | Current target exposes `custom_layout`, but frozen evidence currently proves related layout/update surfaces rather than this literal Product attribute identity with sufficient certainty. | Current-target discovery/progress ledger; related presentation/layout provider evidence. | Authoritative Adobe/Magento source proving stable literal `custom_layout` identity and exact ownership/behavior for Magento V1. | `review_needed`; no global provider-standard claim while open. |
