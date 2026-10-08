<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Filament\Resources\MediaAssetResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMediaAsset extends ViewRecord
{
    protected static string $resource = MediaAssetResource::class;

    public function getTitle(): string
    {
        return MediaAssetResource::displayName($this->record);
    }
}
