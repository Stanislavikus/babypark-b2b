<?php

namespace App\Support\Sync\Preview;

use App\Models\ConnectorAccount;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\Exceptions\InvalidConnectorProfileConfiguration;
use Illuminate\Contracts\Container\Container;

final class SyncPreviewConnectorCapabilityResolver
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
        private readonly Container $container,
    ) {}

    public function resolve(ConnectorAccount $account): SyncPreviewConnectorCapability
    {
        $definition = $this->profileRegistry->profileDefinition($account->auth_profile);
        $previewCapabilityClass = $definition->previewCapabilityClass;

        if ($previewCapabilityClass === null || $previewCapabilityClass === '') {
            throw new InvalidConnectorProfileConfiguration(
                sprintf(
                    'Connector profile [%s] does not declare preview_capability.',
                    $definition->profileCode,
                ),
            );
        }

        $capability = $this->container->make($previewCapabilityClass);

        if (! $capability instanceof SyncPreviewConnectorCapability) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf(
                    'Connector profile [%s] preview_capability class [%s] must implement %s.',
                    $definition->profileCode,
                    $previewCapabilityClass,
                    SyncPreviewConnectorCapability::class,
                ),
            );
        }

        return $capability;
    }
}
