<?php

namespace App\Services\Pricing;

use App\Enums\PriceDisplayMode;

final class PriceDisplayPresenter
{
    public function present(ResolvedPrice $price, PriceDisplayMode $mode): PriceDisplayPresentation
    {
        $net = $price->effectiveNetPrice;
        $gross = $price->grossPrice;
        $taxAmount = round($gross - $net, 2);
        $vatPercent = $this->formatPercent($price->vatRate);

        $netFormatted = $this->formatMoney($net);
        $grossFormatted = $this->formatMoney($gross);
        $taxFormatted = $this->formatMoney($taxAmount);

        return match ($mode) {
            PriceDisplayMode::TaxInclusivePrimary => new PriceDisplayPresentation(
                primaryLine: "{$grossFormatted} з податком",
                secondaryLine: "{$netFormatted} без податку · Податок {$vatPercent}: {$taxFormatted}",
            ),
            PriceDisplayMode::TaxExclusivePrimary => new PriceDisplayPresentation(
                primaryLine: "{$netFormatted} без податку",
                secondaryLine: "{$grossFormatted} з податком · Податок {$vatPercent}: {$taxFormatted}",
            ),
            PriceDisplayMode::BothEqual => new PriceDisplayPresentation(
                primaryLine: "Без податку {$netFormatted}",
                secondaryLine: "Податок {$vatPercent} {$taxFormatted}",
                tertiaryLine: "З податком {$grossFormatted}",
            ),
        };
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' ₴';
    }

    private function formatPercent(float $rate): string
    {
        $formatted = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

        return $formatted.'%';
    }
}
