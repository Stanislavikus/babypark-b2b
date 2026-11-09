<?php

namespace Tests\Unit;

use App\Models\PriceList;
use App\Services\Pricing\PriceResolutionSnapshot;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\ProductPricingSummary;
use App\Support\CatalogRowData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PricingQueryProfileTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_duplicate_heavy_fixture_reduces_sql_and_dedupes_executions(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.0);

        $product = $variant->product->load(['variants.stocks', 'category']);
        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());

        PriceResolver::resetStandardResolutionExecutions();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $summary = app(ProductPricingSummary::class);
        $summary->resolveVariantDisplay($variant, $customer, 1, $snapshot);
        $summary->resolveVariantDisplay($variant, $customer, 1, $snapshot);
        $summary->resolveVariantDisplay($variant, $customer, 1, $snapshot);
        CatalogRowData::forProduct($product, $customer, 1, $snapshot);

        $withDedupQueries = count(DB::getQueryLog());
        $withDedupExecutions = PriceResolver::standardResolutionExecutions();
        $this->assertSame(1, $withDedupExecutions);
        $this->assertGreaterThan(0, $withDedupQueries);

        PriceResolver::resetStandardResolutionExecutions();
        DB::flushQueryLog();

        $summary->resolveVariantDisplay($variant, $customer, 1);
        $summary->resolveVariantDisplay($variant, $customer, 1);
        $summary->resolveVariantDisplay($variant, $customer, 1);
        CatalogRowData::forProduct($product, $customer, 1);

        $baselineQueries = count(DB::getQueryLog());
        $baselineExecutions = PriceResolver::standardResolutionExecutions();

        $this->assertGreaterThan($withDedupExecutions, $baselineExecutions);
        $this->assertLessThan($baselineQueries, $withDedupQueries);
    }

    public function test_control_fixture_does_not_increase_queries(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.0);
        $product = $variant->product->load(['variants.stocks', 'category']);

        PriceResolver::resetStandardResolutionExecutions();
        DB::flushQueryLog();
        DB::enableQueryLog();
        CatalogRowData::forProduct($product, $customer, 1);
        $baselineQueries = count(DB::getQueryLog());
        $baselineExecutions = PriceResolver::standardResolutionExecutions();

        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());
        PriceResolver::resetStandardResolutionExecutions();
        DB::flushQueryLog();
        CatalogRowData::forProduct($product, $customer, 1, $snapshot);
        $dedupQueries = count(DB::getQueryLog());
        $dedupExecutions = PriceResolver::standardResolutionExecutions();

        $this->assertLessThanOrEqual($baselineExecutions, $dedupExecutions);
        $this->assertLessThanOrEqual($baselineQueries, $dedupQueries);
    }

    public function test_customer_list_miss_fallback_not_repeated_on_cache_hit(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('is_default', true)
            ->first();
        $this->createPriceListItem($defaultList, $variant, 55.50);

        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());
        $resolver = app(PriceResolver::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolver->resolveForCustomer($variant, $customer, 1, snapshot: $snapshot);
        $firstQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $resolver->resolveForCustomer($variant, $customer, 1, snapshot: $snapshot);
        $secondQueries = count(DB::getQueryLog());

        $this->assertSame(0, $secondQueries);
        $this->assertGreaterThan(0, $firstQueries);
    }
}
