<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Category;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'товар';

    protected static ?string $pluralModelLabel = 'Товари';

    protected static ?int $navigationSort = 3;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns extra <img> attributes for thumbnail that opens the shared JS lightbox
     * (bpOpenLightbox injected by AdminPanelProvider::BODY_END renderHook).
     *
     * @return array<string, string>
     */
    public static function lightboxImgAttributes(Product $record): array
    {
        $url = self::firstImage($record);

        if (! $url) {
            return [
                'class' => 'rounded object-cover',
                'style' => 'cursor: default;',
            ];
        }

        $safe  = e($url);
        $title = e($record->name);

        return [
            'class'   => 'rounded object-cover',
            'style'   => 'cursor: zoom-in;',
            'title'   => 'Натисніть для збільшення',
            'onclick' => "event.stopPropagation();event.preventDefault();bpOpenLightbox('{$safe}','{$title}')",
        ];
    }

    /** Returns the first image URL from a product's images JSON, or null. */
    public static function firstImage(Product $record): ?string
    {
        $images = $record->images;

        if (is_string($images)) {
            $images = json_decode($images, true);
        }

        return is_array($images) && count($images) > 0 ? $images[0] : null;
    }

    /** Compute stock status string for a product using category threshold logic. */
    protected static function computeStockStatus(Product $record): string
    {
        $threshold    = $record->category?->stock_display_threshold ?? 10;
        $totalQty     = 0;
        $expectedDate = null;

        foreach ($record->variants as $variant) {
            foreach ($variant->stocks as $stock) {
                $totalQty += (int) $stock->quantity;
                if ($stock->expected_date && $expectedDate === null) {
                    $expectedDate = $stock->expected_date;
                }
            }
        }

        if ($totalQty > $threshold) {
            return 'В наявності';
        }
        if ($totalQty > 0) {
            return "Залишилось {$totalQty} шт";
        }
        if ($expectedDate) {
            return 'Очікується ' . $expectedDate->format('d.m');
        }

        return 'Немає в наявності';
    }

    /** Compute maximum RRP string for a product, or null when not available. */
    protected static function computeRrp(Product $record): ?string
    {
        $maxRrp = null;

        foreach ($record->variants as $variant) {
            foreach ($variant->prices as $price) {
                if (
                    $price->recommended_retail_price !== null
                    && ($maxRrp === null || $price->recommended_retail_price > $maxRrp)
                ) {
                    $maxRrp = (float) $price->recommended_retail_price;
                }
            }
        }

        return $maxRrp !== null
            ? '₴ ' . number_format($maxRrp, 2, '.', ' ')
            : null;
    }

    // -------------------------------------------------------------------------
    // Form (edit)
    // -------------------------------------------------------------------------

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основне (з 1С)')->schema([
                    Forms\Components\TextInput::make('sku')
                        ->label('Артикул')
                        ->disabled(),
                    Forms\Components\TextInput::make('name')
                        ->label('Назва')
                        ->disabled(),
                    Forms\Components\TextInput::make('brand')
                        ->label('Бренд')
                        ->disabled(),
                    Forms\Components\TextInput::make('category.name')
                        ->label('Категорія')
                        ->disabled(),
                ])->columns(2),

                Forms\Components\Section::make('Сайт')->schema([
                    Forms\Components\Group::make([
                        Forms\Components\TextInput::make('product_url')
                            ->label('URL товару на сайті')
                            ->url()
                            ->placeholder('https://babypark.ua/product/...')
                            ->maxLength(2048)
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('open_url')
                                    ->icon('heroicon-m-arrow-top-right-on-square')
                                    ->url(fn (?string $state) => $state)
                                    ->openUrlInNewTab()
                                    ->visible(fn (?string $state) => filled($state))
                            ),
                    ]),

                    Forms\Components\Group::make([
                        Forms\Components\Placeholder::make('photo_preview')
                            ->label('Фото товару')
                            ->content(fn (Forms\Get $get, ?Product $record) => new \Illuminate\Support\HtmlString(
                                $record
                                    ? self::buildPhotoPreviewHtml($record)
                                    : self::buildPhotoPlaceholderHtml()
                            )),
                    ]),
                ])->columns(2),
            ]);
    }

    // -------------------------------------------------------------------------
    // Infolist (view)
    // -------------------------------------------------------------------------

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Основне (з 1С)')->schema([
                    Infolists\Components\TextEntry::make('sku')
                        ->label('Артикул'),

                    Infolists\Components\TextEntry::make('name')
                        ->label('Назва'),

                    Infolists\Components\TextEntry::make('brand')
                        ->label('Бренд')
                        ->placeholder('—'),

                    // Stock status after "Бренд"
                    Infolists\Components\TextEntry::make('stock_status')
                        ->label('Наявність')
                        ->getStateUsing(fn (Product $record): string => self::computeStockStatus($record))
                        ->badge()
                        ->color(fn (string $state): string => match (true) {
                            $state === 'В наявності'                 => 'success',
                            str_starts_with($state, 'Залишилось')    => 'warning',
                            str_starts_with($state, 'Очікується')    => 'info',
                            default                                  => 'gray',
                        }),

                    Infolists\Components\TextEntry::make('category.name')
                        ->label('Категорія')
                        ->placeholder('—'),

                    // RRP after "Категорія"
                    Infolists\Components\TextEntry::make('rrp')
                        ->label('РРЦ')
                        ->getStateUsing(fn (Product $record): ?string => self::computeRrp($record))
                        ->placeholder('—'),

                    // Photo thumbnail (full width)
                    Infolists\Components\TextEntry::make('photo_preview')
                        ->label('Фото товару')
                        ->columnSpanFull()
                        ->getStateUsing(function ($record) {
                            if (! $record) {
                                return new \Illuminate\Support\HtmlString('');
                            }
                            $url = self::firstImage($record);

                            if ($url) {
                                $safe  = e($url);
                                $title = e($record->name);

                                return new \Illuminate\Support\HtmlString(
                                    '<img src="' . $safe . '"' .
                                    ' style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;cursor:zoom-in;"' .
                                    ' title="Натисніть для збільшення"' .
                                    ' onclick="bpOpenLightbox(\'' . $safe . '\',\'' . $title . '\')" />'
                                );
                            }

                            return new \Illuminate\Support\HtmlString(
                                '<div style="width:48px;height:48px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;display:flex;align-items:center;justify-content:center;">' .
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
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('first_image')
                    ->label('')
                    ->state(fn (Product $record): ?string => self::firstImage($record))
                    ->size(48)
                    ->defaultImageUrl(fn () => 'data:image/svg+xml,' . rawurlencode(self::placeholderSvg(48)))
                    ->extraImgAttributes(fn (Product $record): array => self::lightboxImgAttributes($record)),

                Tables\Columns\TextColumn::make('sku')
                    ->label('Артикул')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Категорія')
                    ->sortable(),

                Tables\Columns\TextColumn::make('brand')
                    ->label('Бренд')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('rrp')
                    ->label('РРЦ')
                    ->getStateUsing(fn (Product $record): ?string => self::computeRrp($record))
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('stock_status')
                    ->label('Наявність')
                    ->getStateUsing(fn (Product $record): string => self::computeStockStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        $state === 'В наявності'               => 'success',
                        str_starts_with($state, 'Залишилось')  => 'warning',
                        str_starts_with($state, 'Очікується')  => 'info',
                        default                                => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
            ])
            ->defaultSort('sku')
            ->filters([
                // Multi-select checkbox filter for categories
                Tables\Filters\Filter::make('categories')
                    ->form([
                        Forms\Components\CheckboxList::make('category_ids')
                            ->label('Категорії')
                            ->options(fn (): array => Category::orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
                            ->columns(2),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $ids = $data['category_ids'] ?? [];

                        return $query->when(
                            ! empty($ids),
                            fn (Builder $q) => $q->whereIn('category_id', $ids)
                        );
                    })
                    ->indicateUsing(function (array $data): array {
                        $ids = $data['category_ids'] ?? [];
                        if (empty($ids)) {
                            return [];
                        }

                        $names = Category::whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ');

                        return ["Категорії: {$names}"];
                    }),

                // Multi-select checkbox filter for brands
                Tables\Filters\Filter::make('brands')
                    ->form([
                        Forms\Components\CheckboxList::make('brand_values')
                            ->label('Бренди')
                            ->options(fn (): array => Product::whereNotNull('brand')
                                ->distinct()
                                ->orderBy('brand')
                                ->pluck('brand', 'brand')
                                ->toArray())
                            ->columns(2),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $brands = $data['brand_values'] ?? [];

                        return $query->when(
                            ! empty($brands),
                            fn (Builder $q) => $q->whereIn('brand', $brands)
                        );
                    })
                    ->indicateUsing(function (array $data): array {
                        $brands = $data['brand_values'] ?? [];
                        if (empty($brands)) {
                            return [];
                        }

                        return ['Бренди: ' . implode(', ', $brands)];
                    }),

                // Sort options
                Tables\Filters\Filter::make('sort_by')
                    ->form([
                        Forms\Components\Select::make('sort_option')
                            ->label('Сортування')
                            ->placeholder('За замовчуванням')
                            ->options([
                                'rrp_asc'    => 'РРЦ: від меншого до більшого',
                                'rrp_desc'   => 'РРЦ: від більшого до меншого',
                                'stock_first' => 'Наявність: спочатку в наявності',
                            ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $option = $data['sort_option'] ?? null;
                        if (! $option) {
                            return $query;
                        }

                        // Correlated subquery for max RRP across all variants of a product
                        $rrpSubq = '(SELECT MAX(p.recommended_retail_price)'
                            . ' FROM prices p'
                            . ' JOIN product_variants pv ON pv.id = p.variant_id'
                            . ' WHERE pv.product_id = products.id)';

                        // Correlated subquery for total stock quantity
                        $stockSubq = '(SELECT COALESCE(SUM(s.quantity), 0)'
                            . ' FROM stocks s'
                            . ' JOIN product_variants pv ON pv.id = s.variant_id'
                            . ' WHERE pv.product_id = products.id)';

                        match ($option) {
                            // ISNULL() = 0 for non-null → non-null rows sort first; then by value asc
                            'rrp_asc'    => $query
                                ->orderByRaw("ISNULL({$rrpSubq}) ASC")
                                ->orderByRaw("{$rrpSubq} ASC"),

                            // NULLs still last (ISNULL ASC), then highest RRP first
                            'rrp_desc'   => $query
                                ->orderByRaw("ISNULL({$rrpSubq}) ASC")
                                ->orderByRaw("{$rrpSubq} DESC"),

                            // Most stock first
                            'stock_first' => $query->orderByRaw("{$stockSubq} DESC"),

                            default => null,
                        };

                        return $query;
                    })
                    ->indicateUsing(function (array $data): array {
                        return match ($data['sort_option'] ?? null) {
                            'rrp_asc'    => ['Сортування: РРЦ ↑'],
                            'rrp_desc'   => ['Сортування: РРЦ ↓'],
                            'stock_first' => ['Сортування: в наявності спочатку'],
                            default      => [],
                        };
                    }),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Активний'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    // -------------------------------------------------------------------------
    // Eager loading
    // -------------------------------------------------------------------------

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->with(['variants.stocks', 'variants.prices', 'category']);
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'view'  => Pages\ViewProduct::route('/{record}'),
            'edit'  => Pages\EditProduct::route('/{record}/edit'),
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

    public static function buildPhotoPlaceholderHtml(): string
    {
        return <<<HTML
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
        $alt  = e($record->name);

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
     * HTML for the photo entry inside an infolist TextEntry.
     * Shows the image with a lightbox-style link; shows the styled placeholder when no image.
     */
    public static function buildPhotoInfolstHtml(Product $record): string
    {
        $url = self::firstImage($record);

        if (! $url) {
            return self::buildPhotoPlaceholderHtml();
        }

        $safe = e($url);
        $alt  = e($record->name);

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
}
