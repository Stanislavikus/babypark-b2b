<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use App\Filament\Resources\OrderResource\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use Illuminate\Auth\Access\Response;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string | \UnitEnum | null $navigationGroup = 'B2B';

    protected static ?string $modelLabel = 'замовлення';

    protected static ?string $pluralModelLabel = 'Замовлення';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Замовлення')->schema([
                    Select::make('customer_id')
                        ->label('Клієнт')
                        ->relationship('customer', 'name')
                        ->disabled(),
                    TextInput::make('onec_number')
                        ->label('№ 1С')
                        ->disabled(),
                    Select::make('status')
                        ->label('Статус')
                        ->options(OrderStatus::options())
                        ->required(),
                    TextInput::make('total_with_vat')
                        ->label('Сума з ПДВ')
                        ->disabled()
                        ->prefix('₴'),
                    Textarea::make('comment')
                        ->label('Коментар клієнта')
                        ->disabled()
                        ->columnSpanFull(),
                ])->columns(2),
                Section::make('Менеджер')->schema([
                    Textarea::make('manager_comment')
                        ->label('Коментар менеджера')
                        ->columnSpanFull(),
                    Toggle::make('needs_call')
                        ->label('Потрібен дзвінок'),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Клієнт')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof OrderStatus ? $state->label() : (string) $state),
                TextColumn::make('total_with_vat')
                    ->label('Сума з ПДВ')
                    ->money('UAH')
                    ->sortable(),
                IconColumn::make('needs_call')
                    ->label('Дзвінок')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(OrderStatus::options()),
                SelectFilter::make('customer_id')
                    ->label('Клієнт')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('created_at')
                    ->label('Період')
                    ->schema([
                        DatePicker::make('from')->label('Від'),
                        DatePicker::make('until')->label('До'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }


    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }
}
