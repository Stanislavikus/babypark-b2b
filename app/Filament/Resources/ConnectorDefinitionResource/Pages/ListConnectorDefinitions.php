<?php

namespace App\Filament\Resources\ConnectorDefinitionResource\Pages;

use App\Filament\Resources\ConnectorDefinitionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListConnectorDefinitions extends ListRecords
{
    protected static string $resource = ConnectorDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
