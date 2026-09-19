<?php

namespace App\Filament\Cabinet\Resources\ProductResource\Pages;

use App\Filament\Cabinet\Resources\ProductResource;
use App\Filament\Concerns\HasMarginFormatToggle;
use Filament\Resources\Pages\ViewRecord;

class ViewProduct extends ViewRecord
{
    use HasMarginFormatToggle;

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
