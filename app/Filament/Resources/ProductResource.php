<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
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
                        )
                        ->columnSpanFull(),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('first_image')
                    ->label('')
                    ->state(fn (Product $record): ?string => is_array($record->images) && count($record->images) > 0
                        ? $record->images[0]
                        : null
                    )
                    ->size(48)
                    ->defaultImageUrl(fn () => 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24"><rect width="24" height="24" rx="4" fill="#f3f4f6"/><path d="M8 8h8v8H8z" fill="#d1d5db"/><circle cx="10" cy="10.5" r="1.5" fill="#9ca3af"/><path d="m8 14 3-3 2 2 2-3 3 4H8z" fill="#9ca3af"/></svg>'))
                    ->extraImgAttributes(['class' => 'cursor-pointer rounded'])
                    ->action(
                        Tables\Actions\Action::make('view_image')
                            ->modalHeading(fn (Product $record) => $record->name)
                            ->modalWidth('md')
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Закрити')
                            ->modalContent(fn (Product $record) => view(
                                'filament.product-image-modal',
                                ['images' => is_array($record->images) ? $record->images : []]
                            ))
                            ->visible(fn (Product $record) => is_array($record->images) && count($record->images) > 0)
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
                Tables\Columns\TextColumn::make('product_url')
                    ->label('URL на сайті')
                    ->url(fn (?string $state) => $state)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->limit(40)
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'view' => Pages\ViewProduct::route('/{record}'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
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
}
