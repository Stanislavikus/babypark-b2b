<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'категорія';

    protected static ?string $pluralModelLabel = 'Категорії';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Назва')
                    ->disabled(),
                Forms\Components\TextInput::make('stock_display_threshold')
                    ->label('Поріг відображення залишку')
                    ->helperText('Якщо залишок ≤ порогу — показувати точну кількість')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(10),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Батьківська')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('stock_display_threshold')
                    ->label('Поріг залишку')
                    ->sortable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->label('Товарів')
                    ->counts('products'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
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
