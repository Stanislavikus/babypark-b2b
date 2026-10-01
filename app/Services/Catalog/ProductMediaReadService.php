<?php

namespace App\Services\Catalog;

use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class ProductMediaReadService
{
    /**
     * @return Collection<int, ProductMedia>
     */
    public function productMedia(Product $product): Collection
    {
        return ProductMedia::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->whereNull('locale')
            ->with(['asset' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * First-class media source references in gallery order.
     *
     * null means this Product has no first-class associations and the legacy JSON path
     * remains authoritative for compatibility.
     *
     * @return list<string|null>|null
     */
    public function firstClassSourceReferences(Product $product): ?array
    {
        $hasFirstClassMedia = ProductMedia::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->exists();

        if (! $hasFirstClassMedia) {
            return null;
        }

        return $this->productMedia($product)
            ->map(fn (ProductMedia $item): ?string => $this->sourceReference($item->asset))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function orderedUrls(Product $product): array
    {
        $firstClass = $this->firstClassSourceReferences($product);

        if ($firstClass !== null) {
            return collect($firstClass)
                ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
                ->map(fn (string $url): string => trim($url))
                ->values()
                ->all();
        }

        $images = $product->images;

        if (is_string($images)) {
            $images = json_decode($images, true);
        }

        if (! is_array($images)) {
            return [];
        }

        return collect($images)
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => trim($url))
            ->unique()
            ->values()
            ->all();
    }

    public function firstImageUrl(Product $product): ?string
    {
        return $this->orderedUrls($product)[0] ?? null;
    }

    public function sourceReference(?MediaAsset $asset): ?string
    {
        if (! $asset instanceof MediaAsset || ! $asset->isOriginal()) {
            return null;
        }

        if (filled($asset->source_url)) {
            return trim((string) $asset->source_url);
        }

        if (! filled($asset->storage_disk) || ! filled($asset->storage_path)) {
            return null;
        }

        $url = Storage::disk((string) $asset->storage_disk)->url((string) $asset->storage_path);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }
}
