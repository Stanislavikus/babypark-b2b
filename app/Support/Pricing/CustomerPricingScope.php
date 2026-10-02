<?php

namespace App\Support\Pricing;

use App\Enums\PriceListStatus;
use App\Models\Customer;
use App\Models\PriceList;
use Illuminate\Database\Eloquent\Builder;

class CustomerPricingScope
{
    public static function priceListIdFor(Customer $customer): ?string
    {
        if ($customer->default_price_list_id !== null) {
            return $customer->default_price_list_id;
        }

        return PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $customer->workspace_id)
            ->where('is_default', true)
            ->where('status', PriceListStatus::Active)
            ->value('id');
    }

    /**
     * Active products with at least one active variant (catalog eligibility only; price resolution is separate).
     */
    public static function applyProductScope(Builder $query, Customer $customer): Builder
    {
        return $query
            ->where('products.is_active', true)
            ->whereHas('variants', function (Builder $variantQuery): void {
                $variantQuery->where('is_active', true);
            });
    }

    public static function eagerLoadForCustomer(Customer $customer): array
    {
        return [
            'category',
            'variants' => fn ($q) => $q->where('is_active', true),
            'variants.stocks',
        ];
    }
}
