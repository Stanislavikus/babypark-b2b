# Magento V1 Pending Certification Items

**Status:** active campaign ledger  
**Updated:** 2026-09-12
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
- Current truth: false `KnownApplied` was removed; stale remote values now fail closed with `stock_custom_attribute_clear_not_certified` and zero PUT. Real target probes on 2026-09-11 show type/scope-specific behavior: optional store-scoped text `meta_title` with `value=""` becomes absent; required global select `manufacturer` rejects `""` with HTTP 400 but `null` removes the attribute; optional website-scoped price/decimal `c_carseats_adac_rating` maps `""` to `0.000000`, while `null` returns HTTP 200 but the effective GET remains `2.000000` (consistent with inherited/use-default behavior on a non-admin store view). Every probe was immediately restored and final custom-attribute diff was zero.
- Frozen V1 decision after independent GPT-5.4 + Sonnet review: there is no universal clear payload. Required attributes are never clearable. Scoped `inherit/use default` is not claimed through stock Product REST because effective GET cannot prove raw override removal. `price`/non-global decimal and `multiselect` remain fail-closed. Optional text-like clear is admitted only per separately certified target/type evidence; optional select/date/boolean/plain-decimal require their own proof before admission.
- Why still open: only representative target cases are certified; cross-type and cross-store semantics remain incomplete.
- Needed proof: add only narrowly proven type/scope clear rules, with fail-closed default and scope-aware postconditions; never infer override removal from effective-value equality alone.
- Reviewer candidate: no broad re-review needed; reopen only for a new type/scope rule or contradictory real-target evidence.
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
- Current truth: R3 consequential Receive Apply remains real-target certified for canonical Product `name`. R4 (2026-09-13) additively implements behavior-class Receive for active workspace custom Dynamic single-select fields: exact `FieldOptionMapping` reverse resolution, `Differs` / `LocalAbsent` proposal states, fresh remote revalidation, and `GovernedDynamicFieldValueWriter::setIfCurrentValue(...)` stale-local protection. No Magento field code is individually allowlisted. `RemoteAbsent` is not Clear. Public Adobe Products/Import/Live support remains false and there is no merchant Apply UI.
- Why still open: R4 is code/test certified but has not yet received a destructive real-target Receive mutation certification for a custom attribute; broader behavior classes (including Money/MultiSelect/etc.) remain intentionally unclaimed.
- Needed proof: complete R4 broad regression and, before advertising public Import support, run a controlled real-target custom-select change/read/apply/restore certification. Future breadth expands by behavior class through its owning writer, never by assuming outbound certification implies Receive writability.
- Reviewer candidate: yes for a narrow R4 implementation review or when the next behavior class is admitted.

### P-08 — Product WRITE paused/remediation presentation — CLOSED 2026-09-12

- Surface: merchant Overview operation-specific readiness.
- Current truth: the implementation slice now projects immutable Live Export `SyncRunItem.findings` through `AdobeProductWritePauseProjector` instead of changing account connection truth. A Connected Magento account shows `Передача змін товарів призупинена` only when a terminal Live Export contains `command_evidence` with `stock_write_permission_denied`, an actual consequential WRITE attempt, `write_access_classification=permission_denied`, and `not_applied`. Ambiguous/message-only evidence does not create the state. A newer verified `stock_write_verified` WRITE clears an older denial; unrelated no-write/mapping runs do not. Successful credential replacement invalidates older WRITE-denial evidence without pretending that the replacement itself proves WRITE readiness. The projection is limited to actors allowed to manage the connector account so read-only Overview paths retain their existing zero connection-check-query/safe-presentation boundary. `ConnectorAccount.connection_status` remains `Connected`, and no raw Magento response/finding detail is rendered.
- Closure evidence: Sonnet 5 High narrow adversarial review found one production-significant temporal-ordering defect: second-precision run timestamps plus UUID ordering could hide a newer proven denial. The projector now orders by persisted run/item chronology, groups indistinguishable timestamp buckets, and fails safe to permission denial when contradictory evidence cannot be ordered. The successful-credential boundary now includes equal-second evidence (`>=`) so a same-second denial cannot be silently discarded. A later item in the same run can supersede an earlier denial when item timestamps actually order them. Focused P-08 coverage is 7/7 and includes both UUID/insertion orders, same-run later success, and same-second credential replacement. No schema widening is required for correctness because ties are handled conservatively rather than guessed.
- Remaining non-blocking note: historical scan volume may eventually warrant a derived/indexed decisive-evidence projection if measured Overview latency requires it; do not trade correctness for an arbitrary time/row cutoff.
- Reviewer candidate: no — review finding accepted and corrected; presentation slice closed.
### P-09 — Store-view media label clear/inheritance semantics

- Surface: gallery `label` plus `image_label` / `small_image_label` / `thumbnail_label` projections.
- Current truth: on the real default-store target, media `label=null` alone restored gallery metadata but left role-label EAV values; `label=""` cleared those projections and Magento normalized gallery label back to `null` with content/roles unchanged. During the 2026-09-18 Configurable linked-update certification, ordinary Product PUT on Junama children with non-empty gallery labels and previously absent role-label projections materialized `image_label` / `small_image_label` / `thumbnail_label` only in the configured `default` store view; `all` and other store views stayed unchanged. The already-certified default-store `label=""` media reset removed those projections again without changing media content, file, or roles. Configurable child runtime now fails closed before Product PUT unless fresh GET proves assigned gallery labels and role-label projections are already equal (or both absent). Post-certification review hardened the same boundary further: orphaned role-label projections are unsafe, the configurable parent is identity/type preflighted before any child HTTP, and parent drift that would require Product PUT also requires media-role label materialization safety. Request serialization remains context-aware: default-store null reset emits `""`; non-default store-view null remains `null`.
- Why deferred: non-default store views distinguish `null` (use default/inherit) from `""` (explicit empty), so only the default-store reset is certified.
- Needed proof: a real non-default store-view inheritance/explicit-empty cycle before claiming cross-store media-label clear support.
- Reviewer candidate: yes if V1 ownership expands beyond default-store media labels.

### P-10 — Store-view Product PUT may materialize untouched scoped overrides — CLOSED 2026-09-17 [Resolved]

- Surface: stock `PUT /V1/products/{sku}` through the configured non-admin store-view code `default`.
- Historical blocker: the 2026-09-12 read-only probe confirmed that ordinary Product GET cannot distinguish inherited values from equal-valued store-specific EAV rows, so a blind PUT could not safely clear the Magento #8897/#26484 side-effect risk. Historical evidence remains `docs/connectors/adobe-commerce/magento_v1_store_scope_inheritance_probe_2026_09_12.json`.
- Closure proof: temporary SSH access was used only for a read-only Magento bootstrap/ResourceConnection raw-EAV observer. For real Product `SKU 1234567890`, logical `entity_id=1`, store `default` / `store_id=1`, an exhaustive non-global EAV scan found six inherited canaries with an Admin row and no store-1 row: `image`, `small_image`, `swatch_image`, `thumbnail`, `url_key`, and website-scoped `cost`. Baseline raw-EAV SHA-256 was `a900f7ce31d24565bfb497809bc5da92e186169a39dc4ed6bb8bcda2802e4c22`.
- Production mutation: the existing moduleless runtime `AdobeProductSimpleCommandExecutor -> AdobeProductStockSimpleWriteExecutor` executed the normal `GET -> PUT -> GET` cycle for price `150 -> 151`; result was `KnownApplied / stock_write_verified`, with exactly one consequential PUT and one reconciliation GET. The raw-EAV snapshot after the PUT had the identical SHA-256 and zero new store-1 overrides across all six canaries.
- Restore: the same production writer restored price `151 -> 150` with `KnownApplied / stock_write_verified`. Final raw-EAV SHA-256 again matched baseline exactly, and an independent production `AdobeProductDocumentReader` GET returned `entity_id=1`, SKU `1234567890`, type `simple`, price `150`, name `Test Product`.
- Conclusion: the supported default-store stock Product PUT path did **not** materialize untouched inherited store/website EAV overrides on the certification target. P-10 is closed for this supported V1 path. This closure does not itself flip public Live support or claim broader non-default-store/media-label inheritance semantics.
- Evidence: `docs/connectors/adobe-commerce/magento_v1_store_scope_inheritance_certification_2026_09_17.json`.
- Reviewer candidate: no further review required unless the supported store-context/write path changes.

### P-11 — Existing-family Configurable structure mutation

- Surface: configurable option mutation, child-link mutation, inactive linked-child lifecycle.
- Current truth: real-target certification on 2026-09-18 now proves **existing configurable option UPDATE-only** for trusted family `524000027bbg`. A controlled validation setup changed option `id=3` / attribute `138` position `0→1`; production `AdobeConfigurableProductCommandCoordinator -> AdobeConfigurableOptionCommandExecutor::executeExistingUpdateOnly()` restored `1→0` with `KnownApplied / configurable_option_put_reconciled`, exactly one PUT and one reconciliation GET. Parent and children were no-op, child links stayed unchanged, the final independent option GET matched the complete baseline, and temporary local MerchantConfirmed fixtures rolled back. The same campaign now also proves **trusted child relink**: controlled DELETE removed child `524000027bbg-Чорний`; production `executeTrustedRelinkOnly()` freshly verified parent/child logical identity, restored the link with one POST + one reconciliation GET, and then repaired Magento's option-value side effect through one certified option PUT + reconciliation GET. Final semantic structure matched baseline; Magento rebuilt only its internal option row id (`9→10`), which is not persisted platform identity.
- Fail-closed boundary: missing option remains `configurable_option_create_not_certified` with zero write; destructive value removal remains `configurable_option_value_removal_requires_adobe_validation` with zero write. The broader historic option POST/create executor remains dormant. Child relink is admitted only for a MerchantConfirmed parent and child whose discriminator/SKU/type are freshly verified; inactive lifecycle remains no-op-only.
- Remaining proof: trusted inactive-child status disable → verified restore without Product CREATE or blind retry.
- Evidence: `docs/connectors/adobe-commerce/magento_v1_configurable_structure_certification_2026_09_18.json`.
- Reviewer candidate: only if real-target child-link/lifecycle behavior contradicts the frozen moduleless V1 path or exposes new identity/transaction ambiguity.
