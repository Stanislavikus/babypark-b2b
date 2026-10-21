<?php

namespace App\Services\Pricing\Resolution;

enum PriceResolutionReason: string
{
    case PriceListNotAssigned = 'price_list_not_assigned';
    case PriceListInactive = 'price_list_inactive';
    case ItemMissing = 'item_missing';
    case ItemInactive = 'item_inactive';
    case QuantityBelowMinimum = 'quantity_below_minimum';
    case NotYetEffective = 'not_yet_effective';
    case Expired = 'expired';
    case Matched = 'matched';
    case PreviousSourceResolved = 'previous_source_resolved';
    case AllSourcesExhausted = 'all_sources_exhausted';
    case DefaultPriceListMisconfigured = 'default_price_list_misconfigured';
}
