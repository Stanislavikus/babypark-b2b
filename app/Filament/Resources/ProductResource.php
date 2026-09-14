<?php

namespace App\Filament\Resources;

use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Enums\TagBulkOperation;
use App\Exceptions\Catalog\InvalidTagBulkSelectionException;
use App\Filament\Concerns\HasProductLightbox;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\Resources\ProductResource\Support\ProductStructureEditor;
use App\Filament\Resources\ProductResource\Support\TagBulkUi;
use App\Models\FieldBinding;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\TagManager;
use App\Services\Pricing\PricingSqlExpressions;
use App\Services\Pricing\ProductPricingSummary;
use App\Services\ProductStructure\ProductCompletenessService;
use App\Services\ProductStructure\ProductOptionalGroupBulkMutationService;
use App\Services\ProductStructure\ProductOptionalGroupMutationService;
use App\Services\ProductStructure\ProductTypeBulkMutationService;
use App\Services\ProductStructure\ProductTypeChangeImpactService;
use App\Services\ProductStructure\ProductTypeMutationService;
use App\Services\ProductStructure\ProductVariantBulkValueMutationService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\AdminAvailabilityPresenter;
use App\Support\ProductFields\AdminProductMargin;
use App\Support\ProductFields\MarginToggle;
use App\Support\ProductFields\ProductColumnVisibility;
use App\Support\ProductStructure\Exceptions\ProductTypeChangeStaleException;
use App\Support\ProductTableLink;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
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
            ->components([
                Section::make('Основне (з 1С)')->schema([
                    TextInput::make('sku')
                        ->label('Артикул')
                        ->disabled(),
                    TextInput::make('name')
                        ->label('Назва')
                        ->disabled(),
                    TextInput::make('brand')
                        ->label('Бренд')
                        ->disabled(),
                    TextInput::make('category.name')
                        ->label('Категорія')
                        ->disabled(),
                    Placeholder::make('cost_price_summary')
                        ->label('Вхідна ціна')
                        ->content(fn (?Product $record): string => $record
                            ? (app(ProductPricingSummary::class)->formatCostPrice($record) ?? '—')
                            : '—'),
                ])->columns(2),

                Section::make('Структура товару')->schema([
                    Placeholder::make('product_type_summary')
                        ->label('Тип товару')
                        ->content(fn (?Product $record): string => $record ? self::productTypeLabel($record) : '—'),
                    Placeholder::make('completeness_summary')
                        ->label('Повнота структури')
                        ->content(fn (?Product $record): string => $record ? self::completenessLabel($record) : '—'),
                ])->columns(2)->visible(fn (?Product $record): bool => $record !== null),

                Section::make('Класифікація')->schema([
                    TextInput::make('merchant_type')
                        ->label('Внутрішній тип товару')
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
                        ->searchable(),
                ])->columns(2),

                Section::make('Сайт')->schema([
                    // Left column: URL field
                    Group::make([
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
                            ),
                    ]),

                    // Right column: photo preview
                    Group::make([
                        Placeholder::make('photo_preview')
                            ->label('Фото товару')
                            ->content(fn (Get $get, ?Product $record) => new HtmlString(
                                $record
                                    ? self::buildPhotoPreviewHtml($record)
                                    : self::buildPhotoPlaceholderHtml()
                            )),
                    ]),
                ])->columns(2),
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

                Section::make('Структура товару')->schema([
                    TextEntry::make('product_type_summary')
                        ->label('Тип товару')
                        ->getStateUsing(fn (Product $record): string => self::productTypeLabel($record)),
                    TextEntry::make('completeness_summary')
                        ->label('Повнота структури')
                        ->getStateUsing(fn (Product $record): string => self::completenessLabel($record)),
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
                // --- Default visible columns (7 total) ---

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
            ])
            ->recordActions([
                self::makeEditStructureValuesAction(),
                self::makeBulkVariantValueAction(),
                self::makeChangeProductTypeAction(),
                self::makeOptionalGroupsAction(),
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
                    self::makeProductTypeBulkAction(),
                    self::makeOptionalGroupBulkAction(),
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
            ->with(['variants.stocks', 'category', 'tags'])
            ->withExists([
                'productType as has_optional_product_type_groups' => fn (Builder $query) => $query
                    ->whereHas('groupPlacements', fn (Builder $groups) => $groups->where('is_optional', true)),
            ]);
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    private static function productTypeLabel(Product $product): string
    {
        $type = ProductType::withoutWorkspaceScope()->find($product->product_type_id);

        return (string) ($type?->localized_labels['uk'] ?? $type?->localized_labels['en'] ?? $type?->code ?? '—');
    }

    private static function completenessLabel(Product $product): string
    {
        $projection = app(ProductCompletenessService::class)->project($product, 'uk');

        return sprintf('%d%% (%d/%d)', $projection->percentage, $projection->filledCount, $projection->requiredCount);
    }

    private static function canManageProductStructure(Product $product): bool
    {
        $workspace = app(WorkspaceContext::class)->current();

        return (string) $workspace->id === (string) $product->workspace_id
            && self::canManageCurrentWorkspaceStructure();
    }

    private static function canManageCurrentWorkspaceStructure(): bool
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return false;
        }
        $workspace = app(WorkspaceContext::class)->current();
        $cacheKey = sprintf('product-structure-permission:%s:%s', $actor->id, $workspace->id);
        $request = request();

        if ($request->attributes->has($cacheKey)) {
            return (bool) $request->attributes->get($cacheKey);
        }

        $allowed = app(WorkspaceAuthorization::class)->allows(
            $actor,
            $workspace,
            WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
        );
        $request->attributes->set($cacheKey, $allowed);

        return $allowed;
    }

    private static function canManageCurrentWorkspaceStructure(): bool
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return false;
        }
        $workspace = app(WorkspaceContext::class)->current();

        return app(WorkspaceAuthorization::class)->allows(
            $actor,
            $workspace,
            WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
        );
    }

    public static function makeEditStructureValuesAction(): Action
    {
        return Action::make('edit_structure_values')
            ->label('Редагувати поля типу')
            ->icon('heroicon-o-pencil-square')
            ->modalHeading(fn (Product $record): string => 'Поля типу — '.self::productTypeLabel($record))
            ->modalWidth('7xl')
            ->fillForm(fn (Product $record): array => app(ProductStructureEditor::class)->fill($record, 'uk'))
            ->schema(fn (?Product $record): array => $record instanceof Product
                ? app(ProductStructureEditor::class)->schema($record, 'uk')
                : [Placeholder::make('structure_editor_waiting')->content('Оберіть товар.')])
            ->action(function (array $data, Product $record): void {
                app(ProductStructureEditor::class)->save($record, $data, 'uk');
                Notification::make()->success()->title('Поля товару збережено')->send();
            });
    }

    public static function makeBulkVariantValueAction(): Action
    {
        return Action::make('bulk_variant_value')
            ->label('Одне значення для варіантів')
            ->icon('heroicon-o-table-cells')
            ->visible(fn (Product $record): bool => ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $record->workspace_id)
                ->where('product_id', $record->id)
                ->where('is_active', true)
                ->exists()
                && ProductTypeFieldPlacement::withoutWorkspaceScope()
                    ->where('workspace_id', $record->workspace_id)
                    ->where('product_type_id', $record->product_type_id)
                    ->whereHas('fieldBinding', fn (Builder $query) => $query
                        ->where('object_type', FieldObjectType::ProductVariant->value)
                        ->where('status', AttributeStatus::Active->value))
                    ->exists())
            ->schema([
                Select::make('field_binding_id')
                    ->label('Поле варіанта')
                    ->options(fn (?Product $record): array => ! $record instanceof Product ? [] : ProductTypeFieldPlacement::withoutWorkspaceScope()
                        ->with([
                            'fieldBinding' => fn ($query) => $query->withoutGlobalScopes(),
                            'fieldBinding.fieldDefinition' => fn ($query) => $query->withoutGlobalScopes(),
                        ])
                        ->where('workspace_id', $record->workspace_id)
                        ->where('product_type_id', $record->product_type_id)
                        ->orderBy('sort_order')
                        ->get()
                        ->filter(fn ($placement): bool => $placement->fieldBinding?->object_type === FieldObjectType::ProductVariant
                            && $placement->fieldBinding?->status === AttributeStatus::Active
                            && $placement->fieldBinding?->fieldDefinition?->status === AttributeStatus::Active)
                        ->mapWithKeys(fn ($placement): array => [
                            $placement->field_binding_id => $placement->fieldBinding->fieldDefinition->localizedLabel('uk'),
                        ])->all())
                    ->required()
                    ->searchable(),
                Select::make('variant_ids')
                    ->label('Варіанти')
                    ->multiple()
                    ->options(fn (?Product $record): array => ! $record instanceof Product ? [] : ProductVariant::withoutWorkspaceScope()
                        ->where('workspace_id', $record->workspace_id)
                        ->where('product_id', $record->id)
                        ->where('is_active', true)
                        ->orderBy('id')
                        ->get()
                        ->mapWithKeys(fn (ProductVariant $variant): array => [
                            $variant->id => $variant->sku ?: 'Variant #'.$variant->id,
                        ])->all())
                    ->required()
                    ->searchable(),
                Select::make('operation')
                    ->label('Операція')
                    ->options(['set' => 'Встановити', 'clear' => 'Очистити'])
                    ->default('set')
                    ->required()
                    ->live(),
                TextInput::make('value')
                    ->label('Значення / stable option code')
                    ->helperText('Для MultiSelect введіть stable codes через кому. Для Boolean: true/false, 1/0, так/ні.')
                    ->visible(fn (Get $get): bool => $get('operation') === 'set')
                    ->required(fn (Get $get): bool => $get('operation') === 'set'),
                TextInput::make('locale')
                    ->label('Locale для локалізованого поля')
                    ->default('uk')
                    ->maxLength(16),
            ])
            ->action(function (array $data, Product $record): void {
                $binding = FieldBinding::withoutWorkspaceScope()->with('fieldDefinition')->findOrFail((string) $data['field_binding_id']);
                $definition = $binding->fieldDefinition;
                abort_unless($definition !== null, 422);
                $service = app(ProductVariantBulkValueMutationService::class);
                $clear = ($data['operation'] ?? 'set') === 'clear';
                $value = $clear ? null : $service->coerceTextInput($definition, (string) ($data['value'] ?? ''));
                $result = $service->apply(
                    $record,
                    $binding,
                    array_map('intval', $data['variant_ids'] ?? []),
                    $value,
                    $clear,
                    filled($data['locale'] ?? null) ? (string) $data['locale'] : null,
                );
                $succeeded = count($result['succeeded_variant_ids']);
                $failed = count($result['failed']);

                Notification::make()
                    ->title('Масове значення варіантів застосовано')
                    ->body("Успішно: {$succeeded}. Помилки: {$failed}.")
                    ->color($failed > 0 ? 'warning' : 'success')
                    ->send();
            });
    }

    private static function makeChangeProductTypeAction(): Action
    {
        return Action::make('change_product_type')
            ->label('Змінити тип товару')
            ->icon('heroicon-o-squares-2x2')
            ->visible(fn (Product $record): bool => self::canManageProductStructure($record))
            ->schema([
                Select::make('product_type_id')
                    ->label('Новий тип товару')
                    ->options(fn (Product $record): array => ProductType::withoutWorkspaceScope()
                        ->where('workspace_id', $record->workspace_id)
                        ->where('status', 'active')
                        ->orderBy('is_default', 'desc')
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (ProductType $type): array => [
                            $type->id => (string) ($type->localized_labels['uk'] ?? $type->localized_labels['en'] ?? $type->code),
                        ])->all())
                    ->required()
                    ->searchable()
                    ->live(),
                Placeholder::make('impact_notice')
                    ->label('Попередній перегляд впливу')
                    ->content(function (Get $get, ?Product $record): string {
                        $targetId = $get('product_type_id');
                        if (! $record instanceof Product || ! filled($targetId)) {
                            return 'Оберіть новий тип товару, щоб побачити вплив до підтвердження.';
                        }

                        $target = ProductType::withoutWorkspaceScope()
                            ->where('workspace_id', $record->workspace_id)
                            ->find($targetId);
                        if (! $target instanceof ProductType) {
                            return 'Обраний тип товару недоступний.';
                        }

                        try {
                            $impact = app(ProductTypeChangeImpactService::class)->preview($record, $target);
                        } catch (\Throwable) {
                            return 'Не вдалося побудувати попередній перегляд. Зміну не слід підтверджувати.';
                        }

                        return sprintf(
                            'Буде прибрано зі структури: %d полів; нових обов’язкових: %d; збережених значень поза новим типом: %d товарних і %d варіантних; недійсних опційних станів: %d. Повнота: %d%% → %d%%. Значення не видаляються.',
                            count($impact->removedBindingIds),
                            count($impact->newlyRequiredBindingIds),
                            count($impact->outOfTypeProductBindingIds),
                            count($impact->outOfTypeVariantCells),
                            count($impact->invalidOptionalOverrideIds),
                            $impact->completenessBefore ?? 0,
                            $impact->completenessAfter ?? 0,
                        );
                    })
                    ->visible(fn (Get $get): bool => filled($get('product_type_id'))),
            ])
            ->action(function (array $data, Product $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $workspace = Workspace::withoutGlobalScopes()->findOrFail($record->workspace_id);
                $target = ProductType::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->findOrFail((string) $data['product_type_id']);
                $impact = app(ProductTypeChangeImpactService::class)->preview($record, $target);

                try {
                    app(ProductTypeMutationService::class)->change($actor, $workspace, $record, $target, $impact);
                    Notification::make()->success()->title('Тип товару змінено')->send();
                } catch (ProductTypeChangeStaleException $exception) {
                    Notification::make()->danger()->title('Структура вже змінилася')->body($exception->getMessage())->send();
                }
            });
    }

    private static function makeOptionalGroupsAction(): Action
    {
        return Action::make('optional_groups')
            ->label('Опційні групи')
            ->icon('heroicon-o-adjustments-horizontal')
            ->visible(fn (Product $record): bool => self::canManageProductStructure($record)
                && (bool) ($record->has_optional_product_type_groups ?? false))
            ->schema([
                Select::make('group_placement_id')
                    ->label('Опційна група')
                    ->options(fn (Product $record): array => ProductTypeGroupPlacement::withoutWorkspaceScope()
                        ->with(['attributeGroup' => fn ($query) => $query->withoutGlobalScopes()])
                        ->where('workspace_id', $record->workspace_id)
                        ->where('product_type_id', $record->product_type_id)
                        ->where('is_optional', true)
                        ->orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn (ProductTypeGroupPlacement $placement): array => [
                            $placement->id => (string) ($placement->attributeGroup?->localized_labels['uk']
                                ?? $placement->attributeGroup?->localized_labels['en']
                                ?? $placement->attributeGroup?->code
                                ?? $placement->id),
                        ])->all())
                    ->required(),
                Select::make('active')
                    ->label('Стан')
                    ->options([1 => 'Активна', 0 => 'Неактивна'])
                    ->required(),
            ])
            ->action(function (array $data, Product $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $workspace = Workspace::withoutGlobalScopes()->findOrFail($record->workspace_id);
                $placement = ProductTypeGroupPlacement::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->where('product_type_id', $record->product_type_id)
                    ->findOrFail((string) $data['group_placement_id']);
                app(ProductOptionalGroupMutationService::class)->setActive(
                    $actor,
                    $workspace,
                    $record,
                    $placement,
                    (bool) $data['active'],
                );
                Notification::make()->success()->title('Стан опційної групи змінено')->send();
            });
    }

    // -------------------------------------------------------------------------
    // Bulk tag actions
    // -------------------------------------------------------------------------

    private static function makeProductTypeBulkAction(): BulkAction
    {
        return BulkAction::make('assign_product_type')
            ->label('Призначити тип товару')
            ->icon('heroicon-o-squares-2x2')
            ->visible(fn (): bool => self::canManageCurrentWorkspaceStructure())
            ->schema([
                Select::make('target_product_type_id')
                    ->label('Новий тип товару')
                    ->options(fn (): array => ProductType::withoutWorkspaceScope()
                        ->where('workspace_id', app(WorkspaceContext::class)->id())
                        ->where('status', 'active')
                        ->orderBy('is_default', 'desc')
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (ProductType $type): array => [
                            $type->id => (string) ($type->localized_labels['uk'] ?? $type->localized_labels['en'] ?? $type->code),
                        ])->all())
                    ->required()
                    ->searchable()
                    ->live(),
                Placeholder::make('product_type_bulk_preview')
                    ->label('Попередній перегляд впливу')
                    ->content(function (Get $get, ListProducts $livewire): string {
                        $targetId = $get('target_product_type_id');
                        $productIds = array_map('intval', $livewire->getSelectedTableRecords()->modelKeys());
                        if (! filled($targetId) || $productIds === []) {
                            return 'Оберіть товари та цільовий тип.';
                        }

                        $workspaceId = app(WorkspaceContext::class)->id();
                        $target = ProductType::withoutWorkspaceScope()
                            ->where('workspace_id', $workspaceId)
                            ->find($targetId);
                        if (! $target instanceof ProductType) {
                            return 'Цільовий тип недоступний.';
                        }

                        $reviewed = 0;
                        $failed = 0;
                        $outOfTypeProduct = 0;
                        $outOfTypeVariant = 0;
                        $newlyRequired = 0;
                        $before = 0;
                        $after = 0;

                        foreach ($productIds as $productId) {
                            try {
                                $product = Product::withoutWorkspaceScope()
                                    ->where('workspace_id', $workspaceId)
                                    ->findOrFail($productId);
                                $impact = app(ProductTypeChangeImpactService::class)->preview($product, $target);
                                $reviewed++;
                                $outOfTypeProduct += count($impact->outOfTypeProductBindingIds);
                                $outOfTypeVariant += count($impact->outOfTypeVariantCells);
                                $newlyRequired += count($impact->newlyRequiredBindingIds);
                                $before += $impact->completenessBefore ?? 0;
                                $after += $impact->completenessAfter ?? 0;
                            } catch (\Throwable) {
                                $failed++;
                            }
                        }

                        $beforeAverage = $reviewed > 0 ? (int) round($before / $reviewed) : 0;
                        $afterAverage = $reviewed > 0 ? (int) round($after / $reviewed) : 0;

                        return "Перевірено: {$reviewed}; помилки preview: {$failed}. "
                            ."Out-of-type Product values: {$outOfTypeProduct}; Variant cells: {$outOfTypeVariant}. "
                            ."Нових required: {$newlyRequired}. Середня повнота: {$beforeAverage}% → {$afterAverage}%. "
                            .'Значення не видаляються.';
                    })
                    ->visible(fn (Get $get): bool => filled($get('target_product_type_id'))),
            ])
            ->action(function (Collection $records, array $data, ListProducts $livewire): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $workspace = app(WorkspaceContext::class)->current();
                $target = ProductType::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->findOrFail((string) $data['target_product_type_id']);
                $productIds = array_map('intval', $livewire->getSelectedTableRecords()->modelKeys());
                $impacts = [];
                $previewFailures = 0;

                foreach ($productIds as $productId) {
                    try {
                        $product = Product::withoutWorkspaceScope()
                            ->where('workspace_id', $workspace->id)
                            ->findOrFail($productId);
                        $impacts[] = app(ProductTypeChangeImpactService::class)->preview($product, $target);
                    } catch (\Throwable) {
                        $previewFailures++;
                    }
                }

                $result = app(ProductTypeBulkMutationService::class)->changeMany(
                    $actor,
                    $workspace,
                    $target,
                    $impacts,
                );
                $succeeded = count($result['succeeded_product_ids']);
                $failed = $previewFailures + count($result['failed']);

                Notification::make()
                    ->title('Масове призначення типу завершено')
                    ->body("Успішно: {$succeeded}. Помилки: {$failed}.")
                    ->color($failed > 0 ? 'warning' : 'success')
                    ->send();
                $livewire->deselectAllTableRecords();
            })
            ->deselectRecordsAfterCompletion(false);
    }

    private static function makeOptionalGroupBulkAction(): BulkAction
    {
        return BulkAction::make('set_optional_group_state')
            ->label('Змінити опційну групу')
            ->icon('heroicon-o-adjustments-horizontal')
            ->visible(fn (): bool => self::canManageCurrentWorkspaceStructure())
            ->schema([
                Select::make('attribute_group_id')
                    ->label('Опційна група')
                    ->options(fn (): array => ProductTypeGroupPlacement::withoutWorkspaceScope()
                        ->with(['attributeGroup' => fn ($query) => $query->withoutGlobalScopes()])
                        ->where('workspace_id', app(WorkspaceContext::class)->id())
                        ->where('is_optional', true)
                        ->orderBy('sort_order')
                        ->get()
                        ->unique('attribute_group_id')
                        ->mapWithKeys(fn (ProductTypeGroupPlacement $placement): array => [
                            $placement->attribute_group_id => (string) ($placement->attributeGroup?->localized_labels['uk']
                                ?? $placement->attributeGroup?->localized_labels['en']
                                ?? $placement->attributeGroup?->code
                                ?? $placement->attribute_group_id),
                        ])->all())
                    ->required()
                    ->searchable()
                    ->live(),
                Select::make('active')
                    ->label('Стан')
                    ->options([1 => 'Активувати', 0 => 'Деактивувати'])
                    ->required(),
                Placeholder::make('optional_group_bulk_preview')
                    ->label('Попередній перегляд')
                    ->content(function (Get $get, ListProducts $livewire): string {
                        $groupId = $get('attribute_group_id');
                        $productIds = array_map('intval', $livewire->getSelectedTableRecords()->modelKeys());
                        if (! filled($groupId) || $productIds === []) {
                            return 'Оберіть товари та опційну групу.';
                        }

                        $workspaceId = app(WorkspaceContext::class)->id();
                        $eligible = 0;
                        foreach ($productIds as $productId) {
                            $product = Product::withoutWorkspaceScope()
                                ->where('workspace_id', $workspaceId)
                                ->find($productId);
                            if (! $product instanceof Product) {
                                continue;
                            }
                            if (ProductTypeGroupPlacement::withoutWorkspaceScope()
                                ->where('workspace_id', $workspaceId)
                                ->where('product_type_id', $product->product_type_id)
                                ->where('attribute_group_id', $groupId)
                                ->where('is_optional', true)
                                ->exists()) {
                                $eligible++;
                            }
                        }
                        $ineligible = count($productIds) - $eligible;

                        return "Буде змінено: {$eligible}. Не допускають цю групу: {$ineligible}; вони повернуться як partial failures.";
                    })
                    ->visible(fn (Get $get): bool => filled($get('attribute_group_id'))),
            ])
            ->action(function (Collection $records, array $data, ListProducts $livewire): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $workspace = app(WorkspaceContext::class)->current();
                $productIds = array_map('intval', $livewire->getSelectedTableRecords()->modelKeys());
                $result = app(ProductOptionalGroupBulkMutationService::class)->setActiveMany(
                    $actor,
                    $workspace,
                    $productIds,
                    (string) $data['attribute_group_id'],
                    (bool) $data['active'],
                );
                $succeeded = count($result['succeeded_product_ids']);
                $failed = count($result['failed']);

                Notification::make()
                    ->title('Масову зміну опційної групи завершено')
                    ->body("Успішно: {$succeeded}. Помилки: {$failed}.")
                    ->color($failed > 0 ? 'warning' : 'success')
                    ->send();
                $livewire->deselectAllTableRecords();
            })
            ->deselectRecordsAfterCompletion(false);
    }

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
        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }
}
