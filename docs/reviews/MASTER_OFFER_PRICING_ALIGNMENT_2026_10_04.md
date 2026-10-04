# Master Offer Pricing Alignment — 2026-10-04

**Status:** [Resolved — 2026-10-04] — Product-owner semantics confirmed; Sonnet 5 independent arbitration reviewed by Lead AI.

## Goal

Expose a merchant-facing Master Product Card Offer editor with the semantic roles **SELL / COMPARE_AT / COST** while preserving the existing Pricing Foundation, PriceResolver, order snapshots, workspace isolation and moduleless Magento V1 execution.

## Existing authoritative runtime

- `PriceListItem.price` is the regular/base net price.
- `PriceListItem.sale_price` is the nullable promotional net price.
- `ResolvedPrice.effectiveNetPrice = sale_price ?? price`.
- `ProductVariant.cost_price` is canonical internal COST.
- `ProductVariant.recommended_retail_price_cache` is RRP/reference information only and is never COMPARE_AT.
- `ProductVariant.base_price_cache` is PriceResolver's final fallback and is not a second canonical Master writer.
- Magento Product execution consumes price through `PriceResolver::resolveDefault()`.

## Merchant semantics

The Master card exposes semantic roles, not persistence column names:

### Normal offer

- SELL = current selling price.
- COMPARE_AT = null.
- Persistence: `PriceListItem.price = SELL`, `sale_price = null`.

### Sale / promotion

- SELL = current discounted selling price.
- COMPARE_AT = prior/regular price.
- Requirement: `COMPARE_AT > SELL`.
- Persistence: `PriceListItem.price = COMPARE_AT`, `sale_price = SELL`.

### Removing compare-at

Clearing COMPARE_AT does not silently restore some historical regular price. The current semantic SELL becomes the normal price:

- Persistence: `PriceListItem.price = SELL`, `sale_price = null`.

This keeps the Master UI Shopify-like: the merchant edits current Price + optional Compare-at Price; storage adaptation remains internal.

## Canonical Master writer

For a source-neutral/manual Product, Master edits exactly the workspace **active default PriceList** row for the concrete Variant at `quantity_min = 1`.

The Master card does not edit:

- customer-assigned price lists;
- quantity tiers above 1;
- CustomerGroup/PricingRule;
- RRP;
- channel-native pricing settings.

No new pricing columns or migration are required.

## base_price_cache

Master Offer editing must not mirror ordinary edits into `ProductVariant.base_price_cache`.

The cache remains resolver fallback only. Once a default-list `quantity_min=1` item exists, PriceResolver naturally prefers it. Duplicating writes would create two canonical owners.

## COST

COST is written only to `ProductVariant.cost_price`.

## VAT

Canonical SELL / COMPARE_AT storage remains net/VAT-exclusive. The Master editor writes net values. Gross values may be shown read-only through existing tax/default presentation. The editor does not create a new gross→net tax contract and does not change item-specific VAT in this slice.

## Authority

Source-owned / 1C Products remain read-only for Master Offer mutation until a separate approved authority/writeback contract exists.

## Integrity and concurrency

The Master writer must:

1. authorize `manage_products`;
2. lock workspace/product/variant and the active default price list / qty=1 item in deterministic order;
3. use stale/CAS protection against the values the merchant reviewed;
4. reject SELL <= 0;
5. reject COMPARE_AT when `COMPARE_AT <= SELL`;
6. preserve workspace ownership;
7. create or update only the unique `(workspace_id, price_list_id, product_variant_id, quantity_min=1)` item;
8. prevent new GAP-014-invalid data (`sale_price >= price`) at the domain writer boundary.

Existing legacy-invalid rows are not silently rewritten and PriceResolver behavior is not changed by this slice.

## Simple / configurable UX

- Simple Product hides its internal Variant and presents one Offer editor.
- Configurable Product edits concrete explicit Variants only; no Cartesian assumptions.
- The same domain writer is used for both.
- Batch application may reuse the same writer over explicitly selected current Variants; it must not introduce a second pricing algorithm.

## Connector / order compatibility

No Magento-specific price persistence is added. Existing Product execution reads the changed default price via PriceResolver. Order price snapshot semantics remain unchanged.

## Explicit exclusions

- no RRP↔COMPARE_AT conflation;
- no `base_price_cache` mirroring;
- no PriceResolver priority redesign;
- no channel-specific Master price columns;
- no Safe Sync dependency;
- no customer/tier price-list editing from the basic Product Card.
