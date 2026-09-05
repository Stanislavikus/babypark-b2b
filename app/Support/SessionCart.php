<?php

namespace App\Support;

use App\Exceptions\Pricing\PriceNotAvailableException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Pricing\PriceResolver;

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
     * Cart lines with customer-specific resolved prices for display.
     *
     * @return list<array{
     *     variant_id: int,
     *     name: string,
     *     sku: string,
     *     quantity: int,
     *     price_available: bool,
     *     gross_price: ?float,
     *     regular_net_price: ?float,
     *     sale_price: ?float,
     *     line_total: ?float,
     *     price_label: string,
     * }>
     */
    public static function linesForCustomer(Customer $customer): array
    {
        return array_map(
            fn (array $line) => self::publicLineFromResolved($line),
            self::resolvedLinesForCustomer($customer),
        );
    }

    /**
     * Resolved cart lines used by display and order creation.
     *
     * @return list<array{
     *     variant_id: int,
     *     variant: ProductVariant,
     *     name: string,
     *     sku: string,
     *     quantity: int,
     *     price_available: bool,
     *     gross_price: ?float,
     *     regular_net_price: ?float,
     *     sale_price: ?float,
     *     line_total: ?float,
     * }>
     */
    public static function resolvedLinesForCustomer(Customer $customer): array
    {
        $cart = self::all();

        if ($cart === []) {
            return [];
        }

        $variantIds = array_keys($cart);
        $resolver = app(PriceResolver::class);

        $variants = ProductVariant::query()
            ->with('product')
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($cart as $item) {
            $variant = $variants->get($item['variant_id']);

            if (! $variant) {
                continue;
            }

            $quantity = $item['quantity'];
            $priceAvailable = true;
            $grossPrice = null;
            $regularNetPrice = null;
            $salePrice = null;
            $lineTotal = null;

            try {
                $resolved = $resolver->resolveForCustomer($variant, $customer, $quantity);
                $grossPrice = $resolved->grossPrice;
                $regularNetPrice = $resolved->regularNetPrice;
                $salePrice = $resolved->salePrice;
                $lineTotal = round($grossPrice * $quantity, 2);
            } catch (PriceNotAvailableException) {
                $priceAvailable = false;
            }

            $lines[] = [
                'variant_id' => $variant->id,
                'variant' => $variant,
                'name' => $variant->product->name,
                'sku' => $variant->sku,
                'quantity' => $quantity,
                'price_available' => $priceAvailable,
                'gross_price' => $grossPrice,
                'regular_net_price' => $regularNetPrice,
                'sale_price' => $salePrice,
                'line_total' => $lineTotal,
            ];
        }

        return $lines;
    }

    public static function totalWithVat(Customer $customer): float
    {
        return array_sum(
            array_map(
                fn (array $line) => $line['line_total'] ?? 0.0,
                self::resolvedLinesForCustomer($customer),
            )
        );
    }

    public static function hasUnavailablePrices(Customer $customer): bool
    {
        foreach (self::resolvedLinesForCustomer($customer) as $line) {
            if (! $line['price_available']) {
                return true;
            }
        }

        return false;
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

    /**
     * @param  array{
     *     variant_id: int,
     *     name: string,
     *     sku: string,
     *     quantity: int,
     *     price_available: bool,
     *     gross_price: ?float,
     *     regular_net_price: ?float,
     *     sale_price: ?float,
     *     line_total: ?float,
     * }  $line
     * @return array{
     *     variant_id: int,
     *     name: string,
     *     sku: string,
     *     quantity: int,
     *     price_available: bool,
     *     gross_price: ?float,
     *     regular_net_price: ?float,
     *     sale_price: ?float,
     *     line_total: ?float,
     *     price_label: string,
     * }
     */
    private static function publicLineFromResolved(array $line): array
    {
        return [
            'variant_id' => $line['variant_id'],
            'name' => $line['name'],
            'sku' => $line['sku'],
            'quantity' => $line['quantity'],
            'price_available' => $line['price_available'],
            'gross_price' => $line['gross_price'],
            'regular_net_price' => $line['regular_net_price'],
            'sale_price' => $line['sale_price'],
            'line_total' => $line['line_total'],
            'price_label' => $line['price_available']
                ? '₴ '.number_format((float) $line['gross_price'], 2, ',', ' ')
                : 'Ціна не задана',
        ];
    }
}
