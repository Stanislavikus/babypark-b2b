# Google Merchant Provider Audit Arbitration — 2026-09-08

Status: GPT-5.4 PASS REVIEWED — SONNET OVERLAY PENDING

Authoritative review base: `develop @ 47906e741afa0e19ca3215869b14698632fa7cb2`.

This ledger preserves the independent Google Merchant provider review campaign after Adobe Commerce provider review was frozen. It is review evidence, not runtime authority. No canonical correction is accepted merely because a reviewer proposed it.

## Frozen Google input at review start

- ProductAttributes inventory: 145 rows.
- ProductInput inventory: 11 rows.
- Physical provider coverage: 156 rows.
- Provider concepts: 156.
- OPEN disagreement families: 12.
- Google canonical field mappings: 39 total = 13 `unversioned` + 26 `products_v1-2026-09-07`.
- Google canonical option mappings: 11, all `unversioned`.

## GPT-5.4 independent audit

GPT-5.4 reported `GOOGLE VERSIONED COVERAGE/CANONICALIZATION HAS MATERIAL GAPS` after claiming 156/156 physical rows, 156/156 concepts, 39/39 field mappings, 11/11 option mappings and all 13 old/current overlap families reviewed.

### G1 — `sku -> ProductInput.offerId`

Lead verdict: **ACCEPT — MATERIAL**.

Repository evidence:
- current ProductInput physical key is exactly `offerId`;
- registry current mapping stores external field `ProductInput.offerId`;
- `CanonicalFieldMappingSuggestionProvider` requires literal membership of mapping `external_field` in authoritative snapshot external keys;
- the suggestion provider does not interpret dotted object paths.

Therefore the current Products v1 mapping cannot prefill against a snapshot whose ProductInput field key is `offerId`. ProductInput ownership/context belongs in applicability/evidence, not inside the literal external key.

Minimal correction candidate: current Products v1 row should target literal `offerId`; preserve ProductInput surface/context separately. Legacy `id` remains a distinct older surface and is not automatically deleted.

### G2 — `identifier_exists` polarity

Lead verdict: **ACCEPT — MATERIAL, WITH CORRECTION TO GPT ROUTING**.

Current Google semantics are positive-polarity `identifier_exists`: false/no only when appropriate unique product identifiers genuinely do not exist; default is true. Repository evidence is internally contradictory:
- current Products v1 mapping `identifierExists` uses `false_only_when_identifiers_genuinely_absent` and matches provider semantics;
- old `identifier_exists` mapping uses `true_only_when_identifiers_genuinely_absent`;
- canonical field description says `flag when manufacturer identifiers truly do not exist`, which describes an exemption/absence flag despite the code/name `identifier_exists`;
- DEC-002 text correctly says Google `identifier_exists = false` for genuine absence but its mapping consequence incorrectly records `true_only_when_identifiers_genuinely_absent`.

GPT was right about the semantic contradiction but too strong in calling the whole unversioned row stale. The snake_case `identifier_exists` surface remains valid in the Google product data specification/feed vocabulary. Correct the polarity/transformation and documentation; do not delete the surface merely because Merchant API uses camelCase.

### G3 — canonical `url` -> Google `link`

Lead verdict: **REJECT MAJOR FOR NOW; ACCEPT LEDGER CLARIFICATION CANDIDATE**.

Google current `link` is the URL directly linking to the item's page on the merchant online store. Canonical `url` is the primary absolute customer-facing product page URL. The registry mapping is `transformed` (`absolute_product_landing_page_url`), not `direct`, and is specific to primary `link`.

The cross-platform synthesis correctly keeps the broader Google URL family (`link`, `canonicalLink`, `mobileLink`) context-dependent. That does not by itself disprove the narrower primary `url -> link` mapping. The OPEN `google_landing_url` disagreement should be reviewed/narrowed so it does not imply that the already-proven primary `link` relation and the unresolved sibling URL roles are the same question.

Do not downgrade `url -> link` solely because `canonicalLink` and `mobileLink` also exist. Revisit only if Sonnet or stronger provider evidence shows primary `link` can semantically diverge from the canonical primary customer-facing URL in a way the current transformation cannot express.

### G4 — `productWeight` / `shippingWeight`

Lead verdict: **ACCEPT — MATERIAL**.

Canonical DEC-009 defines:
- `net_weight` = product mass excluding packaging;
- `gross_weight` = sellable unit including immediate consumer packaging but excluding additional transport/shipping packaging;
- channel `weight` / `shipping_weight` must not be equated until target packaging level and unit semantics are verified.

Current Google mappings nevertheless mark:
- `net_weight -> productWeight` verified;
- `gross_weight -> shippingWeight` verified.

Google evidence only says `productWeight` is actual assembled product weight and `shippingWeight` is the weight used to calculate shipping cost. It does not establish DEC-009's strict exclusion/inclusion packaging boundaries. The verified mappings overstate evidence.

Minimal correction candidate: remove/downgrade verified semantic equivalence and preserve both Google provider meanings as related weight representations pending explicit transform/packaging-level policy.

## Neighbor-pattern finding — version metadata is not used by mapping suggestion runtime

`CanonicalFieldMappingSuggestionProvider::verifiedMappingsForChannel()` filters only by channel + `verification_status=verified`. It does not select by `channel_schema_version` or snapshot schema version. Exact external-key membership prevents most renamed legacy rows from matching current Products v1 snapshots, and duplicate same-binding/same-key candidates are safely de-duplicated by collision resolution. Still, version metadata currently provides documentation/provenance, not runtime isolation.

This is not yet accepted as a standalone runtime defect: same-key legacy/current overlap families reviewed so far preserve compatible field correspondence. It is a correction-pass regression concern. Any Google mapping whose semantics changed while its external key stayed the same could bypass intended version separation.

## Current Lead routing before Sonnet overlay

Accepted material correction candidates: G1, G2, G4.
Rejected as material at this stage: G3 mapping downgrade.
No arbitration model is needed yet; Sonnet independent semantic/ownership result should be overlaid first. If Sonnet materially challenges G3 or reveals a same-key/version semantic conflict, route only that narrow dispute to Gemini 3.1 / Opus / GPT arbitration.
