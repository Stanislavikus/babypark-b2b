<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Exceptions\Catalog\CategoryTreeMutationException;
use App\Filament\Resources\CategoryResource;
use App\Models\Category;
use App\Models\User;
use App\Services\Catalog\CategoryTreeMutationService;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use SolutionForest\FilamentNestableTree\Filament\Resources\Pages\TreePage;
use SolutionForest\FilamentNestableTree\Tree;

class ManageCategoryTree extends TreePage
{
    public static string $resource = CategoryResource::class;

    protected static ?string $title = 'Категорії';

    public function tree(Tree $tree): Tree
    {
        return $tree
            ->records(fn (): array => $this->treeRecords())
            ->recordKeyField('id')
            ->parentKeyField('parent_id')
            ->childrenField('children')
            ->labelField('tree_label')
            ->searchable()
            ->maxVisibleDepth(20)
            ->allowCrossCategory()
            ->getRecordUsing(fn (int|string $id): ?Category => Category::query()->find($id))
            ->saveOrderUsing(function (array $nodes): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new AuthorizationException('This action is unauthorized.');
                }

                app(CategoryTreeMutationService::class)->saveTree(
                    $actor,
                    app(WorkspaceContext::class)->current(),
                    $nodes,
                );
            })
            ->prependToolbarActions([
                $this->createCategoryAction(),
            ]);
    }

    /**
     * Persist drag/drop only through the BabyPark writer while preserving the
     * package's success notification on committed writes.
     *
     * @param  array<int|string, mixed>  $orderedNodes
     */
    public function saveTreeOrder(array $orderedNodes = [], ?string $treeKey = null): void
    {
        try {
            parent::saveTreeOrder($orderedNodes, $treeKey);
        } catch (CategoryTreeMutationException|AuthorizationException $exception) {
            Notification::make()
                ->warning()
                ->title('Дерево не збережено')
                ->body($exception->getMessage())
                ->send();

            $this->resetTreeOrder($treeKey);

            return;
        }

        // Refresh original placement tokens after a successful commit so the
        // next reorder compares against the canonical server state.
        $this->refreshTreeNodes();
    }

    protected function buildTree(): Tree
    {
        return $this->buildBaseTree()
            ->nodeActions([
                $this->editCategoryAction(),
                $this->addChildAction(),
                $this->toggleActiveAction(),
            ]);
    }

    private function createCategoryAction(): Action
    {
        return Action::make('create_category')
            ->label('Додати категорію')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => CategoryResource::getCreateAuthorizationResponse()->allowed())
            ->schema(fn (Schema $schema): Schema => CategoryResource::form($schema->columns(2)))
            ->action(function (array $data): void {
                $this->mutate(fn (User $actor) => app(CategoryTreeMutationService::class)->create(
                    $actor,
                    app(WorkspaceContext::class)->current(),
                    $data,
                ), 'Категорію створено');
            });
    }

    private function editCategoryAction(): Action
    {
        return Action::make('edit_category')
            ->label('Редагувати')
            ->icon('heroicon-o-pencil-square')
            ->iconButton()
            ->visible(fn (?Category $record): bool => $record instanceof Category
                && CategoryResource::getEditAuthorizationResponse($record)->allowed())
            ->fillForm(fn (?Category $record): array => $record?->only([
                'name',
                'parent_id',
                'is_active',
                'stock_display_threshold',
            ]) ?? [])
            ->schema(fn (Schema $schema): Schema => CategoryResource::form($schema->columns(2)))
            ->action(function (array $data, ?Category $record): void {
                if (! $record instanceof Category) {
                    throw new AuthorizationException('This action is unauthorized.');
                }

                $this->mutate(fn (User $actor) => app(CategoryTreeMutationService::class)->update(
                    $actor,
                    app(WorkspaceContext::class)->current(),
                    $record,
                    $data,
                ), 'Категорію оновлено');
            });
    }

    private function addChildAction(): Action
    {
        return Action::make('add_child_category')
            ->label('Додати підкатегорію')
            ->icon('heroicon-o-plus-circle')
            ->iconButton()
            ->visible(fn (?Category $record): bool => $record instanceof Category
                && CategoryResource::getCreateAuthorizationResponse()->allowed())
            ->schema([
                TextInput::make('name')
                    ->label('Назва')
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label('Активна')
                    ->default(true),
            ])
            ->action(function (array $data, ?Category $record): void {
                if (! $record instanceof Category) {
                    throw new AuthorizationException('This action is unauthorized.');
                }

                $data['parent_id'] = $record->id;

                $this->mutate(fn (User $actor) => app(CategoryTreeMutationService::class)->create(
                    $actor,
                    app(WorkspaceContext::class)->current(),
                    $data,
                ), 'Підкатегорію створено');
            });
    }

    private function toggleActiveAction(): Action
    {
        return Action::make('toggle_category_active')
            ->label(fn (?Category $record): string => $record?->is_active ? 'Деактивувати' : 'Активувати')
            ->icon(fn (?Category $record): string => $record?->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
            ->color(fn (?Category $record): string => $record?->is_active ? 'gray' : 'success')
            ->iconButton()
            ->visible(fn (?Category $record): bool => $record instanceof Category
                && CategoryResource::getEditAuthorizationResponse($record)->allowed())
            ->requiresConfirmation()
            ->action(function (?Category $record): void {
                if (! $record instanceof Category) {
                    throw new AuthorizationException('This action is unauthorized.');
                }

                $this->mutate(fn (User $actor) => app(CategoryTreeMutationService::class)->setActive(
                    $actor,
                    app(WorkspaceContext::class)->current(),
                    $record,
                    ! $record->is_active,
                ), $record->is_active ? 'Категорію деактивовано' : 'Категорію активовано');
            });
    }

    /** @return list<array<string, mixed>> */
    private function treeRecords(): array
    {
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $byParent = [];
        foreach ($categories as $category) {
            $parentKey = $category->parent_id === null ? 'root' : (string) $category->parent_id;
            $byParent[$parentKey] ??= [];
            $byParent[$parentKey][] = $category;
        }

        $totals = [];
        $totalProducts = function (int $id, array $ancestors = []) use (&$totalProducts, &$totals, $byParent, $categories): int {
            if (isset($totals[$id])) {
                return $totals[$id];
            }

            if (isset($ancestors[$id])) {
                return 0;
            }

            $category = $categories->firstWhere('id', $id);
            if (! $category instanceof Category) {
                return 0;
            }

            $ancestors[$id] = true;
            $total = (int) $category->products_count;

            foreach ($byParent[(string) $id] ?? [] as $child) {
                $total += $totalProducts((int) $child->id, $ancestors);
            }

            return $totals[$id] = $total;
        };

        $build = function (string $parentKey, array $ancestors = []) use (&$build, $byParent, $totalProducts): array {
            $nodes = [];

            foreach ($byParent[$parentKey] ?? [] as $category) {
                $id = (int) $category->id;
                if (isset($ancestors[$id])) {
                    continue;
                }

                $nextAncestors = $ancestors;
                $nextAncestors[$id] = true;

                $label = (string) $category->name.' · товарів: '.$totalProducts($id);
                if (! $category->is_active) {
                    $label .= ' · неактивна';
                }

                $nodes[] = [
                    'id' => $id,
                    'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                    'original_parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                    'original_sort_order' => (int) $category->sort_order,
                    'tree_label' => $label,
                    'children' => $build((string) $id, $nextAncestors),
                ];
            }

            return $nodes;
        };

        return $build('root');
    }

    private function mutate(callable $callback, string $successTitle): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        try {
            $callback($actor);
        } catch (CategoryTreeMutationException|AuthorizationException $exception) {
            Notification::make()
                ->warning()
                ->title('Категорію не змінено')
                ->body($exception->getMessage())
                ->send();

            throw new Halt;
        }

        Notification::make()
            ->success()
            ->title($successTitle)
            ->send();

        $this->dispatch('tree-refresh');
    }
}
