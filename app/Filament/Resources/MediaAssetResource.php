<?php

namespace App\Filament\Resources;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Filament\Resources\MediaAssetResource\Pages\EditMediaAsset;
use App\Filament\Resources\MediaAssetResource\Pages\ListMediaAssets;
use App\Filament\Resources\MediaAssetResource\Pages\ViewMediaAsset;
use App\Filament\Support\MediaPreviewFrame;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\MediaAssetLibraryReadService;
use App\Services\Media\MediaAssetSourceResolver;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MediaAssetResource extends Resource
{
    protected static ?string $model = MediaAsset::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'asset';

    protected static ?string $pluralModelLabel = 'Assets';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Group::make([
                    MediaPreviewFrame::entry(
                        ImageEntry::make('current_preview')
                            ->label('Поточне зображення')
                            ->state(fn (MediaAsset $record): ?string => app(MediaAssetSourceResolver::class)->sourceReference($record))
                            ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(180))),
                        MediaPreviewFrame::DETAIL,
                    ),
                    Group::make([
                        TextEntry::make('current_file')
                            ->label('Файл')
                            ->state(fn (MediaAsset $record): string => self::displayName($record))
                            ->columnSpanFull(),
                        TextEntry::make('current_dimensions')
                            ->label('Розмір')
                            ->state(fn (MediaAsset $record): string => self::dimensions($record)),
                        TextEntry::make('current_megapixels')
                            ->label('Мегапікселі')
                            ->state(fn (MediaAsset $record): string => self::megapixels($record)),
                        TextEntry::make('current_byte_size')
                            ->label('Вага')
                            ->state(fn (MediaAsset $record): string => self::formatBytes($record->byte_size)),
                        TextEntry::make('current_mime_type')
                            ->label('Формат')
                            ->state(fn (MediaAsset $record): string => filled($record->mime_type) ? (string) $record->mime_type : '—'),
                    ])->columns(2),
                ])->columns([
                    'default' => 1,
                    'md' => 2,
                ])->columnSpanFull(),
                FileUpload::make('replacement_upload')
                    ->label('Нове зображення')
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
                    ->helperText('Перетягніть файл або виберіть його. Максимум 20 МіБ і 25 МП.')
                    ->live(),
                Group::make([
                    TextEntry::make('source_kind_edit')
                        ->label('Зберігання')
                        ->state(fn (MediaAsset $record): string => self::sourceLabel($record))
                        ->badge()
                        ->color(fn (MediaAsset $record): string => self::sourceColor($record)),
                    TextEntry::make('diagnosis_status_edit')
                        ->label('Технічний стан')
                        ->state(fn (MediaAsset $record): string => self::diagnosisLabel($record->diagnosis_status))
                        ->badge()
                        ->color(fn (MediaAsset $record): string => self::diagnosisColor($record)),
                    TextEntry::make('created_at_edit')
                        ->label('Додано')
                        ->state(fn (MediaAsset $record): mixed => $record->created_at)
                        ->dateTime('d.m.Y H:i'),
                ])->columns([
                    'default' => 1,
                    'md' => 3,
                ])->columnSpanFull(),
            ]),
            Section::make('Використовується в')->schema([
                TextEntry::make('usage_items_edit')
                    ->label('Використання')
                    ->hiddenLabel()
                    ->state(function (MediaAsset $record): ?array {
                        $items = app(MediaAssetLibraryReadService::class)->usageItems($record);

                        return $items === [] ? null : $items;
                    })
                    ->formatStateUsing(fn (array $state): string => self::usageItemLabel($state))
                    ->url(fn (array $state): ?string => self::usageItemUrl($state))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->limitList(8)
                    ->expandableLimitedList()
                    ->placeholder('Не використовується'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components(self::detailSchema());
    }

    /**
     * @return array<int, Section>
     */
    public static function detailSchema(): array
    {
        return [
            Section::make('Поточне зображення')->schema([
                Group::make([
                    MediaPreviewFrame::entry(
                        ImageEntry::make('preview_url')
                            ->label('Зображення')
                            ->hiddenLabel()
                            ->state(fn (MediaAsset $record): ?string => app(MediaAssetSourceResolver::class)->sourceReference($record))
                            ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(180))),
                        MediaPreviewFrame::DETAIL,
                    ),
                    Group::make([
                        TextEntry::make('display_name')
                            ->label('Файл')
                            ->state(fn (MediaAsset $record): string => self::displayName($record))
                            ->columnSpanFull(),
                        TextEntry::make('dimensions')
                            ->label('Розмір')
                            ->state(fn (MediaAsset $record): string => self::dimensions($record)),
                        TextEntry::make('megapixels')
                            ->label('Мегапікселі')
                            ->state(fn (MediaAsset $record): string => self::megapixels($record)),
                        TextEntry::make('byte_size')
                            ->label('Вага')
                            ->formatStateUsing(fn (mixed $state): string => self::formatBytes(is_numeric($state) ? (int) $state : null)),
                        TextEntry::make('mime_type')
                            ->label('Формат')
                            ->placeholder('—'),
                    ])->columns(2),
                ])->columns([
                    'default' => 1,
                    'md' => 2,
                ])->columnSpanFull(),
                Group::make([
                    TextEntry::make('source_kind')
                        ->label('Зберігання')
                        ->state(fn (MediaAsset $record): string => self::sourceLabel($record))
                        ->badge()
                        ->color(fn (MediaAsset $record): string => self::sourceColor($record)),
                    TextEntry::make('diagnosis_status')
                        ->label('Технічний стан')
                        ->formatStateUsing(fn (mixed $state): string => self::diagnosisLabel($state))
                        ->badge()
                        ->color(fn (MediaAsset $record): string => self::diagnosisColor($record)),
                    TextEntry::make('created_at')
                        ->label('Додано')
                        ->dateTime('d.m.Y H:i'),
                ])->columns([
                    'default' => 1,
                    'md' => 3,
                ])->columnSpanFull(),
                TextEntry::make('diagnosis_help')
                    ->label('Що це означає')
                    ->state(fn (MediaAsset $record): ?string => self::diagnosisHelp($record))
                    ->visible(fn (MediaAsset $record): bool => $record->diagnosis_status !== MediaDiagnosisStatus::Ready)
                    ->columnSpanFull(),
                TextEntry::make('source_url')
                    ->label('Зовнішнє посилання')
                    ->visible(fn (MediaAsset $record): bool => filled($record->source_url))
                    ->columnSpanFull(),
                TextEntry::make('external_source_note')
                    ->label('Перевірка джерела')
                    ->state('Зовнішній URL може змінитися або стати недоступним. Відсутність попередження не означає, що посилання було нещодавно перевірено.')
                    ->color('warning')
                    ->visible(fn (MediaAsset $record): bool => app(MediaAssetSourceResolver::class)->sourceKind($record) === 'external')
                    ->columnSpanFull(),
            ]),
            Section::make('Використовується в')->schema([
                TextEntry::make('usage_items')
                    ->label('Використання')
                    ->hiddenLabel()
                    ->state(function (MediaAsset $record): ?array {
                        $items = app(MediaAssetLibraryReadService::class)->usageItems($record);

                        return $items === [] ? null : $items;
                    })
                    ->formatStateUsing(fn (array $state): string => self::usageItemLabel($state))
                    ->url(fn (array $state): ?string => self::usageItemUrl($state))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->limitList(8)
                    ->expandableLimitedList()
                    ->placeholder('Не використовується'),
            ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    MediaPreviewFrame::column(
                        ImageColumn::make('asset_preview')
                            ->label('')
                            ->state(fn (MediaAsset $record): ?string => app(MediaAssetSourceResolver::class)->sourceReference($record))
                            ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(88)))
                            ->grow(false),
                        MediaPreviewFrame::ASSET_CARD,
                    ),
                    Stack::make([
                        TextColumn::make('asset_name')
                            ->label('Файл')
                            ->state(fn (MediaAsset $record): string => self::displayName($record))
                            ->weight(FontWeight::SemiBold)
                            ->searchable(['original_filename', 'source_url'])
                            ->limit(70)
                            ->tooltip(fn (MediaAsset $record): string => self::displayName($record)),
                        TextColumn::make('asset_meta')
                            ->label('Параметри')
                            ->state(fn (MediaAsset $record): string => self::dimensions($record).' · '.self::formatBytes($record->byte_size)),
                        TextColumn::make('asset_usage')
                            ->label('Використання')
                            ->state(fn (MediaAsset $record): string => app(MediaAssetLibraryReadService::class)->usageSummary($record))
                            ->color(fn (MediaAsset $record): string => app(MediaAssetLibraryReadService::class)->isUsed($record) ? 'gray' : 'warning'),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('asset_source')
                            ->label('Зберігання')
                            ->state(fn (MediaAsset $record): string => self::sourceLabel($record))
                            ->badge()
                            ->color(fn (MediaAsset $record): string => self::sourceColor($record))
                            ->grow(false),
                        TextColumn::make('asset_status')
                            ->label('Технічний стан')
                            ->state(fn (MediaAsset $record): ?string => self::diagnosisIssueLabel($record))
                            ->badge()
                            ->color(fn (MediaAsset $record): string => self::diagnosisColor($record))
                            ->grow(false),
                    ])->space(1)->grow(false),
                ])->from('md'),
            ])
            ->contentGrid(fn ($livewire): ?array => ($livewire->assetLayout ?? 'grid') === 'grid'
                ? ['md' => 2, '2xl' => 3]
                : null)
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('source')
                    ->label('Зберігання')
                    ->options([
                        'managed' => 'У платформі',
                        'external' => 'Зовнішнє',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => app(MediaAssetLibraryReadService::class)
                        ->applySourceFilter($query, is_string($data['value'] ?? null) ? $data['value'] : null)),
                SelectFilter::make('usage')
                    ->label('Використання')
                    ->options([
                        'used' => 'Використовується',
                        'unused' => 'Не використовується',
                        'products' => 'Товари',
                        'variants' => 'Варіанти',
                        'brand_logos' => 'Логотипи брендів',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => app(MediaAssetLibraryReadService::class)
                        ->applyUsageFilter($query, is_string($data['value'] ?? null) ? $data['value'] : null)),
                Filter::make('attention')
                    ->label('Потребує уваги')
                    ->query(fn (Builder $query): Builder => app(MediaAssetLibraryReadService::class)
                        ->applyAttentionFilter($query, true)),
            ], layout: FiltersLayout::Modal)
            ->deferFilters()
            ->filtersFormWidth('md')
            ->filtersApplyAction(
                fn (Action $action): Action => $action
                    ->label('Застосувати')
                    ->color('gray')
            )
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->button()
                    ->outlined()
                    ->color('gray')
                    ->label('Фільтри')
                    ->tooltip('Фільтри')
                    ->extraAttributes(['class' => 'bp-toolbar-count-trigger'])
                    ->slideOver()
                    ->extraModalFooterActions([
                        $table->getFiltersApplyAction()->close(),
                        Action::make('resetFilters')
                            ->label('Скинути')
                            ->color('gray')
                            ->outlined()
                            ->action('resetTableFiltersForm')
                            ->button()
                            ->close(),
                    ])
            )
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
                    ->modalHeading(fn (MediaAsset $record): string => self::displayName($record))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрити')
                    ->schema(fn (): array => self::detailSchema())
                    ->extraModalFooterActions(fn (MediaAsset $record): array => [
                        Action::make('open_full_page_footer')
                            ->label('Відкрити повну картку')
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->color('gray')
                            ->url(self::fullCardUrl($record))
                            ->openUrlInNewTab()
                            ->close(),
                    ]),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaAssets::route('/'),
            'view' => ViewMediaAsset::route('/{record}'),
            'edit' => EditMediaAsset::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(MediaAssetLibraryReadService::class)
            ->originalsQuery(app(WorkspaceContext::class)->current());
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return $record instanceof MediaAsset
            && (string) $record->workspace_id === app(WorkspaceContext::class)->id()
                ? Response::allow()
                : Response::deny();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        if (! $record instanceof MediaAsset
            || (string) $record->workspace_id !== app(WorkspaceContext::class)->id()
            || ! $record->isOriginal()
            || $record->asset_type !== MediaAssetType::Image
        ) {
            return Response::deny();
        }

        $actor = auth()->user();

        return $actor instanceof User
            && app(WorkspaceAuthorization::class)->allows(
                $actor,
                app(WorkspaceContext::class)->current(),
                WorkspacePermissions::MANAGE_PRODUCTS,
            )
                ? Response::allow()
                : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function fullCardUrl(MediaAsset $record): string
    {
        return self::canEdit($record)
            ? self::getUrl('edit', ['record' => $record])
            : self::getUrl('view', ['record' => $record]);
    }

    public static function displayName(MediaAsset $asset): string
    {
        if (filled($asset->original_filename)) {
            return (string) $asset->original_filename;
        }

        if (filled($asset->source_url)) {
            $path = parse_url((string) $asset->source_url, PHP_URL_PATH);
            $name = is_string($path) ? basename($path) : '';

            if ($name !== '') {
                return $name;
            }
        }

        return 'Image '.substr((string) $asset->id, 0, 8);
    }

    public static function dimensions(MediaAsset $asset): string
    {
        return $asset->width_px !== null && $asset->height_px !== null
            ? $asset->width_px.' × '.$asset->height_px.' px'
            : 'Розмір невідомий';
    }

    public static function megapixels(MediaAsset $asset): string
    {
        if ($asset->width_px === null || $asset->height_px === null) {
            return '—';
        }

        $megapixels = ($asset->width_px * $asset->height_px) / 1_000_000;
        $precision = $megapixels < 0.01 ? 3 : ($megapixels < 1 ? 2 : ($megapixels < 10 ? 1 : 0));

        return number_format($megapixels, $precision, ',', ' ').' МП';
    }

    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'Вага невідома';
        }

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', ' ').' МіБ';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', ' ').' КіБ';
        }

        return $bytes.' Б';
    }

    private static function sourceLabel(MediaAsset $asset): string
    {
        return match (app(MediaAssetSourceResolver::class)->sourceKind($asset)) {
            'managed' => 'У платформі',
            'external' => 'Зовнішнє',
            default => 'Немає джерела',
        };
    }

    private static function sourceColor(MediaAsset $asset): string
    {
        return match (app(MediaAssetSourceResolver::class)->sourceKind($asset)) {
            'managed' => 'success',
            'external' => 'info',
            default => 'danger',
        };
    }

    private static function diagnosisLabel(mixed $state): string
    {
        $value = $state instanceof MediaDiagnosisStatus ? $state->value : (string) $state;

        return match ($value) {
            MediaDiagnosisStatus::Ready->value => 'Перевірено',
            MediaDiagnosisStatus::Pending->value => 'Ще не перевірено',
            MediaDiagnosisStatus::Attention->value => 'Потребує уваги',
            MediaDiagnosisStatus::Failed->value => 'Помилка',
            default => 'Невідомо',
        };
    }

    private static function diagnosisIssueLabel(MediaAsset $asset): ?string
    {
        return $asset->diagnosis_status === MediaDiagnosisStatus::Ready
            ? null
            : self::diagnosisLabel($asset->diagnosis_status);
    }

    private static function diagnosisHelp(MediaAsset $asset): ?string
    {
        return match ($asset->diagnosis_status) {
            MediaDiagnosisStatus::Ready => null,
            MediaDiagnosisStatus::Pending => 'Джерело ще не перевірено. Перед критичним використанням перевірте посилання або завантажте файл у платформу.',
            MediaDiagnosisStatus::Attention => 'Перевірте джерело або замініть зображення перед критичним використанням.',
            MediaDiagnosisStatus::Failed => 'Зображення не пройшло технічну перевірку. Замініть файл або джерело.',
        };
    }

    /**
     * @param  array{type:string,id:string,label:string,detail:?string,product_id:?string}  $state
     */
    private static function usageItemLabel(array $state): string
    {
        $type = match ($state['type']) {
            'brand' => 'Бренд',
            'product' => 'Товар',
            'variant' => 'Варіант',
            default => 'Використання',
        };

        $detail = filled($state['detail'] ?? null) ? ' · '.$state['detail'] : '';

        return $type.' · '.$state['label'].$detail;
    }

    /**
     * @param  array{type:string,id:string,label:string,detail:?string,product_id:?string}  $state
     */
    private static function usageItemUrl(array $state): ?string
    {
        return match ($state['type']) {
            'brand' => BrandResource::getUrl('edit', ['record' => $state['id']]),
            'product' => ProductResource::getUrl('edit', ['record' => $state['id']]),
            'variant' => filled($state['product_id'] ?? null)
                ? ProductResource::getUrl('edit', ['record' => $state['product_id']])
                : null,
            default => null,
        };
    }

    private static function diagnosisColor(MediaAsset $asset): string
    {
        return match ($asset->diagnosis_status) {
            MediaDiagnosisStatus::Ready => 'success',
            MediaDiagnosisStatus::Pending => 'gray',
            MediaDiagnosisStatus::Attention => 'warning',
            MediaDiagnosisStatus::Failed => 'danger',
        };
    }
}
