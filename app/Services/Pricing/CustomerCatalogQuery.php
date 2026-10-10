<?php

namespace App\Services\Pricing;

use App\Enums\CatalogSort;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Workspace;
use App\Services\Catalog\CategoryHierarchyService;
use App\Support\Pricing\CustomerCatalogCriteria;
use App\Support\Pricing\CustomerPricingScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CustomerCatalogQuery
{
    public function paginateFor(Customer $customer, CustomerCatalogCriteria $criteria): LengthAwarePaginator
    {
        $query = CustomerPricingScope::applyProductScope(
            Product::query()->where('is_active', true),
            $customer,
        )->with(CustomerPricingScope::eagerLoadForCustomer($customer));

        if (filled($criteria->search)) {
            $search = $criteria->search;
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhereHas('brand', fn (Builder $brandQuery): Builder => $brandQuery->where('name', 'like', "%{$search}%"))
            );
        }

        if ($criteria->categoryIds !== []) {
            $categoryIds = app(CategoryHierarchyService::class)->activeDescendantIds(
                (string) $customer->workspace_id,
                $criteria->categoryIds,
            );

            if ($categoryIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('category_id', $categoryIds);
            }
        }

        if ($criteria->brandIds !== []) {
            $query->whereIn('brand_id', $criteria->brandIds);
        }

        $query = $this->applySorting($query, $customer, $criteria->sort);

        return $query->paginate($criteria->perPage);
    }

    /** @return array<string, string> */
    public function availableBrands(Customer $customer): array
    {
        $brandIds = CustomerPricingScope::applyProductScope(
            Product::query()->where('is_active', true)->whereNotNull('brand_id'),
            $customer,
        )->distinct()->pluck('brand_id')->filter()->values()->all();

        return Brand::withoutWorkspaceScope()
            ->where('workspace_id', $customer->workspace_id)
            ->whereIn('id', $brandIds)
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();
    }

    private function applySorting(Builder $query, Customer $customer, CatalogSort $sort): Builder
    {
        $legacy = $sort->toLegacy();
        $dir = $legacy['sortDir'];
        $priceListId = CustomerPricingScope::priceListIdFor($customer);
        $workspaceRate = app(WorkspaceTaxDefaults::class)->resolveWorkspaceRate(
            $customer->workspace ?? Workspace::query()->findOrFail($customer->workspace_id)
        );

        return match ($legacy['sortBy']) {
            'sku' => $query->orderBy('sku', $dir),
            'name' => $query->orderBy('name', $dir),
            'category' => $query->orderBy(
                Category::select('name')
                    ->whereColumn('categories.id', 'products.category_id')
                    ->limit(1),
                $dir
            ),
            'brand' => $query->orderBy(
                Brand::withoutWorkspaceScope()
                    ->select('name')
                    ->whereColumn('brands.id', 'products.brand_id')
                    ->whereColumn('brands.workspace_id', 'products.workspace_id')
                    ->limit(1),
                $dir
            ),
            'stock' => $this->applyStockSorting($query, $dir),
            'price' => $priceListId
                ? (function () use ($query, $priceListId, $dir, $workspaceRate) {
                    $priceExpr = PricingSqlExpressions::maxGrossPriceSqlForProduct('products.id', $priceListId, $workspaceRate);

                    return $query->orderByRaw("CASE WHEN ({$priceExpr}) IS NULL THEN 1 ELSE 0 END ASC")
                        ->orderByRaw("{$priceExpr} {$dir}")
                        ->orderBy('products.id', 'asc');
                })()
                : $query->orderBy('sku', $dir),
            'rrp' => $query->orderByRaw(
                'COALESCE('.PricingSqlExpressions::maxRrpSqlForProduct('products.id').", 0) {$dir}"
            ),
            'margin' => $priceListId
                ? (function () use ($query, $priceListId, $dir, $workspaceRate) {
                    $marginExpr = PricingSqlExpressions::customerMarginSortSql('products.id', $priceListId, $workspaceRate);
                    $minGrossExpr = PricingSqlExpressions::minGrossPriceSqlForProduct('products.id', $priceListId, $workspaceRate);

                    return $query->orderByRaw("CASE WHEN ({$minGrossExpr}) IS NULL THEN 1 ELSE 0 END ASC")
                        ->orderByRaw("{$marginExpr} {$dir}")
                        ->orderBy('products.id', 'asc');
                })()
                : $query->orderBy('sku', $dir),
            default => $query->orderBy('sku', 'asc'),
        };
    }

    private function applyStockSorting(Builder $query, string $dir): Builder
    {
        $totalQty = '(SELECT COALESCE(SUM(s.quantity), 0)
                      FROM stocks s
                      INNER JOIN product_variants pv ON s.variant_id = pv.id
                      WHERE pv.product_id = products.id)';

        $minExpectedDate = '(SELECT MIN(s.expected_date)
                            FROM stocks s
                            INNER JOIN product_variants pv ON s.variant_id = pv.id
                            WHERE pv.product_id = products.id
                            AND s.expected_date IS NOT NULL)';

        $priorityExpr = "CASE
            WHEN {$totalQty} > 0 THEN 0
            WHEN {$minExpectedDate} IS NOT NULL THEN 1
            ELSE 2
        END";

        return $query
            ->orderByRaw("{$priorityExpr} {$dir}")
            ->orderByRaw("{$totalQty} DESC")
            ->orderByRaw("{$minExpectedDate} ASC");
    }
}
