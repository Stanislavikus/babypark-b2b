<?php

namespace App\Services\Pricing;

use App\Enums\PriceDisplayMode;

final class PriceDisplayPresenter
{
    public function __construct(
        private readonly MoneyFormatter $moneyFormatter,
    ) {}

    public function present(ResolvedPrice $price, PriceDisplayMode $mode): PriceDisplayPresentation
    {
        $net = $price->effectiveNetPrice;
        $gross = $price->grossPrice;
        $taxAmount = round($gross - $net, 2);
        $vatPercent = $this->formatPercent($price->vatRate);

        $netFormatted = $this->formatMoney($net, $price->currency);
        $grossFormatted = $this->formatMoney($gross, $price->currency);
        $taxFormatted = $this->formatMoney($taxAmount, $price->currency);

        return match ($mode) {
            PriceDisplayMode::TaxInclusivePrimary => new PriceDisplayPresentation(
                primaryLine: __('price_display.with_tax', ['amount' => $grossFormatted]),
                secondaryLine: __('price_display.secondary_inclusive', [
                    'net' => $netFormatted,
                    'percent' => $vatPercent,
                    'tax' => $taxFormatted,
                ]),
                decisionPathLabel: __('price_display.with_tax', ['amount' => $grossFormatted]),
            ),
            PriceDisplayMode::TaxExclusivePrimary => new PriceDisplayPresentation(
                primaryLine: __('price_display.without_tax', ['amount' => $netFormatted]),
                secondaryLine: __('price_display.secondary_exclusive', [
                    'gross' => $grossFormatted,
                    'percent' => $vatPercent,
                    'tax' => $taxFormatted,
                ]),
                decisionPathLabel: __('price_display.without_tax', ['amount' => $netFormatted]),
            ),
            PriceDisplayMode::BothEqual => new PriceDisplayPresentation(
                primaryLine: __('price_display.without_tax_prefix', ['amount' => $netFormatted]),
                secondaryLine: __('price_display.tax_line', [
                    'percent' => $vatPercent,
                    'amount' => $taxFormatted,
                ]),
                tertiaryLine: __('price_display.with_tax_prefix', ['amount' => $grossFormatted]),
                decisionPathLabel: __('price_display.both_compact', [
                    'net' => $netFormatted,
                    'gross' => $grossFormatted,
                ]),
            ),
        };
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return $this->moneyFormatter->format($amount, $currency);
    }

    private function formatPercent(float $rate): string
    {
        $formatted = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

        return $formatted.'%';
    }
}
