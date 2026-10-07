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
     *     stock_display_threshold:int
     * }
     */
    public function impact(Workspace $workspace, Category $category): array
    {
        $fresh = $this->categoryInWorkspace($workspace, $category);

        return [
            'products_count' => Product::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('category_id', $fresh->id)
                ->count(),
            'children_count' => Category::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('parent_id', $fresh->id)
                ->count(),
            'mappings_count' => ConnectorCategoryMapping::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('category_id', $fresh->id)
                ->count(),
            'adobe_mappings_count' => count($this->adobeMappingAccountIds(
                (string) $workspace->id,
                (int) $fresh->id,
            )),
            'stock_display_threshold' => (int) $fresh->stock_display_threshold,
        ];
    }

    /** @return array<string, string> */
    public function destinationOptions(Workspace $workspace, Category $category): array
    {
        $fresh = $this->categoryInWorkspace($workspace, $category);
        $options = $this->hierarchy->selectableOptions((string) $workspace->id);

        unset($options[(int) $fresh->id]);

        return ['__uncategorized__' => 'Без категорії'] + array_combine(
            array_map('strval', array_keys($options)),
            array_values($options),
        );
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
