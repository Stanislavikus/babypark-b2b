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
                ])->columns(3),
                Forms\Components\Section::make('Контент (редагується)')->schema([
                    Forms\Components\Textarea::make('description')
                        ->label('Опис')
                        ->rows(6)
                        ->columnSpanFull(),
                    Forms\Components\TagsInput::make('images')
                        ->label('URL зображень')
                        ->placeholder('Додати URL')
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('meta_title')
                        ->label('Meta title')
                        ->maxLength(255),
                    Forms\Components\Textarea::make('meta_description')
                        ->label('Meta description')
                        ->rows(3)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('rozetka_category_id')
                        ->label('Rozetka category ID')
                        ->numeric(),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
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
