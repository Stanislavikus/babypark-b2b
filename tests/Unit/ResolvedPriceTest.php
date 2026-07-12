<?php

namespace Tests\Unit;

use App\Services\Pricing\ResolvedPrice;
use Tests\TestCase;

class ResolvedPriceTest extends TestCase
{
    public function test_from_list_item_populates_provenance_and_sale_metadata(): void
    {
        $resolved = ResolvedPrice::fromListItem(
            regularNetPrice: 100.0,
            salePrice: 80.0,
            vatRate: 20.0,
            currency: 'UAH',
            source: 'customer_price_list',
            sourcePriceListId: 'list-uuid',
            sourcePriceListItemId: 'item-uuid',
        );

        $this->assertSame('list-uuid', $resolved->sourcePriceListId);
        $this->assertSame('item-uuid', $resolved->sourcePriceListItemId);
        $this->assertSame(120.0, $resolved->regularGrossPrice);
        $this->assertSame(96.0, $resolved->grossPrice);
        $this->assertTrue($resolved->isOnSale);
    }

    public function test_is_on_sale_false_when_sale_price_equals_regular_price(): void
    {
        $resolved = ResolvedPrice::fromListItem(
            regularNetPrice: 100.0,
            salePrice: 100.0,
            vatRate: 20.0,
            currency: 'UAH',
            source: 'customer_price_list',
            sourcePriceListId: 'list-uuid',
            sourcePriceListItemId: 'item-uuid',
        );

        $this->assertFalse($resolved->isOnSale);
        $this->assertSame(100.0, $resolved->effectiveNetPrice);
        $this->assertSame(120.0, $resolved->grossPrice);
    }

    public function test_is_on_sale_false_when_sale_price_exceeds_regular_price(): void
    {
        $resolved = ResolvedPrice::fromListItem(
            regularNetPrice: 100.0,
            salePrice: 110.0,
            vatRate: 20.0,
            currency: 'UAH',
            source: 'customer_price_list',
            sourcePriceListId: 'list-uuid',
            sourcePriceListItemId: 'item-uuid',
        );

        $this->assertFalse($resolved->isOnSale);
        $this->assertSame(110.0, $resolved->effectiveNetPrice);
        $this->assertSame(132.0, $resolved->grossPrice);
    }

    public function test_is_on_sale_false_when_no_sale_price(): void
    {
        $resolved = ResolvedPrice::fromListItem(
            regularNetPrice: 100.0,
            salePrice: null,
            vatRate: 20.0,
            currency: 'UAH',
            source: 'workspace_default_price_list',
            sourcePriceListId: 'list-uuid',
            sourcePriceListItemId: 'item-uuid',
        );

        $this->assertFalse($resolved->isOnSale);
        $this->assertSame(100.0, $resolved->effectiveNetPrice);
    }

    public function test_from_base_price_cache_sets_null_provenance_and_no_sale(): void
    {
        $resolved = ResolvedPrice::fromBasePriceCache(42.0, 'UAH');

        $this->assertSame('base_price_cache', $resolved->source);
        $this->assertNull($resolved->sourcePriceListId);
        $this->assertNull($resolved->sourcePriceListItemId);
        $this->assertSame(50.4, $resolved->regularGrossPrice);
        $this->assertSame(50.4, $resolved->grossPrice);
        $this->assertFalse($resolved->isOnSale);
    }
}
