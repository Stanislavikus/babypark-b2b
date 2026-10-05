<?php

namespace App\Filament\Resources;

use App\Enums\MediaRole;
use App\Enums\ProductLifecycleStatus;
use App\Enums\TagBulkOperation;
use App\Exceptions\Availability\InventoryMutationException;
use App\Exceptions\Catalog\InvalidTagBulkSelectionException;
use App\Exceptions\Pricing\MasterOfferMutationException;
use App\Filament\Concerns\HasProductLightbox;
use App\Filament\Pages\Sync\ManageAdobeProductsChannel;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\Resources\ProductResource\Support\TagBulkUi;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfigurationProductSelection;
use App\Models\Tag;
use App\Models\User;
use App\Models\VariantMedia;
use App\Models\Workspace;
use App\Services\Availability\MasterInventoryMutationService;
use App\Services\Availability\MasterInventoryReadService;
use App\Services\Catalog\ProductCategoryTreeOptions;
use App\Services\Catalog\ProductMediaReadService;
use App\Services\Catalog\ProductWorkspaceSummaryService;
use App\Services\Catalog\TagManager;
use App\Services\Pricing\MasterOfferMutationService;
use App\Services\Pricing\MasterOfferReadService;
use App\Services\Pricing\PricingSqlExpressions;
use App\Services\Pricing\ProductPricingSummary;
use App\Services\Sync\ProductChannelReadinessReadService;
use App\Services\Sync\ProductMagentoClassificationEditor;
use App\Services\Sync\ProductChannelSelectionService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\AdminAvailabilityPresenter;
use App\Support\ProductFields\AdminProductMargin;
use App\Support\ProductFields\MarginToggle;
use App\Support\ProductFields\ProductColumnVisibility;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Sync\Exceptions\AdobeProductClassificationException;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
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
                            : 'Master-дані товару. Для чернетки достатньо заповнити лише «Назва».')
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
                            SchemaActions::make([
                                self::pendingCapabilityAction(
                                    'create_with_ai',
                                    'Створити з AI',
                                    'AI підготує керовані пропозиції для Master-даних. Фактичні значення не будуть записані без звичайного підтвердження.',
                                    'heroicon-o-sparkles',
                                )->color('primary'),
                                self::pendingCapabilityAction(
                                    'fill_from_supplier_document',
                                    'Заповнити з файлу',
                                    'Заповнення даних цього товару із PDF, документа або зображення постачальника буде доступне разом із AI-помічником.',
                                    'heroicon-o-document-arrow-up',
                                ),
                            ])->key('basic_capability_actions')->columnSpanFull(),
                        ])
                        ->columns(2),

                    self::draftLockedSection(
                        'draft_media_locked',
                        'Медіа',
                        'Тут зберігаються вихідні зображення товару.'
                    ),

                    Section::make('Медіа')
                        ->description('Тут зберігаються вихідні зображення товару. Версії, підготовлені для окремих каналів, не дублюються в галереї.')
                        ->schema([
                            Placeholder::make('workspace_media')
                                ->hiddenLabel()
                                ->content(fn (?Product $record): HtmlString => self::buildMediaWorkspaceHtml($record)),
                            SchemaActions::make([
                                self::pendingCapabilityAction(
                                    'media_enhance',
                                    'Покращити',
                                    'Покращення буде доступне для слабких вихідних зображень або коли потрібна свідома творча обробка.',
                                    'heroicon-o-sparkles',
                                ),
                                self::pendingCapabilityAction(
                                    'media_remove_background',
                                    'Видалити фон',
                                    'Автоматичне видалення або заміна фону буде підключено окремо.',
                                    'heroicon-o-photo',
                                ),
                                self::pendingCapabilityAction(
                                    'media_prepare_channel',
                                    'Підготувати для каналу',
                                    'Підготовка формату, розміру, фону та супровідних даних під вимоги конкретного каналу буде підключена окремо.',
                                    'heroicon-o-paper-airplane',
                                ),
                            ])->key('media_pending_actions'),
                        ])
                        ->visible(fn (?Product $record): bool => $record !== null),

                    self::draftLockedSection('draft_price_locked', 'Ціна'),

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
                                ->label('Собівартість')
                                ->content(fn (?Product $record): string => $record
                                    ? (app(ProductPricingSummary::class)->formatCostPrice($record) ?? '—')
                                    : '—')
                                ->visible(fn (?Product $record): bool => self::canManageProductCost($record)),
                            SchemaActions::make([
                                self::offerEditorAction(),
                            ])->key('offer_actions')->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->visible(fn (?Product $record): bool => $record !== null),

                    self::draftLockedSection('draft_inventory_locked', 'Залишки'),

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
                            SchemaActions::make([
                                self::inventoryEditorAction(),
                            ])->key('inventory_actions')->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->visible(fn (?Product $record): bool => $record !== null),

                    self::draftLockedSection(
                        'draft_shipping_locked',
                        'Доставка та фізичні дані',
                        'Вага, габарити та упаковка.'
                    ),

                    Section::make('Доставка та фізичні дані')
                        ->description('Вага, габарити та упаковка. Варіантні правила доставки й backorder залишаються у Характеристиках.')
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
                                ->integer()
                                ->minValue(0)
                                ->suffix('мм')
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('height_mm')
                                ->label('Висота')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->suffix('мм')
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('depth_mm')
                                ->label('Глибина')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->suffix('мм')
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('volume_m3')
                                ->label('Об’єм')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('м³')
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('package_quantity')
                                ->label('Кількість в упаковці')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('package_type')
                                ->label('Тип упаковки')
                                ->maxLength(255)
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('units_per_box')
                                ->label('Одиниць у коробці')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('boxes_per_pallet')
                                ->label('Коробок на палеті')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            TextInput::make('lead_time_days')
                                ->label('Термін поставки')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->suffix('днів')
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                        ])
                        ->columns(3)
                        ->collapsible()
                        ->collapsed()
                        ->visible(fn (?Product $record): bool => $record !== null),

                    self::draftLockedSection('draft_variants_locked', 'Варіанти'),

                    Section::make('Варіанти')
                        ->schema([
                            Placeholder::make('workspace_variants')
                                ->hiddenLabel()
                                ->content(fn (?Product $record): HtmlString => self::buildVariantWorkspaceHtml($record)),
                        ])
                        ->visible(fn (?Product $record): bool => $record !== null),

                    self::draftLockedSection(
                        'draft_characteristics_locked',
                        'Характеристики',
                        'Групи визначаються сімейством товару.'
                    ),

                    Section::make('Характеристики')
                        ->description('Групи визначаються типом товару. Обов’язкові поля показуються першими; поля каналу сюди не дублюються.')
                        ->schema([
                            Placeholder::make('workspace_attributes')
                                ->hiddenLabel()
                                ->content(fn (?Product $record): HtmlString => self::buildAttributeGroupsHtml($record)),
                            SchemaActions::make([
                                self::pendingCapabilityAction(
                                    'enrich_characteristics_from_file',
                                    'Заповнити характеристики з файлу',
                                    'Автоматичне перенесення характеристик із PDF, документа або зображення постачальника буде підключено разом із AI-помічником.',
                                    'heroicon-o-document-text',
                                ),
                            ])->key('characteristics_pending_actions'),
                        ])
                        ->visible(fn (?Product $record): bool => $record !== null),

                    self::draftLockedSection('draft_seo_locked', 'SEO та пошук'),

                    Section::make('SEO та пошук')
                        ->description('Розділ показано для візуальної обкатки. Пошук ключових слів, AI-опис і аналіз пошуку підключимо на фінальному етапі.')
                        ->schema([
                            Placeholder::make('seo_connection_state')
                                ->hiddenLabel()
                                ->content(new HtmlString(
                                    '<div style="padding:10px 12px;border:1px solid #fde68a;background:#fffbeb;border-radius:8px;color:#92400e;">'.
                                    '<strong>Чекає на підключення</strong>'.
                                    '<div style="margin-top:3px;font-size:12px;">Поточні значення показані лише для орієнтації та не редагуються в цій кампанії.</div>'.
                                    '</div>'
                                )),
                            TextInput::make('meta_title')
                                ->label('SEO title')
                                ->maxLength(255)
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpanFull(),
                            TextInput::make('meta_description')
                                ->label('Meta description')
                                ->maxLength(500)
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpanFull(),
                            SchemaActions::make([
                                self::pendingCapabilityAction(
                                    'seo_keywords',
                                    'Отримати ключові слова',
                                    'Пошук і підбір ключових слів буде доступний після підключення фінального SEO-модуля.',
                                    'heroicon-o-magnifying-glass',
                                ),
                                self::pendingCapabilityAction(
                                    'seo_ai_description',
                                    'Створити опис з AI',
                                    'AI зможе підготувати опис для перевірки перед збереженням після підключення фінального модуля.',
                                    'heroicon-o-sparkles',
                                ),
                                self::pendingCapabilityAction(
                                    'seo_performance',
                                    'Аналіз пошуку',
                                    'Аналіз даних Google Search Console, Merchant Center і маркетплейсів буде підключено окремим фінальним етапом.',
                                    'heroicon-o-chart-bar',
                                ),
                            ])->key('seo_pending_actions'),
                        ])
                        ->collapsible()
                        ->visible(fn (?Product $record): bool => $record !== null),
                ])->columnSpan(2),

                Group::make([
                    Section::make('Статус')
                        ->schema([
                            Placeholder::make('workspace_lifecycle')
                                ->label('Стан у Master')
                                ->content(fn (?Product $record): string => $record
                                    ? (($record->lifecycle_status ?? ($record->is_active ? ProductLifecycleStatus::Active : ProductLifecycleStatus::Archived))->label())
                                    : 'Нова чернетка'),
                            Placeholder::make('workspace_publication_boundary')
                                ->label('Публікація')
                                ->content('Окремо для кожного каналу'),
                            Placeholder::make('workspace_source')
                                ->label('Джерело даних')
                                ->content(fn (?Product $record): string => self::isSourceOwned($record)
                                    ? '1С · авторитетне джерело'
                                    : 'Master Workspace'),
                        ])
                        ->columns(1),

                    Section::make('Організація')
                        ->schema([
                            Select::make('category_id')
                                ->label('Категорія')
                                ->relationship(name: 'category', titleAttribute: 'name')
                                ->getOptionLabelFromRecordUsing(
                                    fn (Category $record): string => app(ProductCategoryTreeOptions::class)->label($record)
                                )
                                ->helperText('Показано повний шлях: батьківська категорія › підкатегорія.')
                                ->searchable()
                                ->preload(),
                            TextInput::make('brand')
                                ->label('Бренд')
                                ->maxLength(255)
                                ->disabled(fn (?Product $record): bool => self::isSourceOwned($record)),
                            Placeholder::make('workspace_product_type')
                                ->label('Сімейство товару')
                                ->content(fn (?Product $record): string => $record
                                    ? app(ProductWorkspaceSummaryService::class)->productTypeLabel($record)
                                    : 'Базовий товар буде призначено автоматично'),
                            TextInput::make('merchant_type')
                                ->label('Внутрішня класифікація')
                                ->helperText('Вільна внутрішня мітка. Не визначає характеристики, варіанти або сімейство товару.')
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

                    self::draftLockedSection('draft_quality_locked', 'Якість даних'),

                    Section::make('Якість даних')
                        ->schema([
                            Placeholder::make('workspace_quality')
                                ->hiddenLabel()
                                ->content(fn (?Product $record): HtmlString => self::buildBasicQualityHtml($record)),
                        ])
                        ->visible(fn (?Product $record): bool => $record !== null),

                    self::draftLockedSection('draft_channels_locked', 'Канали публікації'),

                    Section::make('Канали публікації')
                        ->schema([
                            Placeholder::make('workspace_channels')
                                ->hiddenLabel()
                                ->content(fn (?Product $record): HtmlString => self::buildChannelWorkspaceHtml($record)),
                            SchemaActions::make([
                                self::magentoClassificationAction(),
                                Action::make('open_magento_v1')
                                    ->label('Відкрити Magento V1')
                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                    ->url(fn (?Product $record): ?string => self::magentoChannelUrl($record))
                                    ->openUrlInNewTab()
                                    ->visible(fn (?Product $record): bool => self::magentoChannelUrl($record) !== null),
                                self::pendingCapabilityAction(
                                    'product_associations',
                                    'Related / Upsell / Cross-sell',
                                    'Редагування пов’язаних, рекомендованих і супутніх товарів для Magento буде підключено окремо.',
                                    'heroicon-o-link',
                                ),
                            ])->key('channel_capability_actions'),
                        ])
                        ->visible(fn (?Product $record): bool => $record !== null),

                    self::draftLockedSection('draft_attention_locked', 'Потребує уваги'),

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
                        ->label('Собівартість')
                        ->getStateUsing(fn (Product $record): ?string => app(ProductPricingSummary::class)->formatCostPrice($record))
                        ->placeholder('—')
                        ->visible(fn (): bool => self::canManageCurrentWorkspaceProductCost()),
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
                        ->placeholder('—')
                        ->visible(fn (): bool => self::canManageCurrentWorkspaceProductCost()),
                    TextEntry::make('admin_status')
                        ->label('Статус')
                        ->getStateUsing(fn (Product $record): string => self::productLifecycleLabel($record))
                        ->badge()
                        ->color(fn (string $state): string => self::productLifecycleColor($state)),
                ])->columns(2),

                Section::make('Класифікація')->schema([
                    TextEntry::make('merchant_type')
                        ->label('Внутрішня класифікація')
                        ->placeholder('—'),
                    TextEntry::make('tags.name')
                        ->label('Теги')
                        ->badge()
                        ->placeholder('—'),
                ])->columns(2),

                Section::make('Медіа')->schema([
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
                TextColumn::make('lifecycle_status')
                    ->label('Статус')
                    ->getStateUsing(fn (Product $record): string => self::productLifecycleLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => self::productLifecycleColor($state))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('lifecycle_status', $direction)->orderBy('id', $direction);
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
                    ->label('Собівартість')
                    ->getStateUsing(fn (Product $record): ?string => app(ProductPricingSummary::class)->formatCostPrice($record))
                    ->placeholder('—')
                    ->visible(fn (): bool => self::canManageCurrentWorkspaceProductCost())
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
                    ->visible(fn (): bool => self::canManageCurrentWorkspaceProductCost())
                    ->toggleable(in_array('margin', $toggleable), isToggledHiddenByDefault: true)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            PricingSqlExpressions::adminMarginSortSql('products.id')." {$direction}"
                        );
                    }),

                TextColumn::make('merchant_type')
                    ->label('Внутрішня класифікація')
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
                        'working' => 'Чернетки й активні',
                        ProductLifecycleStatus::Draft->value => 'Тільки чернетки',
                        ProductLifecycleStatus::Active->value => 'Тільки активні',
                        ProductLifecycleStatus::Archived->value => 'Тільки архівні',
                    ])
                    ->default('working')
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'working' => $query->whereIn('lifecycle_status', [
                                ProductLifecycleStatus::Draft->value,
                                ProductLifecycleStatus::Active->value,
                            ]),
                            ProductLifecycleStatus::Draft->value => $query->where('lifecycle_status', ProductLifecycleStatus::Draft->value),
                            ProductLifecycleStatus::Active->value => $query->where('lifecycle_status', ProductLifecycleStatus::Active->value),
                            ProductLifecycleStatus::Archived->value => $query->where('lifecycle_status', ProductLifecycleStatus::Archived->value),
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
                    ->label('Внутрішня класифікація')
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
            ->visible(fn (): bool => self::canManageCurrentWorkspaceProducts())
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
                abort_unless(self::canManageCurrentWorkspaceProducts(), 403);

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

    private static function offerEditorAction(): Action
    {
        return Action::make('edit_offer')
            ->label('Редагувати ціни')
            ->icon('heroicon-o-banknotes')
            ->disabled(fn (?Product $record): bool => self::isSourceOwned($record))
            ->tooltip(fn (?Product $record): ?string => self::isSourceOwned($record)
                ? 'Для товару з джерелом 1С ціна в Master поки доступна лише для перегляду.'
                : null)
            ->modalHeading('Ціна товару')
            ->modalDescription('Редагуйте поточну ціну та, за потреби, ціну до знижки. У Master ці значення зберігаються без ПДВ; сума з ПДВ розраховується автоматично.')
            ->modalSubmitActionLabel('Зберегти ціну')
            ->fillForm(fn (?Product $record): array => self::offerEditorFormState($record))
            ->schema([
                Select::make('variant_id')
                    ->label('Варіант')
                    ->options(fn (?Product $record): array => self::offerVariantOptions($record))
                    ->required()
                    ->live()
                    ->hidden(fn (?Product $record): bool => count(self::offerVariantOptions($record)) <= 1)
                    ->afterStateUpdated(function (mixed $state, Set $set, ?Product $record): void {
                        $offer = self::offerVariantState($record, $state);
                        $set('expected_item_id', $offer['expected_item_id']);
                        $set('expected_regular_net', $offer['expected_regular_net']);
                        $canManageCost = self::canManageProductCost($record);

                        $set('expected_sale_net', $offer['expected_sale_net']);
                        $set('expected_cost_net', $canManageCost ? $offer['expected_cost_net'] : null);
                        $set('sell_net', $offer['sell_net']);
                        $set('compare_at_net', $offer['compare_at_net']);
                        $set('cost_net', $canManageCost ? $offer['cost_net'] : null);
                        $set('write_cost', $canManageCost);
                        $set('effective_vat_rate', $offer['effective_vat_rate']);
                    }),
                Hidden::make('expected_item_id'),
                Hidden::make('expected_regular_net'),
                Hidden::make('expected_sale_net'),
                Hidden::make('expected_cost_net'),
                Hidden::make('write_cost'),
                Hidden::make('effective_vat_rate'),
                Placeholder::make('offer_editor_state')
                    ->hiddenLabel()
                    ->content(function (Get $get, ?Product $record): string {
                        $offer = self::offerVariantState($record, $get('variant_id'));

                        return $offer['message'];
                    }),
                TextInput::make('sell_net')
                    ->label('Ціна')
                    ->numeric()
                    ->gt(0)
                    ->required()
                    ->prefix(fn (Get $get, ?Product $record): string => self::offerVariantState(
                        $record,
                        $get('variant_id'),
                    )['currency'])
                    ->helperText('Поточна ціна продажу без ПДВ.')
                    ->live(onBlur: true)
                    ->disabled(fn (Get $get, ?Product $record): bool => ! self::offerVariantState(
                        $record,
                        $get('variant_id'),
                    )['editable']),
                TextInput::make('compare_at_net')
                    ->label('Ціна до знижки')
                    ->numeric()
                    ->nullable()
                    ->gt('sell_net')
                    ->prefix(fn (Get $get, ?Product $record): string => self::offerVariantState(
                        $record,
                        $get('variant_id'),
                    )['currency'])
                    ->helperText('Необов’язково. Для акції має бути вищою за поточну ціну.')
                    ->live(onBlur: true)
                    ->disabled(fn (Get $get, ?Product $record): bool => ! self::offerVariantState(
                        $record,
                        $get('variant_id'),
                    )['editable']),
                TextInput::make('cost_net')
                    ->label('Собівартість')
                    ->numeric()
                    ->minValue(0)
                    ->nullable()
                    ->prefix(fn (Get $get, ?Product $record): string => self::offerVariantState(
                        $record,
                        $get('variant_id'),
                    )['currency'])
                    ->helperText('Внутрішня собівартість без ПДВ. Доступна лише ролям з окремим дозволом.')
                    ->visible(fn (?Product $record): bool => self::canManageProductCost($record))
                    ->disabled(fn (Get $get, ?Product $record): bool => ! self::offerVariantState(
                        $record,
                        $get('variant_id'),
                    )['editable']),
                Placeholder::make('offer_gross_preview')
                    ->label('З ПДВ')
                    ->content(function (Get $get): string {
                        $sell = $get('sell_net');
                        $compareAt = $get('compare_at_net');
                        $vat = $get('effective_vat_rate');

                        if (! is_numeric($sell) || ! is_numeric($vat)) {
                            return '—';
                        }

                        $sellGross = round((float) $sell * (1 + (float) $vat / 100), 2);
                        $label = number_format($sellGross, 2, '.', ' ');

                        if (is_numeric($compareAt) && (float) $compareAt > (float) $sell) {
                            $compareGross = round((float) $compareAt * (1 + (float) $vat / 100), 2);
                            $label .= ' · до знижки '.number_format($compareGross, 2, '.', ' ');
                        }

                        return $label.' · ПДВ '.number_format((float) $vat, 2, '.', '').'%';
                    }),
            ])
            ->action(function (array $data, ?Product $record): void {
                if (! $record instanceof Product) {
                    throw new Halt;
                }

                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $variantId = self::offerResolvedVariantId($record, $data['variant_id'] ?? null);
                $variant = ProductVariant::withoutWorkspaceScope()
                    ->where('workspace_id', $record->workspace_id)
                    ->where('product_id', $record->id)
                    ->where('is_active', true)
                    ->whereKey($variantId)
                    ->first();

                if (! $variant instanceof ProductVariant) {
                    Notification::make()
                        ->danger()
                        ->title('Варіант уже недоступний')
                        ->body('Оновіть товар і повторіть дію.')
                        ->send();

                    throw new Halt;
                }

                $offer = app(MasterOfferReadService::class)->state($variant);
                if (! $offer['editable']) {
                    Notification::make()
                        ->warning()
                        ->title('Ціна лише для перегляду')
                        ->body($offer['message'])
                        ->send();

                    throw new Halt;
                }

                $workspace = Workspace::query()->findOrFail($record->workspace_id);

                try {
                    $item = app(MasterOfferMutationService::class)->setPrice(
                        $actor,
                        $workspace,
                        $record,
                        $variant,
                        expectedItemId: $data['expected_item_id'] ?? null,
                        expectedRegularNet: $data['expected_regular_net'] ?? null,
                        expectedSaleNet: $data['expected_sale_net'] ?? null,
                        sellNet: $data['sell_net'],
                        compareAtNet: $data['compare_at_net'] ?? null,
                        writeCost: (bool) ($data['write_cost'] ?? false),
                        expectedCostNet: $data['expected_cost_net'] ?? null,
                        costNet: $data['cost_net'] ?? null,
                    );
                } catch (MasterOfferMutationException $exception) {
                    Notification::make()
                        ->warning()
                        ->title('Ціну не змінено')
                        ->body($exception->getMessage())
                        ->send();

                    throw new Halt;
                } catch (AuthorizationException) {
                    Notification::make()
                        ->warning()
                        ->title('Ціну не змінено')
                        ->body('Ваші права на редагування ціни або собівартості змінилися. Оновіть сторінку.')
                        ->send();

                    throw new Halt;
                }

                $record->refresh();

                $currentSell = $item->sale_price ?? $item->price;
                Notification::make()
                    ->success()
                    ->title('Ціну оновлено')
                    ->body('Поточна ціна: '.number_format((float) $currentSell, 2, '.', ' ').' '.$item->priceList?->currency)
                    ->send();
            });
    }

    private static function offerResolvedVariantId(Product $record, mixed $requestedVariantId): int
    {
        $options = self::offerVariantOptions($record);

        if (count($options) === 1) {
            return (int) array_key_first($options);
        }

        return is_numeric($requestedVariantId) ? (int) $requestedVariantId : 0;
    }

    /**
     * @return array{
     *   variant_id:?string,
     *   expected_item_id:?string,
     *   expected_regular_net:?string,
     *   expected_sale_net:?string,
     *   sell_net:?string,
     *   compare_at_net:?string,
     *   expected_cost_net:?string,
     *   cost_net:?string,
     *   write_cost:bool,
     *   effective_vat_rate:?string
     * }
     */
    private static function offerEditorFormState(?Product $record): array
    {
        $options = self::offerVariantOptions($record);
        $variantId = array_key_first($options);
        $offer = self::offerVariantState($record, $variantId);

        $canManageCost = self::canManageProductCost($record);

        return [
            'variant_id' => $variantId !== null ? (string) $variantId : null,
            'expected_item_id' => $offer['expected_item_id'],
            'expected_regular_net' => $offer['expected_regular_net'],
            'expected_sale_net' => $offer['expected_sale_net'],
            'expected_cost_net' => $canManageCost ? $offer['expected_cost_net'] : null,
            'sell_net' => $offer['sell_net'],
            'compare_at_net' => $offer['compare_at_net'],
            'cost_net' => $canManageCost ? $offer['cost_net'] : null,
            'write_cost' => $canManageCost,
            'effective_vat_rate' => $offer['effective_vat_rate'],
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function offerVariantOptions(?Product $record): array
    {
        if (! $record instanceof Product) {
            return [];
        }

        $summary = app(ProductWorkspaceSummaryService::class)->variants($record);
        $options = [];

        foreach ($summary['rows'] as $index => $row) {
            $parts = array_values(array_filter(
                $row['options'],
                static fn (string $value): bool => $value !== '' && $value !== '—',
            ));
            $label = $parts !== []
                ? implode(' · ', $parts)
                : ($summary['count'] > 1 ? 'Варіант '.($index + 1) : 'Товар');

            if (filled($row['sku'])) {
                $label .= ' · SKU '.$row['sku'];
            }

            $options[(string) $row['id']] = $label;
        }

        return $options;
    }

    /**
     * @return array{
     *   editable:bool,
     *   state:string,
     *   sell_net:?string,
     *   compare_at_net:?string,
     *   cost_net:?string,
     *   sell_gross:?string,
     *   compare_at_gross:?string,
     *   currency:string,
     *   effective_vat_rate:?string,
     *   expected_item_id:?string,
     *   expected_regular_net:?string,
     *   expected_sale_net:?string,
     *   expected_cost_net:?string,
     *   message:string
     * }
     */
    private static function offerVariantState(?Product $record, mixed $variantId): array
    {
        if (! $record instanceof Product || ! is_numeric($variantId)) {
            return [
                'editable' => false,
                'state' => 'variant_required',
                'sell_net' => null,
                'compare_at_net' => null,
                'cost_net' => null,
                'sell_gross' => null,
                'compare_at_gross' => null,
                'currency' => 'UAH',
                'effective_vat_rate' => null,
                'expected_item_id' => null,
                'expected_regular_net' => null,
                'expected_sale_net' => null,
                'expected_cost_net' => null,
                'message' => 'Оберіть варіант.',
            ];
        }

        $variant = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $record->workspace_id)
            ->where('product_id', $record->id)
            ->where('is_active', true)
            ->whereKey((int) $variantId)
            ->first();

        if (! $variant instanceof ProductVariant) {
            return [
                'editable' => false,
                'state' => 'variant_unavailable',
                'sell_net' => null,
                'compare_at_net' => null,
                'cost_net' => null,
                'sell_gross' => null,
                'compare_at_gross' => null,
                'currency' => 'UAH',
                'effective_vat_rate' => null,
                'expected_item_id' => null,
                'expected_regular_net' => null,
                'expected_sale_net' => null,
                'expected_cost_net' => null,
                'message' => 'Варіант уже недоступний. Оновіть товар.',
            ];
        }

        return app(MasterOfferReadService::class)->state($variant);
    }

    private static function canManageProductCost(?Product $record): bool
    {
        if (! $record instanceof Product) {
            return false;
        }

        $workspace = app(WorkspaceContext::class)->current();

        return (string) $workspace->id === (string) $record->workspace_id
            && self::canManageCurrentWorkspaceProductCost();
    }

    private static function canManageCurrentWorkspaceProductCost(): bool
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return false;
        }

        $workspace = app(WorkspaceContext::class)->current();

        return app(WorkspaceAuthorization::class)->allows(
            $actor,
            $workspace,
            WorkspacePermissions::MANAGE_PRODUCT_COST,
        );
    }

    private static function inventoryEditorAction(): Action
    {
        return Action::make('edit_inventory')
            ->label('Редагувати залишки')
            ->icon('heroicon-o-archive-box')
            ->disabled(fn (?Product $record): bool => self::isSourceOwned($record))
            ->tooltip(fn (?Product $record): ?string => self::isSourceOwned($record)
                ? 'Для товару з джерелом 1С залишок у Master поки доступний лише для перегляду.'
                : null)
            ->modalHeading('Залишки товару')
            ->modalDescription('Змінюється Master-залишок конкретного варіанта. Доступно до продажу враховує тимчасові резерви автоматично.')
            ->modalSubmitActionLabel('Зберегти залишок')
            ->fillForm(fn (?Product $record): array => self::inventoryEditorFormState($record))
            ->schema([
                Select::make('variant_id')
                    ->label('Варіант')
                    ->options(fn (?Product $record): array => self::inventoryVariantOptions($record))
                    ->required()
                    ->live()
                    ->hidden(fn (?Product $record): bool => count(self::inventoryVariantOptions($record)) <= 1)
                    ->afterStateUpdated(function (mixed $state, Set $set, ?Product $record): void {
                        $inventory = self::inventoryVariantState($record, $state);
                        $set('expected_quantity', $inventory['current_quantity']);
                        $set('new_quantity', $inventory['current_quantity']);
                    }),
                Hidden::make('expected_quantity'),
                Placeholder::make('inventory_editor_state')
                    ->hiddenLabel()
                    ->content(function (Get $get, ?Product $record): string {
                        $inventory = self::inventoryVariantState($record, $get('variant_id'));

                        return $inventory['message'].
                            ' Поточний залишок: '.$inventory['current_quantity'].' шт. · '.
                            'Доступно до продажу: '.$inventory['net_available'].' шт.';
                    }),
                TextInput::make('new_quantity')
                    ->label('Новий залишок')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->required()
                    ->suffix('шт.')
                    ->disabled(fn (Get $get, ?Product $record): bool => ! self::inventoryVariantState(
                        $record,
                        $get('variant_id'),
                    )['editable']),
                TextInput::make('reason')
                    ->label('Причина')
                    ->placeholder('Необов’язково')
                    ->maxLength(255),
            ])
            ->action(function (array $data, ?Product $record): void {
                if (! $record instanceof Product) {
                    throw new Halt;
                }

                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $variantId = self::inventoryResolvedVariantId($record, $data['variant_id'] ?? null);
                $variant = ProductVariant::withoutWorkspaceScope()
                    ->where('workspace_id', $record->workspace_id)
                    ->where('product_id', $record->id)
                    ->where('is_active', true)
                    ->whereKey($variantId)
                    ->first();

                if (! $variant instanceof ProductVariant) {
                    Notification::make()
                        ->danger()
                        ->title('Варіант уже недоступний')
                        ->body('Оновіть товар і повторіть дію.')
                        ->send();

                    throw new Halt;
                }

                $inventory = app(MasterInventoryReadService::class)->state($variant);
                if (! $inventory['editable']) {
                    Notification::make()
                        ->warning()
                        ->title('Залишок лише для перегляду')
                        ->body($inventory['message'])
                        ->send();

                    throw new Halt;
                }

                $workspace = Workspace::query()->findOrFail($record->workspace_id);

                try {
                    $stock = app(MasterInventoryMutationService::class)->setQuantity(
                        $actor,
                        $workspace,
                        $record,
                        $variant,
                        expectedQuantity: (int) ($data['expected_quantity'] ?? -1),
                        newQuantity: (int) $data['new_quantity'],
                        reason: $data['reason'] ?? null,
                    );
                } catch (InventoryMutationException $exception) {
                    Notification::make()
                        ->warning()
                        ->title('Залишок не змінено')
                        ->body($exception->getMessage())
                        ->send();

                    throw new Halt;
                }

                $record->refresh();

                Notification::make()
                    ->success()
                    ->title('Залишок оновлено')
                    ->body('Новий залишок: '.(int) $stock->quantity.' шт.')
                    ->send();
            });
    }

    private static function inventoryResolvedVariantId(Product $record, mixed $requestedVariantId): int
    {
        $options = self::inventoryVariantOptions($record);

        if (count($options) === 1) {
            return (int) array_key_first($options);
        }

        return is_numeric($requestedVariantId) ? (int) $requestedVariantId : 0;
    }

    /**
     * @return array{variant_id:?string,expected_quantity:int,new_quantity:int,reason:null}
     */
    private static function inventoryEditorFormState(?Product $record): array
    {
        $options = self::inventoryVariantOptions($record);
        $variantId = array_key_first($options);
        $inventory = self::inventoryVariantState($record, $variantId);

        return [
            'variant_id' => $variantId !== null ? (string) $variantId : null,
            'expected_quantity' => $inventory['current_quantity'],
            'new_quantity' => $inventory['current_quantity'],
            'reason' => null,
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function inventoryVariantOptions(?Product $record): array
    {
        if (! $record instanceof Product) {
            return [];
        }

        $summary = app(ProductWorkspaceSummaryService::class)->variants($record);
        $options = [];

        foreach ($summary['rows'] as $index => $row) {
            $parts = array_values(array_filter(
                $row['options'],
                static fn (string $value): bool => $value !== '' && $value !== '—',
            ));
            $label = $parts !== []
                ? implode(' · ', $parts)
                : ($summary['count'] > 1 ? 'Варіант '.($index + 1) : 'Товар');

            if (filled($row['sku'])) {
                $label .= ' · SKU '.$row['sku'];
            }

            $options[(string) $row['id']] = $label;
        }

        return $options;
    }

    /**
     * @return array{
     *   editable:bool,
     *   state:string,
     *   current_quantity:int,
     *   net_available:int,
     *   pending_quantity:int,
     *   message:string
     * }
     */
    private static function inventoryVariantState(?Product $record, mixed $variantId): array
    {
        if (! $record instanceof Product || ! is_numeric($variantId)) {
            return [
                'editable' => false,
                'state' => 'variant_required',
                'current_quantity' => 0,
                'net_available' => 0,
                'pending_quantity' => 0,
                'message' => 'Оберіть варіант.',
            ];
        }

        $variant = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $record->workspace_id)
            ->where('product_id', $record->id)
            ->where('is_active', true)
            ->whereKey((int) $variantId)
            ->first();

        if (! $variant instanceof ProductVariant) {
            return [
                'editable' => false,
                'state' => 'variant_unavailable',
                'current_quantity' => 0,
                'net_available' => 0,
                'pending_quantity' => 0,
                'message' => 'Варіант уже недоступний. Оновіть товар.',
            ];
        }

        return app(MasterInventoryReadService::class)->state($variant);
    }

    private static function magentoClassificationAction(): Action
    {
        return Action::make('configure_magento')
            ->label('Налаштувати Magento')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('primary')
            ->slideOver()
            ->modalWidth(Width::Large)
            ->modalHeading('Magento · класифікація товару')
            ->modalDescription('Master-дані не дублюються. Тут змінюються лише Magento Category та Attribute Set для вибраного каналу.')
            ->modalSubmitActionLabel('Зберегти Magento')
            ->visible(fn (?Product $record): bool => self::canManageMagentoClassification($record))
            ->fillForm(function (?Product $record): array {
                if (! $record instanceof Product) {
                    return [];
                }

                $editor = app(ProductMagentoClassificationEditor::class);
                $accountId = array_key_first($editor->accountOptions($record));

                return is_string($accountId)
                    ? $editor->formState($record, $accountId)
                    : [];
            })
            ->schema([
                Select::make('account_id')
                    ->label('Magento магазин')
                    ->options(fn (?Product $record): array => $record instanceof Product
                        ? app(ProductMagentoClassificationEditor::class)->accountOptions($record)
                        : [])
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set, ?Product $record): void {
                        if (! $record instanceof Product || ! is_string($state) || $state === '') {
                            return;
                        }

                        foreach (app(ProductMagentoClassificationEditor::class)->formState($record, $state) as $key => $value) {
                            $set($key, $value);
                        }
                    }),
                Placeholder::make('magento_effective_classification')
                    ->label('Поточний результат')
                    ->content(fn (Get $get, ?Product $record): HtmlString => self::magentoClassificationSummary(
                        $record,
                        is_string($get('account_id')) ? $get('account_id') : null,
                    )),
                Radio::make('category_mode')
                    ->label('Категорія Magento')
                    ->options([
                        'automatic' => 'Автоматично з Master Category',
                        'override' => 'Власний вибір для цього товару',
                    ])
                    ->required()
                    ->inline()
                    ->live(),
                Select::make('category_ids')
                    ->label('Категорії Magento')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn (Get $get, ?Product $record): array => self::magentoCategoryOptions(
                        $record,
                        is_string($get('account_id')) ? $get('account_id') : null,
                    ))
                    ->required(fn (Get $get): bool => $get('category_mode') === 'override')
                    ->visible(fn (Get $get): bool => $get('category_mode') === 'override')
                    ->helperText('Показано лише актуальні активні Product-категорії; root/store-root не доступні.'),
                Radio::make('attribute_set_mode')
                    ->label('Attribute Set')
                    ->options(fn (Get $get, ?Product $record): array => self::magentoAttributeSetModeOptions(
                        $record,
                        is_string($get('account_id')) ? $get('account_id') : null,
                    ))
                    ->required()
                    ->inline()
                    ->live(),
                Select::make('attribute_set_id')
                    ->label('Magento Attribute Set')
                    ->searchable()
                    ->preload()
                    ->options(fn (Get $get, ?Product $record): array => self::magentoAttributeSetOptions(
                        $record,
                        is_string($get('account_id')) ? $get('account_id') : null,
                    ))
                    ->required(fn (Get $get): bool => $get('attribute_set_mode') === 'override')
                    ->visible(fn (Get $get): bool => $get('attribute_set_mode') === 'override'),
            ])
            ->action(function (array $data, ?Product $record): void {
                if (! $record instanceof Product) {
                    throw new Halt;
                }

                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $workspace = Workspace::query()->findOrFail($record->workspace_id);

                try {
                    app(ProductMagentoClassificationEditor::class)->apply(
                        $actor,
                        $workspace,
                        $record,
                        $data,
                    );
                } catch (AdobeProductClassificationException|AuthorizationException $exception) {
                    Notification::make()
                        ->warning()
                        ->title('Magento класифікацію не змінено')
                        ->body($exception->getMessage())
                        ->send();

                    throw new Halt;
                }

                $record->refresh();

                Notification::make()
                    ->success()
                    ->title('Magento класифікацію збережено')
                    ->send();
            });
    }

    private static function canManageMagentoClassification(?Product $record): bool
    {
        if (! $record instanceof Product) {
            return false;
        }

        $actor = auth()->user();
        if (! $actor instanceof User) {
            return false;
        }

        $workspace = app(WorkspaceContext::class)->current();

        return (string) $workspace->id === (string) $record->workspace_id
            && app(WorkspaceAuthorization::class)->allows(
                $actor,
                $workspace,
                WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            )
            && app(ProductMagentoClassificationEditor::class)->accountOptions($record) !== [];
    }

    /**
     * @return array<string, string>
     */
    private static function magentoCategoryOptions(?Product $record, ?string $accountId): array
    {
        if (! $record instanceof Product || ! is_string($accountId) || $accountId === '') {
            return [];
        }

        try {
            return app(ProductMagentoClassificationEditor::class)->categoryOptions($record, $accountId);
        } catch (AuthorizationException) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private static function magentoAttributeSetModeOptions(?Product $record, ?string $accountId): array
    {
        if (! $record instanceof Product || ! is_string($accountId) || $accountId === '') {
            return [
                'automatic' => 'Автоматично з Сімейства товару',
                'override' => 'Власний вибір для цього товару',
            ];
        }

        try {
            $classification = app(ProductMagentoClassificationEditor::class)->effective($record, $accountId);

            if ($classification->hasTrustedRemoteSubject) {
                return [
                    'observed_remote' => 'Визначається Magento · лише перегляд',
                ];
            }
        } catch (AdobeProductClassificationException|AuthorizationException) {
            // The action summary will surface the read problem. Keep the safe unlinked options
            // unavailable to a tampered account because apply() re-authorizes and fails closed.
        }

        return [
            'automatic' => 'Автоматично з Сімейства товару',
            'override' => 'Власний вибір для цього товару',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function magentoAttributeSetOptions(?Product $record, ?string $accountId): array
    {
        if (! $record instanceof Product || ! is_string($accountId) || $accountId === '') {
            return [];
        }

        try {
            return app(ProductMagentoClassificationEditor::class)->attributeSetOptions($record, $accountId);
        } catch (AuthorizationException) {
            return [];
        }
    }

    private static function magentoClassificationSummary(?Product $record, ?string $accountId): HtmlString
    {
        if (! $record instanceof Product || ! is_string($accountId) || $accountId === '') {
            return new HtmlString('—');
        }

        try {
            $editor = app(ProductMagentoClassificationEditor::class);
            $classification = $editor->effective($record, $accountId);
            $categoryOptions = $editor->categoryOptions($record, $accountId);

            $categories = collect($classification->externalCategoryIds)
                ->map(fn (string $id): string => $categoryOptions[$id] ?? 'ID '.$id)
                ->implode(', ');

            $categorySource = match ($classification->categorySource) {
                'product_override' => 'власний вибір',
                'category_mapping' => 'автоматично з Master Category',
                default => 'не визначено',
            };

            $attributeSource = match ($classification->attributeSetSource) {
                'product_override' => 'власний вибір',
                'product_type_default' => 'автоматично з Сімейства товару',
                'observed_remote' => 'підтверджено Magento',
                default => 'не визначено',
            };

            return new HtmlString(
                '<div><strong>Категорія:</strong> '.e($categories !== '' ? $categories : 'не визначено').
                ' <span style="color:#6b7280;">('.e($categorySource).')</span></div>'.
                '<div style="margin-top:5px;"><strong>Attribute Set:</strong> '.e($classification->attributeSetName ?? 'не визначено').
                ' <span style="color:#6b7280;">('.e($attributeSource).')</span></div>'
            );
        } catch (AdobeProductClassificationException|AuthorizationException) {
            return new HtmlString('Не вдалося прочитати поточну Magento класифікацію.');
        }
    }

    private static function draftLockedSection(
        string $key,
        string $title,
        ?string $description = null,
    ): Section {
        $section = Section::make($title)
            ->schema([
                Placeholder::make($key)
                    ->hiddenLabel()
                    ->content('Доступно після збереження чернетки.'),
            ])
            ->visible(fn (?Product $record): bool => $record === null);

        return $description === null
            ? $section
            : $section->description($description);
    }

    private static function pendingCapabilityAction(
        string $name,
        string $label,
        string $description,
        string $icon = 'heroicon-o-clock',
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->modalIcon('heroicon-o-clock')
            ->modalHeading('Чекає на підключення')
            ->modalDescription($description)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрити')
            ->action(fn (): null => null);
    }

    private static function magentoChannelUrl(?Product $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $record->loadMissing('syncChannelSelections.syncConfiguration.connectorAccount.connectorDefinition');

        $selection = $record->syncChannelSelections
            ->first(fn ($selection): bool => $selection->syncConfiguration?->connectorAccount?->connectorDefinition?->code === 'adobe_commerce');

        $accountId = $selection?->syncConfiguration?->connectorAccount?->id;

        if (! is_string($accountId) || $accountId === '') {
            return null;
        }

        if (! ManageAdobeProductsChannel::canAccess(['account' => $accountId])) {
            return null;
        }

        return ManageAdobeProductsChannel::getUrl(['account' => $accountId]);
    }

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

        if ($summary['axes'] === [] && $summary['count'] <= 1) {
            $sku = $summary['skus'][0] ?? null;
            $skuHtml = $sku
                ? '<span style="margin-left:8px;color:#6b7280;">SKU '.e($sku).'</span>'
                : '<span style="margin-left:8px;color:#9ca3af;">SKU не задано</span>';

            return new HtmlString(
                '<div><strong>'.$label.'</strong>'.$skuHtml.'</div>'.
                '<div style="margin-top:4px;font-size:12px;color:#6b7280;">Додайте варіанти, щоб оголосити першу опцію — наприклад, колір або розмір.</div>'
            );
        }

        if ($summary['axes'] === []) {
            $chips = collect($summary['skus'])
                ->map(fn (string $sku): string => '<span style="display:inline-flex;padding:3px 7px;border-radius:999px;background:#f3f4f6;margin:3px 4px 0 0;font-size:12px;">'.e($sku).'</span>')
                ->implode('');

            return new HtmlString(
                '<div><strong>'.$label.'</strong></div>'.
                '<div style="margin-top:6px;">'.$chips.'</div>'.
                '<div style="margin-top:6px;font-size:11px;color:#92400e;">Master-опції для цієї сім’ї ще не оголошені. Для товару із зовнішнім джерелом структура залишається read-only.</div>'
            );
        }

        $variantIds = collect($summary['rows'])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $specificByVariant = $variantIds === []
            ? collect()
            : VariantMedia::withoutWorkspaceScope()
                ->where('workspace_id', $record->workspace_id)
                ->whereIn('variant_id', $variantIds)
                ->whereNull('locale')
                ->with(['asset' => fn ($query) => $query->withoutGlobalScopes()])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (VariantMedia $media): int => (int) $media->variant_id);
        $commonMediaCount = app(ProductMediaReadService::class)->productMedia($record)->count();
        $canManageMedia = self::canManageVariantMedia($record);
        $mediaReadService = app(ProductMediaReadService::class);

        $headers = collect($summary['axes'])
            ->map(fn (array $axis): string => '<th style="text-align:left;padding:7px 9px;border-bottom:1px solid #e5e7eb;font-size:11px;color:#6b7280;font-weight:600;">'.e($axis['label']).'</th>')
            ->implode('');
        $rows = collect($summary['rows'])
            ->map(function (array $row) use ($specificByVariant, $commonMediaCount, $canManageMedia, $mediaReadService): string {
                $optionCells = collect($row['options'])
                    ->map(fn (string $value): string => '<td style="padding:8px 9px;border-bottom:1px solid #f3f4f6;">'.e($value).'</td>')
                    ->implode('');

                /** @var Collection<int,VariantMedia> $specific */
                $specific = $specificByVariant->get((int) $row['id'], collect())->values();
                /** @var VariantMedia|null $primary */
                $primary = $specific->first(fn (VariantMedia $media): bool => $media->role === MediaRole::Primary);
                $photoState = '';

                if ($primary instanceof VariantMedia) {
                    $url = $mediaReadService->sourceReference($primary->asset);
                    $thumb = is_string($url) && $url !== ''
                        ? '<img src="'.e($url).'" alt="" style="width:34px;height:34px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">'
                        : '<span style="display:inline-flex;width:34px;height:34px;align-items:center;justify-content:center;border-radius:6px;background:#f3f4f6;">Фото</span>';
                    $more = $specific->count() > 1
                        ? '<span style="font-size:10px;color:#6b7280;">+'.($specific->count() - 1).'</span>'
                        : '';
                    $photoState = '<span style="display:inline-flex;align-items:center;gap:5px;">'.$thumb.$more.'</span>';
                } elseif ($specific->isNotEmpty()) {
                    $photoState = '<span style="color:#92400e;white-space:nowrap;">Власні · '.$specific->count().' · без головного</span>';
                } elseif ($commonMediaCount > 0) {
                    $photoState = '<span style="color:#6b7280;white-space:nowrap;">Лише загальні</span>';
                } else {
                    $photoState = '<span style="color:#9ca3af;white-space:nowrap;">Немає фото</span>';
                }

                if ($canManageMedia) {
                    $arguments = json_encode(['variant_id' => (int) $row['id']], JSON_THROW_ON_ERROR);
                    $handler = e("mountAction('assign_variant_media', {$arguments})");
                    $photoState = '<button type="button" wire:click="'.$handler.'" title="Редагувати власні фото варіанта" '.
                        'style="border:0;background:transparent;padding:2px;cursor:pointer;text-align:left;">'.$photoState.'</button>';
                }

                return '<tr>'.
                    $optionCells.
                    '<td style="padding:8px 9px;border-bottom:1px solid #f3f4f6;">'.$photoState.'</td>'.
                    '<td style="padding:8px 9px;border-bottom:1px solid #f3f4f6;">'.e($row['sku'] ?? '—').'</td>'.
                    '<td style="padding:8px 9px;border-bottom:1px solid #f3f4f6;">'.e($row['gtin'] ?? '—').'</td>'.
                    '<td style="padding:8px 9px;border-bottom:1px solid #f3f4f6;white-space:nowrap;">'.e($row['price'] ?? '—').'</td>'.
                    '<td style="padding:8px 9px;border-bottom:1px solid #f3f4f6;text-align:right;">'.e((string) $row['stock']).'</td>'.
                    '</tr>';
            })
            ->implode('');

        return new HtmlString(
            '<div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline;margin-bottom:8px;">'.
                '<strong>'.$label.'</strong>'.
                '<span style="font-size:11px;color:#6b7280;">'.count($summary['axes']).' опц.</span>'.
            '</div>'.
            '<div style="overflow-x:auto;border:1px solid #e5e7eb;border-radius:8px;">'.
                '<table style="width:100%;border-collapse:collapse;font-size:12px;min-width:760px;">'.
                    '<thead><tr>'.$headers.
                        '<th style="text-align:left;padding:7px 9px;border-bottom:1px solid #e5e7eb;font-size:11px;color:#6b7280;">Фото</th>'.
                        '<th style="text-align:left;padding:7px 9px;border-bottom:1px solid #e5e7eb;font-size:11px;color:#6b7280;">SKU</th>'.
                        '<th style="text-align:left;padding:7px 9px;border-bottom:1px solid #e5e7eb;font-size:11px;color:#6b7280;">GTIN</th>'.
                        '<th style="text-align:left;padding:7px 9px;border-bottom:1px solid #e5e7eb;font-size:11px;color:#6b7280;">Ціна</th>'.
                        '<th style="text-align:right;padding:7px 9px;border-bottom:1px solid #e5e7eb;font-size:11px;color:#6b7280;">Залишок</th>'.
                    '</tr></thead>'.
                    '<tbody>'.$rows.'</tbody>'.
                '</table>'.
            '</div>'.
            '<div style="margin-top:6px;font-size:11px;color:#9ca3af;">Власні фото варіанта показуються перед загальними. Ціна та залишок лише відображаються зі своїх доменів.</div>'
        );
    }

    private static function canManageVariantMedia(Product $record): bool
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return false;
        }

        $workspace = app(WorkspaceContext::class)->current();

        return (string) $workspace->id === (string) $record->workspace_id
            && app(WorkspaceAuthorization::class)->allows(
                $actor,
                $workspace,
                WorkspacePermissions::MANAGE_PRODUCTS,
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

                if (! $group['active']) {
                    return '<div style="display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid #f3f4f6;">'.
                        '<span>'.$label.'</span>'.
                        '<span style="color:#9ca3af;white-space:nowrap;">Опційна · неактивна</span>'.
                        '</div>';
                }

                $missingRequired = max(0, (int) $group['required'] - (int) $group['filled']);
                $progress = $group['required'] === 0
                    ? 'Обов’язкових полів немає'
                    : ($missingRequired === 0
                        ? 'Обов’язкові поля заповнені'
                        : 'Потрібно заповнити: '.$missingRequired);

                $missing = collect($group['missing'])
                    ->take(4)
                    ->map(fn (string $item): string => e($item))
                    ->implode(', ');
                $missingMore = count($group['missing']) > 4
                    ? ' +'.(count($group['missing']) - 4)
                    : '';
                $missingLine = $missing !== ''
                    ? '<div style="margin-top:4px;font-size:11px;color:#92400e;">Потрібно заповнити: '.$missing.$missingMore.'</div>'
                    : '';

                return '<div style="padding:10px 0;border-bottom:1px solid #f3f4f6;">'.
                    '<div style="display:flex;justify-content:space-between;gap:16px;">'.
                        '<span>'.$label.'</span>'.
                        '<span style="color:#6b7280;white-space:nowrap;">'.$progress.'</span>'.
                    '</div>'.
                    '<div style="margin-top:4px;font-size:11px;color:#9ca3af;">'.$group['total'].' полів у групі</div>'.
                    $missingLine.
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

        $service = app(ProductWorkspaceSummaryService::class);
        $basic = $service->basicCompleteness($record);
        $structure = $service->structureCompleteness($record);

        $basicText = $basic['missing'] === []
            ? 'Основні Master-дані заповнені'
            : 'Додатково можна заповнити: '.implode(', ', $basic['missing']);

        $missingStructure = $structure['missing_product'] + $structure['missing_variant'];
        $structureText = $structure['total'] === 0
            ? 'Обов’язкових характеристик немає'
            : ($missingStructure === 0
                ? 'Обов’язкові характеристики заповнені'
                : 'Потрібно заповнити обов’язкових характеристик: '.$missingStructure);

        return new HtmlString(
            '<div><strong>Master</strong></div>'.
            '<div style="margin-top:4px;color:#6b7280;">'.e($basicText).'</div>'.
            '<div style="margin-top:10px;"><strong>Характеристики</strong></div>'.
            '<div style="margin-top:4px;color:#6b7280;">'.e($structureText).'</div>'.
            '<div style="margin-top:8px;font-size:11px;color:#9ca3af;">Готовність до конкретної дії перевіряється окремо для B2B або каналу публікації.</div>'
        );
    }

    private static function buildChannelWorkspaceHtml(?Product $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('—');
        }

        $channels = app(ProductChannelReadinessReadService::class)->rows($record);

        if ($channels === []) {
            return new HtmlString(
                '<div style="color:#6b7280;">Товар ще не додано до жодного каналу публікації.</div>'
            );
        }

        $hasMagento = false;
        $rows = collect($channels)
            ->map(function (array $channel) use (&$hasMagento): string {
                $isMagento = $channel['platform'] === 'adobe_commerce';
                $hasMagento = $hasMagento || $isMagento;

                $statusColor = match ($channel['status_label']) {
                    'Класифікація готова' => '#166534',
                    'Потрібне налаштування', 'Потрібна перевірка' => '#92400e',
                    default => '#6b7280',
                };

                $details = collect($channel['details'])
                    ->map(fn (string $detail): string => '<div style="margin-top:3px;font-size:11px;color:#6b7280;">'.e($detail).'</div>')
                    ->implode('');

                return '<div style="padding:8px 0;border-bottom:1px solid #f3f4f6;">'.
                    '<div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline;">'.
                        '<span>'.e($channel['label']).'</span>'.
                        '<span style="font-size:11px;color:'.$statusColor.';white-space:nowrap;">'.e($channel['status_label']).'</span>'.
                    '</div>'.
                    $details.
                '</div>';
            })
            ->implode('');

        $footer = $hasMagento
            ? '<div style="margin-top:7px;font-size:11px;color:#9ca3af;">Для Magento тут показано лише стан класифікації. Готовність до публікації перевіряється в каналі окремо.</div>'
            : '';

        return new HtmlString($rows.$footer);
    }

    private static function buildAttentionHtml(?Product $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('—');
        }

        $service = app(ProductWorkspaceSummaryService::class);
        $missing = $service->basicCompleteness($record)['missing'];
        $structure = $service->structureCompleteness($record);
        $missingStructure = $structure['missing_product'] + $structure['missing_variant'];

        if ($missing === [] && $missingStructure === 0) {
            return new HtmlString(
                '<div style="color:#166534;">Базові дані та обов’язкові характеристики заповнені.</div>'.
                '<div style="margin-top:4px;font-size:11px;color:#6b7280;">Канальні вимоги перевіряються окремо.</div>'
            );
        }

        $items = collect($missing)
            ->map(fn (string $label): string => '<li style="margin:4px 0;">'.e($label).'</li>')
            ->implode('');

        $structureItem = $missingStructure > 0
            ? '<li style="margin:4px 0;">Обов’язкові характеристики: '.$missingStructure.' незаповнених</li>'
            : '';

        return new HtmlString(
            '<div style="margin-bottom:6px;color:#92400e;">Потрібна увага до Master-даних</div>'.
            '<ul style="margin:0;padding-left:18px;color:#6b7280;">'.$items.$structureItem.'</ul>'.
            '<div style="margin-top:6px;font-size:11px;color:#9ca3af;">Помилки та готовність каналів перевіряються окремо.</div>'
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

    private static function productLifecycleStatus(Product $record): ProductLifecycleStatus
    {
        return $record->lifecycle_status
            ?? ($record->is_active ? ProductLifecycleStatus::Active : ProductLifecycleStatus::Archived);
    }

    private static function productLifecycleLabel(Product $record): string
    {
        return self::productLifecycleStatus($record)->label();
    }

    private static function productLifecycleColor(string $state): string
    {
        return match ($state) {
            ProductLifecycleStatus::Active->label() => 'success',
            ProductLifecycleStatus::Draft->label() => 'warning',
            default => 'gray',
        };
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return self::canManageCurrentWorkspaceProducts()
            ? Response::allow()
            : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        if (! $record instanceof Product) {
            return Response::deny();
        }

        $workspace = app(WorkspaceContext::class)->current();

        return (string) $record->workspace_id === (string) $workspace->id
            && self::canManageCurrentWorkspaceProducts()
                ? Response::allow()
                : Response::deny();
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

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }
}
