<?php

namespace App\Filament\Resources\DeliverySettingResource\Pages;

use App\Filament\Resources\DeliverySettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDeliverySetting extends CreateRecord
{
    protected static string $resource = DeliverySettingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
