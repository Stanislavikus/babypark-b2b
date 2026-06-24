<?php

namespace App\Filament\Cabinet\Resources\ProductResource\Pages;

use App\Filament\Cabinet\Resources\ProductResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->variants->each(
            fn ($variant) => $variant->setRelation('product', $this->record)
        );
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
