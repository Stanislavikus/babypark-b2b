<?php

namespace Tests\Unit;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Support\AdminAvailabilityPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAvailabilityFixtures;
use Tests\TestCase;

class AdminAvailabilityPresenterTest extends TestCase
{
    use CreatesAvailabilityFixtures;
    use RefreshDatabase;

    private function bucketViaSql($product): string
    {
        $netQty = AdminAvailabilityPresenter::netQtySql();
        $expectedDate = AdminAvailabilityPresenter::earliestExpectedDateSql();

        $result = $product->newQuery()
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

    private function createCustomer(): Customer
    {
        return Customer::query()->create([
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Test Customer',
            'short_name' => 'TC',
            'login' => 'test-'.Str::random(6),
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    public function test_variant_with_positive_cache_and_no_reservations_is_in_stock(): void
    {
        $product = $this->createProductWithStocks([
            ['quantity' => 10],
        ], availableCache: 10);

        $this->assertSame(AdminAvailabilityPresenter::BUCKET_IN_STOCK, AdminAvailabilityPresenter::bucket($product));
        $this->assertSame('У наявності: 10 шт', AdminAvailabilityPresenter::adminLabel($product));
        $this->assertSame(AdminAvailabilityPresenter::BUCKET_IN_STOCK, $this->bucketViaSql($product));
    }

    public function test_pending_reservation_reduces_net_availability_to_out_of_stock(): void
    {
        $product = $this->createProductWithStocks([
            ['quantity' => 5],
        ], availableCache: 5);

        $variant = $product->variants->first();

        Reservation::create([
            'workspace_id' => $variant->workspace_id,
            'customer_id' => $this->createCustomer()->id,
            'variant_id' => $variant->id,
            'quantity' => 10,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->addHour(),
        ]);

        $this->assertSame(AdminAvailabilityPresenter::BUCKET_OUT_OF_STOCK, AdminAvailabilityPresenter::bucket($product));
        $this->assertSame(AdminAvailabilityPresenter::BUCKET_OUT_OF_STOCK, $this->bucketViaSql($product));
    }

    public function test_zero_cache_with_expected_date_is_expected_bucket(): void
    {
        $product = $this->createProductWithStocks([
            ['quantity' => 0, 'expected_date' => '2026-08-01'],
        ], availableCache: 0);

        $this->assertSame(AdminAvailabilityPresenter::BUCKET_EXPECTED, AdminAvailabilityPresenter::bucket($product));
        $this->assertSame('Очікується 01.08', AdminAvailabilityPresenter::adminLabel($product));
        $this->assertSame(AdminAvailabilityPresenter::BUCKET_EXPECTED, $this->bucketViaSql($product));
    }

    public function test_multiple_variant_net_totals_match_sql_path(): void
    {
        $workspace = $this->defaultWorkspace();

        $product = Product::create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-MULTI',
            'name' => 'Multi Variant',
            'is_active' => true,
        ]);

        foreach ([['cache' => 10, 'reserve' => 15], ['cache' => 5, 'reserve' => 0]] as $index => $config) {
            $variant = ProductVariant::create([
                'workspace_id' => $workspace->id,
                'product_id' => $product->id,
                'onec_guid' => (string) Str::uuid(),
                'sku' => 'VAR-'.$index,
                'is_active' => true,
                'available_quantity_cache' => $config['cache'],
                'availability_status' => 'in_stock',
            ]);

            if ($config['reserve'] > 0) {
                Reservation::create([
                    'workspace_id' => $workspace->id,
                    'customer_id' => $this->createCustomer()->id,
                    'variant_id' => $variant->id,
                    'quantity' => $config['reserve'],
                    'status' => ReservationStatus::Pending,
                    'expires_at' => now()->addHour(),
                ]);
            }
        }

        $product = $product->load('variants');

        $phpBucket = AdminAvailabilityPresenter::bucket($product);
        $sqlBucket = $this->bucketViaSql($product);

        $this->assertSame(AdminAvailabilityPresenter::BUCKET_IN_STOCK, $phpBucket);
        $this->assertSame($phpBucket, $sqlBucket);
        $this->assertSame('У наявності: 5 шт', AdminAvailabilityPresenter::adminLabel($product));
    }
}
