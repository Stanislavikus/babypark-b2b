<?php

namespace App\Filament\CabinetTest\Resources;

use App\Filament\CabinetTest\Resources\ContractorProductResource\Pages;
use App\Models\Product;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ContractorProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $modelLabel = 'товар';

    protected static ?string $pluralModelLabel = 'Товари';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sku')
                    ->label('Артикул')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->sortable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('brand')
                    ->label('Бренд')
                    ->sortable(),

                Tables\Columns\TextColumn::make('contractor_price')
                    ->label('Ваша ціна')
                    ->getStateUsing(function (Product $record): ?string {
                        $contractorId = Auth::guard('contractor')->id();

                        $maxPrice = null;
                        foreach ($record->variants as $variant) {
                            foreach ($variant->prices as $price) {
                                if ($price->contractor_id === $contractorId && $price->price_with_vat !== null) {
                                    $value = (float) $price->price_with_vat;
                                    if ($maxPrice === null || $value > $maxPrice) {
                                        $maxPrice = $value;
                                    }
                                }
                            }
                        }

                        return $maxPrice !== null
                            ? '₴ '.number_format($maxPrice, 2, '.', ' ')
                            : null;
                    })
                    ->placeholder('—'),
            ])
            ->defaultSort('sku')
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $contractorId = Auth::guard('contractor')->id();

        return parent::getEloquentQuery()
            ->where('is_active', true)
            ->whereHas('variants.prices', fn ($q) => $q->where('contractor_id', $contractorId))
            ->with([
                'variants.prices' => fn ($q) => $q->where('contractor_id', $contractorId),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContractorProducts::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
