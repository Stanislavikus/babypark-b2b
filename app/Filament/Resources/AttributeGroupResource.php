<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttributeGroupResource\Pages\CreateAttributeGroup;
use App\Filament\Resources\AttributeGroupResource\Pages\EditAttributeGroup;
use App\Filament\Resources\AttributeGroupResource\Pages\ListAttributeGroups;
use App\Models\AttributeGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttributeGroupResource extends Resource
{
    protected static ?string $model = AttributeGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'група атрибутів';

    protected static ?string $pluralModelLabel = 'Групи атрибутів';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основне')->schema([
                TextInput::make('code')->label('Код')->required()->alphaDash()->maxLength(255)
                    ->disabled(fn (?AttributeGroup $record): bool => $record !== null)->dehydrated(),
                TextInput::make('label_uk')->label('Назва (UK)')->required()->maxLength(255),
                TextInput::make('label_en')->label('Назва (EN)')->required()->maxLength(255),
                Textarea::make('description')->label('Опис')->rows(3)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('localized_labels')->label('Назва')
                ->formatStateUsing(fn ($state, AttributeGroup $record): string => (string) ($record->localized_labels['uk'] ?? $record->localized_labels['en'] ?? $record->code)),
            TextColumn::make('code')->label('Код')->sortable(),
            TextColumn::make('product_type_placements_count')->label('Типів товарів')->counts('productTypePlacements'),
        ])->defaultSort('code')->recordActions([EditAction::make()])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttributeGroups::route('/'),
            'create' => CreateAttributeGroup::route('/create'),
            'edit' => EditAttributeGroup::route('/{record}/edit'),
        ];
    }
}
