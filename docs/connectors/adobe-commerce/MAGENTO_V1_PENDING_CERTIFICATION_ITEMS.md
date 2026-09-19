# Magento V1 Pending Certification Items

**Status:** active campaign ledger  
**Updated:** 2026-09-18
**Branch:** `campaign/magento-v1-real-certification`

This file is the durable queue for Magento V1 issues deliberately deferred during field-by-field certification. An item stays here until it is implemented/certified or explicitly closed with evidence. Public Adobe Products / Export / Live support remains false while required items are open.

## Open items

### P-01 — Category relation runtime — CLOSED 2026-09-18 [Resolved]

- Surface: `extension_attributes.category_links` / Magento category-product membership relation.
- Domain boundary: platform Product continues to own one Merchant Category (`products.category_id`). Magento may expose multiple category links. Category correspondence is therefore a dedicated ConnectorAccount-scoped relation mapping, not `FieldMapping`, not Product multi-category redesign, and not Standard Category.
- Persistence: `connector_category_mappings` stores local Category → external Magento category ID with forward uniqueness only; many local categories may intentionally collapse to one external category. `adobe_product_category_assignments` stores destructive ownership only by exact trusted `ExternalRecordLink + external_category_id`; it stores neither `category_id`, `product_id`, nor `product_variant_id`.
- Identity: standalone Simple uses the trusted Variant-subject ERL; Configurable parent uses the trusted Product-subject ERL. A managed assignment also stores the Magento logical `entity_id` witness and fails closed on anchor drift.
- Provider-only safety: a pre-existing Magento category link is satisfaction evidence only and is never adopted as platform-owned. DELETE is allowed only for a locally proven managed assignment. Ambiguous ADD becomes `add_ambiguous` and never gains destructive ownership from a later GET alone; ambiguous consequential mutation is never blindly retried inside the same execution.
- Write semantics: use only granular `CategoryLinkRepositoryInterface::save/deleteByIds` REST endpoints. Whole-array `CategoryLinkManagementInterface::assignProductToCategories` is forbidden because it can remove unrelated categories. Category change is add-before-remove and diffing occurs on distinct external category IDs, so many-local→one-external collapse cannot generate a remove of the same remote relation.
- Global relation context: category-product membership is a global Magento catalog relation. Real-target certification proved that `/rest/default/V1/categories/{id}/products` can website-filter a valid global relation and make granular DELETE return HTTP 400 `The category doesn't contain the specified product.` for a Product not assigned to that Store View's website. The dedicated P-01 relation writer therefore uses `/rest/all` only for granular category membership POST/DELETE. This is a narrow relation-owner exception and does not authorize Product field, media, localized value, or general multi-Store-View writes under `all`.
- Execution consistency: effective account category mappings and their deterministic revision are captured in Preview `configuration_snapshot`; Live admission rejects Preview evidence if the account mapping revision changed after Preview.
- Concurrency: remote relation execution is serialized with the existing ConnectorAccount operation cache lock; DB row locks are held only in short local state-transition transactions, never across Magento HTTP.
- Real-target proof: trusted Simple SKU `1234567890` / `entity_id=1` baseline category `5`; runtime granular ADD of temporary category `7` produced `[5,7]` + managed ledger; runtime granular DELETE restored exactly `[5]`; zero assignment rows and zero temporary local fixtures remained; independent final GET confirmed baseline.
- Public support: Adobe Products / Export / Live remains false; this closure certifies the P-01 relation capability only.
- Evidence: `docs/connectors/adobe-commerce/magento_v1_category_relation_certification_2026_09_18.json`.
- Reviewer candidate: no further architecture review required unless future scope changes category ownership, Store View semantics, or destructive provenance rules.
### P-02 — `url_key` / URL rewrite side effects

- Surface: store-scoped `url_key` plus Commerce URL rewrite/redirect state.
- Current truth: `url_key` is readable as a Product attribute, but a generic change/restore cycle is not sufficient proof because changing the key can leave URL rewrite history/redirects.
- Why deferred: restore of the attribute alone may not restore rewrite side effects.
- Needed proof: capture route/rewrite state before mutation, controlled key change, verify canonical route/redirects, restore key, then prove rewrite state is intentionally restored or explicitly retained according to V1 policy.
- Reviewer candidate: yes.

### P-03 — Custom-attribute clear semantics — CLOSED 2026-09-18 [Resolved]

- Surface: mapped scalar EAV attributes transitioning from value to empty/null.
- Closure proof: V1 now has a bounded, provider-metadata-driven clear policy rather than a universal payload. Real-target production runtime certified four behavior tuples: on trusted Simple SKU `1234567890` / `entity_id=1`, optional store-scoped `text` uses `""` and requires remote absence (`meta_title`), optional store-scoped `textarea` uses `""` and requires remote absence (`meta_keyword`), and optional global `select` uses `null` and requires remote absence (`country_of_manufacture`); on trusted Simple Joolz SKU / `entity_id=3`, optional store-scoped `date` uses `""` and requires remote absence (`custom_design_from`). Each admitted clear completed as `KnownApplied / stock_write_verified` with exactly one PUT + one reconciliation GET and exact production restore. A validation probe proved that `date + null` can return HTTP 200 while leaving the effective value unchanged, so HTTP acceptance is explicitly not clear proof.
- Runtime guard: provider `frontend_input`, `scope`, and `is_required` are carried from fresh export metadata through semantic projection into a typed clear intent. A stale clear request is admitted only for the four certified tuples above; any missing metadata, required field, unsupported type/scope, website-scoped price/decimal, multiselect, store/website select, global text, texteditor/datetime/boolean/plain-decimal, or other unproved behavior remains `stock_custom_attribute_clear_not_certified` with zero PUT.
- Behavior classification: `AdobeProductAttributeClassifier v2` now persists deterministic `clear_semantics` in the behavior signature: `empty_string_to_absent_verified` for optional store `text/textarea/date`, `null_to_absent_verified` for optional global `select`, `required_not_clearable` for required fields, and `fail_closed_not_certified` otherwise. Field codes never participate in this rule.
- Target boundary audit: no optional generic boolean attribute exists on the certification schema (observed booleans are required), and no suitable generic optional plain-decimal custom attribute exists; `weight` is a top-level Product capability and `tier_price` is a dedicated pricing surface. V1 therefore makes no unsupported clear claim for those classes.
- Postcondition: success is never inferred from HTTP alone or effective-value equality; fresh GET must show the cleared attribute absent while the rest of the controlled Product state matches.
- Historical evidence retained: required global `manufacturer` showed why vendor acceptance is not sufficient policy authority, and website-scoped `c_carseats_adac_rating` demonstrated inherited/effective-value ambiguity. Those behaviors remain intentionally unsupported for clear in V1.
- Public support: Adobe Products / Export / Live remains false; this closure does not flip merchant-visible support.
- Evidence: `docs/connectors/adobe-commerce/magento_v1_custom_attribute_clear_certification_2026_09_18.json`.
- Reviewer candidate: no further review required unless a future scope admits another type/scope tuple or contradicts these real-target postconditions.
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

### P-11 — Existing-family Configurable structure mutation — CLOSED 2026-09-18 [Resolved]

- Surface: existing configurable option correction, trusted desired-child relink, and trusted still-linked inactive-child lifecycle.
- Closure proof: real-target family `524000027bbg` completed all three frozen V1 structure repairs through production-intended runtime. Existing option non-destructive drift was reconciled by one PUT + GET. A validation-only missing-child drift was restored by one identity-verified child POST + GET followed by semantic option re-read/reconciliation; Magento provider option row id churn was observed and is treated as a mutable remote handle, not identity authority. Finally, linked child `524000027bbg-Чорний` / `entity_id=13` completed verified status `1→2→1` through lifecycle disable and the certified active-child restore path, with exact final identity/price/media/link/option baseline.
- Fail-closed/unsupported boundaries: missing option CREATE remains `configurable_option_create_not_certified`; destructive option-value removal is not exposed by the standard existing-option path — remote values absent from the active desired set are preserved with zero write instead of being removed; remote child unlink/removal is not exposed as a production capability; Product CREATE and blind consequential retry remain forbidden.
- Identity/safety: MerchantConfirmed ERL remains authority; parent/child SKU + numeric Magento logical `entity_id` + type are freshly checked around structure writes; inactive lifecycle additionally requires the child to still be linked and fresh media-role-label materialization safety before Product PUT.
- Public support: Adobe Products / Export / Live remains false; this closure certifies the internal V1 runtime and does not flip merchant-visible support.
- Evidence: `docs/connectors/adobe-commerce/magento_v1_configurable_structure_certification_2026_09_18.json`.
- Reviewer candidate: no further architecture review required unless a future scope expands into option CREATE/value removal, remote unlink/removal, or new identity/transaction semantics.
