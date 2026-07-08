<?php

namespace App\Filament\Resources;

use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Filament\Resources\AttributeDefinitionResource\Pages;
use App\Models\AttributeDefinition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AttributeDefinitionResource extends Resource
{
    protected static ?string $model = AttributeDefinition::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Система';

    protected static ?string $navigationLabel = 'Поля товару';

    protected static ?string $modelLabel = 'поле товару';

    protected static ?string $pluralModelLabel = 'Поля товару';

    protected static ?int $navigationSort = 11;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Видимість')
                    ->schema([
                        Forms\Components\Toggle::make('visibility_settings.admin')
                            ->label('Показувати в адмінці')
                            ->disabled(fn (AttributeDefinition $record): bool => $record->visibilityRestricted())
                            ->dehydrated(fn (AttributeDefinition $record): bool => ! $record->visibilityRestricted()),

                        Forms\Components\Toggle::make('visibility_settings.b2b')
                            ->label('Показувати в B2B')
                            ->disabled(fn (AttributeDefinition $record): bool => $record->visibilityRestricted())
                            ->dehydrated(fn (AttributeDefinition $record): bool => ! $record->visibilityRestricted()),
                    ])
                    ->columns(2)
                    ->visible(fn (?AttributeDefinition $record): bool => $record !== null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('localized_label')
                    ->label('Назва поля')
                    ->state(fn (AttributeDefinition $record): string => $record->localizedLabel())
                    ->searchable(query: function ($query, string $search) {
                        $query->where('localized_labels->uk', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    })
                    ->sortable(query: function ($query, string $direction) {
                        $query->orderBy('sort_order', $direction);
                    }),

                Tables\Columns\TextColumn::make('data_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state),

                Tables\Columns\TextColumn::make('value_level')
                    ->label('Рівень')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state),

                Tables\Columns\TextColumn::make('attribute_group')
                    ->label('Група')
                    ->formatStateUsing(fn (?string $state): string => config('attribute_dictionary.groups.'.$state, $state ?? '—')),

                Tables\Columns\TextColumn::make('scope')
                    ->label('Джерело')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state),

                Tables\Columns\IconColumn::make('visibility_settings.admin')
                    ->label('Показувати в адмінці')
                    ->boolean()
                    ->state(fn (AttributeDefinition $record): bool => (bool) ($record->visibility_settings['admin'] ?? false)),

                Tables\Columns\IconColumn::make('visibility_settings.b2b')
                    ->label('Показувати в B2B')
                    ->boolean()
                    ->state(fn (AttributeDefinition $record): bool => (bool) ($record->visibility_settings['b2b'] ?? false)),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn ($state) => $state === AttributeStatus::Active ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('scope')
                    ->label('Джерело')
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
            'index' => Pages\ListAttributeDefinitions::route('/'),
            'edit' => Pages\EditAttributeDefinition::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return $record instanceof AttributeDefinition
            && $record->scope !== AttributeScope::System;
    }
}
