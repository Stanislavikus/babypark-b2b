<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Support\AdminAvailabilityPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAvailabilityPresenterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array{warehouse_name?: string, quantity: int, reserved?: int, expected_date?: string|null}>  $stockRows
     */
    private function createProductWithStocks(array $stockRows): Product
    {
        $product = Product::create([
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'SKU-'.Str::random(8),
            'name' => 'Test Product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'VAR-'.Str::random(8),
            'is_active' => true,
        ]);

        foreach ($stockRows as $index => $row) {
            Stock::create([
                'variant_id' => $variant->id,
                'warehouse_name' => $row['warehouse_name'] ?? 'WH-'.$index,
                'quantity' => $row['quantity'],
                'reserved' => $row['reserved'] ?? 0,
                'expected_date' => $row['expected_date'] ?? null,
            ]);
        }

        return $product->load('variants.stocks');
    }

    private function bucketViaSql(Product $product): string
    {
        $netQty = AdminAvailabilityPresenter::netQtySql();
        $expectedDate = AdminAvailabilityPresenter::earliestExpectedDateSql();

        $result = Product::query()
            ->selectRaw("({$netQty}) as net_qty, ({$expectedDate}) as earliest_expected")
            ->where('id', $product->id)
            ->first();

        if ($result->net_qty > 0) {
            return AdminAvailabilityPresenter::BUCKET_IN_STOCK;
        }

        if ($result->earliest_expected !== null) {
            return AdminAvailabilityPresenter::BUCKET_EXPECTED;
        }

        return AdminAvailabilityPresenter::BUCKET_OUT_OF_STOCK;
    }

    public function test_single_stock_row_with_net_positive_quantity_is_in_stock(): void
    {
        $product = $this->createProductWithStocks([
            ['quantity' => 10, 'reserved' => 3],
        ]);

        $this->assertSame(AdminAvailabilityPresenter::BUCKET_IN_STOCK, AdminAvailabilityPresenter::bucket($product));
        $this->assertSame('У наявності: 7 шт', AdminAvailabilityPresenter::adminLabel($product));
        $this->assertSame(AdminAvailabilityPresenter::BUCKET_IN_STOCK, $this->bucketViaSql($product));
    }

    public function test_single_stock_row_fully_reserved_without_expected_date_is_out_of_stock(): void
    {
        $product = $this->createProductWithStocks([
            ['quantity' => 5, 'reserved' => 10],
        ]);

        $this->assertSame(AdminAvailabilityPresenter::BUCKET_OUT_OF_STOCK, AdminAvailabilityPresenter::bucket($product));
        $this->assertSame('Немає в наявності', AdminAvailabilityPresenter::adminLabel($product));
        $this->assertSame(AdminAvailabilityPresenter::BUCKET_OUT_OF_STOCK, $this->bucketViaSql($product));
    }

    public function test_single_stock_row_fully_reserved_with_expected_date_is_expected(): void
    {
        $product = $this->createProductWithStocks([
            ['quantity' => 3, 'reserved' => 10, 'expected_date' => '2026-08-01'],
        ]);

        $this->assertSame(AdminAvailabilityPresenter::BUCKET_EXPECTED, AdminAvailabilityPresenter::bucket($product));
        $this->assertSame('Очікується 01.08', AdminAvailabilityPresenter::adminLabel($product));
        $this->assertSame(AdminAvailabilityPresenter::BUCKET_EXPECTED, $this->bucketViaSql($product));
    }

    public function test_over_reserved_row_and_net_positive_row_classify_consistently_via_php_and_sql(): void
    {
        // Row 1: qty=10, reserved=15 → per-row net = 0
        // Row 2: qty=5,  reserved=0  → per-row net = 5
        // PHP path: max(0,-5) + max(0,5) = 5 → У наявності
        // SQL path (fixed): SUM(CASE WHEN net > 0 THEN net ELSE 0 END) = 5 → У наявності
        $product = $this->createProductWithStocks([
            ['warehouse_name' => 'WH-A', 'quantity' => 10, 'reserved' => 15],
            ['warehouse_name' => 'WH-B', 'quantity' => 5, 'reserved' => 0],
        ]);

        $phpBucket = AdminAvailabilityPresenter::bucket($product);
        $sqlBucket = $this->bucketViaSql($product);

        $this->assertSame(AdminAvailabilityPresenter::BUCKET_IN_STOCK, $phpBucket);
        $this->assertSame($phpBucket, $sqlBucket);
        $this->assertSame('У наявності: 5 шт', AdminAvailabilityPresenter::adminLabel($product));
    }
}
