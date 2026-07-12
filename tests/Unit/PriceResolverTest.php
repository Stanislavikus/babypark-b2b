<?php

namespace Tests\Unit;

use App\Enums\PriceListStatus;
use App\Exceptions\Pricing\InvalidPriceQuantityException;
use App\Exceptions\Pricing\PriceListConfigurationException;
use App\Exceptions\Pricing\PriceNotAvailableException;
use App\Models\PriceList;
use App\Services\Pricing\PriceResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceResolverTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_resolve_for_customer_falls_back_when_assigned_price_list_is_inactive(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $inactiveList = $this->createPriceList(status: PriceListStatus::Inactive);
        $customer->update(['default_price_list_id' => $inactiveList->id]);
        $this->createPriceListItem($inactiveList, $variant, 100.00, 1, null, 20);

        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('is_default', true)
            ->first();

        $this->createPriceListItem($defaultList, $variant, 55.50);

        $resolved = app(PriceResolver::class)->resolveForCustomer($variant, $customer, 1);

        $this->assertSame(55.5, $resolved->effectiveNetPrice);
        $this->assertSame('workspace_default_price_list', $resolved->source);
    }

    public function test_resolve_for_customer_uses_assigned_price_list(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00, 1, null, 20);

        $resolved = app(PriceResolver::class)->resolveForCustomer($variant, $customer, 1);

        $this->assertSame(100.0, $resolved->effectiveNetPrice);
        $this->assertSame(120.0, $resolved->grossPrice);
        $this->assertSame('customer_price_list', $resolved->source);
        $this->assertSame($list->id, $resolved->sourcePriceListId);
        $this->assertNotNull($resolved->sourcePriceListItemId);
        $this->assertSame(120.0, $resolved->regularGrossPrice);
        $this->assertFalse($resolved->isOnSale);
    }

    public function test_sale_price_overrides_regular_net_price(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00, 1, 80.00, 20);

        $resolved = app(PriceResolver::class)->resolveForCustomer($variant, $customer, 1);

        $this->assertSame(100.0, $resolved->regularNetPrice);
        $this->assertSame(80.0, $resolved->salePrice);
        $this->assertSame(80.0, $resolved->effectiveNetPrice);
        $this->assertSame(96.0, $resolved->grossPrice);
        $this->assertTrue($resolved->isOnSale);
        $this->assertSame(120.0, $resolved->regularGrossPrice);
    }

    public function test_resolve_default_falls_back_to_workspace_default_list(): void
    {
        $variant = $this->createVariant();
        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('is_default', true)
            ->first();

        $this->createPriceListItem($defaultList, $variant, 55.50);

        $resolved = app(PriceResolver::class)->resolveDefault($variant, 1);

        $this->assertSame(55.5, $resolved->effectiveNetPrice);
        $this->assertSame('workspace_default_price_list', $resolved->source);
    }

    public function test_resolve_default_uses_base_price_cache_when_no_list_item(): void
    {
        $variant = $this->createVariant(basePriceCache: 42.00);

        $resolved = app(PriceResolver::class)->resolveDefault($variant, 1);

        $this->assertSame(42.0, $resolved->effectiveNetPrice);
        $this->assertSame('base_price_cache', $resolved->source);
        $this->assertNull($resolved->sourcePriceListId);
        $this->assertNull($resolved->sourcePriceListItemId);
        $this->assertFalse($resolved->isOnSale);
    }

    public function test_rejects_non_positive_quantity(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        $this->expectException(InvalidPriceQuantityException::class);
        app(PriceResolver::class)->resolveForCustomer($variant, $customer, 0);
    }

    public function test_throws_when_no_price_available(): void
    {
        $variant = $this->createVariant();

        $this->expectException(PriceNotAvailableException::class);
        app(PriceResolver::class)->resolveDefault($variant, 1);
    }

    public function test_throws_when_multiple_active_default_lists_exist(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL enforces single default price list via database constraint.');
        }

        $workspace = $this->defaultWorkspace();
        $variant = $this->createVariant($workspace);

        PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $this->createPriceList($workspace, isDefault: true);
        $this->createPriceList($workspace, isDefault: true);

        $this->expectException(PriceListConfigurationException::class);
        app(PriceResolver::class)->resolveDefault($variant, 1);
    }

    public function test_tier_matching_selects_highest_quantity_min_not_exceeding_requested_quantity(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $this->createPriceListItem($list, $variant, 100.00, 1);
        $this->createPriceListItem($list, $variant, 90.00, 10);
        $this->createPriceListItem($list, $variant, 80.00, 50);

        $resolved = app(PriceResolver::class)->resolveForCustomer($variant, $customer, 15);

        $this->assertSame(90.0, $resolved->effectiveNetPrice);
    }

    public function test_mysql_default_price_list_uniqueness_with_existing_rows(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific default price list uniqueness constraint.');
        }

        $workspace = $this->defaultWorkspace();

        PriceList::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Non-default A',
            'currency' => 'UAH',
            'is_default' => false,
            'priority' => 0,
            'status' => PriceListStatus::Active,
        ]);

        PriceList::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Non-default B',
            'currency' => 'UAH',
            'is_default' => false,
            'priority' => 0,
            'status' => PriceListStatus::Active,
        ]);

        PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        PriceList::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'First Default',
            'currency' => 'UAH',
            'is_default' => true,
            'priority' => 0,
            'status' => PriceListStatus::Active,
        ]);

        $this->expectException(QueryException::class);

        PriceList::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Second Default',
            'currency' => 'UAH',
            'is_default' => true,
            'priority' => 0,
            'status' => PriceListStatus::Active,
        ]);
    }
}
