<?php

namespace App\Filament\Resources\ConnectorDefinitionResource\Pages;

use App\Enums\ConnectorDefinitionStatus;
use App\Filament\Resources\ConnectorDefinitionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditConnectorDefinition extends EditRecord
{
    protected static string $resource = ConnectorDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(false),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['status'] ?? null) === ConnectorDefinitionStatus::Active->value
            && ! $this->record->hasVerifiedGlobalPrimarySource()) {
            throw ValidationException::withMessages([
                'status' => 'Активна платформа потребує перевіреного глобального первинного джерела схеми.',
            ]);
        }

        return $data;
    }
}
