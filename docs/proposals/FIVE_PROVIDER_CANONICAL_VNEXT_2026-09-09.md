# Five-Provider Canonical Vocabulary vNext — Lead Synthesis Proposal

**Status:** PROPOSAL — not authoritative, not a runtime/seed contract.
**Base:** `origin/develop @ 1e7172a07f93c2e504ad4cd60b5c2abd8924c388`
**Date:** 2026-09-09
**Machine-readable decision matrix:** `docs/data/five_provider_canonical_vnext_decisions.csv`

## Goal

Produce a neutral product-data vocabulary that is broad enough for ordinary merchants to import and map real catalogues without repeatedly creating common fields by hand, while preserving the platform's frozen domain boundaries and refusing false cross-provider equivalence.

The observable product outcome is not "more fields". It is: **ordinary recurring commerce semantics are already known by the platform, provider-specific mechanics stay outside the Product Field Library, and ambiguous mappings fail closed rather than corrupting meaning.**

## Current state and acceptance evidence

- Current canonical registry: **65 concepts**; this proposal audits all 65 exactly once.
- Independent provider evidence: Adobe Commerce, Google Merchant, BigCommerce, Amazon Listings/PTD, and Shopify.
- Provider-local Gate 1 coverage is frozen and machine-accounted in the repository; Shopify corrected blind coverage proved exact accounting over master, structured, alias, standard-metafield and taxonomy inputs.
- The repository Gate 2 synthesis remains research evidence; provider-local concept names do not automatically become canonical codes.
- Universal platform-field promotion still follows the registry rule: minimum two independent sources. Legal/compliance claims require normative evidence.

## Shortest safe path

1. Freeze this Lead proposal after adversarial review.
2. Patch authoritative documentation and registry semantics first; do not materialize new fields before Stop-and-Amend approval.
3. Resolve only the bounded owner/binding/value-model questions identified below.
4. Materialize approved Core/Library/domain concepts.
5. Only then expand automatic mapping and connector field-by-field certification.

## Result at a glance

The five-provider evidence **does not justify replacing the current 65-field model**. The dominant outcome is preservation: 44 of 65 require no semantic change, while the important corrections are concentrated in false-friend descriptions, promotion of already-known Library concepts, and Product-vs-Variant/domain ownership.

Current-registry decision counts:

| lead_decision | count |
| --- | --- |
| KEEP_AS_IS | 44 |
| KEEP_CLARIFY_SEMANTICS | 3 |
| KEEP_CONCEPT_DOC_ALIGNMENT | 2 |
| KEEP_CONCEPT_VARIANT_BINDING_REVIEW | 3 |
| KEEP_LEGAL_REVIEW | 1 |
| KEEP_PROPOSED | 3 |
| KEEP_PROPOSED_BINDING_GAP | 2 |
| KEEP_PROPOSED_OWNER_BINDING_REVIEW | 1 |
| KEEP_STRICT_SEMANTICS | 2 |
| PROMOTE_ACTIVE_VERIFIED | 3 |
| PROMOTE_SEMANTIC_CONFIDENCE_BINDING_REVIEW | 1 |

Missing Concept Union contains **34 explicitly classified candidates/representations**, not 34 proposed fields. Only a small subset is recommended for addition now; the rest are deferred, domain-owned, discovery-only, merged, covered by an existing domain, or rejected.

| lead_decision | count |
| --- | --- |
| ACCEPT_CONCEPT_DEFER_OWNER | 1 |
| ADD_DOMAIN_CANONICAL | 4 |
| ADD_DOMAIN_CANONICAL_MODEL_REVIEW | 1 |
| ADD_PLATFORM_LIBRARY | 1 |
| ADD_PLATFORM_LIBRARY_BINDING_REVIEW | 2 |
| ADD_PLATFORM_LIBRARY_MODEL_REVIEW | 1 |
| COVERED_BY_EXISTING_PRICING_DOMAIN | 1 |
| DEFER_DUPLICATE_BOUNDARY | 1 |
| DEFER_EXISTING_DOMAIN_DECISION | 5 |
| DEFER_MEASUREMENT_NAMING_MODEL | 1 |
| DEFER_SEMANTIC_SPLIT | 1 |
| DEFER_SHAPE_FORM_BOUNDARY | 1 |
| DEFER_SPLIT_FROM_INSTRUCTIONS | 1 |
| DEFER_STRUCTURED_LIFETIME_MODEL | 1 |
| DEFER_URL_ROLE | 1 |
| DISCOVERY_ONLY_SECOND_SOURCE | 5 |
| MERGE_AS_PROVIDER_REPRESENTATION | 2 |
| OUT_OF_SCOPE_ANALYTICS | 1 |
| REJECT_AMBIGUOUS_OVERLAP | 1 |
| RELATION_TYPE_REVIEW | 2 |

## Current 65 — decisions that are not plain KEEP_AS_IS

| concept_key | lead_decision | confidence | materialization_gate | lead_note |
| --- | --- | --- | --- | --- |
| brand | KEEP_CLARIFY_SEMANTICS | HIGH | EXISTING_CONTRACT | Change description to Brand only; manufacturer and vendor are distinct concepts. |
| short_description | KEEP_PROPOSED | MEDIUM | SECOND_EXACT_SOURCE_OR_ARBITRATION | Do not promote by treating short title/subtitle as short description. |
| status | KEEP_CLARIFY_SEMANTICS | HIGH | EXISTING_CONTRACT | Keep internal lifecycle/active-state concept; explicitly exclude channel publication/visibility from the definition. |
| gtin | KEEP_CLARIFY_SEMANTICS | HIGH | EXISTING_CONTRACT | GTIN is not arbitrary barcode text. Valid UPC/EAN representations may normalize to GTIN; generic barcode requires validation. |
| condition | PROMOTE_ACTIVE_VERIFIED | HIGH | DOC_APPROVAL_THEN_LIBRARY_SEED | Semantic promotion is now justified; keep current Variant binding unless later offer-domain work proves a need to split offer condition. |
| net_weight | KEEP_STRICT_SEMANTICS | HIGH | EXISTING_CONTRACT | Keep strict net/gross definitions. Introduce a separate shipping-weight domain concept rather than weakening these fields. |
| gross_weight | KEEP_STRICT_SEMANTICS | HIGH | EXISTING_CONTRACT | Keep strict net/gross definitions. Introduce a separate shipping-weight domain concept rather than weakening these fields. |
| depth_mm | KEEP_CONCEPT_VARIANT_BINDING_REVIEW | HIGH_CONCEPT_MEDIUM_BINDING | TARGETED_BINDING_REVIEW_BEFORE_VARIANT_MAPPING | Keep current Product column semantics. Before Variant mapping, decide how the core_model_property concept is registered through FieldDefinition/FieldBinding so Product can retain a column binding and Variant can use a dynamic binding on the same concept. |
| width_mm | KEEP_CONCEPT_VARIANT_BINDING_REVIEW | HIGH_CONCEPT_MEDIUM_BINDING | TARGETED_BINDING_REVIEW_BEFORE_VARIANT_MAPPING | Keep current Product column semantics. Before Variant mapping, decide how the core_model_property concept is registered through FieldDefinition/FieldBinding so Product can retain a column binding and Variant can use a dynamic binding on the same concept. |
| height_mm | KEEP_CONCEPT_VARIANT_BINDING_REVIEW | HIGH_CONCEPT_MEDIUM_BINDING | TARGETED_BINDING_REVIEW_BEFORE_VARIANT_MAPPING | Keep current Product column semantics. Before Variant mapping, decide how the core_model_property concept is registered through FieldDefinition/FieldBinding so Product can retain a column binding and Variant can use a dynamic binding on the same concept. |
| meta_title | KEEP_CONCEPT_DOC_ALIGNMENT | HIGH | ALIGN_02_NAMING_LOCALIZATION_BEFORE_NEW_SEED | Keep current canonical code/owner. 02 naming is aligned to registry meta_title/meta_description; localization/storage semantics remain a gate before any new governed seed. |
| meta_description | KEEP_CONCEPT_DOC_ALIGNMENT | HIGH | ALIGN_02_NAMING_LOCALIZATION_BEFORE_NEW_SEED | Keep current canonical code/owner. 02 naming is aligned to registry meta_title/meta_description; localization/storage semantics remain a gate before any new governed seed. |
| material | PROMOTE_SEMANTIC_CONFIDENCE_BINDING_REVIEW | HIGH | BINDING_ARBITRATION_BEFORE_SEED | Concept is strong enough for active/verified. Product-only vs Product+Variant binding still merits targeted review. |
| age_group | KEEP_PROPOSED_BINDING_GAP | HIGH_CONCEPT_MEDIUM_BINDING | GAP_022_BINDING_DECISION | Concept is strong; do not seed until Product-vs-Variant applicability is resolved. |
| gender | KEEP_PROPOSED_BINDING_GAP | HIGH_CONCEPT_MEDIUM_BINDING | GAP_022_BINDING_DECISION | Concept is strong; do not seed until Product-vs-Variant applicability is resolved. |
| country_of_origin | KEEP_PROPOSED_OWNER_BINDING_REVIEW | HIGH_CONCEPT_MEDIUM_OWNER | OWNER_AND_BINDING_ARBITRATION | Clarify definition and Product-vs-Variant/domain owner before promotion; do not conflate with country_of_manufacture. |
| manufacturer | PROMOTE_ACTIVE_VERIFIED | HIGH | DOC_APPROVAL_THEN_LIBRARY_SEED | Promote concept; keep separate from brand/vendor. |
| model | PROMOTE_ACTIVE_VERIFIED | HIGH | DOC_APPROVAL_THEN_LIBRARY_SEED | Promote neutral Product model concept; do not merge with MPN. |
| compatibility | KEEP_PROPOSED | MEDIUM | MORE_EVIDENCE | Keep candidate, not active global library yet. |
| battery_type | KEEP_PROPOSED | MEDIUM | MORE_EVIDENCE | Keep candidate; do not flatten structured battery compliance into one field. |
| has_energy_consumption_details | KEEP_LEGAL_REVIEW | MEDIUM | NORMATIVE_LEGAL_EVIDENCE | No promotion without the legal/compliance evidence required by registry governance. |

Runtime note: on `develop` as of `76ec9dbe035fe6ce60087e900d73fca76cf7ab14`, `FieldDefinitionSeeder` already materializes `condition`, `short_description`, `material`, `country_of_origin`, `manufacturer`, `model`, `compatibility`, and `battery_type` (each with a matching `FieldBinding`). The gates above must be read as semantic promotion / owner/binding arbitration gates, not as “first seed exists only in the future”.

### Important preservation decisions

- `name`, `description`, `brand`, `sku`, `gtin`, `mpn`, `url`, Pricing, Inventory, Media and relation ownership remain the platform's neutral concepts; provider wording does not replace these codes.
- `url` remains the primary **absolute customer-facing product URL** under DEC-008. Shopify `handle`, Adobe `url_key`, and BigCommerce `custom_url` do not become raw `url` mappings.
- DEC-009 `net_weight` and `gross_weight` remain strict. Generic/provider shipping or packaged weight must not weaken those definitions.
- Product status remains an internal platform lifecycle/active-state concept. Provider publication, storefront visibility and channel state remain separate.

## Promotions from the current registry

The evidence is now strong enough to treat these as mature neutral concepts, subject to their gates:

| concept_key | concept_name | lead_decision | confidence | evidence_summary | materialization_gate |
| --- | --- | --- | --- | --- | --- |
| condition | Condition | PROMOTE_ACTIVE_VERIFIED | HIGH | Google condition + BigCommerce condition + Amazon condition_type independently confirm the commerce concept; current registry already marks verification=verified. | DOC_APPROVAL_THEN_LIBRARY_SEED |
| material | Material | PROMOTE_SEMANTIC_CONFIDENCE_BINDING_REVIEW | HIGH | Adobe material + Google material + Amazon material + Shopify taxonomy independently confirm the concept; Schema.org material also supports it. | BINDING_ARBITRATION_BEFORE_SEED |
| manufacturer | Manufacturer | PROMOTE_ACTIVE_VERIFIED | HIGH | Amazon manufacturer + Schema.org manufacturer independently confirm the neutral concept. Adobe manufacturer is supplementary only because DEC-010 does not establish guaranteed availability across all installations; Shopify vendor and BigCommerce brand_name are not equivalents. | DOC_APPROVAL_THEN_LIBRARY_SEED |
| model | Model | PROMOTE_ACTIVE_VERIFIED | HIGH | Amazon model_number/model_name + Schema.org Product.model confirm neutral model semantics; provider-specific vehicle model remains separate applicability. | DOC_APPROVAL_THEN_LIBRARY_SEED |

`material` is the important special case: the concept itself is strongly confirmed by Adobe, Google, Amazon and Shopify evidence, but Product-only binding is too narrow to freeze without reviewing a Product+Variant two-binding model.

`age_group` and `gender` are not weak concepts; they remain proposed because GAP-022 is an explicit unresolved binding decision. DEC-006/DEC-007 value normalization stays frozen.

## Strong Missing Concept Union additions

| concept_key | concept_name | lead_decision | confidence | materialization_gate | lead_note |
| --- | --- | --- | --- | --- | --- |
| slug | Product Slug | ADD_PLATFORM_LIBRARY | HIGH | LOCALIZATION_AND_CHANNEL_OVERRIDE_DOC_DECISION | Use neutral code slug, not handle/url_key/url_path; DEC-008 already requires this to remain separate from url. |
| size_system | Size System | ADD_PLATFORM_LIBRARY_BINDING_REVIEW | HIGH_CONCEPT_MEDIUM_BINDING | BINDING_AND_VALUE_MODEL_REVIEW | Useful especially for apparel/footwear; keep separate from size and provider taxonomy IDs. |
| shape | Shape | ADD_PLATFORM_LIBRARY_BINDING_REVIEW | HIGH_CONCEPT_MEDIUM_BINDING | BINDING_AND_VALUE_MODEL_REVIEW | Shopify generic Shape + GS1 Product Shape Code independently confirm the concept; category-specific shape variants remain mappings unless separately justified. |
| ingredients | Ingredients | ADD_PLATFORM_LIBRARY_MODEL_REVIEW | HIGH_CONCEPT_MEDIUM_MODEL | VALUE_MODEL_AND_COMPLIANCE_BOUNDARY_REVIEW | Accept the neutral concept, but decide text statement vs structured ingredients and compliance projections before materialization. |
| shipping_weight | Shipping Weight | ADD_DOMAIN_CANONICAL | HIGH | SHIPPING_DOMAIN_STORAGE_DECISION | Operational weight used for shipping calculations; do not weaken net_weight/gross_weight semantics. |
| shipping_dimensions | Shipping Dimensions | ADD_DOMAIN_CANONICAL | HIGH | SHIPPING_DOMAIN_STRUCTURED_MEASUREMENT_DECISION | Structured shipping dimensions; do not map blindly to intrinsic depth_mm/width_mm/height_mm. |
| harmonized_system_code | Harmonized System Code | ADD_DOMAIN_CANONICAL | HIGH | COMPLIANCE_CUSTOMS_STORAGE_DECISION | Customs classification, not Product category and not tax class. |
| unit_pricing_measure | Unit Pricing Measure | ADD_DOMAIN_CANONICAL | HIGH | PRICING_DOMAIN_CONTRACT_UPDATE | Pricing-owned structured measure; never a generic Product Field. |
| dangerous_goods_classification | Dangerous Goods Classification | ADD_DOMAIN_CANONICAL_MODEL_REVIEW | HIGH_CONCEPT_MEDIUM_MODEL | COMPLIANCE_TRANSPORT_MODEL_DECISION | International transport-compliance concept; do not collapse it to a single is_dangerous_goods boolean. |
| max_order_quantity | Maximum Order Quantity | ACCEPT_CONCEPT_DEFER_OWNER | HIGH_CONCEPT_MEDIUM_OWNER | OWNER_AND_SCOPE_DECISION | Concept is real; decide whether it mirrors Product min_order_quantity or belongs to offer/B2B policy before materialization. |

### Why these additions are bounded

- `slug` is a reusable route-key concept, not another URL field. It is independently evidenced by Adobe `url_key`, BigCommerce `custom_url`, and Shopify `handle`.
- `size_system` is distinct from `size`; Google and Shopify independently expose the sizing-system dimension.
- `shape` now clears the two-source bar through Shopify's generic taxonomy attribute and GS1 Global Data Model `Product Shape Code`; category-specific shape variants remain provider/taxonomy mappings.
- `ingredients` is a recurring international product concept supported by Shopify taxonomy and GS1 ingredient semantics, but its neutral value model must distinguish a display statement from structured/regulatory ingredient data.
- `shipping_weight` and `shipping_dimensions` are operational logistics concepts. Google explicitly separates shipping measurements from product measurements, and Amazon separately models item-package measurements from item measurements. BigCommerce's single weight/dimension fields are **not** disambiguating evidence for the shipping concepts while `bigcommerce_weight_semantics` remains open.
- `shipping_logistics_domain` in this proposal means per-product logistics measurements only; it is not the explicitly excluded shipping-account/carrier-configuration scope.
- `harmonized_system_code` is customs classification, not Product category or tax class.
- `unit_pricing_measure` belongs to Pricing, not Product Fields.
- `dangerous_goods_classification` belongs to a structured Compliance/Transport domain. Amazon PTD hazmat evidence plus the UN Model Regulations support the concept family, but not a universal boolean source-of-truth field.
- `max_order_quantity` is a real neutral commercial constraint, but Product vs offer/B2B policy ownership remains unresolved, so the concept is accepted without premature storage selection.

## Existing Product dimensions need a binding review, not duplicate fields

Google product dimensions, BigCommerce Product/Variant dimensions with documented Variant fallback, and Amazon item dimensions support the neutral physical-dimension family. The current `depth_mm`, `width_mm`, and `height_mm` Product concepts therefore remain valid. BigCommerce's same single measurement fields must **not** simultaneously be treated as evidence for a distinct shipping-dimensions mapping; their shipping identity remains ambiguous.

The external evidence also shows legitimate SKU/variant-level measurements. The frozen FieldBinding architecture permits two bindings on one FieldDefinition, but the current dimensions are still registered as `core_model_property`/Product columns rather than ready-made dynamic FieldDefinitions. The targeted review must therefore decide the representation migration/registration seam first: preserve the Product column binding and, if approved, add a Variant dynamic binding on the **same semantic concept**. Do not create `variant_width`, `variant_height`, etc. merely to avoid that decision.

A separate measurement naming question remains for **length vs depth**; these must not be globally aliased without a documented measurement model.

## Deferred semantic/domain questions

| concept_key | lead_decision | confidence | materialization_gate | lead_note |
| --- | --- | --- | --- | --- |
| short_title | DEFER_SEMANTIC_SPLIT | MEDIUM | TARGETED_PRIMARY_SOURCE_ARBITRATION | Do not merge into short_description without proving title-vs-summary semantics. |
| canonical_url | DEFER_URL_ROLE | MEDIUM | SECOND_COMMERCE_PROVIDER_OR_EXPLICIT_WEB_PUBLICATION_DECISION | Keep separate from url and slug; do not add merely because rel=canonical exists on the web. |
| care_instructions | DEFER_SPLIT_FROM_INSTRUCTIONS | MEDIUM | SECOND_INDEPENDENT_EXACT_SOURCE | Shopify strongly supports care as distinct content, but no second exact independent source yet proves a separate canonical field from generic instructions. |
| length_dimension | DEFER_MEASUREMENT_NAMING_MODEL | MEDIUM_HIGH | MEASUREMENT_MODEL_ARBITRATION | Do not alias length to depth globally; decide whether a separate length concept is required. |
| product_form | DEFER_SHAPE_FORM_BOUNDARY | MEDIUM_HIGH | SHAPE_FORM_SEMANTIC_ARBITRATION | Shopify + GS1 support physical product form, but GS1 explicitly describes form or shape, so a separate field would risk duplicating the newly promoted shape concept. |
| shelf_life | DEFER_STRUCTURED_LIFETIME_MODEL | HIGH_FAMILY_LOW_EQUIVALENCE | SECOND_EXACT_SOURCE_AND_LIFETIME_MODEL | GS1 distinguishes shelf life at arrival, from production, and after opening; reject a single shelf_life_days field until lifetime/lot ownership and exact mappings are resolved. |
| tax_classification | DEFER_EXISTING_DOMAIN_DECISION | MEDIUM | TAX_DOMAIN_DECISION | No new generic field until provider tax class vs third-party tax code semantics are separated. |
| map_price | DEFER_EXISTING_DOMAIN_DECISION | MEDIUM | PRICING_POLICY_DECISION | Keep out of Product Fields until Pricing owner contract is explicit. |
| order_time_customization_options | DEFER_EXISTING_DOMAIN_DECISION | HIGH_CONCEPT_MEDIUM_ARCH | PRODUCT_CUSTOMIZATION_DOMAIN_DECISION | Strong domain capability, but not a scalar canonical field. |
| certifications | DEFER_EXISTING_DOMAIN_DECISION | HIGH_CONCEPT_MEDIUM_MODEL | COMPLIANCE_DOMAIN_MODEL_AND_NORMATIVE_SOURCES | Retain as domain candidate; no generic text/multiselect field. |
| preorder_message | DEFER_EXISTING_DOMAIN_DECISION | LOW_MEDIUM | MORE_EVIDENCE | Do not add yet. |
| related_products | RELATION_TYPE_REVIEW | HIGH | ASSOCIATION_TYPE_TAXONOMY_REVIEW | Not a scalar field. Determine whether existing accessory/cross_sell types cover generic related semantics. |
| complementary_products | RELATION_TYPE_REVIEW | HIGH | ASSOCIATION_TYPE_TAXONOMY_REVIEW | Not a scalar field; avoid duplicate relation semantics. |
| trade_item_description | DEFER_DUPLICATE_BOUNDARY | LOW_MEDIUM | SECOND_SOURCE_AND_CONTENT_BOUNDARY | Do not add a third description concept without evidence. |

The purpose of this queue is to prevent accidental model expansion. `short_title` must not be treated as `short_description`; `care_instructions` must not be split from `instructions` without a second exact source; `product_form` must not duplicate `shape`; shelf-life semantics must not be collapsed into one duration; and `certifications` / order-time customization are structured domain capabilities, not flat Product Fields.

## Discovery-only Platform Library expansion queue

| concept_key | concept_name | confidence | materialization_gate |
| --- | --- | --- | --- |
| scent | Scent | MEDIUM | SECOND_INDEPENDENT_SOURCE |
| flavor | Flavor | MEDIUM | SECOND_INDEPENDENT_SOURCE |
| power_source | Power Source | MEDIUM | SECOND_INDEPENDENT_EXACT_SOURCE |
| activity | Activity / Intended Activity | LOW_MEDIUM | SECOND_INDEPENDENT_SOURCE |
| content_language | Content / Product Language | LOW_MEDIUM | SEMANTIC_SPLIT_AND_SECOND_SOURCE |

These remain useful signals that the eventual Platform Library should be richer than today's baseline, especially for category-heavy catalogues. They still lack sufficient independent evidence or a stable semantic split for promotion. Shopify taxonomy breadth alone is not sufficient.

## Explicit merges, covered concepts, scope exclusions and rejects

| concept_key | lead_decision | proposed_scope_or_owner | lead_note |
| --- | --- | --- | --- |
| aggregate_rating | OUT_OF_SCOPE_ANALYTICS | reviews_analytics_domain | Real concept, intentionally outside current Product Field scope. |
| sale_price_period | COVERED_BY_EXISTING_PRICING_DOMAIN | pricing_domain | Do not create duplicate Product Field; resolve through Pricing. |
| package_weight | MERGE_AS_PROVIDER_REPRESENTATION | shipping_logistics_domain | Do not create both shipping_weight and package_weight unless a future packaging hierarchy requires the distinction. |
| package_dimensions | MERGE_AS_PROVIDER_REPRESENTATION | shipping_logistics_domain | Avoid duplicate dimension families until packaging hierarchy is modeled. |
| product_weight | REJECT_AMBIGUOUS_OVERLAP | n/a | Keep provider mapping partial; no new generic weight field. |

This is important for library quality: a richer vocabulary must not become a synonym graveyard. `product_weight`, `package_weight`, `shipping_weight`, and `gross_weight` cannot all be added merely because providers use different labels.

## External primary-source evidence added during arbitration

The two adversarial reviews did not reopen the five-provider corpus. Lead arbitration used a small set of independent primary sources only to test completeness findings that the provider-only union could not settle:

- **GS1 Global Data Model Attribute Implementation Guide** — `Product Shape Code` confirms product shape excluding packaging; the same guide distinguishes `Minimum Days of Shelf Life at Arrival`, `Minimum Days of Shelf Life from Production`, and `Usage Period After Opening`, which is why this proposal rejects a single generic `shelf_life_days` field. Source: `https://www.gs1.org/standards/gs1-global-data-model-attribute-implementation-guide/14`.
- **GS1 Web Vocabulary** — `productFormDescription` defines physical product form/shape distinct from packaging, creating the explicit `shape` vs `product_form` arbitration boundary. Source: `https://ref.gs1.org/voc/Product`.
- **GS1 Web Vocabulary food/beverage semantics** — `ingredientStatement` and structured ingredient information independently corroborate Shopify's generic Ingredients taxonomy concept while showing that text and structured ingredient representations are not necessarily one storage shape. Source: `https://ref.gs1.org/voc/Beverage`.
- **UNECE UN Model Regulations Rev.24 (2025)** — dangerous goods are a structured transport-compliance subject covering classification, listing, packaging, marking and documentation. This supports a domain concept but argues against a canonical `is_dangerous_goods` boolean as the source of truth. Source: `https://unece.org/transport/dangerous-goods/un-model-regulations-rev-24`.

These sources support only the bounded arbitration changes above. They do not authorize runtime materialization or wholesale expansion beyond the evidence rules.

## Documentation conflicts that must be corrected before materialization

1. **Brand vs manufacturer vs vendor.** Earlier drafts of `02-ATTRIBUTE_DICTIONARY.md` and the registry described `brand` as "Brand or manufacturer name". Five-provider evidence requires a strict split: Brand is the commercial brand; Manufacturer is the manufacturer concept; Shopify `vendor` is a provider-party label and is not automatically either one.
2. **GTIN vs barcode.** Current documentation uses "GTIN / EAN / Barcode" loosely. GTIN is a standardized trade item identifier; an arbitrary barcode string is not automatically GTIN. Provider `barcode` mappings require validation/type evidence.
3. **Status vs publication.** Internal lifecycle/active state must be defined separately from Shopify publication, BigCommerce visibility, Google publication controls and Amazon listing state.
4. **SEO naming alignment.** The registry/Domain Model use `meta_title` / `meta_description`, while Attribute Dictionary localization text still refers to `seo_title` / `seo_description`. Keep one canonical naming contract and resolve localization/storage semantics before mapping expansion.
5. **Country of origin.** Current wording "Manufacturing or origin country" is too broad. Customs/legal origin, manufacturing country and provider inventory-item origin must not be silently merged.
6. **Measurements.** Product dimensions and logistics/shipping dimensions need explicit separation; `length` and `depth` are not universal synonyms.

## Merchant-completeness gate

The target UX after materialization is not a giant mandatory schema. It is a rich known vocabulary with contextually activated fields and automatic suggestions.

A normal import should trend toward:

> `known + automatically suggested` for standard commerce semantics;
> `manual confirmation` only for ambiguous mappings;
> `workspace custom field` only for genuinely merchant-specific concepts;
> `provider-specific/domain-owned` for channel mechanics and structured operational data.

The platform should therefore maintain a small System/Core, a substantially richer optional Platform Library, and explicit domain-owned concepts. The user does not need to understand those internal layers to use "Product Fields".

## Materialization gates

No runtime materialization is authorized by this proposal. Before seed/runtime work:

- resolve `material`, `shape`, and physical-dimension Product-vs-Variant binding/value-model questions;
- close GAP-022 for `age_group` / `gender` binding;
- preserve GAP-023 / DEC-009 measurement-unit and packaging constraints;
- define storage/owner contracts for shipping measurements, HS code, unit pricing, and dangerous-goods classification;
- resolve the `ingredients` text-vs-structured/compliance representation before seeding;
- resolve `max_order_quantity` owner;
- keep `product_form`, `care_instructions`, and `shelf_life` deferred until their semantic/model gates close;
- align brand/GTIN/status/SEO/country-of-origin documentation;
- retain legal review for energy/compliance claims.

## Adversarial review arbitration

Two independent attacks were completed against the same frozen proposal HEAD before this correction:

1. **Evidence / ontology review:** verdict `FREEZE AFTER MINOR CORRECTIONS`. It found no `[Resolved]`/DEC violation and confirmed the central brand/manufacturer/vendor, GTIN/barcode, status/publication, and URL/slug splits. Its one blocking finding was accepted: BigCommerce's single weight/dimension fields cannot be counted as disambiguating evidence for both intrinsic and shipping-specific concepts. Google + Amazon remain the load-bearing independent evidence for the new shipping-measurement family.
2. **Merchant completeness / PIM review:** verdict `FREEZE AFTER BOUNDED LIBRARY EXPANSION`. Lead arbitration accepted only evidence-backed bounded changes: add `ingredients` behind a value-model/compliance gate; add structured `dangerous_goods_classification` rather than a boolean hazmat flag; promote `shape` after independent GS1 corroboration; track `shelf_life` as a deferred semantic family; and keep `product_form` deferred because its boundary overlaps `shape`. Raw suggestions such as `is_dangerous_goods` and `shelf_life_days` were rejected as over-compressed models.

No Opus-level escalation is warranted: the reviews exposed bounded semantic/owner/value-model questions, not a new fundamental DB/security/concurrency architecture decision.

## Acceptance criteria for this proposal

- all current 65 registry concepts appear exactly once in the machine matrix;
- no duplicate `concept_key` exists;
- all **34** Missing Union rows have explicit evidence, decision, confidence and materialization gate;
- frozen `name`, `url`, `mpn`, `net_weight`, `gross_weight`, Pricing/Inventory/Media/Relations boundaries are not silently reopened;
- provider aliases never become canonical merely by naming similarity;
- discovery-only taxonomy candidates remain non-authoritative;
- no runtime/seed/schema change is made before documentation approval.
