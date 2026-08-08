<?php

namespace App\Filament\Resources\DeliverySettingResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\DeliverySettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDeliverySettings extends ListRecords
{
    protected static string $resource = DeliverySettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
