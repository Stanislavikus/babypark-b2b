# Magento V1 Pending Certification Items

**Status:** active campaign ledger  
**Updated:** 2026-09-11  
**Branch:** `campaign/magento-v1-real-certification`

This file is the durable queue for Magento V1 issues deliberately deferred during field-by-field certification. An item stays here until it is implemented/certified or explicitly closed with evidence. Public Adobe Products / Export / Live support remains false while required items are open.

## Open items

### P-01 — Category relation runtime

- Surface: `extension_attributes.category_links` / category-product relation.
- Current truth: Product GET exposes multiple `category_links`, but the platform Product domain currently owns a single `category_id` (`belongsTo Category`); no category mapping / external category-id binding or dedicated Adobe category relation writer exists in `app/`.
- Why deferred: must not be pushed through scalar `custom_attributes`, reduced to an arbitrary “first category”, or allowed to delete provider-only category links.
- Needed proof: dedicated read/write relation seam, controlled assign/unassign or replace semantics, reconciliation, exact restore, no Product-core aliasing.
- Reviewer candidate: yes, after implementation because relation replacement semantics can cause destructive assignment loss.
### P-02 — `url_key` / URL rewrite side effects

- Surface: store-scoped `url_key` plus Commerce URL rewrite/redirect state.
- Current truth: `url_key` is readable as a Product attribute, but a generic change/restore cycle is not sufficient proof because changing the key can leave URL rewrite history/redirects.
- Why deferred: restore of the attribute alone may not restore rewrite side effects.
- Needed proof: capture route/rewrite state before mutation, controlled key change, verify canonical route/redirects, restore key, then prove rewrite state is intentionally restored or explicitly retained according to V1 policy.
- Reviewer candidate: yes.

### P-03 — Custom-attribute clear semantics

- Surface: mapped scalar EAV attributes transitioning from value to empty/null.
- Current truth: false `KnownApplied` was removed; stale remote values now fail closed with `stock_custom_attribute_clear_not_certified` and zero PUT.
- Why deferred: exact field-type/store-scope clearing payload has not been certified against real Magento.
- Needed proof: representative text/select/decimal clear behavior, store-scope inheritance semantics, post-write verification, restore.
- Reviewer candidate: yes after representative target evidence.
### P-04 — Real WRITE permission-denial evidence

- Surface: stock Product/media WRITE authorization failure.
- Current truth: runtime distinguishes structured `401/403 + parameters.resources` from ambiguous access rejection, but proof is fixture-based.
- Why deferred: inducing this safely requires changing Magento Integration permissions.
- Needed proof: controlled permission removal/restoration, captured safe response shape, confirmation that WRITE failure stays operation-specific and connection READ truth remains green.
- Reviewer candidate: yes if real response contradicts current classifier.

### P-05 — Trusted-missing 404 semantics

- Surface: `GET /V1/products/{sku}` for merchant-trusted linked Product.
- Current truth: this target returned 404 with `message` only and no structured `parameters`; runtime conservatively returns `untrusted_or_failed` and performs zero PUT.
- Why deferred: insufficient evidence to promote message-only 404 to `TrustedKnownMissing`.
- Needed proof: official/runtime identity context sufficient to distinguish trusted deletion from routing/store-scope/auth failure without parsing free-form message.
- Reviewer candidate: yes.
### P-06 — `swatch_image` direct ownership

- Surface: Magento media role token `swatch_image`.
- Current truth: media metadata correction now preserves an existing remote `swatch_image` during primary-image no-op/PUT and real-target restore. The connector does not yet claim direct swatch-role assignment ownership.
- Why deferred: preserving an unmanaged role is different from supporting its mutation.
- Needed proof: decide V1 ownership, then either certify explicit swatch assignment/removal or keep the role read/preserve-only.
- Reviewer candidate: yes if ownership is expanded.

### P-07 — Magento Receive Apply breadth

- Surface: Magento → platform mutation, distinct from `AdobeProductDocumentReader` observation.
- Current truth: outbound WRITE + inbound read observation is certified for many fields; actual Receive Apply remains narrower and must not be overstated.
- Needed proof: enumerate current Apply allowlist, implement/certify additional fields deliberately, and verify trust/conflict semantics per field family.
- Reviewer candidate: yes when Apply scope is expanded.

### P-08 — Product WRITE paused/remediation presentation

- Surface: merchant Overview operation-specific readiness.
- Current truth: structured WRITE evidence now exists; healthy Product READ connection must remain Connected when WRITE is blocked.
- Needed proof: projector/UI that surfaces `Передача змін товарів призупинена` only from proven operation evidence, with safe remediation, without changing `ConnectorAccount.connection_status`.
- Reviewer candidate: yes after UI implementation.
### P-09 — Store-view media label clear/inheritance semantics

- Surface: gallery `label` plus `image_label` / `small_image_label` / `thumbnail_label` projections.
- Current truth: on the real default-store target, media `label=null` alone restored gallery metadata but left role-label EAV values; `label=""` cleared those projections and Magento normalized gallery label back to `null` with content/roles unchanged. Request serialization is now context-aware: default-store null reset emits `""`; non-default store-view null remains `null`.
- Why deferred: non-default store views distinguish `null` (use default/inherit) from `""` (explicit empty), so only the default-store reset is certified.
- Needed proof: a real non-default store-view inheritance/explicit-empty cycle before claiming cross-store media-label clear support.
- Reviewer candidate: yes if V1 ownership expands beyond default-store media labels.
