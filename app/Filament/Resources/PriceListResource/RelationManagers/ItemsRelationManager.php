<?php

namespace App\Filament\Resources\PriceListResource\RelationManagers;

use App\Enums\PriceListItemStatus;
use App\Models\ProductVariant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Позиції прайс-листа';

    public function form(Form $form): Form
    {
        $defaultVatRate = (string) config('pricing.default_vat_rate', 20);

        return $form
            ->schema([
                Forms\Components\Select::make('product_variant_id')
                    ->label('Товар / варіант')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->relationship(
                        name: 'productVariant',
                        titleAttribute: 'sku',
                        modifyQueryUsing: fn (Builder $query) => $query
                            ->where('workspace_id', $this->getOwnerRecord()->workspace_id)
                            ->with('product'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (ProductVariant $record): string => $record->product?->name.' — '.$record->sku
                    )
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: function (Unique $rule, Get $get): Unique {
                            return $rule
                                ->where('workspace_id', $this->getOwnerRecord()->workspace_id)
                                ->where('price_list_id', $this->getOwnerRecord()->id)
                                ->where('quantity_min', (int) $get('quantity_min'));
                        },
                    )
                    ->validationMessages([
                        'unique' => 'Позиція з таким товаром і мінімальною кількістю вже існує в цьому списку.',
                    ]),
                Forms\Components\TextInput::make('quantity_min')
                    ->label('Кількість від')
                    ->numeric()
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->default(1),
                Forms\Components\TextInput::make('price')
                    ->label('Ціна')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->gt(0)
                    ->prefix('₴'),
                Forms\Components\TextInput::make('sale_price')
                    ->label('Акційна ціна')
                    ->numeric()
                    ->nullable()
                    ->gt(0)
                    ->lt('price')
                    ->prefix('₴'),
                Forms\Components\TextInput::make('vat_rate')
                    ->label('ПДВ %')
                    ->numeric()
                    ->nullable()
                    ->placeholder("за замовчуванням ({$defaultVatRate}%)"),
                Forms\Components\DatePicker::make('valid_from')
                    ->label('Діє з')
                    ->nullable()
                    ->native(false)
                    ->displayFormat('d.m.Y'),
                Forms\Components\DatePicker::make('valid_until')
                    ->label('Діє до')
                    ->nullable()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->afterOrEqual('valid_from'),
                Forms\Components\Select::make('status')
                    ->label('Статус')
                    ->options(PriceListItemStatus::options())
                    ->default(PriceListItemStatus::Active->value)
                    ->required(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('productVariant.product.name')
                    ->label('Товар')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('productVariant.sku')
                    ->label('Артикул')
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity_min')
                    ->label('Кількість від')
                    ->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->label('Ціна')
                    ->money('UAH')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sale_price')
                    ->label('Акційна ціна')
                    ->money('UAH')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('vat_rate')
                    ->label('ПДВ %')
                    ->formatStateUsing(
                        fn (?string $state): string => $state !== null
                            ? $state.'%'
                            : 'за замовчуванням ('.config('pricing.default_vat_rate').'%)'
                    ),
                Tables\Columns\TextColumn::make('valid_from')
                    ->label('Діє з')
                    ->date('d.m.Y')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Діє до')
                    ->date('d.m.Y')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(
                        fn ($state): string => $state instanceof PriceListItemStatus
                            ? $state->label()
                            : PriceListItemStatus::from((string) $state)->label()
                    ),
            ])
            ->defaultSort('productVariant.product.name')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Додати позицію')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['workspace_id'] = $this->getOwnerRecord()->workspace_id;
                        $data['price_list_id'] = $this->getOwnerRecord()->id;

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['workspace_id'] = $this->getOwnerRecord()->workspace_id;
        $data['price_list_id'] = $this->getOwnerRecord()->id;

        return $data;
    }
}
