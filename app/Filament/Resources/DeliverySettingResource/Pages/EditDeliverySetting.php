<?php

namespace App\Filament\Resources\DeliverySettingResource\Pages;

use App\Filament\Resources\DeliverySettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDeliverySetting extends EditRecord
{
    protected static string $resource = DeliverySettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
