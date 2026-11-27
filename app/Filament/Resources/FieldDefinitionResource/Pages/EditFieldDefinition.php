<?php

namespace App\Filament\Resources\FieldDefinitionResource\Pages;

use App\Enums\FieldObjectType;
use App\Filament\Resources\FieldDefinitionResource;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use Filament\Resources\Pages\EditRecord;

class EditFieldDefinition extends EditRecord
{
    protected static string $resource = FieldDefinitionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var FieldDefinition $record */
        $record = $this->getRecord();

        foreach ([FieldObjectType::Product, FieldObjectType::ProductVariant] as $objectType) {
            $binding = $record->fieldBindings->firstWhere('object_type', $objectType);

            if ($binding !== null) {
                $data["binding_{$objectType->value}"] = $binding->only([
                    'storage_path',
                    'field_group',
                    'is_required',
                    'is_filterable',
                    'is_sortable',
                    'sort_order',
                    'visibility_settings',
                ]);
            }
        }

        $firstBinding = $record->fieldBindings->first();

        if ($firstBinding !== null) {
            $data['visibility_settings'] = $firstBinding->visibility_settings;
        }

        return $data;
    }

    protected function handleRecordUpdate($record, array $data): FieldDefinition
    {
        /** @var FieldDefinition $record */
        foreach ([FieldObjectType::Product, FieldObjectType::ProductVariant] as $objectType) {
            $bindingData = $data["binding_{$objectType->value}"] ?? null;

            if ($bindingData === null) {
                continue;
            }

            /** @var FieldBinding|null $binding */
            $binding = $record->fieldBindings->firstWhere('object_type', $objectType);

            if ($binding === null) {
                continue;
            }

            $visibility = $data['visibility_settings'] ?? $binding->visibility_settings;

            $binding->update([
                'is_required' => $bindingData['is_required'] ?? $binding->is_required,
                'is_filterable' => $bindingData['is_filterable'] ?? $binding->is_filterable,
                'is_sortable' => $bindingData['is_sortable'] ?? $binding->is_sortable,
                'sort_order' => $bindingData['sort_order'] ?? $binding->sort_order,
                'visibility_settings' => $visibility,
            ]);
        }

        $record->update(collect($data)->only([
            'status',
        ])->all());

        return $record->refresh();
    }
}
