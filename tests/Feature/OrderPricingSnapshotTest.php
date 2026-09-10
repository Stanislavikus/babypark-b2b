<?php

namespace Tests\Feature;

use App\Exceptions\Orders\OrderMissingPriceException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderCreator;
use App\Support\SessionCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class OrderPricingSnapshotTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_placed_order_snapshots_resolved_price_immune_to_later_price_list_changes(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $item = $this->createPriceListItem($list, $variant, 100.00, 1, null, 20);

        SessionCart::add($variant->id, 2);

        $order = app(OrderCreator::class)->createFromCart($customer);

        $this->assertCount(1, $order->items);
        $this->assertSame(120.0, (float) $order->items->first()->price_with_vat);
        $this->assertSame(240.0, (float) $order->items->first()->total);

        $item->update(['price' => 200.00]);

        $order->refresh();
        $this->assertSame(120.0, (float) $order->items->first()->price_with_vat);
        $this->assertSame(240.0, (float) $order->items->first()->total);
    }

    public function test_order_creation_refuses_lines_without_resolved_price(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        SessionCart::add($variant->id, 1);

        $this->expectException(OrderMissingPriceException::class);

        try {
            app(OrderCreator::class)->createFromCart($customer);
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, OrderItem::query()->count());
        }
    }

    public function test_session_cart_does_not_assign_zero_price_when_unavailable(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        SessionCart::add($variant->id, 1);

        $lines = SessionCart::linesForCustomer($customer);

        $this->assertCount(1, $lines);
        $this->assertFalse($lines[0]['price_available']);
        $this->assertNull($lines[0]['gross_price']);
        $this->assertNull($lines[0]['line_total']);
        $this->assertSame('Ціна не задана', $lines[0]['price_label']);
    }

    public function test_session_cart_preserves_regular_and_sale_price_fields(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00, 1, 80.00, 20);

        SessionCart::add($variant->id, 1);

        $lines = SessionCart::linesForCustomer($customer);

        $this->assertTrue($lines[0]['price_available']);
        $this->assertSame(100.0, $lines[0]['regular_net_price']);
        $this->assertSame(80.0, $lines[0]['sale_price']);
        $this->assertSame(96.0, $lines[0]['gross_price']);
    }
}
