<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorCapability;
use App\Support\Connectors\Exceptions\ConnectorProfileNotFoundException;
use App\Support\Connectors\Exceptions\DisabledConnectorProfileException;
use App\Support\Connectors\Exceptions\InvalidConnectorProfileConfiguration;
use App\Support\Connectors\Exceptions\UnsupportedConnectorCapabilityException;
use Illuminate\Contracts\Container\Container;
use ValueError;

class ConnectorProfileRegistry
{
    /** @var array<string, ConnectorProfileDefinition> */
    private readonly array $profiles;

    public function __construct(
        private readonly Container $container,
        ?array $profilesConfig = null,
    ) {
        $this->profiles = $this->parseProfiles($profilesConfig ?? config('connectors.profiles', []));
    }

    public function profileDefinition(string $profileCode): ConnectorProfileDefinition
    {
        if (! isset($this->profiles[$profileCode])) {
            throw new ConnectorProfileNotFoundException(
                sprintf('Connector profile [%s] is not registered.', $profileCode),
            );
        }

        return $this->profiles[$profileCode];
    }

    public function resolveAdapter(string $profileCode): ConnectorAdapter
    {
        $definition = $this->enabledProfileDefinition($profileCode);

        return $this->container->make($definition->adapterClass);
    }

    public function resolveAccountSchema(string $profileCode): ConnectorAccountSchema
    {
        $definition = $this->enabledProfileDefinition($profileCode);

        return $this->container->make($definition->accountSchemaClass);
    }

    public function requireCapability(string $profileCode, ConnectorCapability $capability): void
    {
        $definition = $this->enabledProfileDefinition($profileCode);

        if (! $definition->supports($capability)) {
            throw new UnsupportedConnectorCapabilityException(
                sprintf(
                    'Connector profile [%s] does not advertise capability [%s].',
                    $profileCode,
                    $capability->value,
                ),
            );
        }
    }

    private function enabledProfileDefinition(string $profileCode): ConnectorProfileDefinition
    {
        $definition = $this->profileDefinition($profileCode);

        if (! $definition->enabled) {
            throw new DisabledConnectorProfileException(
                sprintf('Connector profile [%s] is disabled.', $profileCode),
            );
        }

        return $definition;
    }

    /**
     * @param  array<string, array<string, mixed>>  $profilesConfig
     * @return array<string, ConnectorProfileDefinition>
     */
    private function parseProfiles(array $profilesConfig): array
    {
        $profiles = [];

        foreach ($profilesConfig as $profileCode => $profileConfig) {
            if (! is_string($profileCode) || $profileCode === '') {
                throw new InvalidConnectorProfileConfiguration('Connector profile code must be a non-empty string.');
            }

            if (! is_array($profileConfig)) {
                throw new InvalidConnectorProfileConfiguration(
                    sprintf('Connector profile [%s] must be configured as an array.', $profileCode),
                );
            }

            $profiles[$profileCode] = $this->parseProfileDefinition($profileCode, $profileConfig);
        }

        return $profiles;
    }

    /**
     * @param  array<string, mixed>  $profileConfig
     */
    private function parseProfileDefinition(string $profileCode, array $profileConfig): ConnectorProfileDefinition
    {
        if (! array_key_exists('enabled', $profileConfig)) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf('Connector profile [%s] is missing required key [enabled].', $profileCode),
            );
        }

        if (! is_bool($profileConfig['enabled'])) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf('Connector profile [%s] key [enabled] must be a boolean.', $profileCode),
            );
        }

        if (! array_key_exists('adapter', $profileConfig)) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf('Connector profile [%s] is missing required key [adapter].', $profileCode),
            );
        }

        if (! is_string($profileConfig['adapter']) || $profileConfig['adapter'] === '') {
            throw new InvalidConnectorProfileConfiguration(
                sprintf('Connector profile [%s] key [adapter] must be a non-empty class-string.', $profileCode),
            );
        }

        if (! array_key_exists('account_schema', $profileConfig)) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf('Connector profile [%s] is missing required key [account_schema].', $profileCode),
            );
        }

        if (! is_string($profileConfig['account_schema']) || $profileConfig['account_schema'] === '') {
            throw new InvalidConnectorProfileConfiguration(
                sprintf('Connector profile [%s] key [account_schema] must be a non-empty class-string.', $profileCode),
            );
        }

        if (! array_key_exists('capabilities', $profileConfig)) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf('Connector profile [%s] is missing required key [capabilities].', $profileCode),
            );
        }

        if (! is_array($profileConfig['capabilities'])) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf('Connector profile [%s] key [capabilities] must be an array.', $profileCode),
            );
        }

        $adapterClass = $profileConfig['adapter'];
        $accountSchemaClass = $profileConfig['account_schema'];

        if (! class_exists($adapterClass)) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf('Connector profile [%s] adapter class [%s] does not exist.', $profileCode, $adapterClass),
            );
        }

        if (! is_subclass_of($adapterClass, ConnectorAdapter::class) && $adapterClass !== ConnectorAdapter::class) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf(
                    'Connector profile [%s] adapter class [%s] must implement %s.',
                    $profileCode,
                    $adapterClass,
                    ConnectorAdapter::class,
                ),
            );
        }

        if (! class_exists($accountSchemaClass)) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf(
                    'Connector profile [%s] account schema class [%s] does not exist.',
                    $profileCode,
                    $accountSchemaClass,
                ),
            );
        }

        if (! is_subclass_of($accountSchemaClass, ConnectorAccountSchema::class)
            && $accountSchemaClass !== ConnectorAccountSchema::class) {
            throw new InvalidConnectorProfileConfiguration(
                sprintf(
                    'Connector profile [%s] account schema class [%s] must implement %s.',
                    $profileCode,
                    $accountSchemaClass,
                    ConnectorAccountSchema::class,
                ),
            );
        }

        $capabilities = [];

        foreach ($profileConfig['capabilities'] as $index => $configuredCapability) {
            if (! is_string($configuredCapability)) {
                throw new InvalidConnectorProfileConfiguration(
                    sprintf(
                        'Connector profile [%s] capability at index [%s] must be a string.',
                        $profileCode,
                        (string) $index,
                    ),
                );
            }

            try {
                $capabilities[] = ConnectorCapability::from($configuredCapability);
            } catch (ValueError $exception) {
                throw new InvalidConnectorProfileConfiguration(
                    sprintf(
                        'Connector profile [%s] has unknown capability [%s].',
                        $profileCode,
                        $configuredCapability,
                    ),
                    previous: $exception,
                );
            }
        }

        return new ConnectorProfileDefinition(
            profileCode: $profileCode,
            enabled: $profileConfig['enabled'],
            adapterClass: $adapterClass,
            accountSchemaClass: $accountSchemaClass,
            capabilities: $capabilities,
        );
    }
}
