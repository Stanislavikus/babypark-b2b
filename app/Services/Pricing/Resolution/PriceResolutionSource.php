<?php

namespace App\Services\Pricing\Resolution;

enum PriceResolutionSource: string
{
    case CustomerPriceList = 'customer_price_list';
    case WorkspaceDefaultPriceList = 'workspace_default_price_list';
    case BasePriceCache = 'base_price_cache';
}
