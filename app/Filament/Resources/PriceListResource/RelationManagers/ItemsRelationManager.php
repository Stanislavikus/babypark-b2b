<?php

namespace App\Filament\Resources\PriceListResource\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use App\Enums\PriceListItemStatus;
use App\Models\ProductVariant;
use App\Services\Pricing\WorkspaceTaxDefaults;
use App\Support\Filament\RevalidatesOnUpdate;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Позиції прайс-листа';

    private ?float $resolvedWorkspaceDefaultVatRate = null;

    public function form(Schema $schema): Schema
    {
        $defaultVatRate = (string) $this->workspaceDefaultVatRate();

        return $schema
            ->components([
                RevalidatesOnUpdate::apply(
                    Select::make('product_variant_id')
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
                ),
                TextInput::make('quantity_min')
                    ->label('Кількість від')
                    ->numeric()
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->default(1),
                TextInput::make('price')
                    ->label('Ціна без податку')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->gt(0)
                    ->prefix('₴'),
                TextInput::make('sale_price')
                    ->label('Акційна ціна без податку')
                    ->numeric()
                    ->nullable()
                    ->gt(0)
                    ->lt('price')
                    ->prefix('₴'),
                TextInput::make('vat_rate')
                    ->label('Ставка податку')
                    ->numeric()
                    ->nullable()
                    ->placeholder("за замовчуванням ({$defaultVatRate}%)"),
                DatePicker::make('valid_from')
                    ->label('Діє з')
                    ->nullable()
                    ->native(false)
                    ->displayFormat('d.m.Y'),
                DatePicker::make('valid_until')
                    ->label('Діє до')
                    ->nullable()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->afterOrEqual('valid_from'),
                Select::make('status')
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
                TextColumn::make('productVariant.product.name')
                    ->label('Товар')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.sku')
                    ->label('Артикул')
                    ->searchable(),
                TextColumn::make('quantity_min')
                    ->label('Кількість від')
                    ->sortable(),
                TextColumn::make('price')
                    ->label('Ціна без податку')
                    ->money('UAH')
                    ->sortable(),
                TextColumn::make('sale_price')
                    ->label('Акційна ціна без податку')
                    ->money('UAH')
                    ->placeholder('—'),
                TextColumn::make('vat_rate')
                    ->label('ПДВ %')
                    ->formatStateUsing(
                        fn (?string $state): string => $state !== null
                            ? $state.'%'
                            : 'за замовчуванням ('.$this->workspaceDefaultVatRate().'%)'
                    ),
                TextColumn::make('valid_from')
                    ->label('Діє з')
                    ->date('d.m.Y')
                    ->placeholder('—'),
                TextColumn::make('valid_until')
                    ->label('Діє до')
                    ->date('d.m.Y')
                    ->placeholder('—'),
                TextColumn::make('status')
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
                CreateAction::make()
                    ->label('Додати позицію')
                    ->mutateDataUsing(function (array $data): array {
                        $data['workspace_id'] = $this->getOwnerRecord()->workspace_id;
                        $data['price_list_id'] = $this->getOwnerRecord()->id;

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['workspace_id'] = $this->getOwnerRecord()->workspace_id;
        $data['price_list_id'] = $this->getOwnerRecord()->id;

        return $data;
    }

    private function workspaceDefaultVatRate(): float
    {
        return $this->resolvedWorkspaceDefaultVatRate ??=
            app(WorkspaceTaxDefaults::class)->resolveWorkspaceRate(
                $this->getOwnerRecord()->workspace
            );
    }
}
