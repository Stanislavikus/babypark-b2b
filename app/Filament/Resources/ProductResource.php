<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;

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

    /** Returns the first image URL from a product's images JSON, or null. */
    private static function firstImage(Product $record): ?string
    {
        $images = $record->images;

        return is_array($images) && count($images) > 0 ? $images[0] : null;
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
                            ->content(fn (Forms\Get $get, ?Product $record) => $record
                                ? new \Illuminate\Support\HtmlString(
                                    self::buildPhotoPreviewHtml($record)
                                )
                                : new \Illuminate\Support\HtmlString('<span class="text-sm text-gray-400">Зображення відсутнє</span>')
                            ),
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
                    Infolists\Components\TextEntry::make('sku')->label('Артикул'),
                    Infolists\Components\TextEntry::make('name')->label('Назва'),
                    Infolists\Components\TextEntry::make('brand')->label('Бренд')->placeholder('—'),
                    Infolists\Components\TextEntry::make('category.name')->label('Категорія')->placeholder('—'),
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
                            ? parse_url($state, PHP_URL_HOST) . rtrim(parse_url($state, PHP_URL_PATH) ?? '', '/')
                            : null),

                    // Right: photo preview (infolist Placeholder)
                    Infolists\Components\ViewEntry::make('photo_preview')
                        ->label('Фото товару')
                        ->view('filament.product-photo-entry'),
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
                // 48×48 thumbnail — click opens lightbox (image only, max 600px)
                Tables\Columns\ImageColumn::make('first_image')
                    ->label('')
                    ->state(fn (Product $record): ?string => self::firstImage($record))
                    ->size(48)
                    ->defaultImageUrl(fn () => 'data:image/svg+xml,' . rawurlencode(self::placeholderSvg(48)))
                    ->extraImgAttributes(['class' => 'cursor-pointer rounded object-cover'])
                    ->action(
                        Tables\Actions\Action::make('view_image')
                            ->modalHeading(fn (Product $record) => $record->name)
                            ->modalWidth(MaxWidth::Large)
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Закрити')
                            ->modalContent(fn (Product $record) => view(
                                'filament.product-image-lightbox',
                                ['url' => self::firstImage($record), 'alt' => $record->name]
                            ))
                            ->visible(fn (Product $record) => filled(self::firstImage($record)))
                    ),

                Tables\Columns\TextColumn::make('sku')
                    ->label('Артикул')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Категорія')
                    ->sortable(),

                Tables\Columns\TextColumn::make('brand')
                    ->label('Бренд')
                    ->searchable(),

                // Clickable link column
                Tables\Columns\TextColumn::make('product_url')
                    ->label('URL на сайті')
                    ->url(fn (?string $state) => $state)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconColor('primary')
                    ->limit(35)
                    ->tooltip(fn (?string $state) => $state),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
            ])
            ->defaultSort('sku')
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Категорія')
                    ->relationship('category', 'name'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Активний'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
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

    /** HTML snippet for the photo preview Placeholder in the edit form. */
    public static function buildPhotoPreviewHtml(Product $record): string
    {
        $url = self::firstImage($record);

        if (! $url) {
            return '<span class="text-sm text-gray-400">Зображення відсутнє</span>';
        }

        $safe = e($url);
        $alt  = e($record->name);

        return <<<HTML
<a href="{$safe}" target="_blank" rel="noopener" title="Відкрити у новій вкладці">
  <img src="{$safe}" alt="{$alt}"
       style="max-width:200px; max-height:200px; width:auto; height:auto; border-radius:6px; border:1px solid #e5e7eb; object-fit:contain; background:#f9fafb;"
       onerror="this.outerHTML='<span class=\'text-sm text-gray-400\'>Не вдалося завантажити зображення</span>'"
  />
</a>
HTML;
    }
}
