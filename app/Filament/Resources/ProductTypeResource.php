<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductTypeResource\Pages\CreateProductType;
use App\Filament\Resources\ProductTypeResource\Pages\EditProductType;
use App\Filament\Resources\ProductTypeResource\Pages\ListProductTypes;
use App\Filament\Resources\ProductTypeResource\RelationManagers\FieldPlacementsRelationManager;
use App\Filament\Resources\ProductTypeResource\RelationManagers\GroupPlacementsRelationManager;
use App\Models\ProductType;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductTypeResource extends Resource
{
    protected static ?string $model = ProductType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'тип товару';

    protected static ?string $pluralModelLabel = 'Типи товарів';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основне')->schema([
                TextInput::make('code')
                    ->label('Код')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->disabled(fn (?ProductType $record): bool => $record !== null)
                    ->dehydrated(),
                TextInput::make('label_uk')
                    ->label('Назва (UK)')
                    ->required()
                    ->maxLength(255),
                TextInput::make('label_en')
                    ->label('Назва (EN)')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Опис')
                    ->rows(3)
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('localized_labels')
                    ->label('Назва')
                    ->formatStateUsing(fn ($state, ProductType $record): string => (string) ($record->localized_labels['uk'] ?? $record->localized_labels['en'] ?? $record->code))
                    ->searchable(query: fn ($query, string $search) => $query->where('code', 'like', "%{$search}%")),
                TextColumn::make('code')->label('Код')->sortable(),
                TextColumn::make('is_default')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Системний' : 'Власний'),
                TextColumn::make('structure_revision')->label('Ревізія')->sortable(),
            ])
            ->defaultSort('is_default', 'desc')
            ->recordActions([
                EditAction::make()->visible(fn (ProductType $record): bool => ! $record->is_default),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            GroupPlacementsRelationManager::class,
            FieldPlacementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductTypes::route('/'),
            'create' => CreateProductType::route('/create'),
            'edit' => EditProductType::route('/{record}/edit'),
        ];
    }
}
