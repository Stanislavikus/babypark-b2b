<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Models\FieldOptionMapping;
use App\Models\SyncConfiguration;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use App\Support\Sync\Exceptions\FieldOptionMappingConflictException;
use App\Support\Sync\Exceptions\FieldOptionMappingStaleMutationException;
use App\Support\Sync\FieldOptionMappingMutationContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final class FieldOptionMappingMutationService
{
    public function __construct(
        private readonly SyncConfigurationMutationCoordinator $mutationCoordinator,
        private readonly FieldOptionMappingBindingValidator $bindingValidator,
        private readonly FieldOptionMappingConstraintViolationClassifier $constraintViolationClassifier,
        private readonly FieldOptionMappingOptionValidatorResolver $optionValidatorResolver,
        private readonly FieldDefinitionInternalOptionValidator $internalOptionValidator,
    ) {}

    public function confirm(
        ConnectorAccount $account,
        string $syncConfigurationId,
        string $fieldMappingId,
        string $internalOptionKey,
        string $externalOptionValue,
    ): SyncConfiguration {
        $configuration = $this->resolveConfiguration($account, $syncConfigurationId);
        $mapping = $this->bindingValidator->assertOwnedMapping($configuration, $fieldMappingId);

        if ($this->exactMappingExists($mapping, $internalOptionKey, $externalOptionValue)) {
            return $configuration;
        }

        $this->bindingValidator->assertProductsConfiguration($configuration);
        $this->internalOptionValidator->validate($mapping, $internalOptionKey);

        $this->optionValidatorResolver->resolve($account)->validate(
            $account,
            $mapping,
            $internalOptionKey,
            $externalOptionValue,
        );

        $context = new FieldOptionMappingMutationContext(
            configurationRevision: $configuration->configuration_revision,
            fieldMappingId: $mapping->id,
            externalFieldKey: (string) $mapping->external_field_key,
            internalOptionKey: $internalOptionKey,
            externalOptionValue: $externalOptionValue,
        );

        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $lockedConfiguration) use ($context, $mapping, $internalOptionKey, $externalOptionValue): void {
                $this->assertMutationContextStillCurrent($lockedConfiguration, $context);
                $ownedMapping = $this->bindingValidator->assertOwnedMapping($lockedConfiguration, $mapping->id);
                $this->internalOptionValidator->validate($ownedMapping, $internalOptionKey);

                $existing = FieldOptionMapping::withoutWorkspaceScope()
                    ->where('field_mapping_id', $ownedMapping->id)
                    ->where('internal_option_key', $internalOptionKey)
                    ->first();

                if ($existing !== null) {
                    $existing->delete();
                }

                $this->createMapping($lockedConfiguration, $ownedMapping, $internalOptionKey, $externalOptionValue);
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

        $configuration = $this->resolveConfiguration($account, $syncConfigurationId);
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
            return $configuration;
        }

        $this->bindingValidator->assertProductsConfiguration($configuration);
        $this->internalOptionValidator->validate($mapping, $targetInternalKey);

        $this->optionValidatorResolver->resolve($account)->validate(
            $account,
            $mapping,
            $targetInternalKey,
            $targetExternalValue,
        );

        $context = new FieldOptionMappingMutationContext(
            configurationRevision: $configuration->configuration_revision,
            fieldMappingId: $mapping->id,
            externalFieldKey: (string) $mapping->external_field_key,
            internalOptionKey: $targetInternalKey,
            externalOptionValue: $targetExternalValue,
            existingOptionMappingId: $optionMapping->id,
            existingExternalOptionValue: $externalOptionValue,
        );

        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $lockedConfiguration) use ($context, $mapping, $targetInternalKey, $targetExternalValue): void {
                $this->assertMutationContextStillCurrent($lockedConfiguration, $context);
                $ownedMapping = $this->bindingValidator->assertOwnedMapping($lockedConfiguration, $mapping->id);
                $this->internalOptionValidator->validate($ownedMapping, $targetInternalKey);

                $optionMapping = FieldOptionMapping::withoutWorkspaceScope()
                    ->whereKey($context->existingOptionMappingId)
                    ->where('field_mapping_id', $ownedMapping->id)
                    ->first();

                if ($optionMapping === null) {
                    throw FieldOptionMappingStaleMutationException::configurationChanged();
                }

                $optionMapping->delete();

                $this->createMapping($lockedConfiguration, $ownedMapping, $targetInternalKey, $targetExternalValue);
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
        $configuration = $this->resolveConfiguration($account, $syncConfigurationId);
        $mapping = $this->bindingValidator->assertOwnedMapping($configuration, $fieldMappingId);

        $optionMapping = FieldOptionMapping::withoutWorkspaceScope()
            ->where('field_mapping_id', $mapping->id)
            ->where('internal_option_key', $internalOptionKey)
            ->where('external_option_value', $externalOptionValue)
            ->first();

        if ($optionMapping === null) {
            throw FieldMappingValidationException::mappingNotFound($configuration->id, $fieldMappingId);
        }

        $context = new FieldOptionMappingMutationContext(
            configurationRevision: $configuration->configuration_revision,
            fieldMappingId: $mapping->id,
            externalFieldKey: (string) $mapping->external_field_key,
            internalOptionKey: $internalOptionKey,
            existingOptionMappingId: $optionMapping->id,
            existingExternalOptionValue: $externalOptionValue,
        );

        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $lockedConfiguration) use ($context): void {
                $this->assertMutationContextStillCurrent($lockedConfiguration, $context);
                $ownedMapping = $this->bindingValidator->assertOwnedMapping($lockedConfiguration, $context->fieldMappingId);
                $this->bindingValidator->assertProductsConfiguration($lockedConfiguration);

                $optionMapping = FieldOptionMapping::withoutWorkspaceScope()
                    ->whereKey($context->existingOptionMappingId)
                    ->where('field_mapping_id', $ownedMapping->id)
                    ->first();

                if ($optionMapping === null) {
                    throw FieldOptionMappingStaleMutationException::configurationChanged();
                }

                $optionMapping->delete();
            },
        );
    }

    public function removeById(
        ConnectorAccount $account,
        string $syncConfigurationId,
        string $fieldMappingId,
        string $fieldOptionMappingId,
    ): SyncConfiguration {
        $configuration = $this->resolveConfiguration($account, $syncConfigurationId);
        $mapping = $this->bindingValidator->assertOwnedMapping($configuration, $fieldMappingId);

        $optionMapping = FieldOptionMapping::withoutWorkspaceScope()
            ->whereKey($fieldOptionMappingId)
            ->where('field_mapping_id', $mapping->id)
            ->first();

        if ($optionMapping === null) {
            throw FieldMappingValidationException::mappingNotFound($configuration->id, $fieldMappingId);
        }

        $context = new FieldOptionMappingMutationContext(
            configurationRevision: $configuration->configuration_revision,
            fieldMappingId: $mapping->id,
            externalFieldKey: (string) $mapping->external_field_key,
            internalOptionKey: $optionMapping->internal_option_key,
            existingOptionMappingId: $optionMapping->id,
            existingExternalOptionValue: $optionMapping->external_option_value,
        );

        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $lockedConfiguration) use ($context): void {
                $this->assertMutationContextStillCurrent($lockedConfiguration, $context);
                $ownedMapping = $this->bindingValidator->assertOwnedMapping($lockedConfiguration, $context->fieldMappingId);
                $this->bindingValidator->assertProductsConfiguration($lockedConfiguration);

                $optionMapping = FieldOptionMapping::withoutWorkspaceScope()
                    ->whereKey($context->existingOptionMappingId)
                    ->where('field_mapping_id', $ownedMapping->id)
                    ->first();

                if ($optionMapping === null) {
                    throw FieldOptionMappingStaleMutationException::configurationChanged();
                }

                $optionMapping->delete();
            },
        );
    }

    private function resolveConfiguration(ConnectorAccount $account, string $syncConfigurationId): SyncConfiguration
    {
        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('id', $syncConfigurationId)
            ->first();

        if ($configuration === null) {
            throw FieldMappingValidationException::mappingNotFound($syncConfigurationId, $syncConfigurationId);
        }

        return $configuration;
    }

    private function assertMutationContextStillCurrent(
        SyncConfiguration $configuration,
        FieldOptionMappingMutationContext $context,
    ): void {
        if ($configuration->configuration_revision !== $context->configurationRevision) {
            throw FieldOptionMappingStaleMutationException::configurationChanged();
        }

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('id', $context->fieldMappingId)
            ->first();

        if ($mapping === null || (string) $mapping->external_field_key !== $context->externalFieldKey) {
            throw FieldOptionMappingStaleMutationException::configurationChanged();
        }
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
