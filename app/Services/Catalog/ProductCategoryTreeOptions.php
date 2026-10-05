<?php

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Support\Collection;

final class ProductCategoryTreeOptions
{
    /** @var array<string, array<int, string>> */
    private array $labelsByWorkspace = [];

    /**
     * @return array<int, string>
     */
    public function options(string $workspaceId): array
    {
        $labels = $this->labels($workspaceId);

        uasort(
            $labels,
            static fn (string $left, string $right): int => strnatcasecmp($left, $right),
        );

        return $labels;
    }

    public function label(Category $category): string
    {
        $workspaceId = (string) $category->workspace_id;

        return $this->labels($workspaceId)[(int) $category->getKey()]
            ?? (string) $category->name;
    }

    /**
     * @return array<int, string>
     */
    private function labels(string $workspaceId): array
    {
        return $this->labelsByWorkspace[$workspaceId] ??= $this->buildLabels($workspaceId);
    }

    /**
     * @return array<int, string>
     */
    private function buildLabels(string $workspaceId): array
    {
        /** @var Collection<int, Category> $categories */
        $categories = Category::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->get(['id', 'workspace_id', 'name', 'parent_id']);

        /** @var array<int, Category> $byId */
        $byId = $categories->keyBy(fn (Category $category): int => (int) $category->getKey())->all();

        $labels = [];

        foreach ($categories as $category) {
            $labels[(int) $category->getKey()] = $this->buildPath($category, $byId);
        }

        return $labels;
    }

    /**
     * @param  array<int, Category>  $byId
     */
    private function buildPath(Category $category, array $byId): string
    {
        $segments = [];
        $seen = [];
        $current = $category;

        while (true) {
            $currentId = (int) $current->getKey();

            if (isset($seen[$currentId])) {
                break;
            }

            $seen[$currentId] = true;
            array_unshift($segments, (string) $current->name);

            $parentId = $current->parent_id;

            if ($parentId === null || ! isset($byId[(int) $parentId])) {
                break;
            }

            $current = $byId[(int) $parentId];
        }

        return implode(' › ', $segments);
    }
}
