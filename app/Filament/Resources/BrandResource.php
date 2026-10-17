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
use App\Services\Media\OriginalImageUrlIngestService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Media\Exceptions\MediaIngestException;
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
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class BrandResource extends Resource
{
    public const LIST_REFRESH_STORAGE_KEY = 'babypark:admin:brands:refresh';

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
                Section::make(__('media_ui.section'))
                    ->extraAttributes(['class' => 'bp-brand-media-section'])
                    ->description(fn (Get $get, ?Brand $record): string => self::formLogoAsset($get, $record) instanceof MediaAsset ? __('media_ui.one_image') : __('media_ui.zero_images'))
                    ->schema([
                        Text::make('Поточне зображення')
                            ->weight(FontWeight::SemiBold)
                            ->visible(fn (Get $get, ?Brand $record): bool => self::formLogoAsset($get, $record) instanceof MediaAsset),
                        Group::make([
                            Group::make([
                                MediaPreviewFrame::entry(
                                    ImageEntry::make('logo_preview')
                                        ->label('Поточне зображення')
                                        ->hiddenLabel()
                                        ->state(fn (Get $get, ?Brand $record): ?string => app(MediaAssetSourceResolver::class)->sourceReference(self::formLogoAsset($get, $record)))
                                        ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(160))),
                                    MediaPreviewFrame::BRAND_FORM,
                                ),
                                Actions::make([
                                    Action::make('remove_logo')
                                        ->label('Прибрати логотип')
                                        ->tooltip('Прибрати логотип')
                                        ->icon('heroicon-m-x-mark')
                                        ->iconButton()
                                        ->color('gray')
                                        ->visible(fn (Get $get, ?Brand $record): bool => $record instanceof Brand
                                            && filled($get('logo_media_asset_id'))
                                            && (string) $get('logo_media_asset_id') === (string) $record->logo_media_asset_id
                                            && blank($get('logo_upload')))
                                        ->action(function (Set $set): void {
                                            // Stage an unlink on Save; do not delete the MediaAsset Original.
                                            $set('logo_media_asset_id', null);
                                            $set('logo_upload', null);
                                        }),
                                ])->extraAttributes(['class' => 'bp-brand-media-current-dismiss']),
                            ])->columns(1)->extraAttributes(['class' => 'bp-brand-media-current-image']),
                            Group::make([
                                TextEntry::make('logo_file')
                                    ->label('Файл')
                                    ->state(function (Get $get, ?Brand $record): string {
                                        $asset = self::formLogoAsset($get, $record);

                                        return $asset instanceof MediaAsset ? MediaAssetResource::displayName($asset) : '—';
                                    })
                                    ->columnSpanFull(),
                                TextEntry::make('logo_dimensions')
                                    ->label('Розмір')
                                    ->state(function (Get $get, ?Brand $record): string {
                                        $asset = self::formLogoAsset($get, $record);

                                        return $asset instanceof MediaAsset ? MediaAssetResource::dimensions($asset) : '—';
                                    }),
                                TextEntry::make('logo_megapixels')
                                    ->label('Мегапікселі')
                                    ->state(function (Get $get, ?Brand $record): string {
                                        $asset = self::formLogoAsset($get, $record);

                                        return $asset instanceof MediaAsset ? MediaAssetResource::megapixels($asset) : '—';
                                    }),
                                TextEntry::make('logo_byte_size')
                                    ->label('Вага')
                                    ->state(function (Get $get, ?Brand $record): string {
                                        $asset = self::formLogoAsset($get, $record);

                                        return $asset instanceof MediaAsset ? MediaAssetResource::formatBytes($asset->byte_size) : '—';
                                    }),
                                TextEntry::make('logo_mime_type')
                                    ->label('Формат')
                                    ->state(function (Get $get, ?Brand $record): string {
                                        $asset = self::formLogoAsset($get, $record);

                                        return $asset instanceof MediaAsset && filled($asset->mime_type) ? (string) $asset->mime_type : '—';
                                    }),
                            ])->columns(2)
                                ->extraAttributes(['class' => 'bp-brand-media-current-metadata']),
                        ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                            ])
                            ->extraAttributes(['class' => 'bp-brand-media-current-layout'])
                            ->columnSpanFull()
                            ->visible(fn (Get $get, ?Brand $record): bool => self::formLogoAsset($get, $record) instanceof MediaAsset),
                        Group::make([
                            Text::make(new HtmlString('<svg aria-hidden="true" viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="16" height="16" rx="2"/><circle cx="8" cy="10" r="1.3"/><path d="m5 18 4.5-5 3.5 3 2.5-3 2.5 3"/><path d="M20 1v8m-4-4h8"/></svg>'))
                                ->extraAttributes(['class' => 'bp-brand-media-icon']),
                            Text::make(__('media_ui.drop_heading'))
                                ->weight(FontWeight::SemiBold)
                                ->extraAttributes(['class' => 'bp-brand-media-heading']),
                            Text::make(__('media_ui.drop_subheading'))
                                ->extraAttributes(['class' => 'bp-brand-media-subheading']),
                            // A library/URL selection previews its existing canonical Original,
                            // never creating a second upload or MediaAsset identity.
                            ImageEntry::make('pending_logo_source')
                                ->hiddenLabel()
                                ->state(fn (Get $get): ?string => app(MediaAssetSourceResolver::class)
                                    ->sourceReference(self::selectedLogoAsset($get)))
                                ->visible(fn (Get $get, ?Brand $record): bool => blank($get('logo_upload'))
                                    && self::selectedLogoAsset($get) instanceof MediaAsset
                                    && (string) $get('logo_media_asset_id') !== (string) ($record?->logo_media_asset_id ?? ''))
                                ->extraAttributes(['class' => 'bp-brand-media-candidate']),
                            Actions::make([
                                Action::make('cancel_pending_logo')
                                    ->label(__('media_ui.cancel_pending_image'))
                                    ->tooltip(__('media_ui.cancel_pending_image'))
                                    ->icon('heroicon-m-x-mark')
                                    ->iconButton()
                                    ->color('gray')
                                    ->visible(fn (Get $get, ?Brand $record): bool => blank($get('logo_upload'))
                                        && self::selectedLogoAsset($get) instanceof MediaAsset
                                        && (string) $get('logo_media_asset_id') !== (string) ($record?->logo_media_asset_id ?? ''))
                                    ->action(function (Set $set, ?Brand $record): void {
                                        // Cancel only unsaved selection, not the canonical file.
                                        $set('logo_media_asset_id', $record?->logo_media_asset_id);
                                    }),
                            ])->extraAttributes(['class' => 'bp-brand-media-candidate-dismiss']),
                            Group::make([
                                Group::make([
                                FileUpload::make('logo_upload')
                                    ->label(__('media_ui.upload'))
                                    // Native default panel layout can accommodate a 320px
                                    // preview without compact-mode scroller clipping.
                                    ->imagePreviewHeight('320')
                                    ->hiddenLabel()
                                    ->placeholder(__('media_ui.upload'))
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
                                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state, Component $livewire, ?Brand $record): void {
                                        if ($livewire instanceof EditBrand) {
                                            $livewire->logoPostSaveNotice = null;
                                        }

                                        if (filled($state)) {
                                            $set('logo_media_asset_id', null);
                                        } elseif (blank($get('logo_media_asset_id')) && $record instanceof Brand) {
                                            // X on a pending file cancels it; it does not delete the saved logo.
                                            $set('logo_media_asset_id', $record->logo_media_asset_id);
                                        }
                                    }),
                                    View::make('filament.brand-media-upload-trigger'),
                                ])->extraAttributes(['class' => 'bp-brand-media-upload-slot']),
                                OriginalImageAssetPicker::make('logo_media_asset_id')
                                    ->label(__('media_ui.select_existing'))
                                    ->hiddenLabel()
                                    ->placeholder(null)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, mixed $state, Component $livewire): void {
                                        if ($livewire instanceof EditBrand) {
                                            $livewire->logoPostSaveNotice = null;
                                        }

                                        if (filled($state)) {
                                            $set('logo_upload', null);
                                        }
                                    })
                                    ->helperText(null)
                                    ->extraAttributes(['class' => 'bp-brand-media-picker']),
                                Actions::make([
                                    Action::make('import_logo_from_url')
                                        ->label(__('media_ui.add_from_url'))
                                        ->icon('heroicon-o-link')
                                        ->color('gray')
                                        ->outlined()
                                        ->modalHeading(__('media_ui.add_from_url'))
                                        ->modalSubmitActionLabel(__('media_ui.add_from_url'))
                                        ->schema([
                                            TextInput::make('url')
                                                ->label(__('media_ui.image_url'))
                                                ->placeholder('https://example.com/photo.jpg')
                                                ->url()
                                                ->required()
                                                ->maxLength(2048),
                                        ])
                                        ->action(function (array $data, Set $set, Component $livewire): void {
                                            $actor = auth()->user();
                                            if (($actor instanceof User) === false) {
                                                throw new AuthorizationException('This action is unauthorized.');
                                            }

                                            try {
                                                $asset = app(OriginalImageUrlIngestService::class)->ingest(
                                                    $actor,
                                                    app(WorkspaceContext::class)->current(),
                                                    (string) $data['url'],
                                                );
                                            } catch (MediaIngestException $e) {
                                                throw ValidationException::withMessages([
                                                    'mountedActions.0.data.url' => $e->getMessage(),
                                                ]);
                                            } catch (ValidationException $e) {
                                                throw ValidationException::withMessages([
                                                    'mountedActions.0.data.url' => $e->errors()['url'][0]
                                                        ?? 'Не вдалося завантажити зображення.',
                                                ]);
                                            }

                                            if ($livewire instanceof EditBrand) {
                                                $livewire->logoPostSaveNotice = null;
                                            }

                                            $set('logo_upload', null);
                                            $set('logo_media_asset_id', (string) $asset->id);
                                        }),
                                ])
                                    ->key('brand_media_url_actions')
                                    ->extraAttributes(['class' => 'bp-brand-media-url-action']),
                            ])
                                ->extraAttributes(['class' => 'bp-brand-media-actions']),
                            Text::make(__('media_ui.upload_hint'))
                                ->extraAttributes(['class' => 'bp-brand-media-format']),
                        ])
                            ->extraAttributes(['class' => 'bp-brand-media-dropzone'])
                            ->columnSpanFull(),
                        Callout::make('Зверніть увагу')
                            ->info()
                            ->description(fn (Component $livewire): ?string => $livewire instanceof EditBrand
                                ? $livewire->logoPostSaveNotice
                                : null)
                            ->visible(fn (Component $livewire): bool => $livewire instanceof EditBrand
                                && filled($livewire->logoPostSaveNotice)),
                    ])
                    ->columnSpanFull(),
                Textarea::make('short_description')
                    ->label('Короткий опис бренду')
                    ->rows(3)
                    ->maxLength(2000),
                Group::make([
                    TextEntry::make('logo_source_kind')
                        ->label('Зберігання')
                        ->state(function (Get $get, ?Brand $record): string {
                            $asset = self::formLogoAsset($get, $record);

                            return $asset instanceof MediaAsset ? MediaAssetResource::sourceLabel($asset) : '—';
                        })
                        ->badge()
                        ->color(function (Get $get, ?Brand $record): string {
                            $asset = self::formLogoAsset($get, $record);

                            return $asset instanceof MediaAsset ? MediaAssetResource::sourceColor($asset) : 'gray';
                        }),
                    TextEntry::make('logo_diagnosis_status')
                        ->label('Технічний стан')
                        ->state(function (Get $get, ?Brand $record): string {
                            $asset = self::formLogoAsset($get, $record);

                            return $asset instanceof MediaAsset ? MediaAssetResource::diagnosisLabel($asset->diagnosis_status) : '—';
                        })
                        ->badge()
                        ->color(function (Get $get, ?Brand $record): string {
                            $asset = self::formLogoAsset($get, $record);

                            return $asset instanceof MediaAsset ? MediaAssetResource::diagnosisColor($asset) : 'gray';
                        }),
                    TextEntry::make('logo_created_at')
                        ->label('Додано')
                        ->state(fn (Get $get, ?Brand $record): mixed => self::formLogoAsset($get, $record)?->created_at)
                        ->dateTime('d.m.Y H:i')
                        ->placeholder('—'),
                ])
                    ->columns([
                        'default' => 1,
                        'md' => 3,
                    ])
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'bp-brand-media-footer-metadata']),
                Toggle::make('is_active')
                    ->label('Активний')
                    ->helperText('Неактивний бренд залишається у Master і на існуючих товарах, але не пропонується для нового призначення.')
                    ->default(true),
            ])->columnSpanFull(),
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

    private static function formLogoAsset(Get $get, ?Brand $record): ?MediaAsset
    {
        if ($record instanceof Brand) {
            // Keep the persisted preview during replacement, but not after
            // an explicit pending unlink from its native remove action.
            if (blank($get('logo_media_asset_id')) && blank($get('logo_upload'))) {
                return null;
            }

            return $record->logo;
        }

        return self::selectedLogoAsset($get);
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
