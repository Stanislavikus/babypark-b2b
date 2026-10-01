<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductTypeGroupPlacement;
use App\Models\VariantFieldValue;
use App\Services\Availability\AvailabilityResolver;
use App\Services\Pricing\MoneyFormatter;
use App\Services\Pricing\ProductPricingSummary;
use App\Services\ProductStructure\ProductCompletenessService;
use App\Services\Sync\ProductChannelSelectionService;

final class ProductWorkspaceSummaryService
{
    public function __construct(
        private readonly ProductChannelSelectionService $channelSelectionService,
        private readonly ProductCompletenessService $productCompletenessService,
        private readonly ProductVariantStructureService $productVariantStructureService,
        private readonly AvailabilityResolver $availabilityResolver,
        private readonly ProductPricingSummary $productPricingSummary,
        private readonly MoneyFormatter $moneyFormatter,
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
     *   active:bool,
     *   missing:list<string>
     * }>
     */
    public function attributeGroups(Product $product): array
    {
        $product->loadMissing([
            'productType.groupPlacements.attributeGroup',
            'productType.groupPlacements.fieldPlacements',
            'productType.groupPlacements.fieldPlacements.fieldBinding.fieldDefinition',
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

                $missingBindingIds = collect($groupProjection?->missingProductBindingIds ?? [])
                    ->merge(collect($groupProjection?->missingVariantCells ?? [])->pluck('field_binding_id'))
                    ->unique()
                    ->values();

                $missingLabels = $placement->fieldPlacements
                    ->filter(fn ($fieldPlacement): bool => $missingBindingIds->contains((string) $fieldPlacement->field_binding_id))
                    ->map(function ($fieldPlacement): string {
                        $definition = $fieldPlacement->fieldBinding?->fieldDefinition;

                        return (string) (
                            $definition?->localized_labels['uk']
                            ?? $definition?->localized_labels['en']
                            ?? $definition?->code
                            ?? $fieldPlacement->field_binding_id
                        );
                    })
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'label' => $label,
                    'total' => $placement->fieldPlacements->count(),
                    'required' => $groupProjection?->requiredCount ?? 0,
                    'filled' => $groupProjection?->filledCount ?? 0,
                    'percentage' => $groupProjection?->percentage ?? 100,
                    'optional' => (bool) $placement->is_optional,
                    'active' => $groupProjection?->isActive ?? ! $placement->is_optional,
                    'missing' => $missingLabels,
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
     * @return array{
     *   count:int,
     *   label:string,
     *   skus:list<string>,
     *   axes:list<array{binding_id:string,label:string}>,
     *   rows:list<array{id:int,sku:?string,gtin:?string,options:list<string>,price:?string,stock:int}>
     * }
     */
    public function variants(Product $product): array
    {
        $product->loadMissing('variants');

        $active = $product->variants->where('is_active', true)->values();
        $count = $active->count();
        $declaredAxes = $this->productVariantStructureService->declaredAxes($product);
        $candidates = $this->productVariantStructureService->axisCandidates($product);
        $axisBindingIds = $declaredAxes->pluck('field_binding_id')->map(fn ($id): string => (string) $id)->all();
        $values = $axisBindingIds === [] || $active->isEmpty()
            ? collect()
            : VariantFieldValue::withoutWorkspaceScope()
                ->where('workspace_id', $product->workspace_id)
                ->whereIn('variant_id', $active->pluck('id'))
                ->whereIn('field_binding_id', $axisBindingIds)
                ->get()
                ->keyBy(fn (VariantFieldValue $row): string => $row->variant_id.':'.$row->field_binding_id);

        $axes = $declaredAxes->map(function ($axis) use ($candidates): array {
            $bindingId = (string) $axis->field_binding_id;
            $candidate = $candidates->get($bindingId);

            return [
                'binding_id' => $bindingId,
                'label' => is_array($candidate)
                    ? (string) $candidate['label']
                    : (string) ($axis->fieldBinding?->fieldDefinition?->code ?? $bindingId),
            ];
        })->values()->all();

        $rows = $active->map(function ($variant) use ($declaredAxes, $candidates, $values): array {
            $options = [];
            foreach ($declaredAxes as $axis) {
                $bindingId = (string) $axis->field_binding_id;
                $code = $values->get($variant->id.':'.$bindingId)?->value_text;
                $candidate = $candidates->get($bindingId);
                $options[] = is_array($candidate) && is_string($code)
                    ? (string) ($candidate['options'][$code] ?? $code)
                    : (is_string($code) ? $code : '—');
            }

            $priceDisplay = $this->productPricingSummary->resolveDefaultDisplay($variant);
            $price = $priceDisplay->available && $priceDisplay->resolvedPrice !== null
                ? $this->moneyFormatter->format($priceDisplay->grossPrice, $priceDisplay->resolvedPrice->currency)
                : null;

            return [
                'id' => (int) $variant->id,
                'sku' => filled($variant->sku) ? (string) $variant->sku : null,
                'gtin' => filled($variant->barcode_ean) ? (string) $variant->barcode_ean : null,
                'options' => $options,
                'price' => $price,
                'stock' => $this->availabilityResolver->netAvailable($variant),
            ];
        })->values()->all();

        $label = $declaredAxes->isEmpty() && $count <= 1
            ? 'Простий товар'
            : $count.' варіантів';

        return [
            'count' => $count,
            'label' => $label,
            'skus' => $active
                ->pluck('sku')
                ->filter(fn ($sku): bool => is_string($sku) && trim($sku) !== '')
                ->take(6)
                ->values()
                ->all(),
            'axes' => $axes,
            'rows' => $rows,
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
