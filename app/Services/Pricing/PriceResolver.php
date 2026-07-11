<?php

namespace App\Services\Pricing;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Exceptions\Pricing\InvalidPriceQuantityException;
use App\Exceptions\Pricing\PriceListConfigurationException;
use App\Exceptions\Pricing\PriceNotAvailableException;
use App\Models\Contractor;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use Illuminate\Support\Carbon;

class PriceResolver
{
    public function resolveForContractor(ProductVariant $variant, Contractor $contractor, int $quantity): ResolvedPrice
    {
        $this->assertPositiveQuantity($quantity);

        if ($contractor->default_price_list_id !== null) {
            $assignedList = PriceList::withoutWorkspaceScope()
                ->where('id', $contractor->default_price_list_id)
                ->first();

            if ($assignedList !== null && $assignedList->status === PriceListStatus::Active) {
                $item = $this->matchingListItem($assignedList->id, $variant->id, $quantity);

                if ($item !== null) {
                    return $this->resolvedFromItem($item, 'contractor_price_list');
                }
            }
        }

        return $this->resolveWorkspaceDefaultOrCache($variant, $quantity);
    }

    public function resolveDefault(ProductVariant $variant, int $quantity = 1): ResolvedPrice
    {
        $this->assertPositiveQuantity($quantity);

        return $this->resolveWorkspaceDefaultOrCache($variant, $quantity);
    }

    private function resolveWorkspaceDefaultOrCache(ProductVariant $variant, int $quantity): ResolvedPrice
    {
        $defaultList = $this->activeDefaultPriceListForWorkspace($variant->workspace_id);

        $item = $this->matchingListItem($defaultList->id, $variant->id, $quantity);

        if ($item !== null) {
            return $this->resolvedFromItem($item, 'workspace_default_price_list');
        }

        if ($variant->base_price_cache !== null) {
            return ResolvedPrice::fromBasePriceCache(
                (float) $variant->base_price_cache,
                $defaultList->currency ?: (string) config('pricing.default_currency', 'UAH'),
            );
        }

        throw new PriceNotAvailableException(
            "No price available for variant {$variant->id} at quantity {$quantity}."
        );
    }

    private function activeDefaultPriceListForWorkspace(string $workspaceId): PriceList
    {
        $defaults = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('is_default', true)
            ->where('status', PriceListStatus::Active)
            ->get();

        if ($defaults->isEmpty()) {
            throw new PriceListConfigurationException(
                "Workspace {$workspaceId} has no active default price list."
            );
        }

        if ($defaults->count() > 1) {
            throw new PriceListConfigurationException(
                "Workspace {$workspaceId} has multiple active default price lists."
            );
        }

        return $defaults->first();
    }

    private function matchingListItem(string $priceListId, int $variantId, int $quantity): ?PriceListItem
    {
        $now = Carbon::now();

        return PriceListItem::withoutWorkspaceScope()
            ->where('price_list_id', $priceListId)
            ->where('product_variant_id', $variantId)
            ->where('status', PriceListItemStatus::Active)
            ->where('quantity_min', '<=', $quantity)
            ->where(function ($query) use ($now): void {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            })
            ->orderByDesc('quantity_min')
            ->first();
    }

    private function resolvedFromItem(PriceListItem $item, string $source): ResolvedPrice
    {
        $item->loadMissing('priceList');

        $currency = $item->priceList?->currency
            ?: (string) config('pricing.default_currency', 'UAH');

        return ResolvedPrice::fromListItem(
            regularNetPrice: (float) $item->price,
            salePrice: $item->sale_price !== null ? (float) $item->sale_price : null,
            vatRate: $item->vat_rate !== null ? (float) $item->vat_rate : null,
            currency: $currency,
            source: $source,
            sourcePriceListId: $item->price_list_id,
            sourcePriceListItemId: $item->id,
        );
    }

    private function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidPriceQuantityException(
                'Price resolution requires a quantity greater than zero.'
            );
        }
    }
}
