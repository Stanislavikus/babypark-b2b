<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HasProductLightbox;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use App\Support\ProductFields\ProductColumnVisibility;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ProductResource extends Resource
{
    use HasProductLightbox;

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'товар';

    protected static ?string $pluralModelLabel = 'Товари';

    protected static ?int $navigationSort = 3;

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
                    // Left column: URL field
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

                    // Right column: photo preview
                    Forms\Components\Group::make([
                        Forms\Components\Placeholder::make('photo_preview')
                            ->label('Фото товару')
                            ->content(fn (Forms\Get $get, ?Product $record) => new HtmlString(
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
                    // Row 1: Артикул | Назва
                    Infolists\Components\TextEntry::make('sku')->label('Артикул'),
                    Infolists\Components\TextEntry::make('name')->label('Назва'),
                    // Row 2: Бренд | Наявність (real admin quantity, no threshold)
                    Infolists\Components\TextEntry::make('brand')->label('Бренд')->placeholder('—'),
                    Infolists\Components\TextEntry::make('admin_stock_status')
                        ->label('Наявність')
                        ->getStateUsing(function (Product $record): string {
                            $totalQty = 0;
                            $earliestExpectedDate = null;

                            foreach ($record->variants as $variant) {
                                foreach ($variant->stocks as $stock) {
                                    $totalQty += (int) $stock->quantity;
                                    if ($stock->expected_date !== null && ($earliestExpectedDate === null || $stock->expected_date < $earliestExpectedDate)) {
                                        $earliestExpectedDate = $stock->expected_date;
                                    }
                                }
                            }

                            if ($totalQty > 0) {
                                return "У наявності: {$totalQty} шт";
                            }
                            if ($earliestExpectedDate) {
                                return 'Очікується '.$earliestExpectedDate->format('d.m');
                            }

                            return 'Немає в наявності';
                        })
                        ->badge()
                        ->color(fn (string $state): string => match (true) {
                            str_starts_with($state, 'У наявності') => 'success',
                            str_starts_with($state, 'Очікується') => 'info',
                            default => 'gray',
                        }),
                    // Row 3: Категорія | РРЦ
                    Infolists\Components\TextEntry::make('category.name')->label('Категорія')->placeholder('—'),
                    Infolists\Components\TextEntry::make('admin_rrp')
                        ->label('РРЦ')
                        ->getStateUsing(function (Product $record): ?string {
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
                                ? '₴ '.number_format($maxRrp, 2, '.', ' ')
                                : null;
                        })
                        ->placeholder('—'),
                    // Row 4: Статус | (empty)
                    Infolists\Components\TextEntry::make('admin_status')
                        ->label('Статус')
                        ->getStateUsing(fn (Product $record): string => $record->is_active ? 'Активний' : 'Неактивний')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Активний' ? 'success' : 'gray'),
                ])->columns(2),

                Infolists\Components\Section::make('Сайт')->schema([
                    // Left: clickable URL
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

                    // Right: 48×48 thumbnail — click opens the shared bpOpenLightbox() JS overlay.
                    // HtmlString bypasses Filament's sanitisation (triggered by ->html()) that
                    // would strip event handlers; Htmlable rendering via {{ }} keeps them intact.
                    Infolists\Components\TextEntry::make('photo_preview')
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
                // 48×48 thumbnail — toggleable; click opens the shared bpOpenLightbox() JS overlay
                Tables\Columns\ImageColumn::make('first_image')
                    ->label('Фото')
                    ->state(fn (Product $record): ?string => self::firstImage($record))
                    ->size(48)
                    ->defaultImageUrl(fn () => 'data:image/svg+xml,'.rawurlencode(self::placeholderSvg(48)))
                    ->extraImgAttributes(fn (Product $record): array => self::lightboxImgAttributes($record))
                    ->toggleable(in_array('photo', $toggleable)),

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
                    ->sortable()
                    ->toggleable(in_array('category', $toggleable)),

                Tables\Columns\TextColumn::make('brand')
                    ->label('Бренд')
                    ->searchable()
                    ->sortable()
                    ->toggleable(in_array('brand', $toggleable)),

                Tables\Columns\TextColumn::make('rrp')
                    ->label('РРЦ')
                    ->getStateUsing(function (Product $record): ?string {
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
                            ? '₴ '.number_format($maxRrp, 2, '.', ' ')
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
                    }),

                Tables\Columns\TextColumn::make('stock_status')
                    ->label('Наявність')
                    ->getStateUsing(function (Product $record): string {
                        $totalQty = 0;
                        $earliestExpectedDate = null;

                        foreach ($record->variants as $variant) {
                            foreach ($variant->stocks as $stock) {
                                $totalQty += (int) $stock->quantity;
                                if ($stock->expected_date !== null && ($earliestExpectedDate === null || $stock->expected_date < $earliestExpectedDate)) {
                                    $earliestExpectedDate = $stock->expected_date;
                                }
                            }
                        }

                        if ($totalQty > 0) {
                            return "У наявності: {$totalQty} шт";
                        }
                        if ($earliestExpectedDate) {
                            return 'Очікується '.$earliestExpectedDate->format('d.m');
                        }

                        return 'Немає в наявності';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'У наявності') => 'success',
                        str_starts_with($state, 'Очікується') => 'info',
                        default => 'gray',
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        // Subquery expressions reused across ORDER BY clauses.
                        $totalQty = '(SELECT COALESCE(SUM(s.quantity), 0)
                                      FROM stocks s
                                      INNER JOIN product_variants pv ON s.variant_id = pv.id
                                      WHERE pv.product_id = products.id)';

                        $minExpectedDate = '(SELECT MIN(s.expected_date)
                                            FROM stocks s
                                            INNER JOIN product_variants pv ON s.variant_id = pv.id
                                            WHERE pv.product_id = products.id)';

                        // Priority bucket: 0 = У наявності, 1 = Очікується, 2 = Немає в наявності.
                        // Sorting by $direction means asc→best-first, desc→worst-first.
                        $priorityExpr = "CASE
                            WHEN {$totalQty} > 0 THEN 0
                            WHEN {$minExpectedDate} IS NOT NULL THEN 1
                            ELSE 2
                        END";

                        return $query
                            ->orderByRaw("{$priorityExpr} {$direction}")
                            // Within "У наявності" bucket: more stock first (fixed DESC).
                            ->orderByRaw("{$totalQty} DESC")
                            // Within "Очікується" bucket: soonest arrival first (fixed ASC).
                            ->orderByRaw("{$minExpectedDate} ASC");
                    }),

                // Clickable link column
                Tables\Columns\TextColumn::make('product_url')
                    ->label('URL на сайті')
                    ->url(fn (?string $state) => $state)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconColor('primary')
                    ->limit(35)
                    ->tooltip(fn (?string $state) => $state)
                    ->toggleable(in_array('url', $toggleable), isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Статус')
                    ->boolean()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        // Secondary sort by id keeps rows stable when all visible values are identical
                        // (e.g. when a status filter is active and every row shares the same is_active value).
                        return $query->orderBy('is_active', $direction)->orderBy('id', $direction);
                    }),
            ])
            ->defaultSort('sku')
            ->recordUrl(fn (Product $record): string => static::getUrl('view', ['record' => $record]))
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Категорії')
                    ->relationship('category', 'name')
                    ->multiple()
                    ->preload(),
                Tables\Filters\SelectFilter::make('brand')
                    ->label('Бренди')
                    ->options(fn (): array => Product::query()
                        ->distinct()
                        ->orderBy('brand')
                        ->whereNotNull('brand')
                        ->pluck('brand', 'brand')
                        ->toArray())
                    ->multiple(),
                Tables\Filters\SelectFilter::make('status')
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
            ])
            ->actions([])
            ->bulkActions([]);
    }

    // -------------------------------------------------------------------------
    // Eager loading
    // -------------------------------------------------------------------------

    public static function getEloquentQuery(): Builder
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
     * HTML for the "no image" placeholder — styled to match other infolist/form fields
     * (rounded border, muted background, consistent with Filament's field appearance).
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
}
