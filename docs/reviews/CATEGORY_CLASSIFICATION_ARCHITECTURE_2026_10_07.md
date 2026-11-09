# Category Classification Architecture — 2026-10-07

> **STATUS: [Resolved — 2026-10-07] — PRODUCT OWNER APPROVED**
>
> Base: `origin/develop @ f3a3a487b4eb915c492a941bb4478dd0ee9d7879`.
>
> This decision amends the platform-wide interpretation of Category without rewriting
> the proven Magento P-01 relation writer. Reopen only for a newer conflicting
> document, a proven runtime blocker, or material new evidence from a target channel.

## Goal

Keep Master Product organization simple for merchants while ensuring that adding
Magento, Shopify, BigCommerce, Google, Amazon, B2B, or another future publication
target does not force BabyPark to reshape its Master taxonomy around that provider.

## Research conclusion

The platform must keep four concepts separate:

1. **Master Classification** — BabyPark/workspace-owned organization of Master Products.
2. **Assortment / Publication Selection** — which Products are intended for a target.
3. **Channel Classification Projection** — target-specific classification required by that provider.
4. **Merchandising grouping** — Sale/New/Bestseller/seasonal/featured style grouping, which is not a substitute for taxonomy.

Market evidence is architecture evidence, not a schema to copy. Strong platforms split
these concerns differently: Akeneo uses category trees/channel scope; Shopify separates
Standard Product Category, Publication and Collections; Saleor separates Category,
Collection and ProductChannelListing; BigCommerce separates Product↔Channel assignment
from category assignment; Shopware separates Categories, Sales Channels and Dynamic
Product Groups; Google distinguishes `google_product_category` from merchant-owned
`product_type`; Magento supports multiple provider category memberships.

Therefore there is no safe universal rule that "Master Category == provider Category",
"Collection == Category", or "Channel assortment == Category membership".

## Resolved platform model

### 1. Master Classification

`Category` remains a workspace-owned Master/Catalogue classification and B2B navigation
primitive.

Current runtime storage is still `Product -> 0..1 Category` through
`products.category_id`. That is a **v1 implementation cardinality, not a permanent
platform invariant**. Future evidence may justify `0..N` Master classifications or
primary+secondary membership without changing the meaning of Master Product.

No migration to multi-category Product membership is authorized by this decision.

`ProductType`, `Merchant Type`, Brand, Tags and Attributes remain distinct concepts.
They must not be collapsed into Category.

### 2. Assortment is independent of Category

Whether a Product is intended for Magento, Shopify, Amazon, Google, B2B, or another
target is a publication/assortment decision, not a consequence of Master Category.

Example:

- Master: 10,000 Products;
- Magento: 1,000 selected Products;
- Shopify: 600 selected Products;
- Amazon: 200 selected Products;
- B2B: 10 selected Products.

Magento already has explicit outbound Product selection through
`SyncConfigurationProductSelection`.

The current native B2B catalogue does **not** yet have an explicit B2B assortment: it
projects active Products with active variants through
`CustomerPricingScope::applyProductScope()`. A future B2B assortment capability is a
separate backlog item; it must not be implemented by overloading Category.

### 3. Channel Classification Projection

Target classification is provider/capability specific. Examples include Magento
navigation/category membership, Shopify Standard Product Category, Shopify Collections,
Google `google_product_category`, Google merchant `product_type`, Amazon marketplace /
Product Type / browse classification, and future provider-specific taxonomy concepts.

These concepts may have different cardinalities and semantics. The platform must not
prematurely force them into one generic "Channel Category" entity/table.

Master Classification may be an input/default for a target projection, but it is not
the provider's authority.

Approved projections may eventually support, when a concrete channel requires them:

- many Master classifications -> one target classification;
- one Master classification/subtree -> multiple target classifications;
- exact Product-specific overrides;
- deterministic conditions based on Product Type, Brand, Tags or Attributes.

No fuzzy identity is permitted. Similar names or paths never establish correspondence.
Provider IDs must be exact and confirmed/observed through the governed connector path.

### 4. Merchandising is separate

Sale, New, Bestseller, Seasonal, Featured and similar commercial groupings are not
Master Categories by default.

Tags already provide a lightweight internal signal. Provider Collections / dynamic
groups / merchandising rules are separate future capabilities and must be implemented
only when a concrete destination requires them.

No generic custom rules engine is authorized now.

## Magento P-01 compatibility

The proven Magento P-01 relation runtime remains the safety boundary for Magento
category membership: exact provider IDs, trusted `ExternalRecordLink`, provider-only
relation protection, managed ownership ledger, add-before-remove, anchor drift checks,
reconciliation, account serialization and Preview revision protection.

The existing P-01 statement that a Product owns one Merchant Category describes the
**certified current input model**, not a universal future platform invariant.

The safe writer must not be redesigned merely because the upstream classification
planner later becomes richer. The intended extension seam is:

`classification inputs -> deterministic planner -> external_category_ids[] -> existing P-01 writer`.

Current Magento defaults remain account-scoped Master Category mapping with sparse
Product overrides until a separate implementation campaign proves a richer planner.

## Provider import semantics

Observed provider classification is remote truth and does not automatically become
Master Category.

Importing/linking a Product from Magento, Shopify or another provider must not create
or match Master Categories by similar name/path. Promotion into Master Classification
requires an explicit merchant action grounded in exact provider identity/evidence.

This does not prohibit file import from creating a merchant-supplied Master category
path; that is a separate Master-import workflow.

## Resolved Master Category delete semantics

Physical deletion is a valid Master-category lifecycle operation. Hiding and deleting
remain distinct:

- **Hide/Show** is reversible and preserves Category identity/mappings.
- **Delete** physically removes one selected Master Category.

### Delete v1 scope

Only **one selected Category** is deleted per operation.

Deleting an entire subtree/branch in one action is deferred and requires a separate
future decision/capability.

### Products

Products are never deleted with a Category.

If Products are directly assigned to the Category being deleted, the merchant must
choose explicitly:

- **Без категорії**; or
- another existing destination Category.

A destination Category must belong to the same workspace, be effectively active, and
not be the Category being deleted. The operation never silently chooses a replacement.

If `Без категорії` is chosen, affected Products end with `category_id = NULL`. They
remain valid Master Products but may be incomplete/Partial for downstream classification
until the merchant assigns the required target classification.

If the deleted Category had a non-default B2B `stock_display_threshold`, the delete
confirmation must warn that uncategorized Products use the current B2B fallback threshold
`10`.

### Children

Direct child Categories are reparented to the deleted Category's parent. If the deleted
Category is a root, its direct children become roots.

Deletion must not accidentally make a previously hidden branch visible. If reparenting
an active child would make it effectively active solely because the deleted ancestor was
inactive, the child must be made inactive. No redundant state change is required when
the new ancestor chain already keeps it effectively inactive.

### Connector mappings and remote systems

Local connector mappings owned by the deleted Master Category are removed inside the
governed delete transaction.

Deleting a Master Category:

- never deletes a provider Category;
- never directly changes Magento/provider category membership;
- never treats disappearance of Master Category as destructive remote intent;
- never deletes `AdobeProductCategoryAssignment` ownership/reconciliation ledger rows;
- does not delete Product-level provider classification overrides.

If a Product is moved to a destination Master Category whose required Magento mapping
is absent, the confirmation must warn that affected Products will remain Partial until
classification is resolved.

A modern Preview whose classification/mapping revisions no longer match is stale and
must be regenerated before Live.

"Uncategorized in Magento" is not currently an expressible successful desired state:
an unresolved modern Magento classification remains Partial. Supporting an explicit
empty provider-category set is a separate future decision.

### Confirmation and transaction

Before deletion the UI shows the impact counts, including at least Products directly
assigned, direct child Categories and connector mappings.

If Products are affected, destructive confirmation requires explicit typed confirmation
(for example `ВИДАЛИТИ`) in addition to the impact summary.

The delete writer must authorize the exact workspace, use one database transaction,
lock/re-check affected state inside the transaction, move/unassign Products according to
the explicit choice, reparent/deactivate children according to the rules above, remove
local mappings, delete the Category last, and fail closed on concurrent or cross-workspace
change.

Soft delete/restore is not introduced in v1.

## Mandatory precondition before Delete UI

Physical Category Delete must **not** be enabled until the legacy Magento relation
compatibility path is hardened.

Current legacy behavior on the resolved base contains regression test
`null_local_category_removes_only_proven_managed_relation`, which expects a managed
Magento category relation DELETE when `category_id = NULL`.

That legacy behavior is not a Product decision and conflicts with the invariant:

> **MasterCategoryDeleteNeverCausesProviderWrite**

The required preceding ORANGE fix is deliberately narrow:

- when the legacy execution path has `category_id = NULL` **and** managed category
  ledger rows exist for the trusted Product/Variant subject, fail closed as Partial
  before any category POST/DELETE;
- preserve legacy behavior for a Product with no category and no managed ledger rows;
- retain ledger rows;
- add regression evidence for both cases.

Modern Live admission already validates both category-mapping and Adobe classification
revisions against Preview. The legacy hardening remains required because persisted
legacy execution input must not be able to turn a Master-data edit into consequential
provider DELETE.

## Delivery order

1. **Docs-only decision** — this document and authoritative references.
2. **Separate ORANGE Magento safety PR** — close the legacy NULL-category destructive
   path with focused regression tests.
3. **Separate Category Delete campaign** — transaction/writer/UI/tests for deleting one
   Category.
4. **Future B2B assortment** — explicit Product selection for native B2B.
5. **Future second-channel classification campaign** — implement that provider's actual
   classification semantics, then standardize only abstractions proven to repeat.

No production implementation is authorized by this documentation campaign itself.

## Architecture invariants

> **Adding a new publication target must not require reshaping Master Product taxonomy
> to mirror that target's taxonomy.**

> **Deleting or clearing Master classification is never by itself authorization for a
> destructive provider classification WRITE.**
