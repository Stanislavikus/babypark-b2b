<?php

namespace App\Filament\Resources\SyncLogResource\Pages;

use Filament\Actions\EditAction;
use App\Filament\Resources\SyncLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSyncLog extends ViewRecord
{
    protected static string $resource = SyncLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
