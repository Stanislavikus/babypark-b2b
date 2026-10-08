<?php

namespace App\Filament\Resources;

use App\Enums\MediaDiagnosisStatus;
use App\Filament\Resources\MediaAssetResource\Pages\ListMediaAssets;
use App\Filament\Resources\MediaAssetResource\Pages\ViewMediaAsset;
use App\Models\MediaAsset;
use App\Services\Media\MediaAssetLibraryReadService;
use App\Services\Media\MediaAssetSourceResolver;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
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
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Original')->schema([
                ImageEntry::make('preview_url')
                    ->label('Зображення')
                    ->state(fn (MediaAsset $record): ?string => app(MediaAssetSourceResolver::class)->sourceReference($record))
                    ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(180)))
                    ->imageWidth('100%')
                    ->imageHeight('20rem')
                    ->extraImgAttributes([
                        'style' => 'object-fit: contain; max-width: 100%; max-height: 20rem; background-color: white;',
                    ]),
                TextEntry::make('display_name')
                    ->label('Файл')
                    ->state(fn (MediaAsset $record): string => self::displayName($record)),
                TextEntry::make('source_kind')
                    ->label('Зберігання')
                    ->state(fn (MediaAsset $record): string => self::sourceLabel($record))
                    ->badge()
                    ->color(fn (MediaAsset $record): string => self::sourceColor($record)),
                TextEntry::make('dimensions')
                    ->label('Розмір')
                    ->state(fn (MediaAsset $record): string => self::dimensions($record)),
                TextEntry::make('byte_size')
                    ->label('Вага')
                    ->formatStateUsing(fn (mixed $state): string => self::formatBytes(is_numeric($state) ? (int) $state : null)),
                TextEntry::make('mime_type')
                    ->label('Формат')
                    ->placeholder('—'),
                TextEntry::make('diagnosis_status')
                    ->label('Технічний стан')
                    ->formatStateUsing(fn (mixed $state): string => self::diagnosisLabel($state))
                    ->badge()
                    ->color(fn (MediaAsset $record): string => self::diagnosisColor($record)),
                TextEntry::make('diagnosis_help')
                    ->label('Що це означає')
                    ->state(fn (MediaAsset $record): ?string => self::diagnosisHelp($record))
                    ->visible(fn (MediaAsset $record): bool => $record->diagnosis_status !== MediaDiagnosisStatus::Ready),
                TextEntry::make('source_url')
                    ->label('Зовнішнє посилання')
                    ->visible(fn (MediaAsset $record): bool => filled($record->source_url)),
            ])->columns(2),
            Section::make('Використовується в')->schema([
                TextEntry::make('usage_items')
                    ->label('')
                    ->state(fn (MediaAsset $record): array => app(MediaAssetLibraryReadService::class)->usageItems($record))
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    ImageColumn::make('asset_preview')
                        ->label('')
                        ->state(fn (MediaAsset $record): ?string => app(MediaAssetSourceResolver::class)->sourceReference($record))
                        ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(88)))
                        ->imageWidth(88)
                        ->imageHeight(88)
                        ->extraImgAttributes([
                            'style' => 'object-fit: contain; width: 88px; height: 88px; max-width: 88px; max-height: 88px; background-color: white;',
                        ])
                        ->grow(false),
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
            ])
            ->recordUrl(fn (MediaAsset $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()
                    ->label('Деталі'),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaAssets::route('/'),
            'view' => ViewMediaAsset::route('/{record}'),
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
        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
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
