<?php

namespace App\Services\Catalog;

use App\Enums\MediaRole;
use App\Models\MediaAsset;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\VariantMedia;
use Illuminate\Support\Collection;

final class VariantMediaReadService
{
    public function __construct(
        private readonly ProductMediaReadService $productMediaReadService,
    ) {}

    /**
     * @return Collection<int, VariantMedia>
     */
    public function variantMedia(ProductVariant $variant): Collection
    {
        return VariantMedia::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('variant_id', $variant->id)
            ->whereNull('locale')
            ->with(['asset' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, ProductMedia>
     */
    public function commonProductMedia(ProductVariant $variant): Collection
    {
        $product = $variant->product()->withoutGlobalScopes()->firstOrFail();

        return $this->productMediaReadService->productMedia($product);
    }

    /**
     * Specific assets first, then common Product assets not already explicitly associated.
     *
     * @return Collection<int, MediaAsset>
     */
    public function composedAssets(ProductVariant $variant): Collection
    {
        $specific = $this->variantMedia($variant)
            ->pluck('asset')
            ->filter(fn ($asset): bool => $asset instanceof MediaAsset)
            ->values();

        $specificIds = $specific->pluck('id')->map(fn ($id): string => (string) $id)->all();

        $common = $this->commonProductMedia($variant)
            ->pluck('asset')
            ->filter(fn ($asset): bool => $asset instanceof MediaAsset)
            ->reject(fn (MediaAsset $asset): bool => in_array((string) $asset->id, $specificIds, true))
            ->values();

        return $specific->concat($common)->values();
    }

    /**
     * @return array{
     *   state:'explicit_primary'|'specific_without_primary'|'common_only'|'none',
     *   specific_count:int,
     *   common_count:int,
     *   has_explicit_primary:bool,
     *   primary_media_asset_id:?string
     * }
     */
    public function coverage(ProductVariant $variant): array
    {
        $specific = $this->variantMedia($variant);
        $common = $this->commonProductMedia($variant);
        /** @var VariantMedia|null $primary */
        $primary = $specific->first(fn (VariantMedia $media): bool => $media->role === MediaRole::Primary);

        $state = match (true) {
            $primary instanceof VariantMedia => 'explicit_primary',
            $specific->isNotEmpty() => 'specific_without_primary',
            $common->isNotEmpty() => 'common_only',
            default => 'none',
        };

        return [
            'state' => $state,
            'specific_count' => $specific->count(),
            'common_count' => $common->count(),
            'has_explicit_primary' => $primary instanceof VariantMedia,
            'primary_media_asset_id' => $primary?->media_asset_id,
        ];
    }
}
