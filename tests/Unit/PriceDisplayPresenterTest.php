<?php

namespace Tests\Unit;

use App\Enums\PriceDisplayMode;
use App\Services\Pricing\PriceDisplayPresenter;
use App\Services\Pricing\ResolvedPrice;
use Tests\TestCase;

class PriceDisplayPresenterTest extends TestCase
{
    private PriceDisplayPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = app(PriceDisplayPresenter::class);
    }

    public function test_tax_inclusive_primary_mode(): void
    {
        $price = $this->samplePrice();

        $presentation = $this->presenter->present($price, PriceDisplayMode::TaxInclusivePrimary);

        $this->assertSame('108,00 ₴ з податком', $presentation->primaryLine);
        $this->assertSame('90,00 ₴ без податку · Податок 20%: 18,00 ₴', $presentation->secondaryLine);
        $this->assertNull($presentation->tertiaryLine);
    }

    public function test_tax_exclusive_primary_mode(): void
    {
        $price = $this->samplePrice();

        $presentation = $this->presenter->present($price, PriceDisplayMode::TaxExclusivePrimary);

        $this->assertSame('90,00 ₴ без податку', $presentation->primaryLine);
        $this->assertSame('108,00 ₴ з податком · Податок 20%: 18,00 ₴', $presentation->secondaryLine);
    }

    public function test_both_equal_mode(): void
    {
        $price = $this->samplePrice();

        $presentation = $this->presenter->present($price, PriceDisplayMode::BothEqual);

        $this->assertSame('Без податку 90,00 ₴', $presentation->primaryLine);
        $this->assertSame('Податок 20% 18,00 ₴', $presentation->secondaryLine);
        $this->assertSame('З податком 108,00 ₴', $presentation->tertiaryLine);
    }

    private function samplePrice(): ResolvedPrice
    {
        return ResolvedPrice::fromListItem(
            regularNetPrice: 90.0,
            salePrice: null,
            vatRate: 20.0,
            currency: 'UAH',
            source: 'workspace_default_price_list',
            sourcePriceListId: 'list-id',
            sourcePriceListItemId: 'item-id',
        );
    }
}
