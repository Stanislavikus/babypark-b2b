<?php

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Support\Collection;

final class CategoryHierarchyService
{
    /** @var array<string, Collection<int, Category>> */
    private array $categoriesByWorkspace = [];

    /** @var array<string, array<int, string>> */
    private array $labelsByWorkspace = [];

    /** @var array<string, array<int, bool>> */
    private array $effectiveActiveByWorkspace = [];

    /** @return array<int, string> */
    public function labels(string $workspaceId): array
    {
        return $this->labelsByWorkspace[$workspaceId] ??= $this->buildLabels($workspaceId);
    }

    /** @return array<int, string> */
    public function selectableOptions(string $workspaceId, ?int $includeCategoryId = null): array
    {
        $labels = $this->labels($workspaceId);
        $active = $this->effectiveActiveMap($workspaceId);

        $options = array_filter(
            $labels,
            static fn (string $label, int $id): bool => ($active[$id] ?? false) || $id === $includeCategoryId,
            ARRAY_FILTER_USE_BOTH,
        );

        uasort($options, static fn (string $left, string $right): int => strnatcasecmp($left, $right));

        return $options;
    }

    /**
     * @param  list<int|string>  $selectedIds
     * @return list<int>
     */
    public function activeDescendantIds(string $workspaceId, array $selectedIds): array
    {
        $categories = $this->categories($workspaceId);
        $active = $this->effectiveActiveMap($workspaceId);
        $children = [];

        foreach ($categories as $category) {
            $parentId = $category->parent_id === null ? null : (int) $category->parent_id;
            $children[$parentId] ??= [];
            $children[$parentId][] = (int) $category->id;
        }

        $queue = [];
        foreach ($selectedIds as $selectedId) {
            $id = filter_var($selectedId, FILTER_VALIDATE_INT);
            if ($id !== false && $id > 0 && ($active[$id] ?? false)) {
                $queue[] = $id;
            }
        }

        $result = [];
        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($result[$id])) {
                continue;
            }

            $result[$id] = true;

            foreach ($children[$id] ?? [] as $childId) {
                if ($active[$childId] ?? false) {
                    $queue[] = $childId;
                }
            }
        }

        $ids = array_keys($result);
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    public function effectiveActiveIds(string $workspaceId): array
    {
        return array_keys(array_filter($this->effectiveActiveMap($workspaceId)));
    }

    /** @return Collection<int, Category> */
    private function categories(string $workspaceId): Collection
    {
        return $this->categoriesByWorkspace[$workspaceId] ??= Category::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'name', 'parent_id', 'sort_order', 'is_active']);
    }

    /** @return array<int, string> */
    private function buildLabels(string $workspaceId): array
    {
        $categories = $this->categories($workspaceId);
        /** @var array<int, Category> $byId */
        $byId = $categories->keyBy(fn (Category $category): int => (int) $category->id)->all();
        $labels = [];

        foreach ($categories as $category) {
            $segments = [];
            $seen = [];
            $current = $category;

            while (true) {
                $id = (int) $current->id;

                if (isset($seen[$id])) {
                    break;
                }

                $seen[$id] = true;
                array_unshift($segments, (string) $current->name);

                if ($current->parent_id === null || ! isset($byId[(int) $current->parent_id])) {
                    break;
                }

                $current = $byId[(int) $current->parent_id];
            }

            $labels[(int) $category->id] = implode(' › ', $segments);
        }

        return $labels;
    }

    /** @return array<int, bool> */
    private function effectiveActiveMap(string $workspaceId): array
    {
        return $this->effectiveActiveByWorkspace[$workspaceId] ??= $this->buildEffectiveActiveMap($workspaceId);
    }

    /** @return array<int, bool> */
    private function buildEffectiveActiveMap(string $workspaceId): array
    {
        $categories = $this->categories($workspaceId);
        /** @var array<int, Category> $byId */
        $byId = $categories->keyBy(fn (Category $category): int => (int) $category->id)->all();
        $resolved = [];

        $resolve = function (int $id, array $trail = []) use (&$resolve, &$resolved, $byId): bool {
            if (array_key_exists($id, $resolved)) {
                return $resolved[$id];
            }

            if (isset($trail[$id]) || ! isset($byId[$id])) {
                return $resolved[$id] = false;
            }

            $category = $byId[$id];
            if (! $category->is_active) {
                return $resolved[$id] = false;
            }

            if ($category->parent_id === null) {
                return $resolved[$id] = true;
            }

            $trail[$id] = true;

            return $resolved[$id] = $resolve((int) $category->parent_id, $trail);
        };

        foreach (array_keys($byId) as $id) {
            $resolve((int) $id);
        }

        return $resolved;
    }
}
