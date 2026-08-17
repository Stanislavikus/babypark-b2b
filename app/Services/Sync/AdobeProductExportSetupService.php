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

    public function configureAttributeSet(ConnectorAccount $account, int $attributeSetId): SyncConfiguration
    {
        if ($attributeSetId < 1) {
            throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                'attribute_set_id must be a positive integer.',
            );
        }

        $configuration = $this->reachabilityService->ensureProductsExportConfiguration($account);
        $metadata = $this->metadataReader->read(
            $account->workspace_id,
            $account->id,
            $attributeSetId,
        );

        $exists = false;

        foreach ($metadata->attributeSets as $attributeSet) {
            if (($attributeSet['attribute_set_id'] ?? null) === $attributeSetId) {
                $exists = true;
                break;
            }
        }

        if (! $exists) {
            throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                'Configured Adobe attribute_set_id does not exist in the connected store.',
            );
        }

        $adobeConfiguration = AdobeProductExportExecutionConfiguration::fromPayload([
            'attribute_set_id' => $attributeSetId,
        ]);

        return DB::transaction(function () use ($account, $configuration, $adobeConfiguration): SyncConfiguration {
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
        foreach ($metadata->attributeSets as $attributeSet) {
            if (($attributeSet['attribute_set_id'] ?? null) === $metadata->selectedAttributeSetId) {
                return true;
            }
        }

        return false;
    }
}
