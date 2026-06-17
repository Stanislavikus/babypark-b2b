<?php

namespace App\Support;

use App\Models\Order;

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
                'quantity'   => $quantity,
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
