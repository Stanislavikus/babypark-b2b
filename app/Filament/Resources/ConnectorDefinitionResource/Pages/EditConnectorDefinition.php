<?php

namespace App\Filament\Resources\ConnectorDefinitionResource\Pages;

use App\Filament\Resources\ConnectorDefinitionResource;
use App\Models\ConnectorDefinition;
use App\Services\Connectors\ConnectorDefinitionGovernanceService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditConnectorDefinition extends EditRecord
{
    protected static string $resource = ConnectorDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->using(function (ConnectorDefinition $record): bool {
                    app(ConnectorDefinitionGovernanceService::class)
                        ->deleteDefinitionWhenUnreferenced($record);

                    return true;
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ConnectorDefinitionGovernanceService::class)->updateDefinition($record, $data);
    }
}
