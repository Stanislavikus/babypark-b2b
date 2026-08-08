<?php

namespace App\Filament\Resources\ConnectorDefinitionResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\ConnectorDefinitionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListConnectorDefinitions extends ListRecords
{
    protected static string $resource = ConnectorDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
