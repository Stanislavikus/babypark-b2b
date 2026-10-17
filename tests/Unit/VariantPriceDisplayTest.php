<?php

namespace Tests\Unit;

use App\Enums\CatalogPriceDisplayStatus;
use App\Services\Pricing\ResolvedPrice;
use App\Support\Pricing\VariantPriceDisplay;
use Tests\TestCase;

class VariantPriceDisplayTest extends TestCase
{
    public function test_from_resolved_carries_provenance_metadata(): void
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

        $display = VariantPriceDisplay::fromResolved($resolved, 150.0);

        $this->assertTrue($display->available);
        $this->assertSame(CatalogPriceDisplayStatus::Resolved, $display->status);
        $this->assertSame('list-uuid', $display->sourcePriceListId);
        $this->assertSame('item-uuid', $display->sourcePriceListItemId);
        $this->assertSame(120.0, $display->regularGrossPrice);
        $this->assertTrue($display->isOnSale);
        $this->assertSame(96.0, $display->grossPrice);
        $this->assertSame(150.0, $display->recommendedRetailPrice);
    }

    public function test_unavailable_sets_exact_provenance_defaults(): void
    {
        $display = VariantPriceDisplay::unavailable();

        $this->assertFalse($display->available);
        $this->assertSame(CatalogPriceDisplayStatus::Unavailable, $display->status);
        $this->assertSame('unavailable', $display->source);
        $this->assertNull($display->sourcePriceListId);
        $this->assertNull($display->sourcePriceListItemId);
        $this->assertSame(0.0, $display->regularGrossPrice);
        $this->assertFalse($display->isOnSale);
        $this->assertSame(0.0, $display->grossPrice);
        $this->assertSame(0.0, $display->regularNetPrice);
    }
}
