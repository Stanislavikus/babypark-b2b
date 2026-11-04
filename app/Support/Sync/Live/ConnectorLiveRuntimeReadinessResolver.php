<?php

namespace App\Support\Sync\Live;

use App\Models\ConnectorAccount;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\Exceptions\InvalidConnectorProfileConfiguration;
use Illuminate\Contracts\Container\Container;

final class ConnectorLiveRuntimeReadinessResolver
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
        private readonly Container $container,
    ) {}

    public function resolve(ConnectorAccount $account): ConnectorLiveRuntimeReadiness
    {
        $definition = $this->profileRegistry->profileDefinition($account->auth_profile);
        $readinessClass = $definition->liveRuntimeReadinessClass;

        if ($readinessClass === null || $readinessClass === '') {
            throw new InvalidConnectorProfileConfiguration(
                sprintf(
                    'Connector profile [%s] does not declare live_runtime_readiness.',
                    $definition->profileCode,
                ),
            );
        }

        $readiness = $this->container->make($readinessClass);

        if (! $readiness instanceof ConnectorLiveRuntimeReadiness) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf(
                    'Connector profile [%s] live_runtime_readiness class [%s] must implement %s.',
                    $definition->profileCode,
                    $readinessClass,
                    ConnectorLiveRuntimeReadiness::class,
                ),
            );
        }

        return $readiness;
    }
}
