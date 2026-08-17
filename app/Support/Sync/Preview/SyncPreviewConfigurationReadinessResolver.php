<?php

namespace App\Support\Sync\Preview;

use App\Models\ConnectorAccount;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\Exceptions\InvalidConnectorProfileConfiguration;
use Illuminate\Contracts\Container\Container;

final class SyncPreviewConfigurationReadinessResolver
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
        private readonly Container $container,
    ) {}

    public function resolve(ConnectorAccount $account): SyncPreviewConfigurationReadinessPort
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

        $readiness = $this->container->make($previewCapabilityClass);

        if (! $readiness instanceof SyncPreviewConfigurationReadinessPort) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf(
                    'Connector profile [%s] preview_capability class [%s] must implement %s.',
                    $definition->profileCode,
                    $previewCapabilityClass,
                    SyncPreviewConfigurationReadinessPort::class,
                ),
            );
        }

        return $readiness;
    }
}
