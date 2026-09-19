<?php

namespace App\Support\Sync\Live;

use App\Models\ConnectorAccount;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\Exceptions\InvalidConnectorProfileConfiguration;
use Illuminate\Contracts\Container\Container;

final class SyncLiveConnectorCapabilityResolver
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
        private readonly Container $container,
    ) {}

    public function resolve(ConnectorAccount $account): SyncLiveConnectorCapability
    {
        $definition = $this->profileRegistry->profileDefinition($account->auth_profile);
        $liveCapabilityClass = $definition->liveCapabilityClass;

        if ($liveCapabilityClass === null || $liveCapabilityClass === '') {
            throw new InvalidConnectorProfileConfiguration(
                sprintf(
                    'Connector profile [%s] does not declare live_capability.',
                    $definition->profileCode,
                ),
            );
        }

        $capability = $this->container->make($liveCapabilityClass);

        if (! $capability instanceof SyncLiveConnectorCapability) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf(
                    'Connector profile [%s] live_capability class [%s] must implement %s.',
                    $definition->profileCode,
                    $liveCapabilityClass,
                    SyncLiveConnectorCapability::class,
                ),
            );
        }

        return $capability;
    }
}
