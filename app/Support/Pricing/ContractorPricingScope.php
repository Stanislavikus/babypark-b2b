<?php

namespace App\Support\Pricing;

use App\Enums\PriceListStatus;
use App\Models\Contractor;
use App\Models\PriceList;
use Illuminate\Database\Eloquent\Builder;

class ContractorPricingScope
{
    public static function priceListIdFor(Contractor $contractor): ?string
    {
        if ($contractor->default_price_list_id !== null) {
            return $contractor->default_price_list_id;
        }

        return PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $contractor->workspace_id)
            ->where('is_default', true)
            ->where('status', PriceListStatus::Active)
            ->value('id');
    }

    /**
     * Products with at least one active variant that has a resolvable price for the contractor.
     */
    public static function applyProductScope(Builder $query, Contractor $contractor): Builder
    {
        $priceListId = self::priceListIdFor($contractor);

        return $query->whereHas('variants', function (Builder $variantQuery) use ($priceListId): void {
            $variantQuery
                ->where('is_active', true)
                ->where(function (Builder $priceQuery) use ($priceListId): void {
                    if ($priceListId !== null) {
                        $priceQuery->whereHas('priceListItems', function (Builder $itemQuery) use ($priceListId): void {
                            $itemQuery
                                ->where('price_list_id', $priceListId)
                                ->where('status', 'active')
                                ->where('quantity_min', '<=', 1)
                                ->where(function (Builder $validQuery): void {
                                    $validQuery->whereNull('valid_from')->orWhere('valid_from', '<=', now());
                                })
                                ->where(function (Builder $validQuery): void {
                                    $validQuery->whereNull('valid_until')->orWhere('valid_until', '>=', now());
                                });
                        });
                    }

                    $priceQuery->orWhereNotNull('base_price_cache');
                });
        });
    }

    public static function eagerLoadForContractor(Contractor $contractor): array
    {
        return [
            'category',
            'variants' => fn ($q) => $q->where('is_active', true),
            'variants.stocks',
        ];
    }
}
