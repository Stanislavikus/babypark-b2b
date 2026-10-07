<?php

namespace App\Services\Catalog;

use App\Exceptions\Catalog\CategoryTreeMutationException;
use App\Models\Category;
use App\Models\ConnectorCategoryMapping;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CategoryTreeMutationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    /** @param array{name?: mixed, parent_id?: mixed, is_active?: mixed, stock_display_threshold?: mixed} $input */
    public function create(User $actor, Workspace $workspace, array $input): Category
    {
        return DB::transaction(function () use ($actor, $workspace, $input): Category {
            $lockedWorkspace = $this->lockWorkspaceAndAuthorize($actor, $workspace);
            $categories = $this->lockCategories($lockedWorkspace);

            $name = $this->name($input['name'] ?? null);
            $parentId = $this->nullableId($input['parent_id'] ?? null);
            $this->assertParentExists($categories, $parentId);

            $nextSort = $categories
                ->filter(fn (Category $category): bool => $this->sameParent($category->parent_id, $parentId))
                ->max('sort_order');

            return Category::withoutWorkspaceScope()->create([
                'workspace_id' => $lockedWorkspace->id,
                'onec_guid' => null,
                'name' => $name,
                'parent_id' => $parentId,
                'sort_order' => ((int) ($nextSort ?? -1)) + 1,
                'is_active' => (bool) ($input['is_active'] ?? true),
                'stock_display_threshold' => $this->threshold($input['stock_display_threshold'] ?? 10),
            ]);
        });
    }

    /** @param array{name?: mixed, parent_id?: mixed, is_active?: mixed, stock_display_threshold?: mixed} $input */
    public function update(User $actor, Workspace $workspace, Category $category, array $input): Category
    {
        return DB::transaction(function () use ($actor, $workspace, $category, $input): Category {
            $lockedWorkspace = $this->lockWorkspaceAndAuthorize($actor, $workspace);
            $categories = $this->lockCategories($lockedWorkspace);
            $lockedCategory = $categories->firstWhere('id', (int) $category->id);

            if (! $lockedCategory instanceof Category) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            $parentId = $this->nullableId($input['parent_id'] ?? null);
            $this->assertParentExists($categories, $parentId);
            $this->assertNoCycle($categories, (int) $lockedCategory->id, $parentId);

            $sortOrder = (int) $lockedCategory->sort_order;
            if (! $this->sameParent($lockedCategory->parent_id, $parentId)) {
                $maxSort = $categories
                    ->reject(fn (Category $candidate): bool => (int) $candidate->id === (int) $lockedCategory->id)
                    ->filter(fn (Category $candidate): bool => $this->sameParent($candidate->parent_id, $parentId))
                    ->max('sort_order');
                $sortOrder = ((int) ($maxSort ?? -1)) + 1;
            }

            $lockedCategory->update([
                'name' => $this->name($input['name'] ?? null),
                'parent_id' => $parentId,
                'sort_order' => $sortOrder,
                'is_active' => (bool) ($input['is_active'] ?? true),
                'stock_display_threshold' => $this->threshold($input['stock_display_threshold'] ?? 10),
            ]);

            return $lockedCategory->refresh();
        });
    }

    public function setActive(User $actor, Workspace $workspace, Category $category, bool $active): Category
    {
        return DB::transaction(function () use ($actor, $workspace, $category, $active): Category {
            $lockedWorkspace = $this->lockWorkspaceAndAuthorize($actor, $workspace);
            $lockedCategory = Category::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereKey($category->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedCategory->update(['is_active' => $active]);

            return $lockedCategory->refresh();
        });
    }

    /**
     * @param  array{products_count:int,children_count:int,mappings_count:int}  $expectedImpact
     */
    public function deleteSingle(
        User $actor,
        Workspace $workspace,
        Category $category,
        ?int $destinationCategoryId,
        array $expectedImpact,
    ): void {
        DB::transaction(function () use (
            $actor,
            $workspace,
            $category,
            $destinationCategoryId,
            $expectedImpact,
        ): void {
            $lockedWorkspace = $this->lockWorkspaceAndAuthorize($actor, $workspace);
            $categories = $this->lockCategories($lockedWorkspace);
            $lockedCategory = $categories->firstWhere('id', (int) $category->id);

            if (! $lockedCategory instanceof Category) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            $products = Product::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('category_id', $lockedCategory->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'category_id']);

            $mappings = ConnectorCategoryMapping::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('category_id', $lockedCategory->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $children = $categories
                ->filter(fn (Category $candidate): bool => $candidate->parent_id !== null
                    && (int) $candidate->parent_id === (int) $lockedCategory->id)
                ->values();

            $actualImpact = [
                'products_count' => $products->count(),
                'children_count' => $children->count(),
                'mappings_count' => $mappings->count(),
            ];

            if ($actualImpact !== [
                'products_count' => (int) ($expectedImpact['products_count'] ?? -1),
                'children_count' => (int) ($expectedImpact['children_count'] ?? -1),
                'mappings_count' => (int) ($expectedImpact['mappings_count'] ?? -1),
            ]) {
                throw CategoryTreeMutationException::staleDeleteImpact();
            }

            $destination = null;
            if ($destinationCategoryId !== null) {
                $destination = $categories->firstWhere('id', $destinationCategoryId);

                if (! $destination instanceof Category
                    || (int) $destination->id === (int) $lockedCategory->id
                    || ! $this->isEffectivelyActive($categories, (int) $destination->id)
                ) {
                    throw CategoryTreeMutationException::invalidDeleteDestination();
                }
            }

            if ($products->isNotEmpty()) {
                Product::withoutWorkspaceScope()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->whereIn('id', $products->pluck('id')->all())
                    ->where('category_id', $lockedCategory->id)
                    ->update(['category_id' => $destination?->id]);
            }

            $this->reparentDeletedCategoryChildren($categories, $lockedCategory);

            foreach ($mappings as $mapping) {
                $mapping->delete();
            }

            $lockedCategory->delete();
        });
    }

    /** @param array<int|string, mixed> $nodes */
    public function saveTree(User $actor, Workspace $workspace, array $nodes): void
    {
        DB::transaction(function () use ($actor, $workspace, $nodes): void {
            $lockedWorkspace = $this->lockWorkspaceAndAuthorize($actor, $workspace);
            $categories = $this->lockCategories($lockedWorkspace);

            $placements = [];
            $seen = [];
            $this->flattenTree($nodes, null, $placements, $seen);

            $expected = $categories->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $actual = array_keys($placements);
            sort($actual);

            if ($expected !== $actual) {
                throw CategoryTreeMutationException::staleTree();
            }

            foreach ($categories as $category) {
                $placement = $placements[(int) $category->id];

                if (! $this->sameParent($category->parent_id, $placement['original_parent_id'])
                    || (int) $category->sort_order !== $placement['original_sort_order']) {
                    throw CategoryTreeMutationException::staleTree();
                }
            }

            foreach ($categories as $category) {
                $placement = $placements[(int) $category->id];

                $category->update([
                    'parent_id' => $placement['parent_id'],
                    'sort_order' => $placement['sort_order'],
                ]);
            }
        });
    }

    private function lockWorkspaceAndAuthorize(User $actor, Workspace $workspace): Workspace
    {
        $lockedWorkspace = Workspace::query()
            ->whereKey($workspace->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCTS)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $lockedWorkspace;
    }

    /** @return Collection<int, Category> */
    private function lockCategories(Workspace $workspace): Collection
    {
        return Category::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function assertParentExists(Collection $categories, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if (! $categories->contains(fn (Category $category): bool => (int) $category->id === $parentId)) {
            throw CategoryTreeMutationException::invalidParent();
        }
    }

    private function assertNoCycle(Collection $categories, int $categoryId, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $categoryId) {
            throw CategoryTreeMutationException::cycle();
        }

        /** @var array<int, Category> $byId */
        $byId = $categories->keyBy(fn (Category $category): int => (int) $category->id)->all();
        $seen = [];
        $currentId = $parentId;

        while (isset($byId[$currentId])) {
            if ($currentId === $categoryId || isset($seen[$currentId])) {
                throw CategoryTreeMutationException::cycle();
            }

            $seen[$currentId] = true;
            $parent = $byId[$currentId]->parent_id;
            if ($parent === null) {
                return;
            }

            $currentId = (int) $parent;
        }
    }

    /**
     * @param  array<int|string, mixed>  $nodes
     * @param  array<int, array{parent_id:?int,sort_order:int,original_parent_id:?int,original_sort_order:int}>  $placements
     * @param  array<int, true>  $seen
     */
    private function flattenTree(array $nodes, ?int $parentId, array &$placements, array &$seen): void
    {
        foreach (array_values($nodes) as $sortOrder => $node) {
            if (! is_array($node)) {
                throw CategoryTreeMutationException::invalidTreePayload();
            }

            $id = filter_var($node['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1 || isset($seen[$id])) {
                throw CategoryTreeMutationException::invalidTreePayload();
            }

            $originalParentId = $this->nullableId($node['original_parent_id'] ?? null);
            $originalSortOrder = filter_var($node['original_sort_order'] ?? null, FILTER_VALIDATE_INT);

            if ($originalSortOrder === false || $originalSortOrder < 0) {
                throw CategoryTreeMutationException::invalidTreePayload();
            }

            $seen[$id] = true;
            $placements[$id] = [
                'parent_id' => $parentId,
                'sort_order' => $sortOrder,
                'original_parent_id' => $originalParentId,
                'original_sort_order' => $originalSortOrder,
            ];

            $children = $node['children'] ?? [];
            if (! is_array($children)) {
                throw CategoryTreeMutationException::invalidTreePayload();
            }

            $this->flattenTree($children, $id, $placements, $seen);
        }
    }

    /** @param  Collection<int, Category>  $categories */
    private function reparentDeletedCategoryChildren(Collection $categories, Category $deleted): void
    {
        $parentId = $deleted->parent_id === null ? null : (int) $deleted->parent_id;

        $siblings = $categories
            ->filter(fn (Category $candidate): bool => $this->sameParent($candidate->parent_id, $parentId))
            ->sort($this->categoryOrder(...))
            ->values();

        $children = $categories
            ->filter(fn (Category $candidate): bool => $candidate->parent_id !== null
                && (int) $candidate->parent_id === (int) $deleted->id)
            ->sort($this->categoryOrder(...))
            ->values();

        $wouldRevealChildren = ! $deleted->is_active
            && $this->parentChainIsEffectivelyActive($categories, $parentId);

        $nextOrder = 0;
        foreach ($siblings as $sibling) {
            if ((int) $sibling->id !== (int) $deleted->id) {
                if ((int) $sibling->sort_order !== $nextOrder) {
                    $sibling->update(['sort_order' => $nextOrder]);
                }

                $nextOrder++;

                continue;
            }

            foreach ($children as $child) {
                $attributes = [
                    'parent_id' => $parentId,
                    'sort_order' => $nextOrder++,
                ];

                if ($wouldRevealChildren && $child->is_active) {
                    $attributes['is_active'] = false;
                }

                $child->update($attributes);
            }
        }
    }

    private function categoryOrder(Category $left, Category $right): int
    {
        return [(int) $left->sort_order, mb_strtolower((string) $left->name), (int) $left->id]
            <=> [(int) $right->sort_order, mb_strtolower((string) $right->name), (int) $right->id];
    }

    /** @param  Collection<int, Category>  $categories */
    private function isEffectivelyActive(Collection $categories, int $categoryId): bool
    {
        /** @var array<int, Category> $byId */
        $byId = $categories->keyBy(fn (Category $category): int => (int) $category->id)->all();
        $seen = [];
        $currentId = $categoryId;

        while (isset($byId[$currentId])) {
            if (isset($seen[$currentId])) {
                return false;
            }

            $seen[$currentId] = true;
            $current = $byId[$currentId];

            if (! $current->is_active) {
                return false;
            }

            if ($current->parent_id === null) {
                return true;
            }

            $currentId = (int) $current->parent_id;
        }

        return false;
    }

    /** @param  Collection<int, Category>  $categories */
    private function parentChainIsEffectivelyActive(Collection $categories, ?int $parentId): bool
    {
        return $parentId === null || $this->isEffectivelyActive($categories, $parentId);
    }

    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';

        if ($name === '' || mb_strlen($name) > 255) {
            throw CategoryTreeMutationException::invalidName();
        }

        return $name;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id !== false && $id > 0
            ? $id
            : throw CategoryTreeMutationException::invalidParent();
    }

    private function threshold(mixed $value): int
    {
        $threshold = filter_var($value, FILTER_VALIDATE_INT);

        return $threshold !== false && $threshold >= 0 ? $threshold : 10;
    }

    private function sameParent(mixed $left, ?int $right): bool
    {
        return $left === null ? $right === null : (int) $left === $right;
    }
}
