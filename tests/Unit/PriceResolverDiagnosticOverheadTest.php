<?php

namespace Tests\Unit;

use App\Models\PriceList;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\Resolution\PriceResolutionDiagnosticCollector;
use App\Support\Workspace\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceResolverDiagnosticOverheadTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    /**
     * Pre-refactor SQL query baselines captured on develop (2026-07-13).
     */
    private const BASELINE_CUSTOMER_ASSIGNED_MATCH = 3;

    private const BASELINE_WORKSPACE_DEFAULT_MATCH = 3;

    private const BASELINE_BASE_CACHE_MATCH = 2;

    private const BASELINE_TIER_MATCH_QTY15 = 3;

    public function test_standard_mode_does_not_instantiate_diagnostic_collector(): void
    {
        PriceResolutionDiagnosticCollector::resetInstantiationCount();

        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        app(PriceResolver::class)->resolveForCustomer($variant, $customer, 1);

        $this->assertSame(
            0,
            PriceResolutionDiagnosticCollector::$instantiationCount,
            'Standard mode must not instantiate PriceResolutionDiagnosticCollector.',
        );
    }

    public function test_standard_mode_query_count_matches_pre_refactor_baseline(): void
    {
        $resolver = app(PriceResolver::class);

        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        app(WorkspaceContext::class)->id();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolver->resolveForCustomer($variant, $customer, 1);
        $this->assertSame(self::BASELINE_CUSTOMER_ASSIGNED_MATCH, count(DB::getQueryLog()));

        $customer2 = $this->createCustomer();
        $variant2 = $this->createVariant();
        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant2->workspace_id)
            ->where('is_default', true)
            ->firstOrFail();
        $this->createPriceListItem($defaultList, $variant2, 55.50);

        app(WorkspaceContext::class)->id();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolver->resolveForCustomer($variant2, $customer2, 1);
        $this->assertSame(self::BASELINE_WORKSPACE_DEFAULT_MATCH, count(DB::getQueryLog()));

        $variant3 = $this->createVariant(basePriceCache: 42.00);
        app(WorkspaceContext::class)->id();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolver->resolveDefault($variant3, 1);
        $this->assertSame(self::BASELINE_BASE_CACHE_MATCH, count(DB::getQueryLog()));

        $customer4 = $this->createCustomer();
        $variant4 = $this->createVariant();
        $list4 = $this->createPriceList();
        $customer4->update(['default_price_list_id' => $list4->id]);
        $this->createPriceListItem($list4, $variant4, 100.00, 1);
        $this->createPriceListItem($list4, $variant4, 90.00, 10);

        app(WorkspaceContext::class)->id();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolver->resolveForCustomer($variant4, $customer4, 15);
        $this->assertSame(self::BASELINE_TIER_MATCH_QTY15, count(DB::getQueryLog()));
    }

    public function test_diagnostic_mode_does_instantiate_collector(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        $result = app(PriceResolver::class)->resolveWithTrace($variant, $customer, 1);

        $this->assertNotEmpty($result->trace->steps);
    }
}
