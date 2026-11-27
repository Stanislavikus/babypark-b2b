# Amazon Provider Audit — Final Lead Arbitration — 2026-09-09

Status: **FINAL ARBITRATION / CORRECTION CONTRACT FROZEN**

Authoritative review base: `develop @ b4458f2293f16b15894f6edbaa3653f777a53685`.

This file supersedes provisional conclusions in `AMAZON_PROVIDER_AUDIT_ARBITRATION_2026-09-09.md` wherever they differ, especially the earlier provisional decision to keep Amazon weight mappings verified.

## Independent reviews

- GPT-5.4: `AMAZON COVERAGE/CANONICALIZATION HAS MATERIAL GAPS`.
- Sonnet High: `AMAZON SEMANTIC CORRECTIONS REQUIRED`.
- Lead independently rechecked both reports against the frozen repository contracts, actual mapping suggestion runtime, and current first-party Amazon SP-API/PTD documentation.

## Final accepted corrections

### A1 — PTD context is evidence authority, but current suggestion runtime ignores it

**ACCEPT — MATERIAL / BLOCKER BEFORE AMAZON ACTIVATION; not a current operational incident.**

All 20 Amazon mapping rows are evidenced by one US `LUGGAGE` PTD context (`ATVPDKIKX0DER`, `LISTING`, product type `LUGGAGE`, specific PTD version). `CanonicalFieldMappingSuggestionProvider` currently filters only by channel, verified mapping status, active/verified canonical field/binding gates, and exact external key presence. It does not consume mapping applicability, marketplace, product type, requirements, parentage, or PTD version.

The current connector foundation does not seed an `amazon` connector definition or Amazon schema source, so none of these mappings is merchant-operational through the standard connector path today. Before Amazon activation, automatic suggestions must fail closed unless the runtime has authoritative context sufficient to prove the mapping applicability.

Preferred correction: generic fail-closed behavior for mappings whose applicability context is more specific than `global` or `channel` until context-aware snapshot authority exists. Do not hard-code LUGGAGE mappings as globally applicable.

### A2 — `condition_type` owner candidate

**ACCEPT — MATERIAL PROVIDER SEMANTIC CORRECTION.**

Canonical `condition` is `product_variant`-bound, Amazon applicability `a097` already records `entity_level=product_variant`, and Amazon puts `condition_type` in the offer/sales-terms surface. Current Amazon concept ownership `ProductData` is internally inconsistent.

Minimal correction: keep the reusable `product_condition_enum` semantic but change owner candidate to `ProductVariantData`; keep `condition_note` as separate listing-condition context. Update the paired AmazonCoverage invariant/generated artifacts.

### A3 — DEC-009: `item_weight` and `item_package_weight`

**ACCEPT SONNET CHALLENGE — DOWNGRADE BOTH TO `partially_verified`.**

DEC-009 requires exact packaging boundaries: `net_weight` excludes packaging; `gross_weight` includes immediate consumer packaging but excludes extra transport/shipping packaging. Amazon primary evidence clearly distinguishes item and item package, and other Amazon reports even expose without-package/with-package measurements, but the reviewed PTD evidence does not explicitly prove that PTD `item_weight` is exactly the former and PTD `item_package_weight` is exactly the latter.

Property-group names alone are not proof either. Under the frozen project rule, semantic packaging boundaries must not be inferred from field names or schema neighborhoods.

Minimal correction:
- `net_weight -> item_weight`: `verified` -> `partially_verified`;
- `gross_weight -> item_package_weight`: `verified` -> `partially_verified`;
- extend/refine the Amazon item/package disagreement/evidence so both related measurements remain explicit until runtime/PTD evidence proves DEC-009 equivalence.

This final decision supersedes the earlier provisional ledger statement that kept both mappings verified.

### A4 — `package_quantity -> number_of_items`

**ACCEPT — MATERIAL SEMANTIC OVERCLAIM.**

Canonical `package_quantity` means units per consumer package. Amazon `number_of_items` describes the number of containers at the lowest branded-packaging level and is not generally equal to the count of product units contained inside those containers. Current cross-platform synthesis already treats Amazon `number_of_items` as separate evidence, so the verified direct mapping contradicts repository synthesis.

Minimal correction: downgrade/remove operational verification of the direct equivalence while retaining `number_of_items` as related PTD-scoped packaging/count evidence.

### A5 — `purchasable_offer` representation

**ACCEPT — PROVIDER REPRESENTATION HARDENING; no scalar canonical mapping.**

`purchasable_offer` is a structured multi-facet offer/pricing envelope carrying audience/currency/marketplace context and pricing sub-structures such as regular price, discounted schedules, seller min/max bounds, and quantity/B2B pricing. Current Pricing ownership and deferred/unmapped status are correct, but the representation/disagreement note should explicitly record this structure so it can never be resolved as a scalar `price` rename.

## Final mappings intentionally kept

- `recommended_retail_price -> list_price`: **KEEP VERIFIED**. Amazon first-party documentation defines List Price as manufacturer/supplier/seller suggested retail price; keep `purchasable_offer` as the unresolved transactional pricing structure.
- `product_highlights -> bullet_point`: **KEEP VERIFIED**. Bullet points are a direct highlights representation; keep `special_feature` separate and record Amazon slot/length constraints in the transform/evidence contract.
- `age_group -> age_range_description`: **KEEP field-level mapping**; no Amazon option mappings until value vocabularies are exhaustively evidenced.
- `gender -> target_gender`: **KEEP field-level mapping**; no option mapping added.
- `condition -> condition_type`: **KEEP field-level mapping**, with corrected ProductVariant-level owner and PTD-context gating.

## Preserved fail-closed boundaries

No correction is required for:
- merchant-suggested ASIN vs established external identity;
- identifier-exemption governance;
- Amazon taxonomy vs merchant Category authority;
- product media vs offer media;
- variation parentage mechanics;
- compliance portability;
- zero Amazon option mappings at this stage.

## Final routing

Amazon **REQUIRES ONE CORRECTION PASS** and is **NOT READY TO FREEZE** yet.

No Gemini/Opus arbitration is required. The only substantive model dispute was the DEC-009 weight boundary; primary Amazon evidence does not reach the project's strict equivalence bar, so fail-closed downgrade is the deterministic project-consistent outcome.

Next implementation step: create a clean correction branch from exact `b4458f2293f16b15894f6edbaa3653f777a53685`, implement A1-A5 in generator/registry/synthesis/tests, regenerate deterministic Amazon artifacts, run targeted Amazon regressions + full CanonicalCoverage + mapping-suggestion suites + Pint/diff-check, then open PR and run authoritative MySQL CI.