<?php

namespace App\Enums;

enum CatalogProductDisplayState: string
{
    case OrderableVariantSelected = 'orderable_variant_selected';
    case ExpectedVariantSelected = 'expected_variant_selected';
    case InformationalPriceOnly = 'informational_price_only';
    case ConfigurationError = 'configuration_error';
    case PriceUnavailable = 'price_unavailable';
}
