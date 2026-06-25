<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /** Margin column display format: 'percent' or 'uah' */
    public string $marginFormat = 'percent';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function toggleMarginFormat(): void
    {
        $this->marginFormat = $this->marginFormat === 'percent' ? 'uah' : 'percent';
    }
}
