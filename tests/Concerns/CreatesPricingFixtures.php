<?php

namespace Tests\Concerns;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use Illuminate\Support\Str;

trait CreatesPricingFixtures
{
    protected function defaultWorkspace(): Workspace
    {
        return Workspace::query()->where('is_default', true)->sole();
    }

    protected function createCustomer(?Workspace $workspace = null): Customer
    {
        $workspace ??= $this->defaultWorkspace();

        return Customer::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Pricing Customer '.Str::random(4),
            'short_name' => 'PC',
            'login' => 'pricing-'.Str::random(8),
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    protected function createVariant(?Workspace $workspace = null, ?float $basePriceCache = null): ProductVariant
    {
        $workspace ??= $this->defaultWorkspace();

        $product = Product::create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-'.Str::random(8),
            'name' => 'Pricing Product',
            'is_active' => true,
        ]);

        return ProductVariant::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'VAR-'.Str::random(8),
            'is_active' => true,
            'available_quantity_cache' => 10,
            'availability_status' => 'in_stock',
            'base_price_cache' => $basePriceCache,
        ]);
    }

    protected function createPriceList(
        ?Workspace $workspace = null,
        bool $isDefault = false,
        PriceListStatus $status = PriceListStatus::Active,
    ): PriceList {
        $workspace ??= $this->defaultWorkspace();

        return PriceList::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'List '.Str::random(6),
            'currency' => 'UAH',
            'is_default' => $isDefault,
            'priority' => 0,
            'status' => $status,
        ]);
    }

    protected function createPriceListItem(
        PriceList $priceList,
        ProductVariant $variant,
        float $price,
        int $quantityMin = 1,
        ?float $salePrice = null,
        ?float $vatRate = 20,
    ): PriceListItem {
        return PriceListItem::withoutWorkspaceScope()->create([
            'workspace_id' => $priceList->workspace_id,
            'price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id,
            'quantity_min' => $quantityMin,
            'price' => $price,
            'sale_price' => $salePrice,
            'vat_rate' => $vatRate,
            'status' => PriceListItemStatus::Active,
        ]);
    }
}
