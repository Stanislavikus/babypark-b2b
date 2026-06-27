# Data fields registry — pricing

This registry tracks where price data lives and which code reads it. Pricing was migrated
from scattered columns to a unified, typed structure (`price_types` + `product_prices`) read
exclusively through `App\Services\PriceResolver`.

> Rule: nothing reads `product_prices` for price **values** except `PriceResolver`. DB-level
> sorting joins `product_prices` directly (it cannot route through PHP), but reads the same
> source so list order matches what the resolver displays.

## Tables

### `price_types` — fixed lookup (seeded with exactly 3 rows)

| code                 | name (uk)        | gmc_term             | is_contractor_specific |
| -------------------- | ---------------- | -------------------- | ---------------------- |
| `contract_price`     | Ціна контрагента | `null`               | `true`                 |
| `list_price`         | РРЦ              | `null`               | `false`                |
| `cost_of_goods_sold` | Вхідна ціна      | `cost_of_goods_sold` | `false`                |

`gmc_term` is populated **only** when Google has a literally-named attribute matching the
concept directly (`cost_of_goods_sold`). `list_price`'s value would feed Google's `price`
field via a future export connector, but that is a channel-mapping decision — not this type's
identity — so `gmc_term` stays `null` here.

### `product_prices` — one row per (variant, contractor?, type)

| column          | notes                                                                       |
| --------------- | --------------------------------------------------------------------------- |
| `variant_id`    | FK → `product_variants`                                                      |
| `contractor_id` | FK → `contractors`, **null** for non-contractor-specific types              |
| `price_type_id` | FK → `price_types`                                                           |
| `value`         | `decimal(12,2)`; read as a string, normalized with bcmath (never float)     |
| `currency`      | default `UAH`                                                                |
| `source`        | `1c` / `manual` / `import` / `calculated` — where **this row's** value came from |
| `contractor_key`| STORED generated `COALESCE(contractor_id, 0)` — see uniqueness note below   |

**Uniqueness / MySQL NULL gotcha:** MySQL (and SQLite) treat `NULL` in a unique index as
distinct, so a plain unique on `(variant_id, contractor_id, price_type_id)` would not stop
duplicate contractor-less rows (`list_price`, `cost_of_goods_sold`). The unique index is on
`(variant_id, contractor_key, price_type_id)` where `contractor_key` coalesces `null → 0`,
restoring real uniqueness on both engines. Locked in by
`PriceResolverTest::test_second_contractorless_row_for_same_variant_and_type_is_rejected`.

## `PriceResolver` API (`app/Services/PriceResolver.php`)

| method                                                | returns                | replaces                       |
| ----------------------------------------------------- | ---------------------- | ------------------------------ |
| `contractPrice(ProductVariant, Contractor)`           | `?string` (з ПДВ)      | `prices.price_with_vat` reads  |
| `listPrice(ProductVariant)`                           | `?string` (РРЦ)        | `prices.recommended_retail_price` reads |
| `costOfGoodsSold(ProductVariant)`                     | `?string`              | (`products.cost_price` reads)  |
| `margin(ProductVariant)`                              | `?string` list − cost  | ad-hoc margin math             |
| `minContractPriceAcrossVariants(Product, Contractor)` | `?ProductVariant`      | inline "cheapest variant" logic |
| `maxListPriceAcrossVariants(Product)`                 | `?string`              | inline max-RRP logic           |

`minContractPriceAcrossVariants` returns the **variant** (not a bare price): callers needing
the price call `contractPrice()` on the returned variant, so "which variant is cheapest" and
"what is that variant's price" always come from one resolved object. Locked in by
`PriceResolverTest::test_catalog_price_and_orderable_variant_agree_with_cart_target`.

## Call sites (all read via `PriceResolver`)

| location                                              | uses                                              |
| ----------------------------------------------------- | ------------------------------------------------- |
| `app/Livewire/Cabinet/Catalog.php`                    | `minContractPriceAcrossVariants`, `contractPrice`, `maxListPriceAcrossVariants` (+ sort joins) |
| `resources/views/livewire/cabinet/catalog.blade.php`  | resolved `maxMyPrice` / `maxRrp` from component   |
| `app/Livewire/Cabinet/ProductDetail.php` (+ blade)    | `contractPrice`, `listPrice` per variant          |
| `app/Filament/Resources/ProductResource.php`          | `maxListPriceAcrossVariants` (РРЦ column + infolist + sort join) |
| `database/seeders/B2BSeeder.php`                       | writes `product_prices` for all three types       |

## Removed columns (no longer exist)

- `prices.price_with_vat` → `product_prices` type `contract_price`
- `prices.recommended_retail_price` → `product_prices` type `list_price` (contractor-less)
- `products.cost_price` → `product_prices` type `cost_of_goods_sold` (was never present in this
  codebase; the data migration handles it defensively if added later)

`prices` still holds the net `price`, `vat_rate`, `min_quantity`, `currency`, and remains the
catalog **visibility** check (a contractor sees a product only where a `prices` row exists).
`order_items.price`/`order_items.price_with_vat` are per-order **snapshots** and are unaffected.

## Migration / upgrade path

1. `2026_06_27_000001_create_price_types_table` — creates + seeds the 3 types.
2. `2026_06_27_000002_create_product_prices_table` — table + generated-column unique.
3. `2026_06_27_000003_migrate_prices_to_product_prices` — one-time data move; surfaces (logs +
   STDERR) any variant whose RRP diverges across contractors before collapsing to a single
   contractor-less row (MAX).
4. `2026_06_27_000004_drop_replaced_columns_from_prices_table` — drops the replaced columns.
