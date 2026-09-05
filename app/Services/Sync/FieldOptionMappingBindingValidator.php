<?php

namespace App\Services\Sync;

use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Support\Sync\Exceptions\FieldMappingValidationException;

final class FieldOptionMappingBindingValidator
{
    public function assertProductsConfiguration(SyncConfiguration $configuration): void
    {
        if ($configuration->data_domain->value !== 'products') {
            throw FieldMappingValidationException::nonProductsConfiguration($configuration->id);
        }
    }

    public function assertOwnedMapping(
        SyncConfiguration $configuration,
        string $fieldMappingId,
    ): FieldMapping {
        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('id', $fieldMappingId)
            ->first();

        if ($mapping === null) {
            throw FieldMappingValidationException::mappingNotFound($configuration->id, $fieldMappingId);
        }

        if ($mapping->workspace_id !== $configuration->workspace_id) {
            throw FieldMappingValidationException::foreignWorkspaceBinding($fieldMappingId);
        }

        return $mapping;
    }
}
