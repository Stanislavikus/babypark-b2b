<?php

namespace App\Filament\Resources;

use App\Enums\TagBulkOperation;
use App\Exceptions\Catalog\InvalidTagBulkSelectionException;
use App\Filament\Concerns\HasProductLightbox;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\Resources\ProductResource\Support\TagBulkUi;
use App\Models\Product;
use App\Models\SyncConfigurationProductSelection;
use App\Models\Tag;
use App\Models\User;
use App\Services\Catalog\ProductWorkspaceSummaryService;
use App\Services\Catalog\TagManager;
use App\Services\Pricing\PricingSqlExpressions;
use App\Services\Pricing\ProductPricingSummary;
use App\Services\Sync\ProductChannelSelectionService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\AdminAvailabilityPresenter;
use App\Support\ProductFields\AdminProductMargin;
use App\Support\ProductFields\MarginToggle;
use App\Support\ProductFields\ProductColumnVisibility;
use App\Support\ProductTableLink;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use LogicException;

class ProductResource extends Resource
{
    use HasProductLightbox;

    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'товар';

    protected static ?string $pluralModelLabel = 'Товари';

    protected static ?int $navigationSort = 3;

    // -------------------------------------------------------------------------
    // Form (edit)
    // -------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make([
                    Section::make('Основна інформація')
                        ->description(fn (?Product $record): string => self::isSourceOwned($record)
                            ? 'Основні ідентифікаційні дані надходять з 1С. Контент і внутрішня організація редагуються окремо.'
                            : 'Master-дані товару. SKU та GTIN необов’язкові для чернетки.')
                        ->schema([
                            TextInput::make('name')
                                ->label('Назва')
                                ->required()
                                ->maxLength(255)
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record))
                                ->columnSpanFull(),
                            RichEditor::make('description')
                                ->label('Опис')
                                ->columnSpanFull(),
                            TextInput::make('sku')
                                ->label('Артикул / SKU')
                                ->maxLength(255)
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record))
                                ->helperText(fn (?Product $record): ?string => $record === null
                                    ? 'Необов’язково. Внутрішня ідентичність товару не залежить від SKU.'
                                    : null),
                            TextInput::make('barcode_ean')
                                ->label('EAN / GTIN')
                                ->maxLength(255)
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                        ])
                        ->columns(2),

                    Section::make('Медіа')
                        ->description('Один логічний кадр у Workspace; технічні версії для каналів не дублюються в галереї.')
                        ->schema([
                            Placeholder::make('workspace_media')
                                ->hiddenLabel()
                                ->content(fn (?Product $record): HtmlString => self::buildMediaWorkspaceHtml($record)),
                        ])
                        ->visible(fn (?Product $record): bool => $record !== null),

                    Section::make('Ціна')
                        ->schema([
                            Placeholder::make('workspace_sale_price')
                                ->label('Поточна ціна')
                                ->content(fn (?Product $record): string => $record
                                    ? (app(ProductPricingSummary::class)->formatDefaultSalePrice($record) ?? '—')
                                    : '—'),
                            Placeholder::make('workspace_rrp')
                                ->label('РРЦ')
                                ->content(fn (?Product $record): string => $record
                                    ? (app(ProductPricingSummary::class)->formatRrp($record) ?? '—')
                                    : '—'),
                            Placeholder::make('workspace_cost')
                                ->label('Вхідна ціна')
                                ->content(fn (?Product $record): string => $record
                                    ? (app(ProductPricingSummary::class)->formatCostPrice($record) ?? '—')
                                    : '—'),
                        ])
                        ->columns(3)
                        ->visible(fn (?Product $record): bool => $record !== null),

                    Section::make('Залишки')
                        ->schema([
                            Placeholder::make('workspace_availability')
                                ->label('Наявність')
                                ->content(fn (?Product $record): string => $record
                                    ? AdminAvailabilityPresenter::adminLabel($record)
                                    : '—'),
                            Placeholder::make('workspace_inventory_scope')
                                ->label('Облік')
                                ->content(fn (?Product $record): string => $record
                                    ? self::inventoryScopeLabel($record)
                                    : '—'),
                        ])
                        ->columns(2)
                        ->visible(fn (?Product $record): bool => $record !== null),

                    Section::make('Доставка та фізичні дані')
                        ->schema([
                            TextInput::make('net_weight')
                                ->label('Вага нетто')
                                ->numeric()
                                ->suffix('кг')
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('gross_weight')
                                ->label('Вага брутто')
                                ->numeric()
                                ->suffix('кг')
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('width_mm')
                                ->label('Ширина')
                                ->numeric()
                                ->suffix('мм')
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('height_mm')
                                ->label('Висота')
                                ->numeric()
                                ->suffix('мм')
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('depth_mm')
                                ->label('Глибина')
                                ->numeric()
                                ->suffix('мм')
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('volume_m3')
                                ->label('Об’єм')
                                ->numeric()
                                ->suffix('м³')
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                        ])
                        ->columns(3)
                        ->collapsible()
                        ->collapsed()
                        ->visible(fn (?Product $record): bool => $record !== null),

                    Section::make('Варіанти')
                        ->schema([
                            Placeholder::make('workspace_variants')
                                ->hiddenLabel()
                                ->content(fn (?Product $record): HtmlString => self::buildVariantWorkspaceHtml($record)),
                        ])
                        ->visible(fn (?Product $record): bool => $record !== null),

                    Section::make('Характеристики')
                        ->description('Групи визначаються типом товару. Тут показується Master-структура, а не поля конкретного каналу.')
                        ->schema([
                            Placeholder::make('workspace_attributes')
                                ->hiddenLabel()
                                ->content(fn (?Product $record): HtmlString => self::buildAttributeGroupsHtml($record)),
                        ])
                        ->visible(fn (?Product $record): bool => $record !== null),

                    Section::make('SEO та пошук')
                        ->schema([
                            TextInput::make('meta_title')
                                ->label('SEO title')
                                ->maxLength(255)
                                ->columnSpanFull(),
                            TextInput::make('meta_description')
                                ->label('Meta description')
                                ->maxLength(500)
                                ->columnSpanFull(),
                            TextInput::make('url')
                                ->label('URL товару на сайті')
                                ->url()
                                ->placeholder('https://babypark.ua/product/...')
                                ->maxLength(2048)
                                ->suffixAction(
                                    Action::make('open_url')
                                        ->icon('heroicon-m-arrow-top-right-on-square')
                                        ->url(fn (?string $state) => $state)
                                        ->openUrlInNewTab()
                                        ->visible(fn (?string $state) => filled($state))
                                )
                                ->columnSpanFull(),
                        ])
                        ->collapsible()
                        ->visible(fn (?Product $record): bool => $record !== null),
                ])->columnSpan(2),

                Group::make([
                    Section::make('Статус')
                        ->schema([
                            Placeholder::make('workspace_lifecycle')
                                ->label('Master')
                                ->content(fn (?Product $record): string => $record
                                    ? ($record->is_active ? 'Активний' : 'Неактивний')
                                    : 'Нова чернетка'),
                            Placeholder::make('workspace_source')
                                ->label('Джерело')
                                ->content(fn (?Product $record): string => self::isSourceOwned($record)
                                    ? '1С · авторитетне джерело'
                                    : 'Master Workspace'),
                        ])
                        ->columns(1),

                    Section::make('Організація')
                        ->schema([
                            Select::make('category_id')
                                ->label('Внутрішня категорія')
                                ->relationship(name: 'category', titleAttribute: 'name')
                                ->searchable()
                                ->preload(),
                            TextInput::make('brand')
                                ->label('Бренд')
                                ->maxLength(255)
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            Placeholder::make('workspace_product_type')
                                ->label('Тип товару')
                                ->content(fn (?Product $record): string => $record
                                    ? app(ProductWorkspaceSummaryService::class)->productTypeLabel($record)
                                    : 'Базовий товар буде призначено автоматично'),
                            TextInput::make('merchant_type')
                                ->label('Внутрішній тип')
                                ->maxLength(255)
                                ->datalist(fn (): array => Product::query()
                                    ->distinct()
                                    ->orderBy('merchant_type')
                                    ->whereNotNull('merchant_type')
                                    ->where('merchant_type', '!=', '')
                                    ->pluck('merchant_type')
                                    ->all())
                                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null),
                            Select::make('tags')
                                ->label('Теги')
                                ->multiple()
                                ->relationship(titleAttribute: 'name')
                                ->createOptionForm([
                                    TextInput::make('name')
                                        ->label('Назва')
                                        ->required()
                                        ->maxLength(255),
                                ])
                                ->createOptionUsing(function (array $data, ?Product $record): string {
                                    if ($record === null) {
                                        throw new LogicException('A persisted Product is required for inline tag creation.');
                                    }

                                    return app(TagManager::class)
                                        ->create($record->workspace_id, $data['name'])
                                        ->getKey();
                                })
                                ->preload()
                                ->searchable()
                                ->visible(fn (?Product $record): bool => $record !== null),
                            Placeholder::make('workspace_tags_after_create')
                                ->label('Теги')
                                ->content('Можна додати після першого створення товару.')
                                ->visible(fn (?Product $record): bool => $record === null),
                        ]),

                    Section::make('Якість даних')
                        ->schema([
                            Placeholder::make('workspace_quality')
                                ->hiddenLabel()
                                ->content(fn (?Product $record): HtmlString => self::buildBasicQualityHtml($record)),
                        ])
                        ->visible(fn (?Product $record): bool => $record !== null),

                    Section::make('Канали публікації')
                        ->schema([
                            Placeholder::make('workspace_channels')
                                ->hiddenLabel()
                                ->content(fn (?Product $record): HtmlString => self::buildChannelWorkspaceHtml($record)),
                        ])
                        ->visible(fn (?Product $record): bool => $record !== null),

                    Section::make('Потребує уваги')
                        ->schema([
                            Placeholder::make('workspace_attention')
                                ->hiddenLabel()
                                ->content(fn (?Product $record): HtmlString => self::buildAttentionHtml($record)),
                        ])
                        ->visible(fn (?Product $record): bool => $record !== null),
                ])->columnSpan(1),
            ]);
    }

    // -------------------------------------------------------------------------
    // Infolist (view) — used both on the dedicated view page and in the slideOver
    // -------------------------------------------------------------------------

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основне (з 1С)')->schema([
                    TextEntry::make('sku')->label('Артикул'),
                    TextEntry::make('variant_ean')
                        ->label('EAN')
                        ->getStateUsing(fn (Product $record): ?string => $record->variants->first()?->barcode_ean)
                        ->placeholder('—'),
                    TextEntry::make('brand')->label('Бренд')->placeholder('—'),
                    TextEntry::make('name')->label('Назва'),
                    TextEntry::make('category.name')->label('Категорія')->placeholder('—'),
                    TextEntry::make('admin_stock_status')
                        ->label('Наявність')
                        ->getStateUsing(fn (Product $record): string => AdminAvailabilityPresenter::adminLabel($record))
                        ->badge()
                        ->color(fn (string $state): string => AdminAvailabilityPresenter::badgeColor($state)),
                    TextEntry::make('cost_price_summary')
                        ->label('Вхідна ціна')
                        ->getStateUsing(fn (Product $record): ?string => app(ProductPricingSummary::class)->formatCostPrice($record))
                        ->placeholder('—'),
                    TextEntry::make('admin_rrp')
                        ->label('РРЦ')
                        ->getStateUsing(fn (Product $record): ?string => app(ProductPricingSummary::class)->formatRrp($record))
                        ->placeholder('—'),
                    TextEntry::make('admin_margin')
                        ->label(fn (): HtmlString => MarginToggle::labelHtml(
                            Livewire::current()?->marginFormat ?? 'percent'
                        ))
                        ->getStateUsing(fn (Product $record): ?string => AdminProductMargin::formatted(
                            $record,
                            Livewire::current()?->marginFormat ?? 'percent'
                        ))
                        ->color(fn (Product $record): ?string => AdminProductMargin::isNegative($record) ? 'danger' : null)
                        ->placeholder('—'),
                    TextEntry::make('admin_status')
                        ->label('Статус')
                        ->getStateUsing(fn (Product $record): string => $record->is_active ? 'Активний' : 'Неактивний')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Активний' ? 'success' : 'gray'),
                ])->columns(2),

                Section::make('Класифікація')->schema([
                    TextEntry::make('merchant_type')
                        ->label('Внутрішній тип товару')
                        ->placeholder('—'),
                    TextEntry::make('tags.name')
                        ->label('Теги')
                        ->badge()
                        ->placeholder('—'),
                ])->columns(2),

                Section::make('Сайт')->schema([
                    // Left: clickable URL
                    TextEntry::make('url')
                        ->label('URL товару на сайті')
                        ->placeholder('—')
                        ->url(fn (?string $state) => $state)
                        ->openUrlInNewTab()
                        ->icon('heroicon-m-arrow-top-right-on-square')
                        ->iconColor('primary')
                        ->formatStateUsing(fn (?string $state) => $state
                            ? parse_url($state, PHP_URL_HOST).rtrim(parse_url($state, PHP_URL_PATH) ?? '', '/')
                            : null),

                    // Right: 48×48 thumbnail — click opens the shared bpOpenLightbox() JS overlay.
                    TextEntry::make('photo_preview')
                        ->label('Фото товару')
                        ->getStateUsing(function ($record) {
                            if (! $record) {
                                return new HtmlString('');
                            }
                            $url = self::firstImage($record);

                            if ($url) {
                                $safe = e($url);
                                $title = e($record->name);

                                return new HtmlString(
                                    '<img src="'.$safe.'"'.
                                    ' style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;cursor:zoom-in;"'.
                                    ' title="Натисніть для збільшення"'.
                                    ' onclick="bpOpenLightbox(\''.$safe.'\',\''.$title.'\')" />'
                                );
                            }

                            return new HtmlString(
                                '<div style="width:48px;height:48px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;display:flex;align-items:center;justify-content:center;">'.
                                '<span style="color:#9ca3af;font-size:10px;">—</span></div>'
                            );
                        }),
                ])->columns(2),
            ]);
    }

    // -------------------------------------------------------------------------
    // Table (list)
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        $panel = 'admin';
        $toggleable = ProductColumnVisibility::toggleableColumns($panel);

        return $table
            ->columns([
                // --- Default visible columns (8 total) ---

                // 1. Фото: thumbnail — click opens shared bpOpenLightbox(); does NOT trigger row action
                ImageColumn::make('first_image')
                    ->label('Фото')
                    ->state(fn (Product $record): ?string => self::firstImage($record))
                    ->size(48)
                    ->defaultImageUrl(fn () => 'data:image/svg+xml,'.rawurlencode(self::placeholderSvg(48)))
                    ->extraImgAttributes(fn (Product $record): array => self::lightboxImgAttributes($record))
                    ->toggleable(in_array('photo', $toggleable)),

                // 2. Назва
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                // 3. Артикул
                TextColumn::make('sku')
                    ->label('Артикул')
                    ->searchable()
                    ->sortable(),

                // 4. Бренд — default visible; user may toggle it off
                TextColumn::make('brand')
                    ->label('Бренд')
                    ->searchable()
                    ->sortable()
                    ->toggleable(in_array('brand', $toggleable)),

                // 5. Ціна — placeholder until admin/base sale price model is resolved (Follow-up 3)
                TextColumn::make('admin_sale_price')
                    ->label('Ціна')
                    ->getStateUsing(fn (Product $record): ?string => app(ProductPricingSummary::class)->formatDefaultSalePrice($record))
                    ->placeholder('—'),

                // 6. Наявність — uses AvailabilityResolver net qty; consistent with filter and infolist
                TextColumn::make('stock_status')
                    ->label('Наявність')
                    ->getStateUsing(fn (Product $record): string => AdminAvailabilityPresenter::adminLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => AdminAvailabilityPresenter::badgeColor($state))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $netQty = AdminAvailabilityPresenter::netQtySql();
                        $minExpectedDate = AdminAvailabilityPresenter::earliestExpectedDateSql();

                        // Priority bucket: 0 = У наявності, 1 = Очікується, 2 = Немає в наявності.
                        $priorityExpr = "CASE
                            WHEN {$netQty} > 0 THEN 0
                            WHEN {$minExpectedDate} IS NOT NULL THEN 1
                            ELSE 2
                        END";

                        return $query
                            ->orderByRaw("{$priorityExpr} {$direction}")
                            ->orderByRaw("{$netQty} DESC")
                            ->orderByRaw("{$minExpectedDate} ASC");
                    }),

                // 7. Статус
                IconColumn::make('is_active')
                    ->label('Статус')
                    ->boolean()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('is_active', $direction)->orderBy('id', $direction);
                    }),

                TextColumn::make('sync_channels')
                    ->label(__('product_channels.columns.channels'))
                    ->getStateUsing(fn (Product $record): array => $record->syncChannelSelections
                        ->map(fn (SyncConfigurationProductSelection $selection): ?string => $selection->syncConfiguration
                            ? app(ProductChannelSelectionService::class)->channelLabel($selection->syncConfiguration)
                            : null)
                        ->filter()
                        ->values()
                        ->all())
                    ->badge()
                    ->placeholder('—'),

                // --- Optional / hidden by default columns ---

                TextColumn::make('barcode_ean')
                    ->label('EAN')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(in_array('barcode_ean', $toggleable), isToggledHiddenByDefault: true),

                TextColumn::make('category.name')
                    ->label('Категорія')
                    ->sortable()
                    ->toggleable(in_array('category', $toggleable), isToggledHiddenByDefault: true),

                TextColumn::make('rrp')
                    ->label('РРЦ')
                    ->getStateUsing(fn (Product $record): ?string => app(ProductPricingSummary::class)->formatRrp($record))
                    ->placeholder('—')
                    ->toggleable(in_array('rrp', $toggleable), isToggledHiddenByDefault: true)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            'COALESCE('.PricingSqlExpressions::maxRrpSqlForProduct('products.id').", 0) {$direction}"
                        );
                    }),

                TextColumn::make('cost_price_summary')
                    ->label('Вхідна ціна')
                    ->getStateUsing(fn (Product $record): ?string => app(ProductPricingSummary::class)->formatCostPrice($record))
                    ->placeholder('—')
                    ->toggleable(in_array('cost_price', $toggleable), isToggledHiddenByDefault: true)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            "(SELECT MIN(pv.cost_price)
                              FROM product_variants pv
                              WHERE pv.product_id = products.id
                              AND pv.is_active = 1
                              AND pv.cost_price IS NOT NULL) {$direction}"
                        );
                    }),

                TextColumn::make('margin')
                    ->label(fn (): HtmlString => MarginToggle::labelHtml(
                        Livewire::current()?->marginFormat ?? 'percent'
                    ))
                    ->getStateUsing(fn (Product $record): ?string => AdminProductMargin::formatted(
                        $record,
                        Livewire::current()?->marginFormat ?? 'percent'
                    ))
                    ->color(fn (Product $record): ?string => AdminProductMargin::isNegative($record) ? 'danger' : null)
                    ->placeholder('—')
                    ->toggleable(in_array('margin', $toggleable), isToggledHiddenByDefault: true)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            PricingSqlExpressions::adminMarginSortSql('products.id')." {$direction}"
                        );
                    }),

                // Clickable external link column
                TextColumn::make('url')
                    ->label('URL на сайті')
                    ->formatStateUsing(fn (?string $state): HtmlString|string => ProductTableLink::externalUrlHtml($state))
                    ->tooltip(fn (?string $state) => $state)
                    ->disableClick()
                    ->toggleable(in_array('url', $toggleable), isToggledHiddenByDefault: true),

                TextColumn::make('merchant_type')
                    ->label('Внутрішній тип товару')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(in_array('merchant_type', $toggleable), isToggledHiddenByDefault: true),

                TextColumn::make('tags.name')
                    ->label('Теги')
                    ->placeholder('—')
                    ->toggleable(in_array('tags', $toggleable), isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sku')
            ->toggleColumnsTriggerAction(
                fn (Action $action) => $action
                    ->label('Стовпці')
                    ->tooltip('Стовпці')
            )
            // Neutral row click opens the ViewAction slideOver instead of navigating to full page.
            ->recordUrl(null)
            ->recordAction('view')
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Категорії')
                    ->relationship('category', 'name')
                    ->multiple()
                    ->preload(),

                SelectFilter::make('brand')
                    ->label('Бренди')
                    ->options(fn (): array => Product::query()
                        ->distinct()
                        ->orderBy('brand')
                        ->whereNotNull('brand')
                        ->pluck('brand', 'brand')
                        ->toArray())
                    ->multiple(),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->placeholder('Всі')
                    ->options([
                        'active' => 'Тільки активні',
                        'inactive' => 'Тільки неактивні',
                    ])
                    ->default('active')
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query->where('is_active', true),
                            'inactive' => $query->where('is_active', false),
                            default => $query,
                        };
                    }),

                // Availability filter — uses same net-qty calculation as the column and infolist.
                // Status buckets: У наявності / Очікується / Немає в наявності.
                // NOTE: Follow-up 1 — migrate to AvailabilityResolver when implemented.
                SelectFilter::make('availability')
                    ->label('Наявність')
                    ->placeholder('Всі')
                    ->options([
                        'in_stock' => AdminAvailabilityPresenter::BUCKET_IN_STOCK,
                        'expected' => AdminAvailabilityPresenter::BUCKET_EXPECTED,
                        'out_of_stock' => AdminAvailabilityPresenter::BUCKET_OUT_OF_STOCK,
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $netQty = AdminAvailabilityPresenter::netQtySql();
                        $expectedDate = AdminAvailabilityPresenter::earliestExpectedDateSql();

                        return match ($data['value'] ?? null) {
                            'in_stock' => $query->whereRaw("{$netQty} > 0"),
                            'expected' => $query->whereRaw("{$netQty} <= 0")
                                ->whereRaw("{$expectedDate} IS NOT NULL"),
                            'out_of_stock' => $query->whereRaw("{$netQty} <= 0")
                                ->whereRaw("{$expectedDate} IS NULL"),
                            default => $query,
                        };
                    }),

                SelectFilter::make('tags')
                    ->label('Теги')
                    ->relationship('tags', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),

                SelectFilter::make('merchant_type')
                    ->label('Внутрішній тип товару')
                    ->options(fn (): array => Product::query()
                        ->distinct()
                        ->orderBy('merchant_type')
                        ->whereNotNull('merchant_type')
                        ->where('merchant_type', '!=', '')
                        ->pluck('merchant_type', 'merchant_type')
                        ->toArray())
                    ->multiple(),

                SelectFilter::make('sync_channel')
                    ->label(__('product_channels.filter.label'))
                    ->options(fn (): array => app(ProductChannelSelectionService::class)
                        ->channelOptions(app(WorkspaceContext::class)->current()))
                    ->query(function (Builder $query, array $data): Builder {
                        $configurationId = $data['value'] ?? null;

                        if (! is_string($configurationId) || $configurationId === '') {
                            return $query;
                        }

                        return $query->whereIn(
                            'products.id',
                            SyncConfigurationProductSelection::withoutWorkspaceScope()
                                ->select('product_id')
                                ->where('workspace_id', app(WorkspaceContext::class)->id())
                                ->where('sync_configuration_id', $configurationId),
                        );
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->extraAttributes(['class' => 'bp-admin-row-view-action-hidden'])
                    ->slideOver()
                    ->extraModalFooterActions(fn (Product $record) => [
                        Action::make('open_full_page_footer')
                            ->label('Відкрити повну картку')
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->color('gray')
                            ->url(static::getUrl('view', ['record' => $record])),
                    ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::makeChannelBulkAction(adding: true),
                    self::makeChannelBulkAction(adding: false),
                    self::makeTagBulkAction(
                        operation: TagBulkOperation::Add,
                        name: 'add_tags',
                        label: 'Додати теги',
                        icon: 'heroicon-o-plus-circle',
                        successTitle: 'Теги додано',
                        failureTitle: 'Не вдалося додати теги',
                    ),
                    self::makeTagBulkAction(
                        operation: TagBulkOperation::Remove,
                        name: 'remove_tags',
                        label: 'Видалити теги',
                        icon: 'heroicon-o-minus-circle',
                        successTitle: 'Теги видалено',
                        failureTitle: 'Не вдалося видалити теги',
                    ),
                ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Eager loading
    // -------------------------------------------------------------------------

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'variants.stocks',
                'category',
                'tags',
                'syncChannelSelections.syncConfiguration.connectorAccount.connectorDefinition',
            ]);
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    // -------------------------------------------------------------------------
    // Product channel assignment actions
    // -------------------------------------------------------------------------

    private static function makeChannelBulkAction(bool $adding): BulkAction
    {
        $name = $adding ? 'add_to_sync_channel' : 'remove_from_sync_channel';

        return BulkAction::make($name)
            ->label(__($adding ? 'product_channels.actions.add' : 'product_channels.actions.remove'))
            ->icon($adding ? 'heroicon-o-arrow-right-circle' : 'heroicon-o-minus-circle')
            ->color($adding ? 'primary' : 'gray')
            ->schema([
                Select::make('sync_configuration_id')
                    ->label(__('product_channels.fields.channel'))
                    ->options(fn (): array => app(ProductChannelSelectionService::class)
                        ->channelOptions(app(WorkspaceContext::class)->current()))
                    ->default(fn (ListProducts $livewire): ?string => $livewire->channelContext)
                    ->required()
                    ->native(false)
                    ->searchable(),
            ])
            ->visible(function (): bool {
                $actor = auth()->user();
                $workspace = app(WorkspaceContext::class)->current();

                return $actor instanceof User
                    && app(ProductChannelSelectionService::class)->canManage($actor, $workspace)
                    && app(ProductChannelSelectionService::class)->channelOptions($workspace) !== [];
            })
            ->action(function (Collection $records, array $data, ListProducts $livewire) use ($adding): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $workspace = app(WorkspaceContext::class)->current();
                $productIds = $livewire->getSelectedTableRecords()->modelKeys();
                $configurationId = (string) ($data['sync_configuration_id'] ?? '');

                try {
                    $service = app(ProductChannelSelectionService::class);
                    $adding
                        ? $service->add($actor, $workspace, $configurationId, $productIds)
                        : $service->remove($actor, $workspace, $configurationId, $productIds);
                } catch (\Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title(__('product_channels.notifications.failed'))
                        ->send();

                    throw new Halt;
                }

                Notification::make()
                    ->success()
                    ->title(__($adding ? 'product_channels.notifications.added' : 'product_channels.notifications.removed'))
                    ->send();

                $livewire->deselectAllTableRecords();
            })
            ->deselectRecordsAfterCompletion(false);
    }

    // -------------------------------------------------------------------------
    // Bulk tag actions
    // -------------------------------------------------------------------------

    private static function makeTagBulkAction(
        TagBulkOperation $operation,
        string $name,
        string $label,
        string $icon,
        string $successTitle,
        string $failureTitle,
    ): BulkAction {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->schema([
                Select::make('tag_ids')
                    ->label('Теги')
                    ->multiple()
                    ->required()
                    ->options(fn (): array => Tag::withoutWorkspaceScope()
                        ->where('workspace_id', app(WorkspaceContext::class)->id())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->live(),
                Placeholder::make('bulk_preview')
                    ->label('Попередній перегляд')
                    ->content(function (Get $get, ListProducts $livewire) use ($operation): string {
                        $tagIds = $get('tag_ids') ?? [];

                        if ($tagIds === []) {
                            return 'Оберіть теги для попереднього перегляду.';
                        }

                        $productIds = $livewire->getSelectedTableRecords()->modelKeys();

                        if ($productIds === []) {
                            return 'Оберіть товари для попереднього перегляду.';
                        }

                        try {
                            $metrics = TagBulkUi::assignmentService()->preview(
                                app(WorkspaceContext::class)->id(),
                                $productIds,
                                $tagIds,
                                $operation,
                            );

                            return TagBulkUi::formatPreviewText($metrics);
                        } catch (InvalidTagBulkSelectionException $exception) {
                            return $exception->getMessage();
                        }
                    })
                    ->visible(fn (Get $get): bool => filled($get('tag_ids'))),
            ])
            ->action(function (Collection $records, array $data, ListProducts $livewire) use ($operation, $successTitle, $failureTitle): void {
                $workspaceId = app(WorkspaceContext::class)->id();
                $productIds = $livewire->getSelectedTableRecords()->modelKeys();
                $tagIds = $data['tag_ids'] ?? [];

                try {
                    $metrics = TagBulkUi::assignmentService()->apply(
                        $workspaceId,
                        $productIds,
                        $tagIds,
                        $operation,
                    );
                } catch (InvalidTagBulkSelectionException $exception) {
                    Notification::make()
                        ->danger()
                        ->title($failureTitle)
                        ->body($exception->getMessage())
                        ->send();

                    throw new Halt;
                }

                Notification::make()
                    ->success()
                    ->title($successTitle)
                    ->body(TagBulkUi::formatResultNotification($metrics))
                    ->send();

                $livewire->deselectAllTableRecords();
            })
            ->deselectRecordsAfterCompletion(false);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function isSourceOwned(?Product $record): bool
    {
        return $record !== null && filled($record->onec_guid);
    }

    private static function inventoryScopeLabel(Product $record): string
    {
        $record->loadMissing('variants.stocks');

        $variants = $record->variants->where('is_active', true);
        $locations = $variants
            ->flatMap(fn ($variant) => $variant->stocks)
            ->pluck('inventory_location_id')
            ->filter()
            ->unique()
            ->count();

        return $variants->count().' вар. · '.$locations.' локац.';
    }

    private static function buildMediaWorkspaceHtml(?Product $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString(
                '<div style="padding:20px;border:1px dashed #d1d5db;border-radius:10px;color:#6b7280;">'.
                'Медіа можна додати після створення товару.'.
                '</div>'
            );
        }

        $urls = app(ProductWorkspaceSummaryService::class)->mediaUrls($record);

        if ($urls === []) {
            return new HtmlString(
                '<div style="padding:24px;border:1px dashed #d1d5db;border-radius:10px;text-align:center;color:#6b7280;">'.
                '<strong style="display:block;color:#374151;margin-bottom:4px;">Медіа ще не додано</strong>'.
                '<span>У Workspace буде один логічний кадр без технічних копій для каналів.</span>'.
                '</div>'
            );
        }

        $main = e($urls[0]);
        $alt = e((string) $record->name);
        $thumbs = collect(array_slice($urls, 1, 5))
            ->map(function (string $url) use ($alt): string {
                $safe = e($url);

                return '<img src="'.$safe.'" alt="'.$alt.'" '.
                    'style="width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;background:#f9fafb;">';
            })
            ->implode('');

        $remaining = count($urls) - 6;
        $more = $remaining > 0
            ? '<div style="width:72px;height:72px;border-radius:8px;border:1px dashed #d1d5db;display:flex;align-items:center;justify-content:center;color:#6b7280;">+'.
                $remaining.'</div>'
            : '';

        return new HtmlString(
            '<div style="display:flex;gap:12px;align-items:flex-start;">'.
                '<img src="'.$main.'" alt="'.$alt.'" '.
                    'style="width:220px;height:220px;object-fit:contain;border-radius:10px;border:1px solid #e5e7eb;background:#f9fafb;">'.
                '<div style="display:flex;gap:8px;flex-wrap:wrap;align-content:flex-start;">'.$thumbs.$more.'</div>'.
            '</div>'.
            '<div style="margin-top:10px;font-size:12px;color:#6b7280;">'.count($urls).' медіа · поточний Master-набір</div>'
        );
    }

    private static function buildVariantWorkspaceHtml(?Product $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('—');
        }

        $summary = app(ProductWorkspaceSummaryService::class)->variants($record);
        $label = e($summary['label']);

        if ($summary['count'] <= 1) {
            $sku = $summary['skus'][0] ?? null;
            $skuHtml = $sku
                ? '<span style="margin-left:8px;color:#6b7280;">SKU '.e($sku).'</span>'
                : '<span style="margin-left:8px;color:#9ca3af;">SKU не задано</span>';

            return new HtmlString(
                '<div><strong>'.$label.'</strong>'.$skuHtml.'</div>'.
                '<div style="margin-top:4px;font-size:12px;color:#6b7280;">Варіанти не показуються, доки товар не має осей варіації.</div>'
            );
        }

        $chips = collect($summary['skus'])
            ->map(fn (string $sku): string => '<span style="display:inline-flex;padding:3px 7px;border-radius:999px;background:#f3f4f6;margin:3px 4px 0 0;font-size:12px;">'.e($sku).'</span>')
            ->implode('');

        return new HtmlString(
            '<div><strong>'.$label.'</strong></div>'.
            '<div style="margin-top:6px;">'.$chips.'</div>'
        );
    }

    private static function buildAttributeGroupsHtml(?Product $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('—');
        }

        $groups = app(ProductWorkspaceSummaryService::class)->attributeGroups($record);

        if ($groups === []) {
            return new HtmlString(
                '<div style="color:#6b7280;">Для поточного типу товару активні групи характеристик не визначені.</div>'
            );
        }

        $rows = collect($groups)
            ->map(function (array $group): string {
                $label = e($group['label']);

                return '<div style="display:flex;justify-content:space-between;gap:16px;padding:8px 0;border-bottom:1px solid #f3f4f6;">'.
                    '<span>'.$label.'</span>'.
                    '<span style="color:#6b7280;white-space:nowrap;">'.$group['total'].' полів · '.$group['required'].' обов’язкових</span>'.
                    '</div>';
            })
            ->implode('');

        return new HtmlString($rows);
    }

    private static function buildBasicQualityHtml(?Product $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('—');
        }

        $summary = app(ProductWorkspaceSummaryService::class)->basicCompleteness($record);
        $percentage = $summary['percentage'];

        return new HtmlString(
            '<div style="display:flex;align-items:baseline;gap:8px;">'.
                '<strong style="font-size:20px;">'.$percentage.'%</strong>'.
                '<span style="color:#6b7280;">'.$summary['filled'].'/'.$summary['total'].' базових сигналів</span>'.
            '</div>'.
            '<div style="height:6px;border-radius:999px;background:#e5e7eb;margin-top:8px;overflow:hidden;">'.
                '<div style="height:100%;width:'.$percentage.'%;background:currentColor;border-radius:999px;"></div>'.
            '</div>'.
            '<div style="margin-top:8px;font-size:11px;color:#6b7280;">Інформаційно · не є готовністю конкретного каналу.</div>'
        );
    }

    private static function buildChannelWorkspaceHtml(?Product $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('—');
        }

        $labels = app(ProductWorkspaceSummaryService::class)->channelLabels($record);

        if ($labels === []) {
            return new HtmlString(
                '<div style="color:#6b7280;">Товар ще не додано до жодного каналу публікації.</div>'
            );
        }

        $rows = collect($labels)
            ->map(function (string $label): string {
                return '<div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid #f3f4f6;">'.
                    '<span>'.e($label).'</span>'.
                    '<span style="font-size:11px;color:#6b7280;white-space:nowrap;">Додано</span>'.
                '</div>';
            })
            ->implode('');

        return new HtmlString($rows);
    }

    private static function buildAttentionHtml(?Product $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('—');
        }

        $missing = app(ProductWorkspaceSummaryService::class)->basicCompleteness($record)['missing'];

        if ($missing === []) {
            return new HtmlString(
                '<div style="color:#166534;">Базові дані заповнені.</div>'.
                '<div style="margin-top:4px;font-size:11px;color:#6b7280;">Канальні вимоги перевіряються окремо.</div>'
            );
        }

        $items = collect($missing)
            ->map(fn (string $label): string => '<li style="margin:4px 0;">'.e($label).'</li>')
            ->implode('');

        return new HtmlString(
            '<div style="margin-bottom:6px;color:#92400e;">'.count($missing).' базових пунктів</div>'.
            '<ul style="margin:0;padding-left:18px;color:#6b7280;">'.$items.'</ul>'
        );
    }

    /** SVG placeholder icon at a given pixel size. */
    public static function placeholderSvg(int $size = 48): string
    {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" fill="none" viewBox="0 0 24 24">
  <rect width="24" height="24" rx="3" fill="#f3f4f6"/>
  <path d="M5 19l4-5 3 3.5 4-5 4 6.5H5z" fill="#d1d5db"/>
  <circle cx="9" cy="9" r="2" fill="#d1d5db"/>
</svg>
SVG;
    }

    /**
     * HTML for the "no image" placeholder — styled to match other infolist/form fields.
     */
    public static function buildPhotoPlaceholderHtml(): string
    {
        return <<<'HTML'
<div style="display:inline-flex; align-items:center; justify-content:center; width:200px; height:200px;
            border-radius:8px; border:1px solid #e5e7eb; background:#f9fafb; color:#9ca3af;">
    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14
                 m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
    </svg>
</div>
HTML;
    }

    /** HTML for the photo preview Placeholder in the edit form (when image exists). */
    public static function buildPhotoPreviewHtml(Product $record): string
    {
        $url = self::firstImage($record);

        if (! $url) {
            return self::buildPhotoPlaceholderHtml();
        }

        $safe = e($url);
        $alt = e($record->name);

        return <<<HTML
<a href="{$safe}" target="_blank" rel="noopener" title="Відкрити у новій вкладці">
    <img src="{$safe}" alt="{$alt}"
         style="max-width:200px; max-height:200px; width:auto; height:auto; border-radius:8px;
                border:1px solid #e5e7eb; object-fit:contain; background:#f9fafb; display:block;"
         onerror="this.outerHTML='<div style=\'display:inline-flex;align-items:center;justify-content:center;
                  width:200px;height:200px;border-radius:8px;border:1px solid #e5e7eb;background:#f9fafb;color:#9ca3af;\'><svg xmlns=\'http://www.w3.org/2000/svg\' width=\'40\' height=\'40\' fill=\'none\' viewBox=\'0 0 24 24\' stroke=\'currentColor\' stroke-width=\'1\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z\'/></svg></div>'"
    />
</a>
HTML;
    }

    /**
     * HTML for the photo entry inside an infolist TextEntry (->html()).
     * Shows the image with a lightbox-style link; shows the styled placeholder when no image.
     */
    public static function buildPhotoInfolstHtml(Product $record): string
    {
        $url = self::firstImage($record);

        if (! $url) {
            return self::buildPhotoPlaceholderHtml();
        }

        $safe = e($url);
        $alt = e($record->name);

        return <<<HTML
<a href="{$safe}" target="_blank" rel="noopener noreferrer" title="Відкрити у новій вкладці">
    <img src="{$safe}" alt="{$alt}"
         style="max-width:200px; max-height:200px; width:auto; height:auto; border-radius:8px;
                border:1px solid #e5e7eb; object-fit:contain; background:#f9fafb; display:block;
                transition:opacity .15s;"
         onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'"
         onerror="this.closest('a').outerHTML='<div style=\'display:inline-flex;align-items:center;justify-content:center;
                  width:200px;height:200px;border-radius:8px;border:1px solid #e5e7eb;background:#f9fafb;color:#9ca3af;\'><svg xmlns=\'http://www.w3.org/2000/svg\' width=\'40\' height=\'40\' fill=\'none\' viewBox=\'0 0 24 24\' stroke=\'currentColor\' stroke-width=\'1\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z\'/></svg></div>'"
    />
</a>
<span style="display:block; margin-top:4px; font-size:11px; color:#9ca3af;">🔍 Відкрити фото</span>
HTML;
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        $actor = auth()->user();

        if ($actor instanceof User) {
            $allowed = app(WorkspaceAuthorization::class)->allows(
                $actor,
                app(WorkspaceContext::class)->current(),
                WorkspacePermissions::MANAGE_PRODUCTS,
            );

            return $allowed ? Response::allow() : Response::deny();
        }

        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }
}
