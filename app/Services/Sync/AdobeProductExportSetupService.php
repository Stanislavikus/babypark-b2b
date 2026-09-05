<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionConfiguration;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\AdobeProductExportMetadataReader;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;
use Illuminate\Support\Facades\DB;

final class AdobeProductExportSetupService
{
    public function __construct(
        private readonly SyncConfigurationReachabilityService $reachabilityService,
        private readonly AdobeProductExportMetadataReader $metadataReader,
        private readonly SyncConfigurationService $configurationService,
    ) {}

    public function ensureProductsExportConfiguration(ConnectorAccount $account): SyncConfiguration
    {
        $configuration = $this->reachabilityService->ensureProductsExportConfiguration($account);

        if ($this->hasConfiguredAttributeSet($configuration)) {
            return $configuration->refresh();
        }

        $preferredAttributeSetId = $this->resolvePreferredAttributeSetId($configuration);

        $metadata = $this->metadataReader->read(
            $account->workspace_id,
            $account->id,
            $preferredAttributeSetId,
        );

        if (! $this->selectedAttributeSetExists($metadata)) {
            throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                'Configured Adobe attribute_set_id does not exist in the connected store.',
            );
        }

        $adobeConfiguration = AdobeProductExportExecutionConfiguration::fromPayload([
            'attribute_set_id' => $metadata->selectedAttributeSetId,
        ]);

        return DB::transaction(function () use ($account, $configuration, $adobeConfiguration): SyncConfiguration {
            $locked = SyncConfiguration::withoutWorkspaceScope()
                ->where('id', $configuration->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->hasConfiguredAttributeSet($locked)) {
                return $locked;
            }

            return $this->configurationService->updateConnectorExecutionConfiguration(
                $account,
                $locked->id,
                ConnectorExecutionConfiguration::fromPayload($adobeConfiguration->toPayload()),
            );
        });
    }

    public function listAvailableAttributeSets(ConnectorAccount $account): array
    {
        return $this->metadataReader->listAttributeSets(
            $account->workspace_id,
            $account->id,
        );
    }

    public function validateAttributeSetSelection(ConnectorAccount $account, int $attributeSetId): void
    {
        $this->assertPositiveAttributeSetId($attributeSetId);

        $attributeSets = $this->metadataReader->listAttributeSets(
            $account->workspace_id,
            $account->id,
        );

        if (! $this->attributeSetExistsInCatalogue($attributeSets, $attributeSetId)) {
            throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                'Configured Adobe attribute_set_id does not exist in the connected store.',
            );
        }
    }

    public function persistValidatedAttributeSet(ConnectorAccount $account, int $attributeSetId): SyncConfiguration
    {
        $this->assertPositiveAttributeSetId($attributeSetId);

        $adobeConfiguration = AdobeProductExportExecutionConfiguration::fromPayload([
            'attribute_set_id' => $attributeSetId,
        ]);

        return DB::transaction(function () use ($account, $adobeConfiguration): SyncConfiguration {
            $configuration = $this->reachabilityService->ensureProductsExportConfiguration($account);

            $locked = SyncConfiguration::withoutWorkspaceScope()
                ->where('id', $configuration->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->configurationService->updateConnectorExecutionConfiguration(
                $account,
                $locked->id,
                ConnectorExecutionConfiguration::fromPayload($adobeConfiguration->toPayload()),
            );
        });
    }

    public function configureAttributeSet(ConnectorAccount $account, int $attributeSetId): SyncConfiguration
    {
        $this->validateAttributeSetSelection($account, $attributeSetId);

        return $this->persistValidatedAttributeSet($account, $attributeSetId);
    }

    private function hasConfiguredAttributeSet(SyncConfiguration $configuration): bool
    {
        try {
            AdobeProductExportExecutionConfiguration::fromPayload(
                $configuration->connectorExecutionConfiguration()->payload(),
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolvePreferredAttributeSetId(SyncConfiguration $configuration): ?int
    {
        try {
            return AdobeProductExportExecutionConfiguration::fromPayload(
                $configuration->connectorExecutionConfiguration()->payload(),
            )->attributeSetId;
        } catch (\Throwable) {
            return null;
        }
    }

    private function selectedAttributeSetExists(AdobeProductExportExecutionMetadata $metadata): bool
    {
        return $this->attributeSetExistsInCatalogue(
            $metadata->attributeSets,
            $metadata->selectedAttributeSetId,
        );
    }

    private function assertPositiveAttributeSetId(int $attributeSetId): void
    {
        if ($attributeSetId < 1) {
            throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                'attribute_set_id must be a positive integer.',
            );
        }
    }

    /**
     * @param  list<array{attribute_set_id: int, attribute_set_name: string}>  $attributeSets
     */
    private function attributeSetExistsInCatalogue(array $attributeSets, int $attributeSetId): bool
    {
        foreach ($attributeSets as $attributeSet) {
            if (($attributeSet['attribute_set_id'] ?? null) === $attributeSetId) {
                return true;
            }
        }

        return false;
    }
}
