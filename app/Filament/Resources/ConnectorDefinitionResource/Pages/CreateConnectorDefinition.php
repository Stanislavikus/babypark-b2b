<?php

namespace App\Filament\Resources\ConnectorDefinitionResource\Pages;

use App\Filament\Resources\ConnectorDefinitionResource;
use App\Services\Connectors\ConnectorDefinitionGovernanceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateConnectorDefinition extends CreateRecord
{
    protected static string $resource = ConnectorDefinitionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(ConnectorDefinitionGovernanceService::class)->createDefinition($data);
    }
}
