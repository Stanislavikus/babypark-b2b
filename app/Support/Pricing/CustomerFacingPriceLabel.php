<?php

namespace App\Support\Pricing;

use App\Enums\CatalogPriceDisplayStatus;

class CustomerFacingPriceLabel
{
    private const INTERNAL_PATTERNS = [
        'DefaultPriceListMisconfigured',
        'active default price list',
        'multiple active default price lists',
        'workspace_id',
    ];

    public static function forDisplay(VariantPriceDisplay $display): string
    {
        return match ($display->status) {
            CatalogPriceDisplayStatus::Resolved => self::formatResolved($display),
            CatalogPriceDisplayStatus::Unavailable => 'Ціна недоступна',
            CatalogPriceDisplayStatus::ConfigurationError => 'Помилка конфігурації цін',
        };
    }

    public static function sanitize(string $output): string
    {
        foreach (self::INTERNAL_PATTERNS as $pattern) {
            if (str_contains($output, $pattern)) {
                return 'Помилка конфігурації цін';
            }
        }

        return $output;
    }

    private static function formatResolved(VariantPriceDisplay $display): string
    {
        return $display->formattedGross();
    }
}
