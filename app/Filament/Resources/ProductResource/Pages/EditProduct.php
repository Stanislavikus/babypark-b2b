<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string
    {
        return (string) ($this->record->name ?: 'Товар');
    }

    public function getSubheading(): ?string
    {
        $source = filled($this->record->onec_guid) ? '1С' : 'Master Workspace';
        $sku = filled($this->record->sku) ? ' · SKU '.$this->record->sku : '';

        return 'Master Product · '.$source.$sku;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Перегляд'),
        ];
    }
}
