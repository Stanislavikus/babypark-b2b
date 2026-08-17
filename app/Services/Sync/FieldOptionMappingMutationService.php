<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Models\FieldOptionMapping;
use App\Models\SyncConfiguration;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use App\Support\Sync\Exceptions\FieldOptionMappingConflictException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final class FieldOptionMappingMutationService
{
    public function __construct(
        private readonly SyncConfigurationMutationCoordinator $mutationCoordinator,
        private readonly FieldOptionMappingBindingValidator $bindingValidator,
        private readonly FieldOptionMappingConstraintViolationClassifier $constraintViolationClassifier,
    ) {}

    public function confirm(
        ConnectorAccount $account,
        string $syncConfigurationId,
        string $fieldMappingId,
        string $internalOptionKey,
        string $externalOptionValue,
    ): SyncConfiguration {
        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $configuration) use ($fieldMappingId, $internalOptionKey, $externalOptionValue): void {
                $this->bindingValidator->assertProductsConfiguration($configuration);
                $mapping = $this->bindingValidator->assertOwnedMapping($configuration, $fieldMappingId);

                if ($this->exactMappingExists($mapping, $internalOptionKey, $externalOptionValue)) {
                    return;
                }

                $existing = FieldOptionMapping::withoutWorkspaceScope()
                    ->where('field_mapping_id', $mapping->id)
                    ->where('internal_option_key', $internalOptionKey)
                    ->first();

                if ($existing !== null) {
                    $existing->delete();
                }

                $this->createMapping($configuration, $mapping, $internalOptionKey, $externalOptionValue);
            },
        );
    }

    public function replace(
        ConnectorAccount $account,
        string $syncConfigurationId,
        string $fieldMappingId,
        string $internalOptionKey,
        string $externalOptionValue,
        ?string $newInternalOptionKey = null,
        ?string $newExternalOptionValue = null,
    ): SyncConfiguration {
        if ($newInternalOptionKey === null && $newExternalOptionValue === null) {
            throw FieldMappingValidationException::mappingNotFound($syncConfigurationId, $fieldMappingId);
        }

        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $configuration) use (
                $fieldMappingId,
                $internalOptionKey,
                $externalOptionValue,
                $newInternalOptionKey,
                $newExternalOptionValue,
            ): void {
                $this->bindingValidator->assertProductsConfiguration($configuration);
                $mapping = $this->bindingValidator->assertOwnedMapping($configuration, $fieldMappingId);

                $optionMapping = FieldOptionMapping::withoutWorkspaceScope()
                    ->where('field_mapping_id', $mapping->id)
                    ->where('internal_option_key', $internalOptionKey)
                    ->where('external_option_value', $externalOptionValue)
                    ->first();

                if ($optionMapping === null) {
                    throw FieldMappingValidationException::mappingNotFound($configuration->id, $fieldMappingId);
                }

                $targetInternalKey = $newInternalOptionKey ?? $internalOptionKey;
                $targetExternalValue = $newExternalOptionValue ?? $externalOptionValue;

                if ($targetInternalKey === $internalOptionKey && $targetExternalValue === $externalOptionValue) {
                    return;
                }

                $optionMapping->delete();

                $this->createMapping($configuration, $mapping, $targetInternalKey, $targetExternalValue);
            },
        );
    }

    public function remove(
        ConnectorAccount $account,
        string $syncConfigurationId,
        string $fieldMappingId,
        string $internalOptionKey,
        string $externalOptionValue,
    ): SyncConfiguration {
        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $configuration) use ($fieldMappingId, $internalOptionKey, $externalOptionValue): void {
                $this->bindingValidator->assertProductsConfiguration($configuration);
                $mapping = $this->bindingValidator->assertOwnedMapping($configuration, $fieldMappingId);

                $optionMapping = FieldOptionMapping::withoutWorkspaceScope()
                    ->where('field_mapping_id', $mapping->id)
                    ->where('internal_option_key', $internalOptionKey)
                    ->where('external_option_value', $externalOptionValue)
                    ->first();

                if ($optionMapping === null) {
                    throw FieldMappingValidationException::mappingNotFound($configuration->id, $fieldMappingId);
                }

                $optionMapping->delete();
            },
        );
    }

    private function exactMappingExists(
        FieldMapping $mapping,
        string $internalOptionKey,
        string $externalOptionValue,
    ): bool {
        return FieldOptionMapping::withoutWorkspaceScope()
            ->where('field_mapping_id', $mapping->id)
            ->where('internal_option_key', $internalOptionKey)
            ->where('external_option_value', $externalOptionValue)
            ->exists();
    }

    private function createMapping(
        SyncConfiguration $configuration,
        FieldMapping $mapping,
        string $internalOptionKey,
        string $externalOptionValue,
    ): void {
        try {
            FieldOptionMapping::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $configuration->workspace_id,
                'field_mapping_id' => $mapping->id,
                'internal_option_key' => $internalOptionKey,
                'external_option_value' => $externalOptionValue,
            ]);
        } catch (QueryException $exception) {
            if ($this->constraintViolationClassifier->isInternalOptionConflict($exception)) {
                throw FieldOptionMappingConflictException::internalOptionAlreadyMapped($internalOptionKey, previous: $exception);
            }

            throw $exception;
        }
    }
}
