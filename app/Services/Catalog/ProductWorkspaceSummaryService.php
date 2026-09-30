<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductTypeGroupPlacement;
use App\Services\Sync\ProductChannelSelectionService;

final class ProductWorkspaceSummaryService
{
    public function __construct(
        private readonly ProductChannelSelectionService $channelSelectionService,
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
     * @return list<array{label:string,total:int,required:int}>
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

        return $product->productType->groupPlacements
            ->sortBy('sort_order')
            ->filter(fn (ProductTypeGroupPlacement $placement): bool => $placement->attributeGroup?->status === 'active')
            ->map(function (ProductTypeGroupPlacement $placement): array {
                $group = $placement->attributeGroup;
                $label = (string) (
                    $group?->localized_labels['uk']
                    ?? $group?->localized_labels['en']
                    ?? $group?->code
                    ?? 'Група'
                );

                return [
                    'label' => $label,
                    'total' => $placement->fieldPlacements->count(),
                    'required' => $placement->fieldPlacements
                        ->where('required_for_completeness', true)
                        ->count(),
                ];
            })
            ->values()
            ->all();
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
