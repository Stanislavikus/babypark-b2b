<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Exceptions\Catalog\CategoryTreeMutationException;
use App\Filament\Resources\CategoryResource;
use App\Models\Category;
use App\Models\User;
use App\Services\Catalog\CategoryDeleteImpactService;
use App\Services\Catalog\CategoryTreeMutationService;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SolutionForest\FilamentNestableTree\Filament\Resources\Pages\TreePage;
use SolutionForest\FilamentNestableTree\Tree;

class ManageCategoryTree extends TreePage
{
    public static string $resource = CategoryResource::class;

    protected static ?string $title = 'Категорії';

    public string $treeSortColumn = 'manual';

    public string $treeSortDirection = 'asc';

    private const TREE_SORT_COLUMNS = [
        'name',
        'children_count',
        'products_count',
        'stock_display_threshold',
        'is_active',
    ];

    protected function getHeaderActions(): array
    {
        return [
            $this->createCategoryAction(),
        ];
    }

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
            ->draggable(fn (): bool => $this->treeSortColumn === 'manual')
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
            ->toolbarActions([
                Action::make('save')
                    ->label('Зберегти порядок')
                    ->extraAttributes(['x-show' => 'hasUnsavedOrder', 'x-cloak' => true])
                    ->alpineClickHandler('$wire.saveTreeOrder([], treeKey)'),
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

    public function content(Schema $schema): Schema
    {
        $tree = $this->getCachedTree();

        return $schema->components([
            View::make('filament.resources.category-resource.pages.category-tree-table')
                ->viewData([
                    'treeConfig' => $tree,
                    'isSearchable' => $tree->isSearchable(),
                    'allowDragDrop' => $tree->isDraggable(),
                    'allowCrossCategory' => $tree->isCrossCategoryAllowed(),
                    'toolbarActions' => $tree->getToolbarActions(),
                    'hasNodeActions' => ! empty($tree->getNodeActions()),
                    'sortColumn' => $this->treeSortColumn,
                    'sortDirection' => $this->treeSortDirection,
                ])
                ->key('category-tree-table-'.$this->treeSortColumn.'-'.$this->treeSortDirection),
        ]);
    }

    public function sortTree(string $column): void
    {
        if (! in_array($column, self::TREE_SORT_COLUMNS, true)) {
            return;
        }

        if ($this->treeSortColumn !== $column) {
            $this->treeSortColumn = $column;
            $this->treeSortDirection = 'asc';
        } elseif ($this->treeSortDirection === 'asc') {
            $this->treeSortDirection = 'desc';
        } else {
            $this->treeSortColumn = 'manual';
            $this->treeSortDirection = 'asc';
        }

        $this->refreshTreeNodes();
    }

    protected function buildTree(): Tree
    {
        return $this->buildBaseTree()
            ->nodeActions([
                $this->editCategoryAction(),
                $this->addChildAction(),
                $this->toggleActiveAction(),
                $this->deleteCategoryAction(),
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
            ->label(fn (?Category $record): string => $record?->is_active ? 'Приховати категорію' : 'Показати категорію')
            ->icon(fn (?Category $record): string => $record?->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
            ->color(fn (?Category $record): string => $record?->is_active ? 'gray' : 'success')
            ->iconButton()
            ->visible(fn (?Category $record): bool => $record instanceof Category
                && CategoryResource::getEditAuthorizationResponse($record)->allowed())
            ->requiresConfirmation()
            ->modalHeading(fn (?Category $record): string => $record?->is_active ? 'Приховати категорію?' : 'Показати категорію?')
            ->modalDescription(fn (?Category $record): string => $record?->is_active
                ? 'Категорія не видаляється. Товари та Magento-зв’язки зберігаються; ця категорія і її підкатегорії стануть недоступними для вибору та відображення.'
                : 'Категорія знову стане доступною, якщо всі її батьківські категорії також активні.')
            ->modalSubmitActionLabel(fn (?Category $record): string => $record?->is_active ? 'Приховати' : 'Показати')
            ->action(function (?Category $record): void {
                if (! $record instanceof Category) {
                    throw new AuthorizationException('This action is unauthorized.');
                }

                $this->mutate(fn (User $actor) => app(CategoryTreeMutationService::class)->setActive(
                    $actor,
                    app(WorkspaceContext::class)->current(),
                    $record,
                    ! $record->is_active,
                ), $record->is_active ? 'Категорію приховано' : 'Категорію показано');
            });
    }

    private function deleteCategoryAction(): Action
    {
        return Action::make('delete_category')
            ->label('Видалити категорію')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->iconButton()
            ->visible(fn (?Category $record): bool => $record instanceof Category
                && CategoryResource::getDeleteAuthorizationResponse($record)->allowed())
            ->fillForm(function (?Category $record): array {
                if (! $record instanceof Category) {
                    return [];
                }

                $impact = app(CategoryDeleteImpactService::class)->impact(
                    app(WorkspaceContext::class)->current(),
                    $record,
                );

                return [
                    'expected_products_count' => $impact['products_count'],
                    'expected_children_count' => $impact['children_count'],
                    'expected_mappings_count' => $impact['mappings_count'],
                    'source_adobe_mappings_count' => $impact['adobe_mappings_count'],
                    'source_stock_display_threshold' => $impact['stock_display_threshold'],
                    'product_destination' => null,
                    'confirmation' => null,
                ];
            })
            ->modalHeading(fn (?Category $record): string => $record instanceof Category
                ? 'Видалити категорію «'.$record->name.'»?'
                : 'Видалити категорію?')
            ->modalDescription('Категорія буде фізично видалена з Master. Товари та категорії в Magento не видаляються цією дією.')
            ->schema([
                Hidden::make('expected_products_count'),
                Hidden::make('expected_children_count'),
                Hidden::make('expected_mappings_count'),
                Hidden::make('source_adobe_mappings_count'),
                Hidden::make('source_stock_display_threshold'),
                Placeholder::make('delete_impact')
                    ->label('Наслідки')
                    ->content(fn (Get $get): string => sprintf(
                        'Товарів: %d · Підкатегорій: %d · Зіставлень з каналами: %d.',
                        (int) $get('expected_products_count'),
                        (int) $get('expected_children_count'),
                        (int) $get('expected_mappings_count'),
                    )),
                Placeholder::make('children_delete_note')
                    ->hiddenLabel()
                    ->content('Підкатегорії будуть підняті на рівень вище. Видалення всієї гілки не виконується.')
                    ->visible(fn (Get $get): bool => (int) $get('expected_children_count') > 0),
                Select::make('product_destination')
                    ->label('Куди перенести товари')
                    ->placeholder('Оберіть дію')
                    ->options(fn (?Category $record): array => $record instanceof Category
                        ? app(CategoryDeleteImpactService::class)->destinationOptions(
                            app(WorkspaceContext::class)->current(),
                            $record,
                        )
                        : ['__uncategorized__' => 'Без категорії'])
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required(fn (Get $get): bool => (int) $get('expected_products_count') > 0)
                    ->visible(fn (Get $get): bool => (int) $get('expected_products_count') > 0),
                Placeholder::make('channel_delete_warning')
                    ->label('Канали')
                    ->content(fn (Get $get, ?Category $record): string => $this->deleteChannelWarning(
                        $record,
                        $get('product_destination'),
                        (int) $get('source_adobe_mappings_count'),
                    ) ?? '')
                    ->visible(fn (Get $get, ?Category $record): bool => $this->deleteChannelWarning(
                        $record,
                        $get('product_destination'),
                        (int) $get('source_adobe_mappings_count'),
                    ) !== null),
                Placeholder::make('stock_threshold_delete_warning')
                    ->label('B2B')
                    ->content('Для товарів без категорії поріг відображення залишку стане стандартним: 10.')
                    ->visible(fn (Get $get): bool => $get('product_destination') === '__uncategorized__'
                        && (int) $get('expected_products_count') > 0
                        && (int) $get('source_stock_display_threshold') !== 10),
                TextInput::make('confirmation')
                    ->label('Підтвердження')
                    ->helperText('Введіть ВИДАЛИТИ, щоб підтвердити незворотне видалення категорії.')
                    ->rules(['in:ВИДАЛИТИ'])
                    ->validationMessages([
                        'in' => 'Введіть слово «ВИДАЛИТИ».',
                    ])
                    ->required(fn (Get $get): bool => (int) $get('expected_products_count') > 0)
                    ->visible(fn (Get $get): bool => (int) $get('expected_products_count') > 0),
            ])
            ->requiresConfirmation()
            ->modalSubmitActionLabel('Видалити')
            ->action(function (array $data, ?Category $record): void {
                if (! $record instanceof Category) {
                    throw new AuthorizationException('This action is unauthorized.');
                }

                $productsCount = (int) ($data['expected_products_count'] ?? -1);
                $destinationCategoryId = null;

                if ($productsCount > 0) {
                    if (($data['confirmation'] ?? null) !== 'ВИДАЛИТИ') {
                        throw ValidationException::withMessages([
                            'confirmation' => 'Введіть слово «ВИДАЛИТИ».',
                        ]);
                    }

                    $destination = $data['product_destination'] ?? null;

                    if ($destination !== '__uncategorized__') {
                        $parsed = filter_var($destination, FILTER_VALIDATE_INT);
                        if ($parsed === false || $parsed < 1) {
                            throw ValidationException::withMessages([
                                'product_destination' => 'Оберіть, куди перенести товари.',
                            ]);
                        }

                        $destinationCategoryId = (int) $parsed;
                    }
                }

                $this->mutate(fn (User $actor) => app(CategoryTreeMutationService::class)->deleteSingle(
                    $actor,
                    app(WorkspaceContext::class)->current(),
                    $record,
                    $destinationCategoryId,
                    [
                        'products_count' => $productsCount,
                        'children_count' => (int) ($data['expected_children_count'] ?? -1),
                        'mappings_count' => (int) ($data['expected_mappings_count'] ?? -1),
                    ],
                ), 'Категорію видалено');
            });
    }

    private function deleteChannelWarning(
        ?Category $record,
        mixed $destination,
        int $sourceAdobeMappingsCount,
    ): ?string {
        if (! $record instanceof Category || $sourceAdobeMappingsCount < 1 || $destination === null || $destination === '') {
            return null;
        }

        if ($destination === '__uncategorized__') {
            return 'Товари залишаться без Master Category і можуть мати статус Partial до повторної класифікації. Існуючі категорії та зв’язки в Magento цим видаленням не змінюються.';
        }

        $destinationId = filter_var($destination, FILTER_VALIDATE_INT);
        if ($destinationId === false || $destinationId < 1) {
            return null;
        }

        $missing = app(CategoryDeleteImpactService::class)->missingAdobeMappingCount(
            app(WorkspaceContext::class)->current(),
            $record,
            (int) $destinationId,
        );

        return $missing > 0
            ? sprintf(
                'У цільової категорії немає Magento-зіставлення для %d підключень. Товари без індивідуального override можуть залишитися Partial.',
                $missing,
            )
            : null;
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

        $sortSiblings = function (array $siblings) use ($byParent, $totalProducts): array {
            if ($this->treeSortColumn === 'manual') {
                return $siblings;
            }

            usort($siblings, function (Category $left, Category $right) use ($byParent, $totalProducts): int {
                $leftValue = match ($this->treeSortColumn) {
                    'name' => mb_strtolower((string) $left->name),
                    'children_count' => count($byParent[(string) $left->id] ?? []),
                    'products_count' => $totalProducts((int) $left->id),
                    'stock_display_threshold' => (int) $left->stock_display_threshold,
                    'is_active' => (int) $left->is_active,
                    default => (int) $left->sort_order,
                };
                $rightValue = match ($this->treeSortColumn) {
                    'name' => mb_strtolower((string) $right->name),
                    'children_count' => count($byParent[(string) $right->id] ?? []),
                    'products_count' => $totalProducts((int) $right->id),
                    'stock_display_threshold' => (int) $right->stock_display_threshold,
                    'is_active' => (int) $right->is_active,
                    default => (int) $right->sort_order,
                };

                $comparison = $leftValue <=> $rightValue;

                if ($comparison !== 0) {
                    return $this->treeSortDirection === 'asc' ? $comparison : -$comparison;
                }

                return [(int) $left->sort_order, (string) $left->name, (int) $left->id]
                    <=> [(int) $right->sort_order, (string) $right->name, (int) $right->id];
            });

            return $siblings;
        };

        $build = function (string $parentKey, array $ancestors = []) use (&$build, $byParent, $sortSiblings, $totalProducts): array {
            $nodes = [];

            foreach ($sortSiblings($byParent[$parentKey] ?? []) as $category) {
                $id = (int) $category->id;
                if (isset($ancestors[$id])) {
                    continue;
                }

                $nextAncestors = $ancestors;
                $nextAncestors[$id] = true;

                $nodes[] = [
                    'id' => $id,
                    'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                    'original_parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                    'original_sort_order' => (int) $category->sort_order,
                    'name' => (string) $category->name,
                    'tree_label' => (string) $category->name,
                    'children_count' => count($byParent[(string) $id] ?? []),
                    'products_count' => $totalProducts($id),
                    'stock_display_threshold' => (int) $category->stock_display_threshold,
                    'is_active' => (bool) $category->is_active,
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
