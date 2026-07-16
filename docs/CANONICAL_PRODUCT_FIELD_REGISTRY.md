# Canonical Product Field Registry v7

Self-contained contract for universal reusable product-data concepts within babypark B2B platform research coverage. Snapshot date: **2026-07-15**.

## Purpose and scope

This registry defines canonical product field semantics, channel mappings, aliases, options, constraints, and applicability contexts. It is a **documentation artifact only** — no seed or runtime code changes are implied by this version.

### Research coverage (included)

- Identity / identifiers
- Basic content
- SEO
- Variants
- Media
- Logistics
- Characteristics
- Relationships (as `product_association_domain`, not text fields)
- B2B-relevant fields
- Compliance semantics (EU legal claims require normative acts)
- Channel mappings for: `google_merchant`, `shopify`, `adobe_commerce`, `bigcommerce`, `amazon`, `rozetka`, `schema_org`

### Explicitly excluded

Campaign, merchant-account, shipping-account, returns, loyalty, destinations, analytics, order-payment domains. Full Amazon schema import, complete category trees, EU compliance engine, connector runtime, and UI "Поля" design are **out of scope for this delivery**.

## File structure

| File | Role |
|---|---|
| `docs/data/canonical_product_fields.csv` | Master field registry |
| `docs/data/canonical_product_field_mappings.csv` | Channel external-field mappings |
| `docs/data/canonical_product_field_aliases.csv` | Import / synonym aliases |
| `docs/data/canonical_product_field_sources.csv` | Evidence sources |
| `docs/data/canonical_product_field_options.csv` | Select-option values |
| `docs/data/canonical_product_field_option_mappings.csv` | Channel option mappings |
| `docs/data/canonical_product_field_constraints.csv` | Validation constraints |
| `docs/data/canonical_product_field_applicability.csv` | Context-specific applicability |

Every file includes **`evidence_subject_key`** with identical column name across all eight files (v7 fix: formerly `subject_key` in sources).

## Evidence standard (typed)

| Claim type | Requirement |
|---|---|
| Universal canonical field | Minimum 2 independent sources |
| Channel mapping | 1 official channel source |
| Internal platform field | Project code + domain contract |
| Legal / compliance | Official normative act |
| Unverified | Research appendix only |

## Stable channel codes

```
channel: google_merchant | shopify | adobe_commerce | bigcommerce |
  amazon | rozetka | schema_org
```

`schema_org` is treated as a mapping source at parity with sales channels for this registry (conscious simplification — do not revisit without explicit decision).

## Enum extensions (v7)

Real project enums extended with `_or_state` values:

```
data_type_or_state: text | long_text | number | decimal | money |
  boolean | date | select | multi_select | image | url | computed |
  not_applicable | undecided

field_group_or_state: basic_information | identifiers | pricing |
  availability | images_media | descriptions | characteristics | b2b |
  seo | logistics | internal | not_applicable | undecided
```

New enum values require a Canonicalization decision entry.

## evidence_subject_key convention

```
field:<internal_code>
mapping:<channel>:<internal_code>:<external_field>:<applicability_id>:<schema_version>
alias:<internal_code>:<locale>:<normalized_alias>:<alias_type>:<scope>
option:<option_id>
option_mapping:<option_mapping_id>
constraint:<constraint_id>
applicability:<applicability_id>
decision:<decision_id>
```

## Cross-column invariants

```
is_localizable=true            ↔ value_localization_strategy=locale_value
is_localizable=false           ↔ value_localization_strategy=not_localizable
is_localizable=not_applicable  ↔ value_localization_strategy=not_applicable
is_localizable=undecided       ↔ value_localization_strategy=requires_research

implementation_kind=connector_only
  → storage_owner=ConnectorMapping, field_definition_eligibility=no,
    scope=not_applicable, data_type_or_state=not_applicable

implementation_kind=pricing_domain
  → field_definition_eligibility=no, inheritance_strategy=domain_owned

implementation_kind=relation
  → field_definition_eligibility=no, data_type_or_state=not_applicable

implementation_kind=product_association_domain
  → data_type_or_state=not_applicable, field_group_or_state=not_applicable

binding_strategy=product_and_variant_two_bindings
  → inheritance_strategy ∈ {product_default_variant_override,
     two_independent_bindings}

mvp_tier=A → default_enabled=true
```

## Semantic FK invariant (v7 fix)

For every child row referencing `applicability_id` in `mappings`, `options`, `constraints`, or (transitively) `option_mappings`:

> **`internal_code` of the child row MUST equal `internal_code` of the referenced applicability row.**

Formal FK presence alone is insufficient. All rows in this v7 snapshot pass this check.

## Date and version formats

```
date_or_state: YYYY-MM-DD | not_applicable | undecided | open_ended
version_or_state: <actual version> | unversioned | undecided | not_applicable
verified_at: always YYYY-MM-DD (never a state token)
locale: BCP 47
country: ISO 3166-1 alpha-2
currency: ISO 4217
```

**No empty cells.** Every dimensional column uses explicit `not_applicable` / `undecided` / `open_ended` / `unversioned` instead of blank values.

## ImportHeaderNormalizer

Documented in `docs/02-ATTRIBUTE_DICTIONARY.md` (service not yet implemented in code). Normalization applied to `normalized_alias`:

1. Trim leading/trailing whitespace; strip punctuation
2. Casefold to lowercase
3. Collapse repeated / non-breaking spaces
4. Unicode NFKC normalization

Example: alias `Item Code` → `normalized_alias` = `item code`.

## Taxonomy boundary

> Taxonomy mappings are a separate registry/domain. Records only whether a field is category-dependent (via `applicability`). Does not import complete taxonomy trees.

Workspace Category tree, Google/Shopify/Amazon/Rozetka taxonomies remain outside this field registry. `category` is a `relation`; `rozetka_category_id` is `connector_only`.

## Product relationships

`accessory`, `compatible_with`, `cross_sell` use:

- `implementation_kind: product_association_domain`
- `storage_owner: ProductAssociation`
- `recommended_action: relation_not_field`

Do **not** model these as multi-select text fields.

## Pricing boundary

`price`, `sale_price`, `cost_price` are `pricing_domain` with `field_definition_eligibility: no`. Values resolve via `PriceResolver` / `PriceListItem` — this registry does not redesign pricing architecture.

## Channel field prohibition

Forbidden as core fields: `google_title`, `shopify_title`, etc. Channel-specific labels exist only in `canonical_product_field_mappings.csv` and `canonical_product_field_option_mappings.csv`.

## MPN — full formulation

Confirmed by Google Merchant Center and schema.org `Product.mpn` as a real manufacturer identifier usable alongside GTIN.

**Current implemented contract (DEC-001 revised, DEC-005):**

- `internal_code: mpn`
- `binding_strategy: product_variant` (fixed — not context-dependent)
- `scope: platform_library`
- `recommended_action: add_to_platform_library` (seeded in `FieldDefinitionSeeder`)
- Stored via dynamic value storage (`FieldDefinition` / `VariantFieldValue`); no global uniqueness constraint on values
- General Google mapping applicability: `a025` (`google:all_products`); general Schema.org mapping applicability: `a026` (`schema_org:all_products`)
- Narrow apparel/DE exception scenario remains in `a001` only — not the applicability for general mappings

## identifier_exists — full formulation

Google: `identifier_exists = false` only when identifiers **truly do not exist** (not assigned by manufacturer) — **not** for empty unfilled fields.

- `implementation_kind: connector_only`
- `field_definition_eligibility: no`
- `recommended_action: connector_mapping_only`
- Do **not** design logic "empty fields → false"
- `manufacturer_identifier_status: unknown | assigned | not_assigned` — research candidate, **not created** in this registry

## hasEnergyConsumptionDetails

schema.org confirms semantic property existence. **Not** proof of EU legal obligation.

- `verification_status: needs_legal_review` for any mandatory-compliance claim
- Only EU official acts can upgrade to `verified` obligation

## Registry governance

1. `source_version` and `verified_at` are mandatory on every source row
2. External schema changes append new source rows — history is not silently overwritten
3. Mappings may carry `effective_from` / `effective_to` via applicability
4. Deprecated fields are never deleted — status becomes `deprecated` + legacy alias
5. Breaking rename → new `internal_code` + `legacy_code` alias on old code
6. Re-verification required before connector implementation
7. Registry is a snapshot as of each row's `verified_at`

## Canonicalization decisions

### DEC-001 — mpn variant binding

**Revised.** Original conclusion ("binding is context-dependent") is superseded by a fixed decision below. The applicability row `a001` (entity_level: product_variant) already matched this outcome — only this DEC's written rationale needed correction.

- **candidate concepts:** product-level MPN, variant-level MPN (fixed), category-dependent binding infrastructure
- **sources compared:** Google Merchant mpn docs ("each variant typically has its own MPN... key exception: different sizes of apparel products, where all sizes often have the same MPN"), schema.org/Product.mpn; existing system convention for sku/gtin (both variant-level)
- **semantic differences:** the apparel-size exception does not require category-dependent binding infrastructure — a shared MPN across variants can simply be entered as the same value on each variant row; no global uniqueness constraint applies
- **canonical code selected:** `mpn`, binding_strategy = `product_variant` (fixed, not context-dependent)
- **why selected:** matches established sku/gtin convention; avoids building category-dependent field binding infrastructure that does not exist in the platform today and has no confirmed demand
- **rejected alternatives:** hard-coded product-level binding (would misrepresent per-unit identifier semantics); duplicate `manufacturer_part_number` code; category-dependent binding infrastructure (deferred, no confirmed demand)
- **mapping/transformation consequence:** Google/schema.org mappings must each reference their own **general, source-specific** applicability row (no category/market/country restriction) — a general Google applicability row for the `google_merchant` mapping (`a025`), and a separate general Schema.org applicability row for the `schema_org` mapping (`a026`) — not the narrow `a001` (apparel/DE), and not each other's channel. `a001` already uses `entity_level: product_variant`, but represents **only** a narrow apparel/DE applicability scenario — it is not evidence of a global `product_variant` contract by itself. The fixed global binding decision is recorded in `canonical_product_fields.csv` and this revised DEC-001; two new, general applicability rows (one per source) are used for general mappings and channel constraints (`a001` remains only for the narrow apparel/DE exception scenario)
- `evidence_subject_key: decision:DEC-001`

### DEC-005 — mpn scope: system → platform_library

- **candidate concepts:** system (core/protected concept required by internal platform operation or foundational identity model — not necessarily "mandatory non-empty value for every product"), platform_library (canonical reusable concept available to workspaces, not required by core platform operation, intended for optional activation when a real per-workspace activation mechanism exists in the future)
- **sources compared:** Google Merchant MPN documentation (manufacturer-assigned, conditional — required only when GTIN absent and manufacturer assigned one); schema.org Product.mpn; existing system fields for comparison — `sku` (required for internal system operation regardless of standard adoption — a product/variant cannot function in this platform without one), `gtin` (extremely common in commerce but still conditional per product)
- **semantic differences:** mpn is not needed for internal system operation — a product/variant can exist and function fully without one; this differs from `sku`, which the system itself depends on
- **canonical code selected:** `mpn` scope = `platform_library`
- **why selected:** mpn is canonical and widely recognized, but its absence never blocks core platform operation, matching the platform_library definition above — not a claim about existing per-workspace activation behavior, which is not yet implemented
- **rejected alternatives:** scope = system (would misrepresent mpn as required for internal platform operation, which it is not)
- **mapping/transformation consequence:** no change to Google/schema.org mapping semantics — only affects internal scope/ownership classification within this registry
- `evidence_subject_key: decision:DEC-005`

### DEC-002 — identifier_exists connector-only

- **candidate concepts:** FieldDefinition boolean, connector transformation flag, inferred-from-empty-fields
- **sources compared:** Google identifier_exists docs, internal Attribute Dictionary channel governance
- **semantic differences:** Google semantics are about genuine absence, not data entry state
- **canonical code selected:** `identifier_exists` as `connector_only`
- **why selected:** prevents polluting FieldDefinition; avoids dangerous empty-field inference
- **rejected alternatives:** system boolean field; auto-false on blank GTIN/MPN
- **mapping/transformation consequence:** mapping `google_merchant:identifier_exists` with transformation `true_only_when_identifiers_genuinely_absent`
- `evidence_subject_key: decision:DEC-002`

### DEC-003 — pricing domain boundary

- **candidate concepts:** FieldDefinition money fields, PriceListItem domain, hybrid
- **sources compared:** docs/02-ATTRIBUTE_DICTIONARY.md seed list, docs/03-DOMAIN_MODEL.md PriceResolver
- **semantic differences:** prices come from 1C sync / price lists, not merchant-editable attribute values
- **canonical code selected:** keep `price`, `sale_price`, `cost_price` as `pricing_domain`
- **why selected:** matches existing architecture; rule "All prices come from 1C"
- **rejected alternatives:** migrating price into FieldDefinition dynamic values
- **mapping/transformation consequence:** Google/Shopify mappings use `transformed` from pricing domain
- `evidence_subject_key: decision:DEC-003`

### DEC-004 — schema_org as mapping channel

- **candidate concepts:** separate semantic registry, inline JSON-LD docs, channel-parity mapping
- **sources compared:** schema.org Product spec, internal connector mapping patterns
- **semantic differences:** schema.org is vocabulary not a sales channel
- **canonical code selected:** include `schema_org` in stable channel list for mapping rows
- **why selected:** task-specified conscious simplification for unified mapping CSV structure
- **rejected alternatives:** separate schema_org file; exclusion from mappings
- **mapping/transformation consequence:** all schema.org external fields live in mappings.csv with `channel: schema_org`
- `evidence_subject_key: decision:DEC-004`

## Validated examples (self-checking)

These examples satisfy all v7 invariants including semantic FK and non-empty cells.

### Applicability

```csv
a001,mpn,category,google:apparel:DE,google_merchant,DE,DE,apparel,google_product_taxonomy,undecided,product_variant,child,advertise,conditionally_required,undecided,open_ended,undecided,partially_verified,applicability:a001
a002,product_name,channel,google:all_products,google_merchant,not_applicable,not_applicable,not_applicable,not_applicable,not_applicable,product,not_applicable,advertise,required,undecided,open_ended,unversioned,verified,applicability:a002
a003,product_name,global,core:product_name,not_applicable,not_applicable,not_applicable,not_applicable,not_applicable,not_applicable,product,not_applicable,not_applicable,not_applicable,undecided,open_ended,not_applicable,partially_verified,applicability:a003
a025,mpn,channel,google:all_products,google_merchant,not_applicable,not_applicable,not_applicable,not_applicable,not_applicable,product_variant,not_applicable,advertise,conditionally_required,undecided,open_ended,unversioned,verified,applicability:a025
a026,mpn,channel,schema_org:all_products,schema_org,not_applicable,not_applicable,not_applicable,not_applicable,not_applicable,product_variant,not_applicable,advertise,recommended,undecided,open_ended,unversioned,verified,applicability:a026
```

### Constraints

```csv
c001,product_name,core,max_length,undecided,not_applicable,a003,partially_verified,constraint:c001
c002,product_name,channel,max_length,150,characters,a002,verified,constraint:c002
c007,mpn,channel,max_length,70,characters,a025,verified,constraint:c007
```

Note: `c001` references `a003` (product_name global) — not `a001` (mpn). v7 fix for prior semantic FK error.

### Alias evidence key (duplicate text, different alias_type)

Two aliases with same normalized text but different `alias_type` produce distinct keys:

```
alias:product_name:en:name:import_header:global
alias:product_name:en:name:common_business_term:global   ← hypothetical; not in seed
```

Current seed demonstrates `alias_type` disambiguation for `price` / `РРЦ`:

```
alias:price:ru:ррц:common_business_term:global
alias:price:ru:цена:import_header:global
```

## Current seed → Proposed registry diff

Based on `database/seeders/FieldDefinitionSeeder.php` (develop@3c3f926) and `docs/02-ATTRIBUTE_DICTIONARY.md`.

### Already correct

| internal_code | Notes |
|---|---|
| `gtin` | Seeded; maps to `product_variants.barcode_ean`; Google + schema.org verified |
| `brand` | Seeded; Google conditionally required |
| `sku` | Seeded at variant level |
| `product_name` | Seeded; only strict required field for product creation |
| `description` | Seeded |
| `category` | Correctly modeled as `relation`, not flat text |
| `color`, `size` | Platform library seeded with option codes |
| `weight_netto`, `weight_brutto`, `volume_m3` | System logistics fields seeded |
| `shipping_required`, `backorder_policy` | Platform library seeded |
| `technical_characteristics`, `instructions` | Localizable platform library seeded |

### Missing confirmed candidates

| internal_code | recommended_action | Blocker |
|---|---|---|
| `mpn` | `add_to_platform_library` | Seeded (DEC-001 binding + DEC-005 scope) |
| `condition` | `add_to_platform_library` | Not in seeder; Google required for ads |
| `price`, `sale_price`, `cost_price` | `covered_by_existing_domain` | Documented in Attribute Dictionary but not in FieldDefinitionSeeder |
| `availability` | `covered_by_existing_domain` | Inventory domain; not FieldDefinition |
| `image` | `covered_by_existing_domain` | Media domain |
| `short_description` | `add_to_platform_library` | Documented as localizable; not seeded |
| `material`, `age_group`, `gender`, `country_of_origin`, `manufacturer`, `model`, `compatibility`, `battery_type` | `add_to_platform_library` | Attribute Dictionary seed list; not in FieldDefinitionSeeder |

### Incorrectly modeled

| Issue | Current state | Registry correction |
|---|---|---|
| `product_name.is_localizable` | Seeded `false`; docs say localizable for product-level content | Registry marks `false` matching **current seeder**; docs/02 conflict flagged for future DEC |
| `status` data_type | Seeded as `boolean` mapping `is_active` | Registry keeps `boolean`; enum lifecycle (draft/active/archived) deferred |
| Legacy `products.sku` column | DB has product-level SKU; seeder binds SKU to variant | Registry follows seeder (variant); legacy column noted as migration debt |

### Connector-only (not FieldDefinition)

| internal_code | Channel |
|---|---|
| `identifier_exists` | google_merchant |
| `rozetka_category_id` | rozetka |

### Unverified (research appendix only)

| Topic | Status |
|---|---|
| Rozetka public API schema | No official spec found; `partially_verified` |
| Amazon product type JSON schemas | Not imported (per scope limit) |
| EU energy label mandatory fields | `needs_legal_review` |
| `manufacturer_identifier_status` enum | Not created — needs research |
| Adobe Commerce / BigCommerce field mappings | Channels listed; mappings deferred to future pass |

## What this delivery does NOT do

- Change migrations (MPN seeded via existing dynamic value storage per DEC-2)
- Implement `ImportHeaderNormalizer` service
- Build connector runtime or EU compliance engine
- Import full Amazon / Rozetka category trees
- Implement `ProductAssociation` storage

## Source index (summary)

Full evidence in `canonical_product_field_sources.csv`. Key external sources:

| Source | URL |
|---|---|
| Google Merchant product data | https://support.google.com/merchants/answer/7052112 |
| Google title attribute | https://support.google.com/merchants/answer/6324415 |
| Google mpn attribute | https://support.google.com/merchants/answer/6324482 |
| Google identifier_exists | https://support.google.com/merchants/answer/6324478 |
| schema.org Product | https://schema.org/Product |
| GS1 GTIN | https://www.gs1.org/standards/id-keys/gtin |
| Shopify Product API | https://shopify.dev/docs/api/admin-rest/latest/resources/product |

Internal sources: `docs/02-ATTRIBUTE_DICTIONARY.md`, `docs/03-DOMAIN_MODEL.md`, `database/seeders/FieldDefinitionSeeder.php`.

---

**Version:** v7 final · **verified_at snapshot:** 2026-07-15 · **Awaiting explicit merge approval.**
