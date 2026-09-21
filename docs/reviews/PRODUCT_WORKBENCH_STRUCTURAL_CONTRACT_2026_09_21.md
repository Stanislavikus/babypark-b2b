# Product Workbench Structural Contract — [Resolved — 2026-09-21]

> **STATUS: [Resolved — 2026-09-21] — PRODUCT OWNER APPROVED**
>
> Product-owner approval received 2026-09-21. Reopen only for conflict with newer authoritative docs or a proven implementation blocker.
> Base at freeze: `origin/develop @ ec47527ab1a71f65dbdce3b605bdc4f9f82d15f3`.

## Goal

Freeze the two structural seams needed before Product Workbench visual prototyping and
implementation:

A. Magento daily workspace row universes.
B. Magento Category / Attribute Set classification semantics.

This contract does not implement Magento Product CREATE. CREATE remains a separate RED
architecture/certification campaign.

---

## Decision A — Magento workspace row universes

### A1. Default daily View

The Magento channel workspace default View is **`Огляд`**.

Its row universe is the **current successful Magento Remote Catalogue snapshot** for the
connected account/target.

Purpose:

- immediately prove to the merchant that the intended store/catalogue was read;
- support browsing/search/filtering the actual remote catalogue;
- surface provider state, correspondence and problems without transferring ownership.

### A2. Publication View

**`Публікація`** is a separate View whose row universe is local **Master Products selected or
linked for outbound preparation** for that Magento account.

It is the place for:

- readiness;
- target classification;
- field/media preparation;
- Preview;
- future capability-gated Create;
- current supported Update;
- governed publication result.

### A3. Links View

**`Зв'язки`** is the correspondence/matching View between remote Magento records and Master
Products.

It supports bulk confirmation and row-level correction while preserving ExternalRecordLink as
the trusted identity authority.

### A4. Ownership invariants remain unchanged

- Remote Catalogue row = provider observation/evidence.
- Master Product = platform product truth.
- ExternalRecordLink = trusted remote correspondence.
- Sync product selection = outbound intent.
- Remote-only rows do not become Master Products merely because they appear in Overview.
- No default UNION table may mix remote-only rows and local-not-yet-created Products as one
  ambiguous product universe.

### A5. Current projection limitation

Decision A freezes the **row-universe architecture**, not imaginary projection fields.

Current Remote Catalogue projection can support the first Overview with:

- SKU;
- remote name;
- remote type;
- provider status;
- remote/freshness timestamps;
- link state derived by the platform.

Current projection does **not** yet provide brand/category fields, and Adobe scanning does not
yet supply the target thumbnail experience.

Therefore:

**Remote Catalogue Projection V2** is a named prerequisite before default-visible/filterable:

- thumbnail;
- brand;
- category/path.

Projection V2 must be designed for 10k–100k catalogue scale and must not introduce per-Product
provider HTTP reads.

---

## Decision B — target classification defaults + sparse overrides

### B1. Universal/domain boundary

Keep universal concepts universal:

- Master Product;
- Master Category;
- ProductType;
- readiness/completeness;
- selection;
- mapping/governance patterns.

Keep Magento-only concepts Magento-owned:

- Adobe Attribute Set identity;
- Adobe provider attribute-set/group/membership structure.

Do not store Magento Attribute Set IDs directly on Product.
Do not equate ProductType with Adobe Attribute Set.
Do not invent a generic stringly-typed classification JSON/EAV DSL merely for nominal
multi-platform generality.

### B2. Default target Category

Reuse the existing account-scoped category mapping:

`ConnectorAccount + Master Category -> one default target Magento category`.

The internal Master Category may be at any hierarchy depth.

The merchant UI may present Category/Subcategory/tree concepts, but persistence must not assume
the provider hierarchy is exactly two levels.

### B3. Product category exception

An authorized merchant may override the default for one Product/account.

Semantics:

- no Product override rows -> inherit Category default;
- one or more Product override rows -> explicit desired managed Magento category set;
- deleting Product override rows -> return to automatic/default mapping.

Persist the override as normalized rows keyed conceptually by:

`workspace + connector_account + product + external_category_id`.

Do not use an opaque JSON array when normalized rows provide stronger uniqueness/filtering.

Multiple Magento categories are allowed.

Do not invent “primary category” unless later provider/product evidence requires it.

### B4. Existing provider-only categories

For an existing linked Magento Product, desired category set does not mean “replace every
remote category”.

Execution semantics remain ownership-based:

- add missing desired managed categories;
- remove only relations previously owned/managed by this platform and no longer desired;
- preserve provider-only categories whose ownership was never proved.

### B5. Default Attribute Set for future CREATE

For a Product that does **not yet exist as a trusted Magento Product**, effective Attribute Set
is resolved:

1. explicit Product/account Attribute Set override;
2. else account-scoped `ProductType -> Magento Attribute Set` default;
3. else NotReady/blocker.

The mapping is connector/account-scoped.

ProductType is not equivalent to Adobe Attribute Set; many ProductTypes may map to the same
provider set and different Magento accounts may map the same ProductType differently.

### B6. Existing trusted Magento Product

For an existing trusted Magento Product:

- current observed remote `attribute_set_id` is the structural context for applicable fields,
  options and readiness;
- ProductType default/override may be advisory evidence;
- mismatch must not silently change the remote Attribute Set;
- changing Attribute Set of an existing Magento Product is a separate consequential capability
  and is outside this contract until explicitly researched/certified.

### B7. Product scope, not Variant scope

Classification override belongs to Product.

`ProductVariant` is a separate child entity and inherits Product-level Category/Attribute Set
classification for the first supported scope.

No variant-specific classification override is authorized by this contract.

### B8. Existing Adobe Attribute Structure must be reused

Use the persisted provider structure:

- `AdobeProductAttributeSet`;
- `AdobeProductAttributeGroup`;
- `AdobeProductAttributeSetMembership`;
- Adobe attribute lineage;
- Adobe option lineage.

Effective Attribute Set determines applicable provider attributes/options from this persisted
catalogue.

Do not introduce one provider metadata HTTP read per Product.

### B9. Planner migration is an explicit implementation slice

The current single `SyncConfiguration.connector_execution_configuration.attribute_set_id`
path is load-bearing in `AdobeProductExportSemanticPlanner`.

Per-Product effective Attribute Set therefore requires a named planner/metadata migration
slice, not an incidental consequence of adding mappings.

That slice must:

- resolve effective set per Product;
- evaluate mapped fields/configurable dimensions against that Product's effective set;
- replace the global set as final semantic-planner owner;
- preserve existing simple/configurable invariants;
- preserve entity-trust comparison semantics for existing records;
- use admitted snapshot data, never mutable latest mapping state during Live.

### B10. Preview / Live determinism

Do not add a stored ConnectorAccount classification counter merely for invalidation.

Use the established pattern:

1. canonical classification payload;
2. deterministic hash/revision;
3. payload + revision stored in Preview `SyncRun.configuration_snapshot`;
4. Live admission recomputes relevant current revision;
5. mismatch invalidates qualifying Preview evidence.

Existing `ConnectorCategoryMappingSnapshotService/category_mapping_revision` remains the
default category-mapping owner.

New Product overrides / ProductType→Attribute Set mappings receive a narrowly owned
classification snapshot/read service.

Where practical, snapshot only classification data relevant to the selected run so an
unrelated Product exception does not invalidate every account Preview.

### B11. Minimum new persistence semantics

Exact class/table names remain implementation-contract details, but required semantics are:

1. **ProductType -> Adobe Attribute Set default**
   - workspace;
   - connector account;
   - ProductType;
   - current observed Adobe Attribute Set identity;
   - unique per account + ProductType.

2. **Product -> Adobe Attribute Set sparse override**
   - workspace;
   - connector account;
   - Product;
   - current observed Adobe Attribute Set identity;
   - unique per account + Product.

3. **Product -> desired Magento category override**
   - workspace;
   - connector account;
   - Product;
   - external category ID;
   - unique per account + Product + external category ID.

Do not reuse:

- ExternalRecordLink for pre-create classification intent;
- AdobeProductCategoryAssignment for desired pre-publication category configuration.

### B12. AI/manual semantics

AI may recommend:

- target category;
- Attribute Set;
- field/option values.

Recommendation is not effective authority until the relevant merchant confirmation policy
accepts it.

An authorized user must be able to correct Category and Attribute Set manually and reset an
override back to automatic/default behavior.

---

## Explicit implementation boundaries after freeze

Decision A and B do **not** imply one giant implementation PR.

Named work after freeze includes at least:

1. Remote Catalogue Projection V2 for scalable thumbnail/brand/category support.
2. Magento Workbench shell / View ordering using actual supported projection fields.
3. Decision-B persistence + mutation services + isolation constraints.
4. Per-Product Attribute Set planner/metadata migration.
5. Classification snapshot/revision integration with Preview/Live.
6. Merchant Category/Attribute Set recommendation + manual correction UX.

Separate campaigns remain:

- Magento Product CREATE — RED, GPT-5.4 + Opus 5 blind studies before implementation.
- Remote-only Magento -> new Master Product import — separate consequential capability.
- Price/stock automated high-frequency channel path — separate runtime campaign.
- AI/SEO enrichment — Workbench-aligned but not required to freeze A+B.

## Freeze implementation note

This document is now `[Resolved — 2026-09-21]`. The documentation-freeze campaign must:

1. amend the presentation-order wording in `PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md`;
2. amend relevant Domain/UX/UI summary sections so `Огляд / Публікація / Зв'язки` and Decision-B classification semantics are authoritative;
3. update documentation-contract tests for the frozen truth;
4. make **no runtime code changes** in the documentation freeze commit.
