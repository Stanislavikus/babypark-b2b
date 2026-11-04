# Product Workbench Decision B — Gemini 3.1 Arbitration — 2026-09-21

**Status:** Lead synthesis candidate — not yet [Resolved].
**Base:** `origin/develop @ ec47527ab1a71f65dbdce3b605bdc4f9f82d15f3`
**Input:** independent Gemini 3.1 challenge of Decision B.

## 1. Verdict

Gemini verdict `ACCEPT WITH CORRECTIONS` is accepted.

The core model remains:

- reusable account-scoped defaults;
- sparse Product-level exceptions;
- Master Product stays connector-neutral;
- Magento structure remains connector-owned;
- Preview/Live use frozen classification evidence.

However several Gemini physical-design suggestions are corrected below.

## 2. Finding arbitration

| ID | Gemini proposal | Lead status | Final direction |
|---|---|---|---|
| G-01 | ProductType -> Magento Attribute Set default | **ACCEPTED WITH SCOPE CORRECTION** | Correct default for products that do not yet have trusted existing Magento structure (especially future CREATE). Existing linked Magento Product uses observed remote Attribute Set as current structural truth unless a separate Attribute-Set-change capability is explicitly certified. |
| G-02 | Overrides only on configurable family root via `is_variant=false` | **SEMANTIC ACCEPT / IMPLEMENTATION CORRECTED** | Our domain already has `Product` + separate `ProductVariant`; there is no `is_variant` Product flag. Classification override attaches only to Product, so variants naturally inherit Product-level structure. |
| G-03 | Category override as JSON array of string IDs | **REJECT JSON / ACCEPT MULTI-CATEGORY SEMANTIC** | External category IDs remain strings, but effective category overrides should use normalized rows with a uniqueness invariant, not an opaque JSON array. |
| G-04 | Stored connector-account classification revision | **REJECTED** | Reuse current pattern: canonical snapshot payload + deterministic hash + Live admission comparison. Do not add a stored account revision merely to invalidate Preview. |
| G-05 | New classification snapshot service | **ACCEPTED WITH REFINEMENT** | Add a narrowly owned snapshot/read service for new Attribute Set defaults/overrides and category overrides; scope Product overrides to the exact selected set where practical. Existing category-mapping revision remains authoritative for default category mappings. |
| G-06 | `external_attribute_set_id` stored as string | **REJECTED** | Magento Attribute Set identity is numeric in current runtime/evidence. Prefer a reference to the existing observed `AdobeProductAttributeSet` row; if raw provider identity is serialized into a snapshot, serialize its positive integer ID. |
| G-07 | `merchant_confirmed_at` + `confirmed_by_user_id` mandatory columns | **DEFERRED / NOT REQUIRED FOR MINIMUM** | Effective rows are authoritative only through an authorized explicit mutation service. AI suggestions remain proposals elsewhere. Do not add speculative provenance columns unless an audit requirement proves necessary. |
## 3. Critical correction Gemini missed — existing Product vs future CREATE

Attribute Set has two different roles and must not be conflated.

### Existing trusted Magento Product

The remote Product already has an observed `attribute_set_id`.

For current UPDATE/enrichment work:

- observed remote Attribute Set is the structural context used to decide which Magento fields,
  options and configurable dimensions are applicable;
- the platform may compare it with the ProductType default as advisory evidence;
- a mismatch must **not** silently cause a structural Attribute Set change;
- changing the Attribute Set of an existing remote Product is a separate consequential
  capability and is outside this Workbench decision until explicitly certified.

### Product not yet existing in Magento

For future CREATE:

1. explicit Product/account Attribute Set override, if present;
2. otherwise account-scoped ProductType -> Magento Attribute Set default;
3. otherwise unresolved publication blocker.

This preserves the value of ProductType defaults without turning an enrichment UI into a
hidden structural mutation tool.

## 4. Existing Attribute Structure foundation must be reused

Gemini focused on persistence but missed that current execution metadata is still built around
one selected Attribute Set per SyncConfiguration.

Current repo already has a stronger provider-structure foundation:

- `AdobeProductAttributeStructureReader` reads all discovered Attribute Sets;
- `AdobeProductAttributeSet` persists observed sets;
- `AdobeProductAttributeGroup` persists groups;
- `AdobeProductAttributeSetMembership` persists which attributes belong to which set;
- attribute/option lineage persists provider field/options.

Therefore multi-Attribute-Set publication must **reuse this structure catalogue** rather than
perform one provider HTTP read per Product.

The old `AdobeProductExportExecutionMetadata(selectedAttributeSetId, ...)` and
config-wide `connector_execution_configuration.attribute_set_id` are legacy single-set
execution seams. They cannot remain the final owner once per-Product effective sets are enabled.

## 5. Final default classification model

### Category default

Reuse:

`ConnectorAccount + Master Category -> one default external Magento category ID`.

The Master Category may itself be any depth in the platform tree. UI presents the target
Magento path/tree, but persistence retains provider category identity.

### Attribute Set default

New Magento-owned mapping:

`ConnectorAccount + ProductType -> observed Magento Attribute Set`.

Prefer referencing the existing `AdobeProductAttributeSet` observation identity rather than
copying a raw provider ID as the relational target.

Many ProductTypes may map to the same Magento Attribute Set.

ProductType is not equivalent to Attribute Set; this is account-scoped publication
classification only.
## 6. Sparse Product exception model

### Attribute Set exception

A Product-level exception is allowed for **future create/preparation**.

It must reference one current/non-missing Magento Attribute Set for the same workspace/account
context.

For a Product already trusted to an existing Magento entity, a different override is not an
automatic UPDATE instruction. Until Attribute-Set-change is certified, such a mismatch is
blocked/advisory rather than silently written.

### Category exception

Use normalized rows conceptually equivalent to:

`workspace + connector_account + product + external_category_id`.

Semantics:

- no Product category-override rows -> inherit Category default;
- one or more rows -> explicit desired managed category set for this Product/account;
- “reset to automatic” deletes those override rows;
- first scope does not need an explicit empty override set; publication without any resolved
  category may remain NotReady unless a later product decision allows uncategorized publishing.

The desired set may contain multiple Magento categories.

Do not invent a primary category unless actual provider behavior/product need requires it.

## 7. Managed vs provider-only category semantics

A desired category set does **not** mean “make the remote Product contain exactly these and
delete everything else”.

For existing linked Products:

- add missing desired managed categories;
- remove only categories previously owned/managed by this platform and no longer desired;
- preserve provider-only categories the platform never proved ownership of.

This extends the already certified single-category relation semantics to a desired set without
weakening provider-only preservation.

For future CREATE, the desired set supplies initial managed category intent after/create-with
the new remote Product according to the separately certified CREATE workflow.

## 8. Product/Variant scope

Classification persists at Product scope.

Reason:

- `ProductVariant` is a separate child entity;
- current semantic planner already applies one Attribute Set to the configurable parent and
  all simple children;
- Product owns Category/ProductType structure.

No first-scope variant-specific Attribute Set/category override is authorized.

If a future provider/product type proves variant-specific structural classification is needed,
that becomes a new decision rather than a hidden exception in this one.
## 9. Effective resolution algorithm

For a selected local Product/account:

### Category desired set

1. if Product category override rows exist -> use that normalized set;
2. else if Product has Master Category and current `ConnectorCategoryMapping` exists -> use
   the one mapped external category as a singleton set;
3. else -> unresolved category blocker.

### Attribute Set

If a trusted existing Magento entity exists:

1. read current observed remote Attribute Set;
2. use that set for field applicability/readiness;
3. compare ProductType default/override only as advisory or as input to a future explicitly
   supported structural-change action.

If no trusted existing Magento entity exists (future CREATE path):

1. explicit Product Attribute Set override;
2. else ProductType -> Magento Attribute Set mapping;
3. else unresolved Attribute Set blocker.

### Fields/options

Resolve required/applicable fields and configurable dimensions from the **effective Attribute
Set** using the persisted Adobe attribute structure catalogue plus current
FieldMapping/FieldOptionMapping.

## 10. Preview / Live determinism

Do not add a stored ConnectorAccount `classification_revision`.

Follow the existing category-mapping pattern:

1. build a canonical classification payload for Preview;
2. include it in `SyncRun.configuration_snapshot`;
3. include a deterministic hash/revision derived from canonical payload;
4. at Live admission recompute the relevant current revision and compare;
5. mismatch => qualifying Preview evidence is stale/missing.

The new snapshot should include only classification data needed by the exact run where
practical, especially sparse Product overrides, so one unrelated Product exception does not
invalidate every Preview on the account.

Default category mappings continue using existing
`ConnectorCategoryMappingSnapshotService/category_mapping_revision` unless a later refactor
proves unification is materially safer.

Preview/Live execution must read admitted snapshot classification, never mutable latest mapping
tables behind the run.

## 11. Minimal new persistence direction

Exact names may be finalized in the implementation contract, but semantics are:

1. **ProductType -> Adobe Attribute Set default mapping**
   - workspace;
   - connector account;
   - ProductType;
   - observed Adobe Attribute Set identity;
   - unique per account + ProductType.

2. **Product -> Adobe Attribute Set sparse override**
   - workspace;
   - connector account;
   - Product;
   - observed Adobe Attribute Set identity;
   - unique per account + Product.

3. **Product -> desired Magento category override rows**
   - workspace;
   - connector account;
   - Product;
   - external category ID string;
   - unique per account + Product + external category ID.

Existing:
- `ConnectorCategoryMapping` remains default category mapping;
- `AdobeProductCategoryAssignment` remains remote-write ownership/reconciliation ledger and
  must **not** be reused as pre-publication intent;
- `ExternalRecordLink` remains remote identity trust and must **not** be reused for
  classification intent.
## 12. No-go alternatives

Reject:

- Magento Attribute Set IDs on `products`;
- ProductType == Magento Attribute Set equivalence;
- copying effective defaults into every Product;
- generic EAV/stringly JSON classification DSL;
- category override JSON array when normalized sparse rows give better constraints;
- variant-specific classification in first scope;
- using ExternalRecordLink as pre-create classification;
- using AdobeProductCategoryAssignment as desired pre-create category configuration;
- changing existing remote Attribute Set merely because ProductType/default mapping differs;
- per-Product provider metadata HTTP calls for Attribute Set applicability.

## 13. Risk / routing

Decision B remains **ORANGE**:

- new relational persistence;
- workspace/account isolation constraints;
- Preview/Live stale-evidence integration;
- interaction with existing category ownership runtime.

Independent challenge requirement is satisfied by Gemini. No Opus escalation is required for
this classification decision because the final scope deliberately excludes a new external
Attribute-Set-change transaction semantic.

Magento Product CREATE remains a separate **RED** campaign and still requires the planned
GPT-5.4 + Opus 5 blind architecture studies before implementation.

## 14. Lead recommendation

**Freeze the semantic model above after product-owner approval.**

It preserves:
- one universal Master Product;
- provider-specific structural semantics;
- scalable defaults;
- sparse exceptions;
- deterministic Preview/Live;
- manual merchant correction;
- future AI recommendations;
- safe evolution to CREATE without pretending CREATE exists today.
