<?php

namespace App\Filament\Resources\AttributeDefinitionResource\Pages;

use App\Enums\AttributeScope;
use App\Filament\Resources\AttributeDefinitionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAttributeDefinition extends EditRecord
{
    protected static string $resource = AttributeDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => $this->record->scope !== AttributeScope::System),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $existing = $this->record->visibility_settings ?? ['channels' => []];

        $data['visibility_settings'] = [
            'admin' => (bool) ($data['visibility_settings']['admin'] ?? $existing['admin'] ?? false),
            'b2b' => (bool) ($data['visibility_settings']['b2b'] ?? $existing['b2b'] ?? false),
            'channels' => $existing['channels'] ?? [],
        ];

        unset($data['code']);

        return $data;
    }
}
