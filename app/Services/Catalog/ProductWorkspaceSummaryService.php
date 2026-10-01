<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductTypeGroupPlacement;
use App\Services\ProductStructure\ProductCompletenessService;
use App\Services\Sync\ProductChannelSelectionService;

final class ProductWorkspaceSummaryService
{
    public function __construct(
        private readonly ProductChannelSelectionService $channelSelectionService,
        private readonly ProductCompletenessService $productCompletenessService,
    ) {}

    /**
     * @return array{filled:int,total:int,percentage:int,missing:list<string>}
     */
    public function basicCompleteness(Product $product): array
    {
        $checks = [
            'Назва' => filled($product->name),
            'Категорія' => $product->category_id !== null,
            'Бренд' => filled($product->brand),
            'Опис' => filled(strip_tags((string) $product->description)),
            'Медіа' => $this->mediaUrls($product) !== [],
        ];

        $filled = count(array_filter($checks));
        $total = count($checks);

        return [
            'filled' => $filled,
            'total' => $total,
            'percentage' => $total === 0 ? 100 : (int) round(($filled / $total) * 100),
            'missing' => array_values(array_keys(array_filter($checks, static fn (bool $isFilled): bool => ! $isFilled))),
        ];
    }

    public function productTypeLabel(Product $product): string
    {
        $product->loadMissing('productType');

        $type = $product->productType;

        return (string) (
            $type?->localized_labels['uk']
            ?? $type?->localized_labels['en']
            ?? $type?->code
            ?? '—'
        );
    }

    /**
     * @return list<array{
     *   label:string,
     *   total:int,
     *   required:int,
     *   filled:int,
     *   percentage:int,
     *   optional:bool,
     *   active:bool
     * }>
     */
    public function attributeGroups(Product $product): array
    {
        $product->loadMissing([
            'productType.groupPlacements.attributeGroup',
            'productType.groupPlacements.fieldPlacements',
        ]);

        if ($product->productType === null) {
            return [];
        }

        $projection = $this->productCompletenessService->project($product, 'uk');
        $projectionByPlacement = collect($projection->groups)->keyBy('groupPlacementId');

        return $product->productType->groupPlacements
            ->sortBy('sort_order')
            ->filter(fn (ProductTypeGroupPlacement $placement): bool => $placement->attributeGroup?->status === 'active')
            ->map(function (ProductTypeGroupPlacement $placement) use ($projectionByPlacement): array {
                $group = $placement->attributeGroup;
                $groupProjection = $projectionByPlacement->get((string) $placement->id);
                $label = (string) (
                    $group?->localized_labels['uk']
                    ?? $group?->localized_labels['en']
                    ?? $group?->code
                    ?? 'Група'
                );

                return [
                    'label' => $label,
                    'total' => $placement->fieldPlacements->count(),
                    'required' => $groupProjection?->requiredCount ?? 0,
                    'filled' => $groupProjection?->filledCount ?? 0,
                    'percentage' => $groupProjection?->percentage ?? 100,
                    'optional' => (bool) $placement->is_optional,
                    'active' => $groupProjection?->isActive ?? ! $placement->is_optional,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   filled:int,
     *   total:int,
     *   percentage:int,
     *   missing_product:int,
     *   missing_variant:int
     * }
     */
    public function structureCompleteness(Product $product): array
    {
        $projection = $this->productCompletenessService->project($product, 'uk');

        return [
            'filled' => $projection->filledCount,
            'total' => $projection->requiredCount,
            'percentage' => $projection->percentage,
            'missing_product' => count($projection->missingProductBindingIds),
            'missing_variant' => count($projection->missingVariantCells),
        ];
    }

    /**
     * @return array{count:int,label:string,skus:list<string>}
     */
    public function variants(Product $product): array
    {
        $product->loadMissing('variants');

        $active = $product->variants->where('is_active', true)->values();
        $count = $active->count();

        $label = match (true) {
            $count <= 1 => 'Простий товар',
            default => $count.' варіантів',
        };

        return [
            'count' => $count,
            'label' => $label,
            'skus' => $active
                ->pluck('sku')
                ->filter(fn ($sku): bool => is_string($sku) && trim($sku) !== '')
                ->take(6)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<string>
     */
    public function channelLabels(Product $product): array
    {
        $product->loadMissing('syncChannelSelections.syncConfiguration.connectorAccount.connectorDefinition');

        return $product->syncChannelSelections
            ->map(function ($selection): ?string {
                $configuration = $selection->syncConfiguration;

                return $configuration
                    ? $this->channelSelectionService->channelLabel($configuration)
                    : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function mediaUrls(Product $product): array
    {
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
}
