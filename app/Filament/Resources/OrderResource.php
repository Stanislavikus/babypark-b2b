<?php

namespace App\Filament\Resources;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'B2B';

    protected static ?string $modelLabel = 'замовлення';

    protected static ?string $pluralModelLabel = 'Замовлення';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Замовлення')->schema([
                    Forms\Components\Select::make('contractor_id')
                        ->label('Контрагент')
                        ->relationship('contractor', 'name')
                        ->disabled(),
                    Forms\Components\TextInput::make('onec_number')
                        ->label('№ 1С')
                        ->disabled(),
                    Forms\Components\Select::make('status')
                        ->label('Статус')
                        ->options(OrderStatus::options())
                        ->required(),
                    Forms\Components\TextInput::make('total_with_vat')
                        ->label('Сума з ПДВ')
                        ->disabled()
                        ->prefix('₴'),
                    Forms\Components\Textarea::make('comment')
                        ->label('Коментар клієнта')
                        ->disabled()
                        ->columnSpanFull(),
                ])->columns(2),
                Forms\Components\Section::make('Менеджер')->schema([
                    Forms\Components\Textarea::make('manager_comment')
                        ->label('Коментар менеджера')
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('needs_call')
                        ->label('Потрібен дзвінок'),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('contractor.name')
                    ->label('Контрагент')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof OrderStatus ? $state->label() : (string) $state),
                Tables\Columns\TextColumn::make('total_with_vat')
                    ->label('Сума з ПДВ')
                    ->money('UAH')
                    ->sortable(),
                Tables\Columns\IconColumn::make('needs_call')
                    ->label('Дзвінок')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(OrderStatus::options()),
                Tables\Filters\SelectFilter::make('contractor_id')
                    ->label('Контрагент')
                    ->relationship('contractor', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('created_at')
                    ->label('Період')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Від'),
                        Forms\Components\DatePicker::make('until')->label('До'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
