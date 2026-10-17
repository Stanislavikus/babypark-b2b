<?php

namespace App\Services\Pricing;

final class MoneyFormatter
{
    public function format(float $amount, string $currency): string
    {
        $symbol = $currency === 'UAH' ? '₴' : $currency;

        return number_format($amount, 2, ',', ' ').' '.$symbol;
    }
}
