<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\HasMarginFormatToggle;
use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewProduct extends ViewRecord
{
    use HasMarginFormatToggle;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
