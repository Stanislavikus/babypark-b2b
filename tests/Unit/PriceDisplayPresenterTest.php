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

        $this->assertSame(__('price_display.with_tax', ['amount' => '108,00 ₴']), $presentation->primaryLine);
        $this->assertSame(__('price_display.secondary_inclusive', [
            'net' => '90,00 ₴',
            'percent' => '20%',
            'tax' => '18,00 ₴',
        ]), $presentation->secondaryLine);
        $this->assertNull($presentation->tertiaryLine);
    }

    public function test_tax_exclusive_primary_mode(): void
    {
        $price = $this->samplePrice();

        $presentation = $this->presenter->present($price, PriceDisplayMode::TaxExclusivePrimary);

        $this->assertSame(__('price_display.without_tax', ['amount' => '90,00 ₴']), $presentation->primaryLine);
        $this->assertSame(__('price_display.secondary_exclusive', [
            'gross' => '108,00 ₴',
            'percent' => '20%',
            'tax' => '18,00 ₴',
        ]), $presentation->secondaryLine);
    }

    public function test_both_equal_mode(): void
    {
        $price = $this->samplePrice();

        $presentation = $this->presenter->present($price, PriceDisplayMode::BothEqual);

        $this->assertSame(__('price_display.without_tax_prefix', ['amount' => '90,00 ₴']), $presentation->primaryLine);
        $this->assertSame(__('price_display.tax_line', [
            'percent' => '20%',
            'amount' => '18,00 ₴',
        ]), $presentation->secondaryLine);
        $this->assertSame(__('price_display.with_tax_prefix', ['amount' => '108,00 ₴']), $presentation->tertiaryLine);
        $this->assertSame(__('price_display.both_compact', [
            'net' => '90,00 ₴',
            'gross' => '108,00 ₴',
        ]), $presentation->decisionPathLabel);
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
