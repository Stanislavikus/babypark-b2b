<?php

namespace App\Services\Sync;

use App\Enums\SyncSemanticOperation;
use App\Models\SyncConfiguration;
use App\Support\Sync\FieldMappingRevisionEntry;
use App\Support\Sync\FieldOptionMappingRevisionEntry;

final class SyncPreviewConfigurationSnapshotBuilder
{
    public function __construct(
        private readonly SyncConfigurationMutationCoordinator $mutationCoordinator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(
        SyncConfiguration $configuration,
        SyncSemanticOperation $semanticOperation,
    ): array {
        $fieldMappings = array_map(
            static fn (FieldMappingRevisionEntry $entry): array => [
                'field_binding_id' => $entry->fieldBindingId,
                'external_field_key' => $entry->externalFieldKey,
                'option_mappings' => array_map(
                    static fn (FieldOptionMappingRevisionEntry $option): array => $option->toRevisionArray(),
                    $entry->optionMappings,
                ),
            ],
            $this->mutationCoordinator->effectiveMappingPayload($configuration),
        );

        return [
            'version' => 'platform.sync-run-input.v1',
            'data_domain' => $configuration->data_domain->value,
            'semantic_operation' => $semanticOperation->value,
            'external_context' => $configuration->external_context ?? [],
            'selection' => [
                'mode' => 'all_products',
            ],
            'field_mappings' => $fieldMappings,
            'connector_execution_configuration' => $configuration->connectorExecutionConfiguration()->payload(),
        ];
    }
}
