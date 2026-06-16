<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeliverySettingResource\Pages;
use App\Models\DeliverySetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DeliverySettingResource extends Resource
{
    protected static ?string $model = DeliverySetting::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Система';

    protected static ?string $modelLabel = 'доставка';

    protected static ?string $pluralModelLabel = 'Доставка';

    protected static ?string $navigationLabel = 'Доставка';

    protected static ?int $navigationSort = 12;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Налаштування доставки')->schema([
                    Forms\Components\TextInput::make('city')
                        ->label('Місто / Регіон')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('free_from')
                        ->label('Безкоштовно від (грн)')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->step(100)
                        ->prefix('₴'),

                    Forms\Components\TextInput::make('delivery_price')
                        ->label('Вартість доставки (грн)')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->step(10)
                        ->prefix('₴'),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Порядок сортування')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Активна')
                        ->default(true)
                        ->inline(false),
                ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('city')
                    ->label('Місто / Регіон')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('free_from')
                    ->label('Безкоштовно від')
                    ->formatStateUsing(fn ($state) => '₴ ' . number_format((float) $state, 0, '.', ' '))
                    ->sortable(),

                Tables\Columns\TextColumn::make('delivery_price')
                    ->label('Вартість доставки')
                    ->formatStateUsing(fn ($state) => '₴ ' . number_format((float) $state, 0, '.', ' '))
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Активна'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDeliverySettings::route('/'),
            'create' => Pages\CreateDeliverySetting::route('/create'),
            'edit'   => Pages\EditDeliverySetting::route('/{record}/edit'),
        ];
    }
}
