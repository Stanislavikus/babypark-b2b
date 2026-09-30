<?php

namespace App\Services\Catalog;

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
        $brand = $this->nullableTrimmedString($input['brand'] ?? null);
        $categoryId = $input['category_id'] ?? null;
        $merchantType = $this->nullableTrimmedString($input['merchant_type'] ?? null);
        $description = $this->nullableTrimmedString($input['description'] ?? null);
        $url = $this->nullableTrimmedString($input['url'] ?? null);

        if ($categoryId !== null && $categoryId !== '') {
            $categoryExists = Category::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereKey($categoryId)
                ->exists();

            if (! $categoryExists) {
                throw new InvalidArgumentException('Product category must belong to the active workspace.');
            }
        } else {
            $categoryId = null;
        }

        return DB::transaction(function () use (
            $workspace,
            $name,
            $sku,
            $ean,
            $brand,
            $categoryId,
            $merchantType,
            $description,
            $url,
        ): Product {
            $product = Product::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'onec_guid' => null,
                'sku' => $sku,
                'barcode_ean' => $ean,
                'name' => $name,
                'category_id' => $categoryId,
                'brand' => $brand,
                'merchant_type' => $merchantType,
                'description' => $description,
                'url' => $url,
                'is_active' => true,
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
