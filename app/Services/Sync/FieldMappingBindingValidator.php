<?php

namespace App\Services\Sync;

use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Enums\SyncDataDomain;
use App\Models\ConnectorAccount;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\SyncConfiguration;
use App\Services\Connectors\AuthoritativeConnectorSchemaSnapshotResolver;
use App\Support\Sync\Exceptions\AuthoritativeDiscoveryValidationException;
use App\Support\Sync\Exceptions\FieldMappingValidationException;

final class FieldMappingBindingValidator
{
    public function __construct(
        private readonly AuthoritativeConnectorSchemaSnapshotResolver $authoritativeSnapshotResolver,
    ) {}

    public function assertProductsConfiguration(SyncConfiguration $configuration): void
    {
        if ($configuration->data_domain !== SyncDataDomain::Products) {
            throw FieldMappingValidationException::nonProductsConfiguration($configuration->id);
        }
    }

    public function assertEligibleBinding(
        SyncConfiguration $configuration,
        string $fieldBindingId,
    ): FieldBinding {
        $binding = FieldBinding::withoutWorkspaceScope()->find($fieldBindingId);

        if ($binding === null) {
            throw FieldMappingValidationException::foreignWorkspaceBinding($fieldBindingId);
        }

        if ($binding->workspace_id !== null && $binding->workspace_id !== $configuration->workspace_id) {
            throw FieldMappingValidationException::foreignWorkspaceBinding($fieldBindingId);
        }

        if ($binding->status !== AttributeStatus::Active) {
            throw FieldMappingValidationException::archivedBinding($fieldBindingId);
        }

        $definition = FieldDefinition::withoutWorkspaceScope()->find($binding->field_definition_id);

        if ($definition === null) {
            throw FieldMappingValidationException::archivedDefinition($binding->field_definition_id);
        }

        if ($definition->workspace_id !== null && $definition->workspace_id !== $configuration->workspace_id) {
            throw FieldMappingValidationException::foreignWorkspaceDefinition($definition->id);
        }

        if ($definition->status !== AttributeStatus::Active) {
            throw FieldMappingValidationException::archivedDefinition($definition->id);
        }

        if (! in_array($binding->object_type, [FieldObjectType::Product, FieldObjectType::ProductVariant], true)) {
            throw FieldMappingValidationException::customerObjectType($fieldBindingId);
        }

        return $binding;
    }

    public function assertExternalFieldKeyInAuthoritativeSnapshot(
        ConnectorAccount $account,
        string $externalFieldKey,
    ): void {
        $this->authoritativeSnapshotResolver->assertResolvableDiscoverySource($account);

        $snapshot = $this->authoritativeSnapshotResolver->resolveSnapshot($account);

        if ($snapshot === null) {
            throw AuthoritativeDiscoveryValidationException::noAuthoritativeSnapshot();
        }

        if (! $this->authoritativeSnapshotResolver->externalFieldKeyExists($account, $externalFieldKey)) {
            throw AuthoritativeDiscoveryValidationException::externalFieldKeyAbsent($externalFieldKey);
        }
    }
}
