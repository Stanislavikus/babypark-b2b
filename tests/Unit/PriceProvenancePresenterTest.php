<?php

namespace Tests\Unit;

use App\Services\Pricing\PriceProvenancePresenter;
use App\Services\Pricing\ResolvedPrice;
use App\Support\Pricing\VariantPriceDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceProvenancePresenterTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    private PriceProvenancePresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new PriceProvenancePresenter;
    }

    public function test_present_returns_null_for_unavailable_variant_price_display(): void
    {
        $this->assertNull($this->presenter->present(VariantPriceDisplay::unavailable()));
    }

    public function test_present_contractor_price_list_with_complete_presentation(): void
    {
        $list = $this->createPriceList();
        $resolved = ResolvedPrice::fromListItem(
            regularNetPrice: 100.0,
            salePrice: 80.0,
            vatRate: 20.0,
            currency: 'UAH',
            source: 'contractor_price_list',
            sourcePriceListId: $list->id,
            sourcePriceListItemId: 'item-uuid',
        );

        $presentation = $this->presenter->present($resolved, $list);

        $this->assertSame("Індивідуальний прайс-лист «{$list->name}»", $presentation->label);
        $this->assertSame('contractor_price_list', $presentation->source);
        $this->assertTrue($presentation->isOnSale);
        $this->assertSame(120.0, $presentation->regularGrossPrice);
        $this->assertSame(96.0, $presentation->effectiveGrossPrice);
        $this->assertSame('UAH', $presentation->currency);
        $this->assertSame($list->id, $presentation->sourcePriceListId);
        $this->assertSame('item-uuid', $presentation->sourcePriceListItemId);
    }

    public function test_present_workspace_default_price_list_with_complete_presentation(): void
    {
        $list = $this->createPriceList(isDefault: true);
        $resolved = ResolvedPrice::fromListItem(
            regularNetPrice: 55.5,
            salePrice: null,
            vatRate: 20.0,
            currency: 'UAH',
            source: 'workspace_default_price_list',
            sourcePriceListId: $list->id,
            sourcePriceListItemId: 'default-item-uuid',
        );

        $presentation = $this->presenter->present($resolved, $list);

        $this->assertSame('Основний прайс-лист компанії', $presentation->label);
        $this->assertSame('workspace_default_price_list', $presentation->source);
        $this->assertFalse($presentation->isOnSale);
        $this->assertSame(66.6, $presentation->regularGrossPrice);
        $this->assertSame(66.6, $presentation->effectiveGrossPrice);
        $this->assertSame('UAH', $presentation->currency);
        $this->assertSame($list->id, $presentation->sourcePriceListId);
        $this->assertSame('default-item-uuid', $presentation->sourcePriceListItemId);
    }

    public function test_present_base_price_cache_with_complete_presentation(): void
    {
        $resolved = ResolvedPrice::fromBasePriceCache(42.0, 'UAH');

        $presentation = $this->presenter->present($resolved);

        $this->assertSame(
            'Базова ціна товару — в основному прайс-листі немає активної ціни для цієї позиції та кількості.',
            $presentation->label,
        );
        $this->assertSame('base_price_cache', $presentation->source);
        $this->assertFalse($presentation->isOnSale);
        $this->assertSame(50.4, $presentation->regularGrossPrice);
        $this->assertSame(50.4, $presentation->effectiveGrossPrice);
        $this->assertSame('UAH', $presentation->currency);
        $this->assertNull($presentation->sourcePriceListId);
        $this->assertNull($presentation->sourcePriceListItemId);
    }

    public function test_present_via_variant_price_display_carries_metadata(): void
    {
        $list = $this->createPriceList();
        $resolved = ResolvedPrice::fromListItem(
            regularNetPrice: 100.0,
            salePrice: null,
            vatRate: 20.0,
            currency: 'UAH',
            source: 'contractor_price_list',
            sourcePriceListId: $list->id,
            sourcePriceListItemId: 'item-uuid',
        );
        $display = VariantPriceDisplay::fromResolved($resolved);

        $presentation = $this->presenter->present($display, $list);

        $this->assertSame("Індивідуальний прайс-лист «{$list->name}»", $presentation->label);
        $this->assertSame($list->id, $presentation->sourcePriceListId);
        $this->assertSame('item-uuid', $presentation->sourcePriceListItemId);
    }

    public function test_is_on_sale_false_when_sale_price_not_lower_than_regular(): void
    {
        $list = $this->createPriceList();
        $resolved = ResolvedPrice::fromListItem(
            regularNetPrice: 100.0,
            salePrice: 110.0,
            vatRate: 20.0,
            currency: 'UAH',
            source: 'contractor_price_list',
            sourcePriceListId: $list->id,
            sourcePriceListItemId: 'item-uuid',
        );

        $presentation = $this->presenter->present($resolved, $list);

        $this->assertFalse($presentation->isOnSale);
        $this->assertSame(120.0, $presentation->regularGrossPrice);
        $this->assertSame(132.0, $presentation->effectiveGrossPrice);
    }

    public function test_list_based_source_throws_when_source_price_list_id_is_null(): void
    {
        $list = $this->createPriceList();
        $price = $this->makeResolvedPrice(sourcePriceListId: null, sourcePriceListItemId: 'item-uuid');

        $this->expectException(LogicException::class);
        $this->presenter->present($price, $list);
    }

    public function test_list_based_source_throws_when_source_price_list_item_id_is_null(): void
    {
        $list = $this->createPriceList();
        $price = $this->makeResolvedPrice(sourcePriceListId: $list->id, sourcePriceListItemId: null);

        $this->expectException(LogicException::class);
        $this->presenter->present($price, $list);
    }

    public function test_list_based_source_throws_when_source_price_list_is_omitted(): void
    {
        $price = $this->makeResolvedPrice(
            sourcePriceListId: 'list-uuid',
            sourcePriceListItemId: 'item-uuid',
        );

        $this->expectException(LogicException::class);
        $this->presenter->present($price);
    }

    public function test_list_based_source_throws_when_source_price_list_id_mismatches(): void
    {
        $assignedList = $this->createPriceList();
        $winningList = $this->createPriceList();
        $price = $this->makeResolvedPrice(
            sourcePriceListId: $winningList->id,
            sourcePriceListItemId: 'item-uuid',
        );

        $this->expectException(LogicException::class);
        $this->presenter->present($price, $assignedList);
    }

    public function test_base_price_cache_throws_when_source_price_list_id_is_not_null(): void
    {
        $price = $this->makeResolvedPrice(
            source: 'base_price_cache',
            sourcePriceListId: 'list-uuid',
            sourcePriceListItemId: null,
        );

        $this->expectException(LogicException::class);
        $this->presenter->present($price);
    }

    public function test_base_price_cache_throws_when_source_price_list_item_id_is_not_null(): void
    {
        $price = $this->makeResolvedPrice(
            source: 'base_price_cache',
            sourcePriceListId: null,
            sourcePriceListItemId: 'item-uuid',
        );

        $this->expectException(LogicException::class);
        $this->presenter->present($price);
    }

    public function test_base_price_cache_throws_when_source_price_list_is_supplied(): void
    {
        $list = $this->createPriceList();
        $price = ResolvedPrice::fromBasePriceCache(42.0, 'UAH');

        $this->expectException(LogicException::class);
        $this->presenter->present($price, $list);
    }

    public function test_unrecognized_source_throws(): void
    {
        $price = $this->makeResolvedPrice(source: 'future_customer_group_rule');

        $this->expectException(LogicException::class);
        $this->presenter->present($price);
    }

    private function makeResolvedPrice(
        string $source = 'contractor_price_list',
        ?string $sourcePriceListId = 'list-uuid',
        ?string $sourcePriceListItemId = 'item-uuid',
    ): ResolvedPrice {
        return new ResolvedPrice(
            regularNetPrice: 100.0,
            salePrice: null,
            effectiveNetPrice: 100.0,
            vatRate: 20.0,
            grossPrice: 120.0,
            currency: 'UAH',
            source: $source,
            sourcePriceListId: $sourcePriceListId,
            sourcePriceListItemId: $sourcePriceListItemId,
            regularGrossPrice: 120.0,
            isOnSale: false,
        );
    }
}
