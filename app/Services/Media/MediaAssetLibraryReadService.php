<?php

namespace App\Services\Media;

use App\Enums\MediaDiagnosisStatus;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\VariantMedia;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

final class MediaAssetLibraryReadService
{
    public function originalsQuery(Workspace $workspace): Builder
    {
        return MediaAsset::withoutWorkspaceScope()
            ->select('media_assets.*')
            ->where('media_assets.workspace_id', $workspace->id)
            ->whereNull('media_assets.parent_media_asset_id')
            ->selectSub(
                ProductMedia::withoutWorkspaceScope()
                    ->selectRaw('COUNT(DISTINCT product_id)')
                    ->whereColumn('product_media.workspace_id', 'media_assets.workspace_id')
                    ->whereColumn('product_media.media_asset_id', 'media_assets.id'),
                'products_usage_count',
            )
            ->selectSub(
                VariantMedia::withoutWorkspaceScope()
                    ->selectRaw('COUNT(DISTINCT variant_id)')
                    ->whereColumn('variant_media.workspace_id', 'media_assets.workspace_id')
                    ->whereColumn('variant_media.media_asset_id', 'media_assets.id'),
                'variants_usage_count',
            )
            ->selectSub(
                Brand::withoutWorkspaceScope()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('brands.workspace_id', 'media_assets.workspace_id')
                    ->whereColumn('brands.logo_media_asset_id', 'media_assets.id'),
                'brands_usage_count',
            )
            ->selectSub(
                MediaAsset::withoutWorkspaceScope()
                    ->from('media_assets as derivatives')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('derivatives.workspace_id', 'media_assets.workspace_id')
                    ->whereColumn('derivatives.parent_media_asset_id', 'media_assets.id'),
                'derivatives_usage_count',
            );
    }

    public function applyUsageFilter(Builder $query, ?string $usage): Builder
    {
        return match ($usage) {
            'used' => $query->where(fn (Builder $nested): Builder => $this->whereUsed($nested)),
            'unused' => $query->where(fn (Builder $nested): Builder => $this->whereUnused($nested)),
            'products' => $query->whereHas('productMedia', fn (Builder $relation): Builder => $relation
                ->withoutGlobalScopes()
                ->whereColumn('product_media.workspace_id', 'media_assets.workspace_id')),
            'variants' => $query->whereHas('variantMedia', fn (Builder $relation): Builder => $relation
                ->withoutGlobalScopes()
                ->whereColumn('variant_media.workspace_id', 'media_assets.workspace_id')),
            'brand_logos' => $query->whereHas('brandsAsLogo', fn (Builder $relation): Builder => $relation
                ->withoutGlobalScopes()
                ->whereColumn('brands.workspace_id', 'media_assets.workspace_id')),
            default => $query,
        };
    }

    public function applySourceFilter(Builder $query, ?string $source): Builder
    {
        return match ($source) {
            'managed' => $query
                ->whereNull('source_url')
                ->whereNotNull('storage_disk')
                ->whereNotNull('storage_path'),
            'external' => $query->whereNotNull('source_url'),
            default => $query,
        };
    }

    public function applyAttentionFilter(Builder $query, bool $enabled): Builder
    {
        if (! $enabled) {
            return $query;
        }

        return $query->where(function (Builder $nested): void {
            $nested
                ->whereIn('diagnosis_status', [
                    MediaDiagnosisStatus::Attention->value,
                    MediaDiagnosisStatus::Failed->value,
                ])
                ->orWhere(function (Builder $missing): void {
                    $missing
                        ->whereNull('source_url')
                        ->where(function (Builder $storage): void {
                            $storage
                                ->whereNull('storage_disk')
                                ->orWhereNull('storage_path');
                        });
                });
        });
    }

    public function usageSummary(MediaAsset $asset): string
    {
        $parts = [];

        $products = (int) ($asset->getAttribute('products_usage_count') ?? 0);
        $variants = (int) ($asset->getAttribute('variants_usage_count') ?? 0);
        $brands = (int) ($asset->getAttribute('brands_usage_count') ?? 0);
        $derivatives = (int) ($asset->getAttribute('derivatives_usage_count') ?? 0);

        if ($products > 0) {
            $parts[] = 'Товари: '.$products;
        }

        if ($variants > 0) {
            $parts[] = 'Варіанти: '.$variants;
        }

        if ($brands > 0) {
            $parts[] = 'Логотипи брендів: '.$brands;
        }

        if ($derivatives > 0) {
            $parts[] = 'Версії: '.$derivatives;
        }

        return $parts === [] ? 'Не використовується' : implode(' · ', $parts);
    }

    public function isUsed(MediaAsset $asset): bool
    {
        return ((int) ($asset->getAttribute('products_usage_count') ?? 0)
            + (int) ($asset->getAttribute('variants_usage_count') ?? 0)
            + (int) ($asset->getAttribute('brands_usage_count') ?? 0)
            + (int) ($asset->getAttribute('derivatives_usage_count') ?? 0)) > 0;
    }

    /**
     * @return array{products:int,variants:int,brands:int,derivatives:int}
     */
    public function freshUsageCounts(MediaAsset $asset): array
    {
        $workspaceId = (string) $asset->workspace_id;
        $assetId = (string) $asset->id;

        return [
            'products' => ProductMedia::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('media_asset_id', $assetId)
                ->distinct()
                ->count('product_id'),
            'variants' => VariantMedia::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('media_asset_id', $assetId)
                ->distinct()
                ->count('variant_id'),
            'brands' => Brand::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('logo_media_asset_id', $assetId)
                ->count(),
            'derivatives' => MediaAsset::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('parent_media_asset_id', $assetId)
                ->count(),
        ];
    }

    /**
     * @return list<array{type:string,id:string,label:string,detail:?string,product_id:?string}>
     */
    public function usageItems(MediaAsset $asset): array
    {
        $workspaceId = (string) $asset->workspace_id;
        $assetId = (string) $asset->id;
        $items = [];

        $brands = Brand::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('logo_media_asset_id', $assetId)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($brands as $brand) {
            $items[] = [
                'type' => 'brand',
                'id' => (string) $brand->id,
                'label' => (string) $brand->name,
                'detail' => null,
                'product_id' => null,
            ];
        }

        $products = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn(
                'id',
                ProductMedia::withoutWorkspaceScope()
                    ->select('product_id')
                    ->where('workspace_id', $workspaceId)
                    ->where('media_asset_id', $assetId),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'sku']);

        foreach ($products as $product) {
            $items[] = [
                'type' => 'product',
                'id' => (string) $product->id,
                'label' => (string) $product->name,
                'detail' => filled($product->sku) ? 'SKU '.$product->sku : null,
                'product_id' => (string) $product->id,
            ];
        }

        $variants = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn(
                'id',
                VariantMedia::withoutWorkspaceScope()
                    ->select('variant_id')
                    ->where('workspace_id', $workspaceId)
                    ->where('media_asset_id', $assetId),
            )
            ->orderBy('sku')
            ->orderBy('id')
            ->get(['id', 'product_id', 'sku']);

        $variantProductNames = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $variants->pluck('product_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        foreach ($variants as $variant) {
            $productName = $variantProductNames->get($variant->product_id);

            $items[] = [
                'type' => 'variant',
                'id' => (string) $variant->id,
                'label' => filled($variant->sku)
                    ? (string) $variant->sku
                    : 'Variant '.substr((string) $variant->id, 0, 8),
                'detail' => is_string($productName) && $productName !== '' ? $productName : null,
                'product_id' => filled($variant->product_id) ? (string) $variant->product_id : null,
            ];
        }

        return $items;
    }

    private function whereUsed(Builder $query): Builder
    {
        return $query
            ->whereHas('productMedia', fn (Builder $relation): Builder => $relation
                ->withoutGlobalScopes()
                ->whereColumn('product_media.workspace_id', 'media_assets.workspace_id'))
            ->orWhereHas('variantMedia', fn (Builder $relation): Builder => $relation
                ->withoutGlobalScopes()
                ->whereColumn('variant_media.workspace_id', 'media_assets.workspace_id'))
            ->orWhereHas('brandsAsLogo', fn (Builder $relation): Builder => $relation
                ->withoutGlobalScopes()
                ->whereColumn('brands.workspace_id', 'media_assets.workspace_id'))
            ->orWhereHas('derivatives', fn (Builder $relation): Builder => $relation
                ->withoutGlobalScopes());
    }

    private function whereUnused(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('productMedia', fn (Builder $relation): Builder => $relation
                ->withoutGlobalScopes()
                ->whereColumn('product_media.workspace_id', 'media_assets.workspace_id'))
            ->whereDoesntHave('variantMedia', fn (Builder $relation): Builder => $relation
                ->withoutGlobalScopes()
                ->whereColumn('variant_media.workspace_id', 'media_assets.workspace_id'))
            ->whereDoesntHave('brandsAsLogo', fn (Builder $relation): Builder => $relation
                ->withoutGlobalScopes()
                ->whereColumn('brands.workspace_id', 'media_assets.workspace_id'))
            ->whereDoesntHave('derivatives', fn (Builder $relation): Builder => $relation
                ->withoutGlobalScopes());
    }
}