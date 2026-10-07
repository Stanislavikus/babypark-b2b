<?php

namespace App\Services\Catalog;

use App\Enums\ProductLifecycleStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MasterProductDraftCreator
{
    public function create(Workspace $workspace, array $input): Product
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('Product name is required.');
        }

        $sku = $this->nullableTrimmedString($input['sku'] ?? null);
        $ean = $this->nullableTrimmedString($input['barcode_ean'] ?? null);
        $brandId = filled($input['brand_id'] ?? null) ? (string) $input['brand_id'] : null;
        $categoryId = $input['category_id'] ?? null;
        $merchantType = $this->nullableTrimmedString($input['merchant_type'] ?? null);
        $description = $this->nullableTrimmedString($input['description'] ?? null);
        $url = $this->nullableTrimmedString($input['url'] ?? null);
        $lifecycle = ProductLifecycleStatus::tryFrom((string) ($input['lifecycle_status'] ?? ''))
            ?? ProductLifecycleStatus::Draft;
        $physical = [
            'net_weight' => $input['net_weight'] ?? null,
            'gross_weight' => $input['gross_weight'] ?? null,
            'width_mm' => $input['width_mm'] ?? null,
            'height_mm' => $input['height_mm'] ?? null,
            'depth_mm' => $input['depth_mm'] ?? null,
            'volume_m3' => $input['volume_m3'] ?? null,
            'package_quantity' => $input['package_quantity'] ?? null,
            'package_type' => $this->nullableTrimmedString($input['package_type'] ?? null),
            'units_per_box' => $input['units_per_box'] ?? null,
            'boxes_per_pallet' => $input['boxes_per_pallet'] ?? null,
            'lead_time_days' => $input['lead_time_days'] ?? null,
        ];

        if ($categoryId !== null && $categoryId !== '') {
            $categoryExists = Category::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereKey($categoryId)
                ->exists();

            if (! $categoryExists) {
                throw new InvalidArgumentException('Product category must belong to the active workspace.');
            }

            if (! in_array(
                (int) $categoryId,
                app(CategoryHierarchyService::class)->effectiveActiveIds((string) $workspace->id),
                true,
            )) {
                throw new InvalidArgumentException('Product category must be active in the full category path.');
            }
        } else {
            $categoryId = null;
        }

        if ($brandId !== null) {
            $brandExists = Brand::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereKey($brandId)
                ->where('is_active', true)
                ->exists();

            if (! $brandExists) {
                throw new InvalidArgumentException('Product brand must be an active Brand in the active workspace.');
            }
        }

        return DB::transaction(function () use (
            $workspace,
            $name,
            $sku,
            $ean,
            $brandId,
            $categoryId,
            $merchantType,
            $description,
            $url,
            $lifecycle,
            $physical,
        ): Product {
            if ($brandId !== null) {
                $lockedBrand = Brand::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($brandId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedBrand instanceof Brand) {
                    throw new InvalidArgumentException('Product brand must be an active Brand in the active workspace.');
                }
            }

            $product = Product::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'onec_guid' => null,
                'sku' => $sku,
                'barcode_ean' => $ean,
                'name' => $name,
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'merchant_type' => $merchantType,
                'description' => $description,
                'url' => $url,
                ...$physical,
                'is_active' => $lifecycle->compatibilityIsActive(),
                'lifecycle_status' => $lifecycle,
            ]);

            ProductVariant::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'product_id' => $product->id,
                'onec_guid' => null,
                'sku' => $sku,
                'barcode_ean' => $ean,
                'attributes' => [],
                'is_active' => true,
            ]);

            return $product->fresh(['variants', 'productType']);
        });
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
