<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Support\Sync\Exceptions\FieldMappingConflictException;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final class FieldMappingMutationService
{
    public function __construct(
        private readonly SyncConfigurationMutationCoordinator $mutationCoordinator,
        private readonly FieldMappingBindingValidator $bindingValidator,
        private readonly FieldMappingConstraintViolationClassifier $constraintViolationClassifier,
    ) {}

    public function confirm(
        ConnectorAccount $account,
        string $syncConfigurationId,
        string $fieldBindingId,
        string $externalFieldKey,
    ): SyncConfiguration {
        $existing = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $syncConfigurationId)
            ->where('field_binding_id', $fieldBindingId)
            ->where('external_field_key', $externalFieldKey)
            ->first();

        if ($existing !== null) {
            return SyncConfiguration::withoutWorkspaceScope()
                ->where('workspace_id', $account->workspace_id)
                ->where('connector_account_id', $account->id)
                ->where('id', $syncConfigurationId)
                ->firstOrFail();
        }

        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $configuration) use ($account, $fieldBindingId, $externalFieldKey): void {
                $this->bindingValidator->assertProductsConfiguration($configuration);
                $this->bindingValidator->assertEligibleBinding($configuration, $fieldBindingId);
                $this->bindingValidator->assertExternalFieldKeyInAuthoritativeSnapshot($account, $externalFieldKey);

                $this->assertNoConflictingMappings(
                    $configuration,
                    $fieldBindingId,
                    $externalFieldKey,
                );

                $this->createMapping($configuration, $fieldBindingId, $externalFieldKey);
            },
        );
    }

    public function replace(
        ConnectorAccount $account,
        string $syncConfigurationId,
        string $fieldBindingId,
        string $externalFieldKey,
        ?string $newFieldBindingId = null,
        ?string $newExternalFieldKey = null,
    ): SyncConfiguration {
        if ($newFieldBindingId === null && $newExternalFieldKey === null) {
            throw FieldMappingValidationException::mappingNotFound($syncConfigurationId, $fieldBindingId);
        }

        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $configuration) use (
                $account,
                $fieldBindingId,
                $externalFieldKey,
                $newFieldBindingId,
                $newExternalFieldKey,
            ): void {
                $this->bindingValidator->assertProductsConfiguration($configuration);

                $mapping = FieldMapping::withoutWorkspaceScope()
                    ->where('sync_configuration_id', $configuration->id)
                    ->where('field_binding_id', $fieldBindingId)
                    ->where('external_field_key', $externalFieldKey)
                    ->first();

                if ($mapping === null) {
                    throw FieldMappingValidationException::mappingNotFound($configuration->id, $fieldBindingId);
                }

                $targetBindingId = $newFieldBindingId ?? $fieldBindingId;
                $targetExternalFieldKey = $newExternalFieldKey ?? $externalFieldKey;

                if ($targetBindingId === $fieldBindingId && $targetExternalFieldKey === $externalFieldKey) {
                    return;
                }

                $this->bindingValidator->assertEligibleBinding($configuration, $targetBindingId);
                $this->bindingValidator->assertExternalFieldKeyInAuthoritativeSnapshot($account, $targetExternalFieldKey);

                $this->assertNoConflictingMappings(
                    $configuration,
                    $targetBindingId,
                    $targetExternalFieldKey,
                    exceptMappingId: $mapping->id,
                );

                $mapping->delete();

                $this->createMapping($configuration, $targetBindingId, $targetExternalFieldKey);
            },
        );
    }

    public function remove(
        ConnectorAccount $account,
        string $syncConfigurationId,
        string $fieldBindingId,
    ): SyncConfiguration {
        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $configuration) use ($fieldBindingId): void {
                $this->bindingValidator->assertProductsConfiguration($configuration);

                $mapping = FieldMapping::withoutWorkspaceScope()
                    ->where('sync_configuration_id', $configuration->id)
                    ->where('field_binding_id', $fieldBindingId)
                    ->first();

                if ($mapping === null) {
                    throw FieldMappingValidationException::mappingNotFound($configuration->id, $fieldBindingId);
                }

                $mapping->delete();
            },
        );
    }

    private function assertNoConflictingMappings(
        SyncConfiguration $configuration,
        string $fieldBindingId,
        string $externalFieldKey,
        ?string $exceptMappingId = null,
    ): void {
        $bindingConflict = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('field_binding_id', $fieldBindingId)
            ->when($exceptMappingId !== null, fn ($query) => $query->where('id', '!=', $exceptMappingId))
            ->exists();

        if ($bindingConflict) {
            throw FieldMappingConflictException::internalTargetAlreadyMapped($fieldBindingId);
        }

        $externalConflict = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('external_field_key', $externalFieldKey)
            ->when($exceptMappingId !== null, fn ($query) => $query->where('id', '!=', $exceptMappingId))
            ->exists();

        if ($externalConflict) {
            throw FieldMappingConflictException::externalFieldAlreadyMapped($externalFieldKey);
        }
    }

    private function createMapping(
        SyncConfiguration $configuration,
        string $fieldBindingId,
        string $externalFieldKey,
    ): void {
        try {
            FieldMapping::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $configuration->workspace_id,
                'sync_configuration_id' => $configuration->id,
                'field_binding_id' => $fieldBindingId,
                'external_field_key' => $externalFieldKey,
            ]);
        } catch (QueryException $exception) {
            if ($this->constraintViolationClassifier->isInternalTargetConflict($exception)) {
                throw FieldMappingConflictException::internalTargetAlreadyMapped($fieldBindingId, previous: $exception);
            }

            if ($this->constraintViolationClassifier->isExternalFieldConflict($exception)) {
                throw FieldMappingConflictException::externalFieldAlreadyMapped($externalFieldKey, previous: $exception);
            }

            throw $exception;
        }
    }
}
