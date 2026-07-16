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

## Machine validation contract

For each of the eight CSV files, this section defines the exact header
(order matters), primary/unique key, foreign keys, allowed enum/state
values, `evidence_subject_key` format, and file-specific invariants. The
validator and the CSV files must conform to this contract; changing it
requires updating this section, the validator and its tests in the same PR.

Enum lists marked "declared" come from other sections of this document.
Enum lists marked "observed (extend via DEC)" reflect the current snapshot
and may grow via a new Canonicalization decision without being a contract
violation. The validator performs enum/state membership checks **only** for
columns whose full allowed set is declared in this section (see
"Enum-validation scope for this PR" at the end). Any other column is still
checked for header presence, emptiness/state-token rules, keys and
FK/semantic-FK integrity, but not enum membership.

### 1. canonical_product_fields.csv
- Header (exact order): `internal_code,canonical_english_name,uk_label,ru_label,description,implementation_kind,storage_owner,field_definition_eligibility,binding_strategy,scope,field_group_or_state,data_type_or_state,value_shape,structure_schema_ref,is_localizable,value_localization_strategy,channel_value_strategy,inheritance_strategy,is_multi_value,unit_family,status,mvp_tier,default_enabled,verification_status,recommended_action,supports_admin_display,supports_b2b_display,supports_search,supports_filter,supports_table_column,evidence_subject_key`
- Primary/unique key: `internal_code`
- `evidence_subject_key` format: `field:<internal_code>` (must equal own `internal_code`)
- `status` (declared, Registry governance §4): `active | proposed | deprecated`
- `data_type_or_state` (declared, Enum extensions v7): `text | long_text | number | decimal | money | boolean | date | select | multi_select | image | url | computed | not_applicable | undecided`
- `field_group_or_state` (declared, Enum extensions v7): `basic_information | identifiers | pricing | availability | images_media | descriptions | characteristics | b2b | seo | logistics | internal | not_applicable | undecided`
- `binding_strategy` (observed, extend via DEC): `product | product_variant | product_and_variant_two_bindings | not_applicable`
- `scope` (observed, extend via DEC): `system | platform_library | not_applicable`
- `mvp_tier` (observed, extend via DEC): `A | B | C | not_applicable`; invariant: `mvp_tier=A → default_enabled=true`
- `implementation_kind` (observed, extend via DEC): `compliance_entity | computed_projection | connector_only | core_model_property | dynamic_field | inventory_domain | media_domain | pricing_domain | product_association_domain | relation`
- `storage_owner` (observed, extend via DEC): `Category | ConnectorMapping | FieldDefinition | MediaAsset | PriceListItem | Product | ProductAssociation | ProductVariant | calculated | not_implemented`
- `field_definition_eligibility` (observed): `yes | no`
- `verification_status` (observed, extend via DEC): `verified | partially_verified | needs_legal_review`
- `recommended_action` (observed, extend via DEC): `add_to_platform_library | computed_not_editable | connector_mapping_only | covered_by_existing_domain | keep_as_is | needs_legal_review | relation_not_field`
- `is_localizable` / `value_localization_strategy` cross-column invariant as defined in "Cross-column invariants" above.
- **Field-specific invariant:** `has_energy_consumption_details.data_type_or_state` MUST be `not_applicable`; its structured shape is represented only by `value_shape: structured_object`. (`data_type_or_state` and `value_shape` are distinct columns — structural shape belongs to `value_shape`, never to `data_type_or_state`.)

### 2. canonical_product_field_mappings.csv
- Header: `internal_code,channel,external_field,mapping_type,transformation,applicability_id,requirement_level,channel_schema_version,verification_status,evidence_subject_key`
- Unique key: `(internal_code, channel, external_field, channel_schema_version, applicability_id)` — the narrower 3-column combination may legitimately repeat across schema versions or applicability contexts.
- FK: `internal_code` → fields.csv; `applicability_id` → applicability.csv
- Semantic FK: `internal_code` of this row MUST equal `internal_code` of the referenced `applicability_id` row.
- `channel` (declared, "Stable channel codes"): `google_merchant | shopify | adobe_commerce | bigcommerce | amazon | rozetka | schema_org`
- `mapping_type` (observed, extend via DEC): `direct | renamed | transformed | connector_only`
- `requirement_level` (observed, extend via DEC): `required | conditionally_required | recommended | optional | undecided`
- `evidence_subject_key` format: `mapping:<channel>:<internal_code>:<external_field>:<applicability_id>:<channel_schema_version>`

### 3. canonical_product_field_aliases.csv
- Header: `internal_code,alias,normalized_alias,locale,alias_type,scope,verification_status,evidence_subject_key`
- Unique key: `(internal_code, normalized_alias, locale, alias_type, scope)` — confirmed necessary: the current snapshot has two legitimate rows for `gtin/en/gtin` differing only by `alias_type` (`import_header` vs `legacy_code`); a narrower key would falsely flag this as a duplicate.
- FK: `internal_code` → fields.csv
- `alias_type` (observed, extend via DEC): `common_business_term | import_header | legacy_code | localized_synonym`
- `evidence_subject_key` format: `alias:<internal_code>:<locale>:<normalized_alias>:<alias_type>:<scope>`

### 4. canonical_product_field_sources.csv
- Header: `source_id,subject_type,evidence_subject_key,source_kind,source_organization,source_title,source_url_or_state,source_ref_or_state,source_version,verified_at,evidence_locator,evidence_note`
- Primary key: `source_id` (unique)
- **`evidence_subject_key` is explicitly NOT unique** — multiple independent sources may confirm the same subject.
- Invariant: `subject_type` MUST equal the prefix of `evidence_subject_key` before the first `:` (hard error on mismatch).
- **Reverse referential integrity (hard error):** every `evidence_subject_key` in this file MUST reference an existing subject:
  - `field:*` → `internal_code` in fields.csv
  - `mapping:*` → a row in mappings.csv whose composite key matches
  - `alias:*` → a row in aliases.csv whose composite key matches
  - `option:*` → `option_id` in options.csv
  - `option_mapping:*` → `option_mapping_id` in option_mappings.csv
  - `constraint:*` → `constraint_id` in constraints.csv
  - `applicability:*` → `applicability_id` in applicability.csv
  - `decision:DEC-NNN` → a matching `### DEC-NNN` heading in `CANONICAL_PRODUCT_FIELD_REGISTRY.md`
  An `evidence_subject_key` with a correct prefix but no matching subject (e.g. `field:brnad`, `option:o999`) is a hard error, not merely a missing-source warning.
- `subject_type` (declared, evidence_subject_key convention): `field | mapping | alias | option | option_mapping | constraint | applicability | decision`
- `source_kind` (observed, extend via DEC): `official_web_doc | api_schema | repository_code | repository_document`
- `verified_at`: must be `YYYY-MM-DD`, never a state token.

### 5. canonical_product_field_options.csv
- Header: `option_id,internal_code,option_code,en_label,uk_label,ru_label,sort_order,option_scope,applicability_id,option_source_strategy,value_domain_ref,verification_status,status,evidence_subject_key`
- Primary unique key: `option_id`
- Secondary unique key: `(internal_code, option_code, option_scope, applicability_id)` — not just `(internal_code, option_code)`; `option_scope`/`applicability_id` distinguish a universal value from a channel/category-specific one.
- FK: `internal_code` → fields.csv; `applicability_id` → applicability.csv
- Semantic FK: `internal_code` of this row MUST equal `internal_code` of referenced `applicability_id` row.
- `option_scope` (observed, extend via DEC): `universal | platform_library`
- `option_source_strategy` (observed, extend via DEC): `external_standard | static_registry`
- `evidence_subject_key` format: `option:<option_id>`

### 6. canonical_product_field_option_mappings.csv
- Header: `option_mapping_id,option_id,channel,external_option_value,mapping_type,applicability_id,channel_schema_version,verification_status,evidence_subject_key`
- Unique key: `option_mapping_id`
- FK: `option_id` → options.csv; `channel` → declared channel list; `applicability_id` → applicability.csv
- Semantic FK (hard error): `options[option_id].internal_code` MUST equal `applicability[applicability_id].internal_code`.
- `mapping_type` (observed, extend via DEC): `direct | renamed`
- `evidence_subject_key` format: `option_mapping:<option_mapping_id>`

### 7. canonical_product_field_constraints.csv
- Header: `constraint_id,internal_code,constraint_context,constraint_type,constraint_value_or_state,unit_or_state,applicability_id,verification_status,evidence_subject_key`
- Unique key: `constraint_id`
- FK: `internal_code` → fields.csv; `applicability_id` → applicability.csv
- Semantic FK: same rule as above.
- `constraint_context` (observed, extend via DEC): `core | channel`
- `constraint_type` (observed, extend via DEC): `max_length | min_value | negative_allowed | regex | unique_value`
- `evidence_subject_key` format: `constraint:<constraint_id>`

### 8. canonical_product_field_applicability.csv
- Header: `applicability_id,internal_code,context_type,context_key,channel_or_state,market_or_state,country_or_state,product_type_or_state,category_taxonomy_or_state,category_code_or_state,entity_level,parentage_level,operation,requirement_level,effective_from,effective_to,schema_version,verification_status,evidence_subject_key`
- Unique key: `applicability_id`
- FK: `internal_code` → fields.csv
- `context_type` (observed, extend via DEC): `global | channel | category`
- `entity_level` (observed, extend via DEC): `product | product_variant`
- `parentage_level` (observed, extend via DEC): `not_applicable | child`
- `operation` (observed, extend via DEC): `not_applicable | advertise | publish`
- `requirement_level` (observed, extend via DEC): `required | conditionally_required | recommended | undecided | not_applicable`
- `evidence_subject_key` format: `applicability:<applicability_id>`

### Enum-validation scope for this PR
The validator performs enum/state membership checks only for columns whose
full allowed set is declared above. Adding enum validation for a column not
listed above requires extending this contract in the same PR.

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

### DEC-006 — age_group canonical value model

The Google Merchant five-value vocabulary (newborn, infant, toddler, kids,
adult) is selected as the current platform normalization profile because it
is compact, operationally required for the prioritized Google Merchant
output, and compatible with the already-approved `select` data_type
contract. It is not claimed to be a universal age ontology or a lossless
representation of Shopify's Standard Product Taxonomy.

Shopify's Standard Product Taxonomy defines a structurally different
12-value `age_group` attribute (data/attributes.yml, verified 2026-07-16)
mixing precise ranges (0_6_months, 6_12_months, 1_2_years), broad groups
(babies, kids, teens, adults) and service values (all_ages, universal,
other). This is deferred as a richer alternative, not adopted now.

No Shopify option mappings are created in this PR. Shopify's taxonomy
confirms the existence of similarly named values, but it does not define
age boundaries equivalent to Google's five buckets, and its connector
representation may use taxonomy handles or globally unique IDs
(`gid://shopify/TaxonomyValue/...`) rather than display labels. Shopify
mapping is deferred to the Shopify connector mapping task.

A numeric suggestedMinAge/suggestedMaxAge model (confirmed as Schema.org's
own representation) is explicitly not introduced now — that is a larger
structural decision than GAP-020, requiring a separate Stop-and-Amend
review of the already-approved select contract.

This decision does not resolve variant-level applicability. Google
documentation confirms age_group may be a variant-distinguishing property;
the registry's current `binding_strategy: product` for this field is
unchanged by this PR. See GAP-022.

### DEC-007 — gender canonical value model

Shopify's Standard Product Taxonomy `target_gender` attribute (female, male,
unisex, other — data/attributes.yml, verified 2026-07-16) is adopted as the
platform_library canonical set. It is a clean superset of Google Merchant's
gender vocabulary (female, male, unisex — verified 2026-07-16): the first
three values match exactly; `other` has no Google equivalent.

Google option_mapping rows exist only for female, male, unisex (direct). No
option_mapping row is created for `other` — the current
canonical_product_field_option_mappings.csv contract supports only
`direct | renamed` mapping_type; representing "no channel equivalent"
requires a documented contract extension, out of scope for this PR. This
DEC records explicitly: Google Merchant cannot receive the `other` gender
value; automatic export is not possible for that value.

No Shopify option mappings are created in this PR. Shopify is used as the
authoritative source for the four-value canonical set, while the exact
connector representation of taxonomy values (handle vs. globally unique ID)
is deferred to the Shopify connector mapping task.

This decision does not resolve variant-level applicability. Google
documentation confirms gender may be a variant-distinguishing property; the
registry's current `binding_strategy: product` for this field is unchanged
by this PR. See GAP-022.

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
