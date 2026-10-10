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
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
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
            Section::make()->schema([
                TextInput::make('name')
                    ->label('Назва')
                    ->required()
                    ->maxLength(255),
                Text::make('Поточне зображення')
                    ->weight(FontWeight::SemiBold),
                Group::make()
                    ->schema(function (Get $get): array {
                        $asset = self::selectedLogoAsset($get);

                        return [
                            MediaPreviewFrame::entry(
                                ImageEntry::make('logo_preview')
                                    ->label('Поточне зображення')
                                    ->hiddenLabel()
                                    ->state(app(MediaAssetSourceResolver::class)->sourceReference($asset))
                                    ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(160))),
                                MediaPreviewFrame::BRAND_FORM,
                            ),
                            Group::make([
                                TextEntry::make('logo_file')
                                    ->label('Файл')
                                    ->state($asset instanceof MediaAsset ? MediaAssetResource::displayName($asset) : '—')
                                    ->columnSpanFull(),
                                TextEntry::make('logo_dimensions')
                                    ->label('Розмір')
                                    ->state($asset instanceof MediaAsset ? MediaAssetResource::dimensions($asset) : '—'),
                                TextEntry::make('logo_megapixels')
                                    ->label('Мегапікселі')
                                    ->state($asset instanceof MediaAsset ? MediaAssetResource::megapixels($asset) : '—'),
                                TextEntry::make('logo_byte_size')
                                    ->label('Вага')
                                    ->state($asset instanceof MediaAsset ? MediaAssetResource::formatBytes($asset->byte_size) : '—'),
                                TextEntry::make('logo_mime_type')
                                    ->label('Формат')
                                    ->state($asset instanceof MediaAsset && filled($asset->mime_type) ? (string) $asset->mime_type : '—'),
                            ])->columns(2),
                        ];
                    })
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->columnSpanFull(),
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
                    ->label('Короткий опис бренду')
                    ->rows(3)
                    ->maxLength(2000),
                Group::make()
                    ->schema(function (Get $get): array {
                        $asset = self::selectedLogoAsset($get);

                        return [
                            TextEntry::make('logo_source_kind')
                                ->label('Зберігання')
                                ->state($asset instanceof MediaAsset ? MediaAssetResource::sourceLabel($asset) : '—')
                                ->badge()
                                ->color($asset instanceof MediaAsset ? MediaAssetResource::sourceColor($asset) : 'gray'),
                            TextEntry::make('logo_diagnosis_status')
                                ->label('Технічний стан')
                                ->state($asset instanceof MediaAsset ? MediaAssetResource::diagnosisLabel($asset->diagnosis_status) : '—')
                                ->badge()
                                ->color($asset instanceof MediaAsset ? MediaAssetResource::diagnosisColor($asset) : 'gray'),
                            TextEntry::make('logo_created_at')
                                ->label('Додано')
                                ->state($asset?->created_at)
                                ->dateTime('d.m.Y H:i')
                                ->placeholder('—'),
                        ];
                    })
                    ->columns([
                        'default' => 1,
                        'md' => 3,
                    ])
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Активний')
                    ->helperText('Неактивний бренд залишається у Master і на існуючих товарах, але не пропонується для нового призначення.')
                    ->default(true),
            ]),
        ]);
    }

    /**
     * @return array<int, Section>
     */
    public static function quickViewSchema(): array
    {
        return [
            Section::make('Бренд')->schema([
                Group::make([
                    MediaPreviewFrame::entry(
                        ImageEntry::make('logo_quick_view')
                            ->label('Логотип')
                            ->hiddenLabel()
                            ->state(fn (Brand $record): ?string => app(MediaAssetSourceResolver::class)
                                ->sourceReference($record->logo))
                            ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(160))),
                        MediaPreviewFrame::BRAND_FORM,
                    ),
                    Group::make([
                        TextEntry::make('name')
                            ->label('Назва'),
                        TextEntry::make('products_count_quick_view')
                            ->label('Товарів')
                            ->state(fn (Brand $record): int => $record->products()->count()),
                        TextEntry::make('is_active_quick_view')
                            ->label('Стан')
                            ->state(fn (Brand $record): string => $record->is_active ? 'Активний' : 'Неактивний')
                            ->badge()
                            ->color(fn (Brand $record): string => $record->is_active ? 'success' : 'gray'),
                    ])->columns(2),
                ])->columns([
                    'default' => 1,
                    'md' => 2,
                ])->columnSpanFull(),
                TextEntry::make('short_description')
                    ->label('Короткий опис бренду')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]),
        ];
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
            ->recordUrl(null)
            ->recordAction('inspect')
            ->recordActions([
                Action::make('inspect')
                    ->label('Деталі')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modal(true)
                    ->slideOver()
                    ->modalWidth(Width::Large)
                    ->modalHeading(fn (Brand $record): string => $record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрити')
                    ->schema(fn (): array => self::quickViewSchema())
                    ->extraModalFooterActions(fn (Brand $record): array => [
                        Action::make('open_full_page_footer')
                            ->label('Відкрити повну картку')
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->color('gray')
                            ->url(self::getUrl('edit', ['record' => $record]))
                            ->openUrlInNewTab()
                            ->close(),
                    ]),
                EditAction::make()
                    ->label('Змінити')
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

    private static function selectedLogoAsset(Get $get): ?MediaAsset
    {
        $assetId = $get('logo_media_asset_id');

        if (! is_string($assetId) || $assetId === '') {
            return null;
        }

        return MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', app(WorkspaceContext::class)->id())
            ->whereNull('parent_media_asset_id')
            ->whereKey($assetId)
            ->first();
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
