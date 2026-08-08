<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\DeliverySettingResource\Pages\ListDeliverySettings;
use App\Filament\Resources\DeliverySettingResource\Pages\CreateDeliverySetting;
use App\Filament\Resources\DeliverySettingResource\Pages\EditDeliverySetting;
use App\Filament\Resources\DeliverySettingResource\Pages;
use App\Models\DeliverySetting;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DeliverySettingResource extends Resource
{
    protected static ?string $model = DeliverySetting::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-truck';

    protected static string | \UnitEnum | null $navigationGroup = 'Система';

    protected static ?string $modelLabel = 'доставка';

    protected static ?string $pluralModelLabel = 'Доставка';

    protected static ?string $navigationLabel = 'Доставка';

    protected static ?int $navigationSort = 12;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Налаштування доставки')->schema([
                    TextInput::make('city')
                        ->label('Місто / Регіон')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('free_from')
                        ->label('Безкоштовно від (грн)')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->step(100)
                        ->prefix('₴'),

                    TextInput::make('delivery_price')
                        ->label('Вартість доставки (грн)')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->step(10)
                        ->prefix('₴'),

                    TextInput::make('sort_order')
                        ->label('Порядок сортування')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    Toggle::make('is_active')
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
                TextColumn::make('city')
                    ->label('Місто / Регіон')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('free_from')
                    ->label('Безкоштовно від')
                    ->formatStateUsing(fn ($state) => '₴ '.number_format((float) $state, 0, '.', ' '))
                    ->sortable(),

                TextColumn::make('delivery_price')
                    ->label('Вартість доставки')
                    ->formatStateUsing(fn ($state) => '₴ '.number_format((float) $state, 0, '.', ' '))
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Активна'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliverySettings::route('/'),
            'create' => CreateDeliverySetting::route('/create'),
            'edit' => EditDeliverySetting::route('/{record}/edit'),
        ];
    }
}
