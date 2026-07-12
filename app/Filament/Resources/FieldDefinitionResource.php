<?php

namespace App\Filament\Resources;

use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Filament\Resources\FieldDefinitionResource\Pages;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FieldDefinitionResource extends Resource
{
    protected static ?string $model = FieldDefinition::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Система';

    protected static ?string $navigationLabel = 'Поля товару';

    protected static ?string $modelLabel = 'поле товару';

    protected static ?string $pluralModelLabel = 'Поля товару';

    protected static ?int $navigationSort = 11;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['fieldBindings'])
            ->whereHas('fieldBindings', fn (Builder $query) => $query->whereIn('object_type', [
                FieldObjectType::Product,
                FieldObjectType::ProductVariant,
            ]));
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Видимість')
                    ->schema([
                        Forms\Components\Toggle::make('visibility_settings.admin')
                            ->label('Показувати в адмінці')
                            ->disabled(fn (FieldDefinition $record): bool => $record->visibilityRestricted())
                            ->dehydrated(fn (FieldDefinition $record): bool => ! $record->visibilityRestricted()),

                        Forms\Components\Toggle::make('visibility_settings.b2b')
                            ->label('Показувати в B2B')
                            ->disabled(fn (FieldDefinition $record): bool => $record->visibilityRestricted())
                            ->dehydrated(fn (FieldDefinition $record): bool => ! $record->visibilityRestricted()),
                    ])
                    ->columns(2)
                    ->visible(fn (?FieldDefinition $record): bool => $record !== null),

                static::bindingSection('Товар', FieldObjectType::Product),
                static::bindingSection('Варіант', FieldObjectType::ProductVariant),
            ]);
    }

    private static function bindingSection(string $label, FieldObjectType $objectType): Forms\Components\Section
    {
        return Forms\Components\Section::make($label)
            ->schema([
                Forms\Components\TextInput::make("binding_{$objectType->value}.storage_path")
                    ->label('Шлях зберігання')
                    ->disabled(),

                Forms\Components\TextInput::make("binding_{$objectType->value}.field_group")
                    ->label('Група')
                    ->formatStateUsing(fn (?string $state): string => config('attribute_dictionary.groups.'.$state, $state ?? '—'))
                    ->disabled(),

                Forms\Components\Toggle::make("binding_{$objectType->value}.is_required")
                    ->label('Обов\'язкове'),

                Forms\Components\Toggle::make("binding_{$objectType->value}.is_filterable")
                    ->label('Фільтрувати'),

                Forms\Components\Toggle::make("binding_{$objectType->value}.is_sortable")
                    ->label('Сортувати'),

                Forms\Components\TextInput::make("binding_{$objectType->value}.sort_order")
                    ->label('Порядок')
                    ->numeric(),
            ])
            ->columns(2)
            ->visible(fn (?FieldDefinition $record): bool => $record?->fieldBindings
                ->contains(fn (FieldBinding $binding): bool => $binding->object_type === $objectType) ?? false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('localized_label')
                    ->label('Назва поля')
                    ->state(fn (FieldDefinition $record): string => $record->localizedLabel())
                    ->searchable(query: function ($query, string $search) {
                        $query->where('localized_labels->uk', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    })
                    ->sortable(query: function ($query, string $direction) {
                        $query->orderBy(
                            FieldBinding::query()
                                ->select('sort_order')
                                ->whereColumn('field_bindings.field_definition_id', 'field_definitions.id')
                                ->whereIn('object_type', [FieldObjectType::Product, FieldObjectType::ProductVariant])
                                ->orderBy('sort_order')
                                ->limit(1),
                            $direction
                        );
                    }),

                Tables\Columns\TextColumn::make('data_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state)
                    ->sortable(),

                Tables\Columns\TextColumn::make('computed_value_level')
                    ->label('Product / Variant / Both')
                    ->state(fn (FieldDefinition $record): string => $record->computedValueLevelLabel()),

                Tables\Columns\TextColumn::make('field_group')
                    ->label('Група')
                    ->state(function (FieldDefinition $record): string {
                        $group = $record->fieldBindings
                            ->first(fn (FieldBinding $b) => in_array($b->object_type, [FieldObjectType::Product, FieldObjectType::ProductVariant], true))
                            ?->field_group;

                        return config('attribute_dictionary.groups.'.$group, $group ?? '—');
                    }),

                Tables\Columns\TextColumn::make('scope')
                    ->label('Походження поля')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state)
                    ->sortable(),

                Tables\Columns\IconColumn::make('visibility_settings.admin')
                    ->label('Показувати в адмінці')
                    ->boolean()
                    ->state(fn (FieldDefinition $record): bool => (bool) ($record->fieldBindings->first()?->visibility_settings['admin'] ?? false)),

                Tables\Columns\IconColumn::make('visibility_settings.b2b')
                    ->label('Показувати в B2B')
                    ->boolean()
                    ->state(fn (FieldDefinition $record): bool => (bool) ($record->fieldBindings->first()?->visibility_settings['b2b'] ?? false)),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn ($state) => $state === AttributeStatus::Active ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state)
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                Tables\Filters\SelectFilter::make('scope')
                    ->label('Походження поля')
                    ->options(collect(AttributeScope::cases())->mapWithKeys(fn (AttributeScope $scope) => [$scope->value => $scope->label()])->all()),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(AttributeStatus::cases())->mapWithKeys(fn (AttributeStatus $status) => [$status->value => $status->label()])->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFieldDefinitions::route('/'),
            'edit' => Pages\EditFieldDefinition::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return $record instanceof FieldDefinition
            && $record->scope !== AttributeScope::System;
    }
}
