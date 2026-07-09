<?php

namespace App\Filament\Cabinet\Resources;

use App\Filament\Cabinet\Resources\ProductResource\Pages;
use App\Filament\Concerns\HasProductLightbox;
use App\Filament\Resources\ProductResource as AdminProductResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Availability\AvailabilityResolver;
use App\Support\CatalogRowData;
use App\Support\ProductFields\CabinetProductMargin;
use App\Support\ProductFields\MarginToggle;
use App\Support\ProductFields\ProductColumnVisibility;
use App\Support\ProductFields\ProductPanelVisibility;
use App\Support\ProductTableLink;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

class ProductResource extends Resource
{
    use HasProductLightbox;

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $modelLabel = 'товар';

    protected static ?string $pluralModelLabel = 'Товари';

    protected static ?string $slug = 'products';

    public static function infolist(Infolist $infolist): Infolist
    {
        $panel = 'cabinet';
        $visible = ProductPanelVisibility::visibleDetailFields($panel);
        $contractor = auth('contractor')->user();

        $schema = [];

        if (in_array('variants', $visible, true)) {
            $schema[] = Infolists\Components\Section::make('Основне')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('active_variants')
                        ->label('')
                        ->getStateUsing(function (Product $record) use ($contractor) {
                            return $record->variants
                                ->where('is_active', true)
                                ->filter(fn (ProductVariant $v) => $v->priceFor($contractor) !== null)
                                ->values();
                        })
                        ->schema([
                            Infolists\Components\TextEntry::make('sku')
                                ->label('Артикул')
                                ->getStateUsing(fn (ProductVariant $record): string => $record->product->sku),

                            Infolists\Components\TextEntry::make('barcode_ean')
                                ->label('EAN')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('brand')
                                ->label('Бренд')
                                ->getStateUsing(fn (ProductVariant $record): ?string => $record->product->brand)
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('name')
                                ->label('Назва')
                                ->getStateUsing(fn (ProductVariant $record): string => $record->product->name),

                            Infolists\Components\TextEntry::make('category')
                                ->label('Категорія')
                                ->getStateUsing(fn (ProductVariant $record): ?string => $record->product->category?->name)
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('stock_status')
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

                            Infolists\Components\TextEntry::make('contractor_price')
                                ->label('Ваша ціна')
                                ->getStateUsing(function (ProductVariant $record) use ($contractor): ?string {
                                    $price = $record->priceFor($contractor);

                                    return $price !== null
                                        ? '₴ '.number_format($price, 2, '.', ' ')
                                        : null;
                                })
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('rrp')
                                ->label('РРЦ')
                                ->getStateUsing(function (ProductVariant $record): ?string {
                                    $rrp = $record->prices->first()?->recommended_retail_price;

                                    return $rrp !== null
                                        ? '₴ '.number_format((float) $rrp, 2, '.', ' ')
                                        : null;
                                })
                                ->placeholder('—')
                                ->extraAttributes(['class' => 'line-through text-gray-400']),

                            Infolists\Components\TextEntry::make('contractor_margin')
                                ->label(fn (): HtmlString => MarginToggle::labelHtml(
                                    Livewire::current()?->marginFormat ?? 'percent'
                                ))
                                ->getStateUsing(function (ProductVariant $record) use ($contractor): ?string {
                                    return CabinetProductMargin::formatted(
                                        $record->product,
                                        $contractor,
                                        Livewire::current()?->marginFormat ?? 'percent'
                                    );
                                })
                                ->color(function (ProductVariant $record) use ($contractor): ?string {
                                    return CabinetProductMargin::isNegative($record->product, $contractor) ? 'danger' : null;
                                })
                                ->placeholder('—'),
                        ])
                        ->columns(2),
                ]);
        }

        if (in_array('product_url', $visible, true)) {
            $schema[] = Infolists\Components\Section::make('Сайт')->schema([
                Infolists\Components\TextEntry::make('product_url')
                    ->label('URL товару на сайті')
                    ->placeholder('—')
                    ->url(fn (?string $state) => $state)
                    ->openUrlInNewTab()
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconColor('primary')
                    ->formatStateUsing(fn (?string $state) => $state
                        ? parse_url($state, PHP_URL_HOST).rtrim(parse_url($state, PHP_URL_PATH) ?? '', '/')
                        : null),

                Infolists\Components\TextEntry::make('photo_preview')
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

        return $infolist->schema($schema);
    }

    public static function table(Table $table): Table
    {
        $panel = 'cabinet';
        $visible = ProductPanelVisibility::visibleCatalogColumns($panel);
        $toggleable = ProductColumnVisibility::toggleableColumns($panel);
        $columns = [];

        if (in_array('photo', $visible, true)) {
            $columns[] = Tables\Columns\ImageColumn::make('first_image')
                ->label('Фото')
                ->state(fn (Product $record): ?string => self::firstImage($record))
                ->size(48)
                ->defaultImageUrl(fn () => 'data:image/svg+xml,'.rawurlencode(AdminProductResource::placeholderSvg(48)))
                ->extraImgAttributes(fn (Product $record): array => self::lightboxImgAttributes($record))
                ->toggleable(in_array('photo', $toggleable));
        }

        if (in_array('sku', $visible, true)) {
            $columns[] = Tables\Columns\TextColumn::make('sku')
                ->label('Артикул')
                ->searchable()
                ->sortable();
        }

        if (in_array('barcode_ean', $visible, true)) {
            $columns[] = Tables\Columns\TextColumn::make('barcode_ean')
                ->label('EAN')
                ->searchable()
                ->sortable()
                ->placeholder('—')
                ->toggleable(in_array('barcode_ean', $toggleable), isToggledHiddenByDefault: true);
        }

        if (in_array('name', $visible, true)) {
            $columns[] = Tables\Columns\TextColumn::make('name')
                ->label('Назва')
                ->searchable()
                ->sortable()
                ->limit(50)
                ->url(fn (Product $record): string => static::getUrl('view', ['record' => $record]));
        }

        if (in_array('category', $visible, true)) {
            $columns[] = Tables\Columns\TextColumn::make('category.name')
                ->label('Категорія')
                ->sortable()
                ->toggleable(in_array('category', $toggleable));
        }

        if (in_array('brand', $visible, true)) {
            $columns[] = Tables\Columns\TextColumn::make('brand')
                ->label('Бренд')
                ->searchable()
                ->sortable()
                ->toggleable(in_array('brand', $toggleable));
        }

        if (in_array('stock', $visible, true)) {
            $columns[] = Tables\Columns\TextColumn::make('stock_status')
                ->label('Наявність')
                ->getStateUsing(function (Product $record): string {
                    $contractor = auth('contractor')->user();
                    $data = CatalogRowData::forProduct($record, $contractor);

                    return $data['badge']['label'];
                })
                ->badge()
                ->color(function (Product $record): string {
                    $contractor = auth('contractor')->user();
                    $data = CatalogRowData::forProduct($record, $contractor);

                    return match ($data['badge']['color']) {
                        'success' => 'success',
                        'warning' => 'warning',
                        'info' => 'info',
                        default => 'gray',
                    };
                })
                ->sortable(query: fn (Builder $query, string $direction): Builder => self::applyStockSorting($query, $direction));
        }

        if (in_array('price', $visible, true)) {
            $columns[] = Tables\Columns\TextColumn::make('contractor_price')
                ->label('Ваша ціна')
                ->getStateUsing(function (Product $record): ?string {
                    $contractor = auth('contractor')->user();
                    $price = CatalogRowData::forProduct($record, $contractor)['myPrice'];

                    return $price !== null
                        ? '₴ '.number_format($price, 2, '.', ' ')
                        : null;
                })
                ->placeholder('—')
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    $contractorId = auth('contractor')->id();

                    return $query->orderByRaw(
                        "COALESCE((SELECT MIN(p.price_with_vat) FROM prices p
                            INNER JOIN product_variants pv ON p.variant_id = pv.id
                            WHERE pv.product_id = products.id
                            AND p.contractor_id = ?), 0) {$direction}",
                        [$contractorId]
                    );
                });
        }

        if (in_array('rrp', $visible, true)) {
            $columns[] = Tables\Columns\TextColumn::make('rrp')
                ->label('РРЦ')
                ->getStateUsing(function (Product $record): ?string {
                    $rrp = $record->maxRrp();

                    return $rrp !== null
                        ? '₴ '.number_format($rrp, 2, '.', ' ')
                        : null;
                })
                ->placeholder('—')
                ->toggleable(in_array('rrp', $toggleable))
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query->orderByRaw(
                        "COALESCE(
                            (SELECT MAX(pr.recommended_retail_price)
                             FROM prices pr
                             INNER JOIN product_variants pv ON pr.variant_id = pv.id
                             WHERE pv.product_id = products.id),
                            0
                        ) {$direction}"
                    );
                });
        }

        if (in_array('margin', $visible, true)) {
            $columns[] = Tables\Columns\TextColumn::make('margin')
                ->label(fn (): HtmlString => MarginToggle::labelHtml(
                    Livewire::current()?->marginFormat ?? 'percent'
                ))
                ->getStateUsing(function (Product $record): ?string {
                    $contractor = auth('contractor')->user();

                    return CabinetProductMargin::formatted(
                        $record,
                        $contractor,
                        Livewire::current()?->marginFormat ?? 'percent'
                    );
                })
                ->color(function (Product $record): ?string {
                    $contractor = auth('contractor')->user();

                    return CabinetProductMargin::isNegative($record, $contractor) ? 'danger' : null;
                })
                ->placeholder('—')
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    $contractorId = auth('contractor')->id();

                    return $query->orderByRaw(
                        "COALESCE((SELECT MAX(p.recommended_retail_price) - MIN(p.price_with_vat) FROM prices p
                            INNER JOIN product_variants pv ON p.variant_id = pv.id
                            WHERE pv.product_id = products.id
                            AND p.contractor_id = ?), 0) {$direction}",
                        [$contractorId]
                    );
                });
        }

        if (in_array('order', $visible, true)) {
            $columns[] = Tables\Columns\ViewColumn::make('order')
                ->label('Замовити')
                ->disableClick()
                ->view('filament.cabinet.columns.quantity-order');
        }

        if (in_array('url', $visible, true)) {
            $columns[] = Tables\Columns\TextColumn::make('product_url')
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
                fn (Tables\Actions\Action $action) => $action
                    ->label('Стовпці')
                    ->tooltip('Стовпці')
            )
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Категорії')
                    ->relationship('category', 'name')
                    ->multiple()
                    ->preload(),
                Tables\Filters\SelectFilter::make('brand')
                    ->label('Бренди')
                    ->options(fn (): array => Product::query()
                        ->where('is_active', true)
                        ->whereHas('variants.prices', fn ($q) => $q->where('contractor_id', auth('contractor')->id()))
                        ->distinct()
                        ->orderBy('brand')
                        ->whereNotNull('brand')
                        ->pluck('brand', 'brand')
                        ->toArray())
                    ->multiple(),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $contractorId = auth('contractor')->id();

        return parent::getEloquentQuery()
            ->where('is_active', true)
            ->whereHas('variants.prices', fn ($q) => $q->where('contractor_id', $contractorId))
            ->with([
                'category',
                'variants' => fn ($q) => $q->where('is_active', true),
                'variants.prices' => fn ($q) => $q->where('contractor_id', $contractorId),
                'variants.stocks',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'view' => Pages\ViewProduct::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
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
}
