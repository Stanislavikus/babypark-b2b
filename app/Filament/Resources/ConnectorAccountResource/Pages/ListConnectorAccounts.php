<?php

namespace App\Filament\Resources\ConnectorAccountResource\Pages;

use App\Filament\Resources\ConnectorAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListConnectorAccounts extends ListRecords
{
    protected static string $resource = ConnectorAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
