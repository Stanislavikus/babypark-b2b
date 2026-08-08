<?php

namespace App\Filament\Cabinet\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Actions\Action;
use Filament\Tables\Filters\SelectFilter;
use App\Filament\Cabinet\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Cabinet\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\Cabinet\Resources\ProductResource\Pages;
use App\Filament\Concerns\HasProductLightbox;
use App\Filament\Resources\ProductResource as AdminProductResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Availability\AvailabilityResolver;
use App\Services\Pricing\PricingSqlExpressions;
use App\Services\Pricing\ProductPricingSummary;
use App\Services\Pricing\WorkspaceTaxDefaults;
use App\Support\CatalogRowData;
use App\Support\Pricing\CustomerPricingScope;
use App\Support\ProductFields\CabinetProductMargin;
use App\Support\ProductFields\MarginToggle;
use App\Support\ProductFields\ProductColumnVisibility;
use App\Support\ProductFields\ProductPanelVisibility;
use App\Support\ProductTableLink;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource
{
    use HasProductLightbox;

    protected static ?string $model = Product::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $modelLabel = 'товар';

    protected static ?string $pluralModelLabel = 'Товари';

    protected static ?string $slug = 'products';

    public static function infolist(Schema $infolist): Schema
    {
        $panel = 'cabinet';
        $visible = ProductPanelVisibility::visibleDetailFields($panel);
        $customer = auth('customer')->user();
        $summary = app(ProductPricingSummary::class);

        $schema = [];

        if (in_array('variants', $visible, true)) {
            $schema[] = Section::make('Основне')
                ->schema([
                    RepeatableEntry::make('active_variants')
                        ->label('')
                        ->getStateUsing(function (Product $record) use ($customer, $summary) {
                            return $record->variants
                                ->where('is_active', true)
                                ->filter(fn (ProductVariant $v) => $summary->variantHasResolvablePrice($v, $customer))
                                ->values();
                        })
                        ->schema([
                            TextEntry::make('sku')
                                ->label('Артикул')
                                ->getStateUsing(fn (ProductVariant $record): string => $record->product->sku),

                            TextEntry::make('barcode_ean')
                                ->label('EAN')
                                ->placeholder('—'),

                            TextEntry::make('brand')
                                ->label('Бренд')
                                ->getStateUsing(fn (ProductVariant $record): ?string => $record->product->brand)
                                ->placeholder('—'),

                            TextEntry::make('name')
                                ->label('Назва')
                                ->getStateUsing(fn (ProductVariant $record): string => $record->product->name),

                            TextEntry::make('category')
                                ->label('Категорія')
                                ->getStateUsing(fn (ProductVariant $record): ?string => $record->product->category?->name)
                                ->placeholder('—'),

                            TextEntry::make('stock_status')
                                ->label('Наявність')
                                ->getStateUsing(function (ProductVariant $record): string {
                                    $threshold = $record->product->category?->stock_display_threshold ?? 10;

                                    return $record->availabilityBadge($threshold)['label'];
                                })
                                ->badge()
                                ->color(function (ProductVariant $record): string {
                                    $threshold = $record->product->category?->stock_display_threshold ?? 10;

                                    return match ($record->availabilityBadge($threshold)['color']) {
                                        'success' => 'success',
                                        'warning' => 'warning',
                                        'info' => 'info',
                                        default => 'gray',
                                    };
                                }),

                            TextEntry::make('customer_price')
                                ->label('Ваша ціна')
                                ->getStateUsing(function (ProductVariant $record) use ($customer, $summary): ?string {
                                    $display = $summary->tryResolveVariantDisplay($record, $customer);

                                    return $display?->formattedGross();
                                })
                                ->placeholder('—'),

                            TextEntry::make('rrp')
                                ->label('РРЦ')
                                ->getStateUsing(function (ProductVariant $record): ?string {
                                    $rrp = $record->recommended_retail_price_cache;

                                    return $rrp !== null
                                        ? '₴ '.number_format((float) $rrp, 2, '.', ' ')
                                        : null;
                                })
                                ->placeholder('—')
                                ->extraAttributes(['class' => 'line-through text-gray-400']),

                            TextEntry::make('customer_margin')
                                ->label(fn (): HtmlString => MarginToggle::labelHtml(
                                    Livewire::current()?->marginFormat ?? 'percent'
                                ))
                                ->getStateUsing(function (ProductVariant $record) use ($customer): ?string {
                                    return CabinetProductMargin::formatted(
                                        $record->product,
                                        $customer,
                                        Livewire::current()?->marginFormat ?? 'percent'
                                    );
                                })
                                ->color(function (ProductVariant $record) use ($customer): ?string {
                                    return CabinetProductMargin::isNegative($record->product, $customer) ? 'danger' : null;
                                })
                                ->placeholder('—'),
                        ])
                        ->columns(2),
                ]);
        }

        if (in_array('url', $visible, true)) {
            $schema[] = Section::make('Сайт')->schema([
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

                TextEntry::make('photo_preview')
                    ->label('Фото товару')
                    ->getStateUsing(function (Product $record) {
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
            ])->columns(2);
        }

        return $infolist->components($schema);
    }

    public static function table(Table $table): Table
    {
        $panel = 'cabinet';
        $visible = ProductPanelVisibility::visibleCatalogColumns($panel);
        $toggleable = ProductColumnVisibility::toggleableColumns($panel);
        $columns = [];

        if (in_array('photo', $visible, true)) {
            $columns[] = ImageColumn::make('first_image')
                ->label('Фото')
                ->state(fn (Product $record): ?string => self::firstImage($record))
                ->size(48)
                ->defaultImageUrl(fn () => 'data:image/svg+xml,'.rawurlencode(AdminProductResource::placeholderSvg(48)))
                ->extraImgAttributes(fn (Product $record): array => self::lightboxImgAttributes($record))
                ->toggleable(in_array('photo', $toggleable));
        }

        if (in_array('sku', $visible, true)) {
            $columns[] = TextColumn::make('sku')
                ->label('Артикул')
                ->searchable()
                ->sortable();
        }

        if (in_array('barcode_ean', $visible, true)) {
            $columns[] = TextColumn::make('barcode_ean')
                ->label('EAN')
                ->searchable()
                ->sortable()
                ->placeholder('—')
                ->toggleable(in_array('barcode_ean', $toggleable), isToggledHiddenByDefault: true);
        }

        if (in_array('name', $visible, true)) {
            $columns[] = TextColumn::make('name')
                ->label('Назва')
                ->searchable()
                ->sortable()
                ->limit(50)
                ->url(fn (Product $record): string => static::getUrl('view', ['record' => $record]));
        }

        if (in_array('category', $visible, true)) {
            $columns[] = TextColumn::make('category.name')
                ->label('Категорія')
                ->sortable()
                ->toggleable(in_array('category', $toggleable));
        }

        if (in_array('brand', $visible, true)) {
            $columns[] = TextColumn::make('brand')
                ->label('Бренд')
                ->searchable()
                ->sortable()
                ->toggleable(in_array('brand', $toggleable));
        }

        if (in_array('stock', $visible, true)) {
            $columns[] = TextColumn::make('stock_status')
                ->label('Наявність')
                ->getStateUsing(function (Product $record): string {
                    $customer = auth('customer')->user();
                    $row = CatalogRowData::forProduct($record, $customer);

                    return $row->badge['label'];
                })
                ->badge()
                ->color(function (Product $record): string {
                    $customer = auth('customer')->user();
                    $row = CatalogRowData::forProduct($record, $customer);

                    return match ($row->badge['color']) {
                        'success' => 'success',
                        'warning' => 'warning',
                        'info' => 'info',
                        default => 'gray',
                    };
                })
                ->sortable(query: fn (Builder $query, string $direction): Builder => self::applyStockSorting($query, $direction));
        }

        if (in_array('price', $visible, true)) {
            $columns[] = TextColumn::make('customer_price')
                ->label('Ваша ціна')
                ->getStateUsing(function (Product $record): ?string {
                    $customer = auth('customer')->user();
                    $row = CatalogRowData::forProduct($record, $customer);
                    $price = $row->price;

                    return $price !== null
                        ? '₴ '.number_format($price, 2, '.', ' ')
                        : null;
                })
                ->placeholder('—')
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    $customer = auth('customer')->user();
                    $priceListId = CustomerPricingScope::priceListIdFor($customer);

                    if ($priceListId === null) {
                        return $query->orderBy('sku', $direction);
                    }

                    $workspaceRate = app(WorkspaceTaxDefaults::class)->resolveWorkspaceRate(
                        $customer->workspace ?? $customer->workspace()->firstOrFail()
                    );

                    return $query->orderByRaw(
                        'COALESCE('.PricingSqlExpressions::minGrossPriceSqlForProduct('products.id', $priceListId, $workspaceRate).", 0) {$direction}"
                    );
                });
        }

        if (in_array('rrp', $visible, true)) {
            $columns[] = TextColumn::make('rrp')
                ->label('РРЦ')
                ->getStateUsing(function (Product $record): ?string {
                    $summary = app(ProductPricingSummary::class);

                    return $summary->formatRrp($record);
                })
                ->placeholder('—')
                ->toggleable(in_array('rrp', $toggleable))
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query->orderByRaw(
                        'COALESCE('.PricingSqlExpressions::maxRrpSqlForProduct('products.id').", 0) {$direction}"
                    );
                });
        }

        if (in_array('margin', $visible, true)) {
            $columns[] = TextColumn::make('margin')
                ->label(fn (): HtmlString => MarginToggle::labelHtml(
                    Livewire::current()?->marginFormat ?? 'percent'
                ))
                ->getStateUsing(function (Product $record): ?string {
                    $customer = auth('customer')->user();

                    return CabinetProductMargin::formatted(
                        $record,
                        $customer,
                        Livewire::current()?->marginFormat ?? 'percent'
                    );
                })
                ->color(function (Product $record): ?string {
                    $customer = auth('customer')->user();

                    return CabinetProductMargin::isNegative($record, $customer) ? 'danger' : null;
                })
                ->placeholder('—')
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    $customer = auth('customer')->user();
                    $priceListId = CustomerPricingScope::priceListIdFor($customer);

                    if ($priceListId === null) {
                        return $query->orderBy('sku', $direction);
                    }

                    $workspaceRate = app(WorkspaceTaxDefaults::class)->resolveWorkspaceRate(
                        $customer->workspace ?? $customer->workspace()->firstOrFail()
                    );

                    return $query->orderByRaw(
                        PricingSqlExpressions::customerMarginSortSql('products.id', $priceListId, $workspaceRate)." {$direction}"
                    );
                });
        }

        if (in_array('order', $visible, true)) {
            $columns[] = ViewColumn::make('order')
                ->label('Замовити')
                ->disableClick()
                ->view('filament.cabinet.columns.quantity-order');
        }

        if (in_array('url', $visible, true)) {
            $columns[] = TextColumn::make('url')
                ->label('URL на сайті')
                ->formatStateUsing(fn (?string $state): HtmlString|string => ProductTableLink::externalUrlHtml($state))
                ->tooltip(fn (?string $state) => $state)
                ->disableClick()
                ->toggleable(in_array('url', $toggleable), isToggledHiddenByDefault: true);
        }

        return $table
            ->columns($columns)
            ->defaultSort('sku')
            ->recordUrl(fn (Product $record): string => static::getUrl('view', ['record' => $record]))
            ->toggleColumnsTriggerAction(
                fn (Action $action) => $action
                    ->label('Стовпці')
                    ->tooltip('Стовпці')
            )
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Категорії')
                    ->relationship('category', 'name')
                    ->multiple()
                    ->preload(),
                SelectFilter::make('brand')
                    ->label('Бренди')
                    ->options(fn (): array => CustomerPricingScope::applyProductScope(
                        Product::query()->where('is_active', true),
                        auth('customer')->user(),
                    )
                        ->distinct()
                        ->orderBy('brand')
                        ->whereNotNull('brand')
                        ->pluck('brand', 'brand')
                        ->toArray())
                    ->multiple(),
            ])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $customer = auth('customer')->user();

        return CustomerPricingScope::applyProductScope(
            parent::getEloquentQuery()->where('is_active', true),
            $customer,
        )->with(CustomerPricingScope::eagerLoadForCustomer($customer));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'view' => ViewProduct::route('/{record}'),
        ];
    }




    protected static function applyStockSorting(Builder $query, string $direction): Builder
    {
        $totalQty = AvailabilityResolver::netQtySqlForProduct('products.id');

        $minExpectedDate = '(SELECT MIN(s.expected_date)
                            FROM stocks s
                            INNER JOIN product_variants pv ON s.variant_id = pv.id
                            WHERE pv.product_id = products.id
                            AND s.expected_date IS NOT NULL)';

        $priorityExpr = "CASE
            WHEN {$totalQty} > 0 THEN 0
            WHEN {$minExpectedDate} IS NOT NULL THEN 1
            ELSE 2
        END";

        return $query
            ->orderByRaw("{$priorityExpr} {$direction}")
            ->orderByRaw("{$totalQty} DESC")
            ->orderByRaw("{$minExpectedDate} ASC");
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
}
