<?php

namespace App\Support;

use App\Models\Contractor;
use App\Models\Order;
use App\Models\Price;
use App\Models\ProductVariant;

/**
 * Session-based cart for the B2B cabinet.
 *
 * Persists in the PHP session so it survives navigation between
 * /catalog, /dashboard, and any other cabinet pages.
 *
 * Structure stored in session:
 *   [ variant_id => ['variant_id' => int, 'quantity' => int], ... ]
 */
class SessionCart
{
    const SESSION_KEY = 'b2b_cart';

    /**
     * Add or merge a variant into the cart.
     * If the variant is already in the cart, quantities are summed.
     */
    public static function add(int $variantId, int $quantity): void
    {
        $cart = self::all();

        if (isset($cart[$variantId])) {
            $cart[$variantId]['quantity'] += $quantity;
        } else {
            $cart[$variantId] = [
                'variant_id' => $variantId,
                'quantity' => $quantity,
            ];
        }

        session()->put(self::SESSION_KEY, $cart);
    }

    /**
     * Return all cart items keyed by variant_id.
     *
     * @return array<int, array{variant_id: int, quantity: int}>
     */
    public static function all(): array
    {
        return session()->get(self::SESSION_KEY, []);
    }

    /** Number of distinct variant lines in the cart. */
    public static function count(): int
    {
        return count(self::all());
    }

    /** Total quantity across all lines. */
    public static function totalQuantity(): int
    {
        return array_sum(array_column(self::all(), 'quantity'));
    }

    /**
     * Cart lines with contractor-specific prices for display.
     *
     * @return list<array{
     *     variant_id: int,
     *     name: string,
     *     sku: string,
     *     quantity: int,
     *     price_with_vat: float,
     *     line_total: float,
     * }>
     */
    public static function linesForContractor(Contractor $contractor): array
    {
        $cart = self::all();

        if ($cart === []) {
            return [];
        }

        $variantIds = array_keys($cart);

        $variants = ProductVariant::query()
            ->with('product')
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');

        $prices = Price::query()
            ->where('contractor_id', $contractor->id)
            ->whereIn('variant_id', $variantIds)
            ->get()
            ->keyBy('variant_id');

        $lines = [];

        foreach ($cart as $item) {
            $variant = $variants->get($item['variant_id']);

            if (! $variant) {
                continue;
            }

            $price = (float) ($prices->get($variant->id)?->price_with_vat ?? 0);

            $lines[] = [
                'variant_id' => $variant->id,
                'name' => $variant->product->name,
                'sku' => $variant->sku,
                'quantity' => $item['quantity'],
                'price_with_vat' => $price,
                'line_total' => $price * $item['quantity'],
            ];
        }

        return $lines;
    }

    public static function totalWithVat(Contractor $contractor): float
    {
        return array_sum(array_column(self::linesForContractor($contractor), 'line_total'));
    }

    /** Remove a single variant line. */
    public static function remove(int $variantId): void
    {
        $cart = self::all();
        unset($cart[$variantId]);
        session()->put(self::SESSION_KEY, $cart);
    }

    /** Empty the entire cart. */
    public static function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Add all items from an existing order into the cart.
     * Used by "Repeat order" on the dashboard — does NOT create a new order.
     */
    public static function addFromOrder(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            self::add($item->variant_id, $item->quantity);
        }
    }
}
