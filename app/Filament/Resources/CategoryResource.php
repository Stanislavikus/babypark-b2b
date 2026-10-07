<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages\ManageCategoryTree;
use App\Models\Category;
use App\Models\User;
use App\Services\Catalog\CategoryHierarchyService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'категорія';

    protected static ?string $pluralModelLabel = 'Категорії';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Назва')
                    ->required()
                    ->maxLength(255),
                Select::make('parent_id')
                    ->label('Батьківська категорія')
                    ->placeholder('Коренева категорія')
                    ->options(fn (?Category $record): array => self::parentOptions($record))
                    ->searchable()
                    ->native(false),
                Toggle::make('is_active')
                    ->label('Активна')
                    ->helperText('Неактивна категорія залишається в Master, але не пропонується для нового призначення товару.')
                    ->default(true),
                TextInput::make('stock_display_threshold')
                    ->label('Поріг відображення залишку')
                    ->helperText('Якщо залишок ≤ порогу — показувати точну кількість.')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(10),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategoryTree::route('/'),
        ];
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return self::canManageCurrentWorkspaceProducts()
            ? Response::allow()
            : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        if (! $record instanceof Category) {
            return Response::deny();
        }

        $workspace = app(WorkspaceContext::class)->current();

        return (string) $record->workspace_id === (string) $workspace->id
            && self::canManageCurrentWorkspaceProducts()
                ? Response::allow()
                : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return self::getEditAuthorizationResponse($record);
    }

    /** @return array<int, string> */
    private static function parentOptions(?Category $record): array
    {
        $workspaceId = (string) app(WorkspaceContext::class)->current()->id;
        $labels = app(CategoryHierarchyService::class)->labels($workspaceId);

        if (! $record instanceof Category) {
            uasort($labels, static fn (string $left, string $right): int => strnatcasecmp($left, $right));

            return $labels;
        }

        $categories = Category::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->get(['id', 'parent_id']);

        $children = [];
        foreach ($categories as $category) {
            $parentId = $category->parent_id === null ? null : (int) $category->parent_id;
            $children[$parentId] ??= [];
            $children[$parentId][] = (int) $category->id;
        }

        $blocked = [(int) $record->id => true];
        $queue = $children[(int) $record->id] ?? [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($blocked[$id])) {
                continue;
            }

            $blocked[$id] = true;
            array_push($queue, ...($children[$id] ?? []));
        }

        $options = array_filter(
            $labels,
            static fn (string $label, int $id): bool => ! isset($blocked[$id]),
            ARRAY_FILTER_USE_BOTH,
        );

        uasort($options, static fn (string $left, string $right): int => strnatcasecmp($left, $right));

        return $options;
    }

    private static function canManageCurrentWorkspaceProducts(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && app(WorkspaceAuthorization::class)->allows(
                $actor,
                app(WorkspaceContext::class)->current(),
                WorkspacePermissions::MANAGE_PRODUCTS,
            );
    }
}
