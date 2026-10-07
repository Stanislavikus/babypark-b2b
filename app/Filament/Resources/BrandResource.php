<?php

namespace App\Filament\Resources;

use App\Enums\MediaAssetType;
use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Resources\BrandResource\Pages\EditBrand;
use App\Filament\Resources\BrandResource\Pages\ListBrands;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Catalog\ProductMediaReadService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'бренд';

    protected static ?string $pluralModelLabel = 'Бренди';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основне')->schema([
                TextInput::make('name')
                    ->label('Назва')
                    ->required()
                    ->maxLength(255),
                Select::make('logo_media_asset_id')
                    ->label('Логотип')
                    ->options(fn (): array => MediaAsset::withoutWorkspaceScope()
                        ->where('workspace_id', app(WorkspaceContext::class)->id())
                        ->whereNull('parent_media_asset_id')
                        ->where('asset_type', MediaAssetType::Image->value)
                        ->orderBy('original_filename')
                        ->orderBy('id')
                        ->get()
                        ->mapWithKeys(fn (MediaAsset $asset): array => [
                            (string) $asset->id => filled($asset->original_filename)
                                ? (string) $asset->original_filename
                                : 'Image '.substr((string) $asset->id, 0, 8),
                        ])
                        ->all())
                    ->searchable()
                    ->preload()
                    ->helperText('Використовується існуючий Original із MediaAsset. Завантаження нового файлу залишається в Media workspace.'),
                Textarea::make('short_description')
                    ->label('Короткий опис')
                    ->rows(3)
                    ->maxLength(2000),
                Toggle::make('is_active')
                    ->label('Активний')
                    ->helperText('Неактивний бренд залишається у Master і на існуючих товарах, але не пропонується для нового призначення.')
                    ->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo_preview')
                    ->label('Лого')
                    ->state(fn (Brand $record): ?string => app(ProductMediaReadService::class)
                        ->sourceReference($record->logo))
                    ->square()
                    ->size(36),
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('products_count')
                    ->label('Товарів')
                    ->counts('products')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Стан')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()
                    ->color('gray'),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBrands::route('/'),
            'create' => CreateBrand::route('/create'),
            'edit' => EditBrand::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('logo')
            ->where('workspace_id', app(WorkspaceContext::class)->id());
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return self::canManageCurrentWorkspaceProducts()
            ? Response::allow()
            : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        if (! $record instanceof Brand) {
            return Response::deny();
        }

        return (string) $record->workspace_id === app(WorkspaceContext::class)->id()
            && self::canManageCurrentWorkspaceProducts()
                ? Response::allow()
                : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
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
