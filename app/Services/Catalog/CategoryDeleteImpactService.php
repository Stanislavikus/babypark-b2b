<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\ConnectorCategoryMapping;
use App\Models\Product;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;

final class CategoryDeleteImpactService
{
    public function __construct(
        private readonly CategoryHierarchyService $hierarchy,
    ) {}

    /**
     * @return array{
     *     products_count:int,
     *     children_count:int,
     *     mappings_count:int,
     *     adobe_mappings_count:int,
     *     stock_display_threshold:int,
     *     fingerprint:string
     * }
     */
    public function impact(Workspace $workspace, Category $category): array
    {
        $fresh = $this->categoryInWorkspace($workspace, $category);

        $products = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('category_id', $fresh->id)
            ->orderBy('id')
            ->get(['id']);
        $children = Category::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('parent_id', $fresh->id)
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'parent_id', 'sort_order', 'is_active', 'stock_display_threshold']);
        $mappings = ConnectorCategoryMapping::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('category_id', $fresh->id)
            ->orderBy('id')
            ->get(['id', 'connector_account_id', 'category_id', 'external_category_id']);

        return [
            'products_count' => $products->count(),
            'children_count' => $children->count(),
            'mappings_count' => $mappings->count(),
            'adobe_mappings_count' => count($this->adobeMappingAccountIds(
                (string) $workspace->id,
                (int) $fresh->id,
            )),
            'stock_display_threshold' => (int) $fresh->stock_display_threshold,
            'fingerprint' => $this->fingerprint($fresh, $products, $children, $mappings),
        ];
    }

    public function fingerprint(
        Category $category,
        iterable $products,
        iterable $children,
        iterable $mappings,
    ): string {
        $payload = [
            'category' => [
                'id' => (int) $category->id,
                'workspace_id' => (string) $category->workspace_id,
                'name' => (string) $category->name,
                'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                'sort_order' => (int) $category->sort_order,
                'is_active' => (bool) $category->is_active,
                'stock_display_threshold' => (int) $category->stock_display_threshold,
            ],
            'product_ids' => collect($products)
                ->map(static fn ($product): int => (int) $product->id)
                ->sort()
                ->values()
                ->all(),
            'children' => collect($children)
                ->map(static fn (Category $child): array => [
                    'id' => (int) $child->id,
                    'parent_id' => $child->parent_id === null ? null : (int) $child->parent_id,
                    'sort_order' => (int) $child->sort_order,
                    'is_active' => (bool) $child->is_active,
                    'stock_display_threshold' => (int) $child->stock_display_threshold,
                ])
                ->sortBy('id')
                ->values()
                ->all(),
            'mappings' => collect($mappings)
                ->map(static fn (ConnectorCategoryMapping $mapping): array => [
                    'id' => (string) $mapping->id,
                    'connector_account_id' => (string) $mapping->connector_account_id,
                    'external_category_id' => (string) $mapping->external_category_id,
                ])
                ->sortBy('id')
                ->values()
                ->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<int|string, string> */
    public function destinationOptions(Workspace $workspace, Category $category): array
    {
        $fresh = $this->categoryInWorkspace($workspace, $category);
        $options = $this->hierarchy->selectableOptions((string) $workspace->id);

        unset($options[(int) $fresh->id]);

        $destinations = ['__uncategorized__' => 'Без категорії'];

        foreach ($options as $id => $label) {
            $destinations[(int) $id] = $label;
        }

        return $destinations;
    }

    public function missingAdobeMappingCount(
        Workspace $workspace,
        Category $source,
        int $destinationCategoryId,
    ): int {
        $source = $this->categoryInWorkspace($workspace, $source);
        $destinationExists = Category::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($destinationCategoryId)
            ->exists();

        if (! $destinationExists) {
            return 0;
        }

        $sourceAccountIds = $this->adobeMappingAccountIds(
            (string) $workspace->id,
            (int) $source->id,
        );

        if ($sourceAccountIds === []) {
            return 0;
        }

        $destinationAccountIds = $this->adobeMappingAccountIds(
            (string) $workspace->id,
            $destinationCategoryId,
        );

        return count(array_diff($sourceAccountIds, $destinationAccountIds));
    }

    private function categoryInWorkspace(Workspace $workspace, Category $category): Category
    {
        $fresh = Category::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($category->id)
            ->first();

        if (! $fresh instanceof Category) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $fresh;
    }

    /** @return list<string> */
    private function adobeMappingAccountIds(string $workspaceId, int $categoryId): array
    {
        return ConnectorCategoryMapping::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('category_id', $categoryId)
            ->whereHas(
                'connectorAccount.connectorDefinition',
                fn ($query) => $query->where('code', 'adobe_commerce'),
            )
            ->orderBy('connector_account_id')
            ->pluck('connector_account_id')
            ->map(static fn ($value): string => (string) $value)
            ->values()
            ->all();
    }
}
