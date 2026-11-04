<?php

namespace App\Services\Catalog;

use App\Models\Category;

final class ProductCategoryTreeOptions
{
    public function __construct(
        private readonly CategoryHierarchyService $hierarchy,
    ) {}

    /** @return array<int, string> */
    public function options(string $workspaceId): array
    {
        $labels = $this->hierarchy->labels($workspaceId);

        uasort($labels, static fn (string $left, string $right): int => strnatcasecmp($left, $right));

        return $labels;
    }

    /** @return array<int, string> */
    public function selectableOptions(string $workspaceId, ?int $includeCategoryId = null): array
    {
        return $this->hierarchy->selectableOptions($workspaceId, $includeCategoryId);
    }

    public function label(Category $category): string
    {
        return $this->hierarchy->labels((string) $category->workspace_id)[(int) $category->id]
            ?? (string) $category->name;
    }
}
