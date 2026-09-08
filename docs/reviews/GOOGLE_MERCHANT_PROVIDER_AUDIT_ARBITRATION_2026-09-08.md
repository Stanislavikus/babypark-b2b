# Google Merchant Provider Audit Arbitration — 2026-09-08

Status: LEAD ARBITRATION COMPLETE — GOOGLE CORRECTION PASS REQUIRED

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

## Sonnet High semantic / ownership overlay

Sonnet High reported `GOOGLE SEMANTIC CORRECTIONS REQUIRED` after independently reviewing the provider shard, generator, tests and current Google Merchant documentation. Its six findings were treated as challenges, not accepted automatically.

### S1 — `structuredTitle` / `structuredDescription`

Lead verdict: **ACCEPT — MATERIAL, WITH REFRAMED CORRECTION**.

Current coverage incorrectly gives both fields the same generic `REUSABLE_SEMANTIC / ProductData / processed_output_with_conditional_input_binding` treatment as plain `title` and `description`, with `PROVIDER_VERIFIED` status.

Google evidence shows these are structured alternatives to normal title/description that carry content plus digital-source provenance (`content` + `digital_source_type`). They are especially required for generative-AI content, but are not merely a Google-generated-output artefact: structured title can also represent non-AI content.

Therefore do not collapse them into ordinary scalar title/description and do not classify them as an unrelated connector control. Minimal correction: move them to an explicit deferred structured-content/provenance representation and add a dedicated disagreement about how their content and provenance relate to canonical title/description.

### S2 — `adult`

Lead verdict: **REJECT MATERIAL OWNER CHANGE; OPTIONAL MINOR REPRESENTATION REFINEMENT**.

Google defines `adult` as an adult-oriented-content / policy restriction flag for products containing adult content or intended to enhance sexual activity. This is not the same semantic as demographic `ageGroup` or `gender`.

Current `Compliance` ownership is defensible and the row is already `DEFERRED_REVIEW` inside `google_compliance`; it is not silently frozen. If touched during correction, representation may be narrowed from generic `publication_compliance_evidence` to an explicit content-policy restriction, but moving it to ProductData/audience is not supported.
### S3 — `googleProductCategory` vs `productTypes`

Lead verdict: **ACCEPT — MATERIAL REPRESENTATION/VOCABULARY SPLIT**.

The two physical rows already have separate provider concepts, so this is not literally a concept merge. The defect is that both are given the same `google_taxonomy_context` representation and broad vocabulary metadata.

Google evidence is explicit: `googleProductCategory` uses Google's predefined taxonomy, while `productTypes` carries the merchant's own categorization system and may be repeated. Preserve both as Connector-owned/deferred, but distinguish `google_controlled_taxonomy` from `merchant_defined_category_path_text` (names illustrative) so future Category mapping can use merchant-authored signal without treating it as Google taxonomy authority.

### S4 — `numberOfUnits`

Lead verdict: **ACCEPT — MINOR / APPLICABILITY CONTEXT HARDENING**.

Source semantics say "number of units available for a specific floor plan". That is availability-like quantity within the property vertical, not merely a static descriptive characteristic.

The row is already PropertyVertical + `DEFERRED_REVIEW`, so no owner or canonical mapping change is justified yet. Preserve PropertyVertical ownership but annotate the availability/quantity nature in source context / vertical disagreement so later arbitration does not treat it like bedrooms, propertyType or amenities.

### S5 — Google `gender` vs canonical `other`

Lead verdict: **REJECT**.

The repository already models the intended asymmetry explicitly. Canonical gender options were deliberately sourced from Shopify taxonomy; Google has separate official option-mapping evidence for `female`, `male`, `unisex`; the source row for canonical `other` explicitly says `No Google equivalent; Shopify-only canonical value.` There is no Google mapping for `other`.

No provenance correction is required. A regression asserting that `other` remains unmapped for Google is useful hardening, but not a semantic correction.
### S6 — `vocabulary_kind` constant for all Google concepts

Lead verdict: **REJECT MATERIAL; ACCEPT MINOR METADATA-HARDENING CANDIDATE**.

`buildConcepts()` does assign `provider_controlled_or_typed` to every Google concept. However, no runtime mapper or option validator consumes this column; exact controlled vocabularies are represented by `canonical_product_field_options.csv` plus channel option mappings. Other provider shards also use broad provider-level vocabulary labels.

Therefore this is not evidence of a current automatic-mapping defect. During the correction pass we may make Google vocabulary metadata more informative for proven enum/taxonomy cases, or explicitly document that `vocabulary_kind` is not option authority. Do not redesign all 156 concepts solely from this finding.

## Sonnet validator caveat — hardcoded returned metrics

Lead verdict: **NOT A FREEZE BLOCKER**.

Some values returned by `GoogleMerchantCoverage::validate()` are literal zero/PASS metrics, but the corresponding invariants are actually enforced elsewhere in the validator: concept authority, conditional write semantics, disagreement integrity and applicability are checked before return. Manifest provenance is recomputed for Google source rows.

In addition, the shared `CanonicalCoverageIntegrityHardeningTest` now mutates immutable manifest provenance for every provider and verifies that every manifest row reproduces exact source bytes from its recorded commit via `git show`. The literal result labels should not be mistaken for the only validation mechanism.

## Cross-review convergence

GPT and Sonnet were complementary rather than duplicative. GPT found versioned-registry/runtime exact-key and semantic-strength defects; Sonnet found provider-local representation/ownership quality issues. Sonnet did not independently challenge the primary `url -> link` mapping, and its provider-level `offerId` classification is compatible with GPT's separate finding that the registry literal key `ProductInput.offerId` is wrong for exact-key suggestion.
## Combined Lead correction contract after GPT + Sonnet

Accepted material corrections:
1. Fix current Products v1 SKU mapping literal key from `ProductInput.offerId` to `offerId`; keep ProductInput surface in applicability/evidence.
2. Fix `identifier_exists` polarity across the legacy transformation, canonical description and DEC-002 consequence; retain snake_case as a valid legacy/data-spec surface.
3. Downgrade/defer `net_weight -> productWeight` and `gross_weight -> shippingWeight` until packaging-level semantics satisfy DEC-009.
4. Reclassify `structuredTitle` / `structuredDescription` from generic verified ProductData into an explicit deferred structured-content + digital-source-provenance representation, with a dedicated disagreement.
5. Split Google taxonomy representation metadata: Google-controlled `googleProductCategory` vs merchant-defined `productTypes`; keep both Connector-owned/deferred.

Accepted minor hardening:
6. Annotate `numberOfUnits` as property-vertical availability/quantity semantics without promoting it to generic Availability.
7. Add regression that canonical `gender=other` has no Google option mapping; do not change existing provenance.
8. Consider narrowing broad `vocabulary_kind` metadata only where evidence proves an enum/taxonomy; do not make it runtime option authority.
9. Narrow `google_landing_url` wording so unresolved `canonicalLink/mobileLink` roles do not imply that the primary transformed `url -> link` mapping is unproven.
10. Add regression coverage for legacy/current Google mapping overlap because runtime suggestions currently do not filter by `channel_schema_version`; exact-key matching remains the fail-closed boundary.

Rejected material corrections:
- do not move `adult` from Compliance to demographic ProductData;
- do not downgrade verified primary `url -> link` on current evidence;
- do not rewrite canonical gender option provenance from Shopify to Google;
- do not treat broad `vocabulary_kind` alone as a runtime mapping defect.

Status: **LEAD ARBITRATION COMPLETE — GOOGLE CORRECTION PASS REQUIRED**.

No external arbitration is required before correction. If implementation exposes a genuine ambiguity in structured-content ownership, URL role semantics, or same-key cross-version behavior, route only that narrow question to Gemini 3.1 / Opus / GPT-5.4.