<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Resources\BrandResource\Pages\EditBrand;
use App\Filament\Resources\BrandResource\Pages\ListBrands;
use App\Filament\Support\MediaPreviewFrame;
use App\Filament\Support\OriginalImageAssetPicker;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\MediaAssetSourceResolver;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

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
                MediaPreviewFrame::entry(
                    ImageEntry::make('logo_preview')
                        ->label('Поточний логотип')
                        ->state(function (Get $get): ?string {
                            $assetId = $get('logo_media_asset_id');

                            if (! is_string($assetId) || $assetId === '') {
                                return null;
                            }

                            $asset = MediaAsset::withoutWorkspaceScope()
                                ->where('workspace_id', app(WorkspaceContext::class)->id())
                                ->whereNull('parent_media_asset_id')
                                ->whereKey($assetId)
                                ->first();

                            return app(MediaAssetSourceResolver::class)->sourceReference($asset);
                        })
                        ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(160))),
                    MediaPreviewFrame::BRAND_FORM,
                ),
                OriginalImageAssetPicker::make('logo_media_asset_id')
                    ->label('Обрати з Assets')
                    ->placeholder('Без логотипу')
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if (filled($state)) {
                            $set('logo_upload', null);
                        }
                    })
                    ->helperText('Оберіть існуюче зображення з Assets. Один файл можна повторно використовувати без дублювання.'),
                FileUpload::make('logo_upload')
                    ->label('Завантажити новий логотип')
                    ->storeFiles(false)
                    ->maxSize(20 * 1024)
                    ->acceptedFileTypes([
                        'image/jpeg',
                        'image/png',
                        'image/gif',
                        'image/webp',
                        'image/avif',
                    ])
                    ->validationMessages([
                        'max' => 'Файл завеликий. Максимальний розмір зображення — 20 МіБ.',
                        'mimetypes' => 'Підтримуються JPEG, PNG, WebP, GIF або AVIF. SVG поки не підтримується.',
                    ])
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if (filled($state)) {
                            $set('logo_media_asset_id', null);
                        }
                    })
                    ->helperText('Новий файл буде додано в Assets і використано як логотип. До 20 МіБ і 25 МП.'),
                Actions::make([
                    Action::make('remove_logo')
                        ->label('Прибрати логотип')
                        ->icon('heroicon-o-x-mark')
                        ->color('gray')
                        ->action(function (Set $set): void {
                            $set('logo_media_asset_id', null);
                            $set('logo_upload', null);
                        }),
                ]),
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
                MediaPreviewFrame::column(
                    ImageColumn::make('logo_preview')
                        ->label('Лого')
                        ->state(fn (Brand $record): ?string => app(MediaAssetSourceResolver::class)
                            ->sourceReference($record->logo))
                        ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(64))),
                    MediaPreviewFrame::BRAND_LIST,
                ),
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
