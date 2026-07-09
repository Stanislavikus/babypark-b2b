<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\Orders\EmptyCartException;
use App\Exceptions\Orders\OrderMissingPriceException;
use App\Models\Contractor;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Pricing\PriceResolver;
use App\Support\SessionCart;
use Illuminate\Support\Facades\DB;

class OrderCreator
{
    public function __construct(
        private readonly PriceResolver $priceResolver,
    ) {}

    /**
     * Create an order from the session cart, snapshotting resolved prices at submission time.
     *
     * @throws EmptyCartException
     * @throws OrderMissingPriceException
     */
    public function createFromCart(Contractor $contractor, ?User $user = null, ?string $comment = null): Order
    {
        $cart = SessionCart::all();

        if ($cart === []) {
            throw new EmptyCartException('Cannot place an order from an empty cart.');
        }

        $lines = SessionCart::resolvedLinesForContractor($contractor);

        foreach ($lines as $line) {
            if (! $line['price_available']) {
                throw new OrderMissingPriceException(
                    "Cannot place order: variant {$line['variant_id']} has no resolved price."
                );
            }
        }

        return DB::transaction(function () use ($contractor, $user, $comment, $lines): Order {
            $totalNet = 0.0;
            $totalGross = 0.0;
            $currency = (string) config('pricing.default_currency', 'UAH');

            $order = Order::query()->create([
                'contractor_id' => $contractor->id,
                'user_id' => $user?->id,
                'status' => OrderStatus::New,
                'total' => 0,
                'total_with_vat' => 0,
                'currency' => $currency,
                'comment' => $comment,
            ]);

            foreach ($lines as $line) {
                $variant = $line['variant'];
                $quantity = $line['quantity'];
                $resolved = $this->priceResolver->resolveForContractor($variant, $contractor, $quantity);
                $currency = $resolved->currency;

                $lineTotalGross = round($resolved->grossPrice * $quantity, 2);
                $lineTotalNet = round($resolved->effectiveNetPrice * $quantity, 2);
                $totalNet += $lineTotalNet;
                $totalGross += $lineTotalGross;

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->product->name,
                    'attributes' => $variant->attributes,
                    'quantity' => $quantity,
                    'price' => $resolved->effectiveNetPrice,
                    'price_with_vat' => $resolved->grossPrice,
                    'total' => $lineTotalGross,
                ]);
            }

            $order->update([
                'total' => round($totalNet, 2),
                'total_with_vat' => round($totalGross, 2),
                'currency' => $currency,
            ]);

            SessionCart::clear();

            return $order->fresh(['items']);
        });
    }
}
