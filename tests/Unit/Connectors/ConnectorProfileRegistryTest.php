<?php

namespace Tests\Unit\Connectors;

use App\Enums\ConnectorCapability;
use App\Support\Connectors\ConnectorAccountMutationMode;
use App\Support\Connectors\ConnectorAccountSchema;
use App\Support\Connectors\ConnectorAccountSettingsInput;
use App\Support\Connectors\ConnectorAdapter;
use App\Support\Connectors\ConnectorProfileDefinition;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\CredentialMutation;
use App\Support\Connectors\Exceptions\ConnectorProfileNotFoundException;
use App\Support\Connectors\Exceptions\DisabledConnectorProfileException;
use App\Support\Connectors\Exceptions\InvalidConnectorProfileConfiguration;
use App\Support\Connectors\Exceptions\UnsupportedConnectorCapabilityException;
use App\Support\Connectors\ValidatedConnectorAccountState;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorProfileRegistryTest extends TestCase
{
    #[Test]
    public function resolves_known_enabled_profile_to_definition_and_adapter(): void
    {
        $registry = app(ConnectorProfileRegistry::class);

        $definition = $registry->profileDefinition('adobe_commerce_paas_oauth1_integration');

        $this->assertInstanceOf(ConnectorProfileDefinition::class, $definition);
        $this->assertSame('adobe_commerce_paas_oauth1_integration', $definition->profileCode);
        $this->assertTrue($definition->enabled);
        $this->assertSame([ConnectorCapability::ConnectionCheck, ConnectorCapability::SchemaDiscovery], $definition->capabilities);

        $adapter = $registry->resolveAdapter('adobe_commerce_paas_oauth1_integration');

        $this->assertInstanceOf(ConnectorAdapter::class, $adapter);
    }

    #[Test]
    public function throws_for_unknown_profile_code(): void
    {
        $registry = app(ConnectorProfileRegistry::class);

        $this->expectException(ConnectorProfileNotFoundException::class);
        $this->expectExceptionMessage('Connector profile [unknown_profile] is not registered.');

        $registry->profileDefinition('unknown_profile');
    }

    #[Test]
    public function throws_for_disabled_profile_when_resolving_adapter(): void
    {
        $registry = $this->registryWithProfiles([
            'disabled_profile' => [
                'enabled' => false,
                'adapter' => TestConnectorAdapter::class,
                'account_schema' => TestConnectorAccountSchema::class,
                'capabilities' => [],
            ],
        ]);

        $this->expectException(DisabledConnectorProfileException::class);
        $this->expectExceptionMessage('Connector profile [disabled_profile] is disabled.');

        $registry->resolveAdapter('disabled_profile');
    }

    #[Test]
    public function throws_for_disabled_profile_when_requiring_capability(): void
    {
        $registry = $this->registryWithProfiles([
            'disabled_profile' => $this->validProfile([
                'enabled' => false,
                'capabilities' => ['connection_check'],
            ]),
        ]);

        $this->expectException(DisabledConnectorProfileException::class);
        $this->expectExceptionMessage('Connector profile [disabled_profile] is disabled.');

        $registry->requireCapability('disabled_profile', ConnectorCapability::ConnectionCheck);
    }

    #[Test]
    public function throws_for_unsupported_capability_on_enabled_profile(): void
    {
        $registry = $this->registryWithProfiles([
            'enabled_profile' => $this->validProfile(),
        ]);

        $this->expectException(UnsupportedConnectorCapabilityException::class);
        $this->expectExceptionMessage(
            'Connector profile [enabled_profile] does not advertise capability [connection_check].',
        );

        $registry->requireCapability('enabled_profile', ConnectorCapability::ConnectionCheck);
    }

    #[Test]
    public function throws_invalid_configuration_for_unknown_configured_capability(): void
    {
        $this->expectException(InvalidConnectorProfileConfiguration::class);
        $this->expectExceptionMessage('Connector profile [broken_profile] has unknown capability [not_a_real_capability].');

        $this->registryWithProfiles([
            'broken_profile' => $this->validProfile([
                'capabilities' => ['not_a_real_capability'],
            ]),
        ]);
    }

    #[Test]
    public function throws_invalid_configuration_for_missing_enabled_key(): void
    {
        $this->expectException(InvalidConnectorProfileConfiguration::class);
        $this->expectExceptionMessage('Connector profile [broken_profile] is missing required key [enabled].');

        $this->registryWithProfiles([
            'broken_profile' => [
                'adapter' => TestConnectorAdapter::class,
                'account_schema' => TestConnectorAccountSchema::class,
                'capabilities' => [],
            ],
        ]);
    }

    #[Test]
    public function throws_invalid_configuration_for_non_boolean_enabled_value(): void
    {
        $this->expectException(InvalidConnectorProfileConfiguration::class);
        $this->expectExceptionMessage('Connector profile [broken_profile] key [enabled] must be a boolean.');

        $this->registryWithProfiles([
            'broken_profile' => $this->validProfile([
                'enabled' => 'yes',
            ]),
        ]);
    }

    #[Test]
    public function throws_invalid_configuration_for_missing_adapter_key(): void
    {
        $this->expectException(InvalidConnectorProfileConfiguration::class);
        $this->expectExceptionMessage('Connector profile [broken_profile] is missing required key [adapter].');

        $this->registryWithProfiles([
            'broken_profile' => [
                'enabled' => true,
                'account_schema' => TestConnectorAccountSchema::class,
                'capabilities' => [],
            ],
        ]);
    }

    #[Test]
    public function throws_invalid_configuration_for_nonexistent_adapter_class(): void
    {
        $this->expectException(InvalidConnectorProfileConfiguration::class);
        $this->expectExceptionMessage(
            'Connector profile [broken_profile] adapter class [App\\Support\\Connectors\\MissingAdapter] does not exist.',
        );

        $this->registryWithProfiles([
            'broken_profile' => $this->validProfile([
                'adapter' => 'App\\Support\\Connectors\\MissingAdapter',
            ]),
        ]);
    }

    #[Test]
    public function throws_invalid_configuration_for_adapter_not_implementing_connector_adapter(): void
    {
        $this->expectException(InvalidConnectorProfileConfiguration::class);
        $this->expectExceptionMessage(
            'Connector profile [broken_profile] adapter class [Tests\\Unit\\Connectors\\NotAConnectorAdapter] must implement App\\Support\\Connectors\\ConnectorAdapter.',
        );

        $this->registryWithProfiles([
            'broken_profile' => $this->validProfile([
                'adapter' => NotAConnectorAdapter::class,
            ]),
        ]);
    }

    #[Test]
    public function resolves_enabled_profile_with_empty_capabilities_array(): void
    {
        $registry = $this->registryWithProfiles([
            'empty_capabilities_profile' => $this->validProfile(),
        ]);

        $definition = $registry->profileDefinition('empty_capabilities_profile');

        $this->assertSame([], $definition->capabilities);
        $this->assertInstanceOf(TestConnectorAdapter::class, $registry->resolveAdapter('empty_capabilities_profile'));
    }

    #[Test]
    public function profile_capabilities_are_derived_from_config_only_not_adapter_class(): void
    {
        $adapterReflection = new \ReflectionClass(ConnectorAdapter::class);
        $adapterMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $adapterReflection->getMethods(),
        );

        $this->assertSame([], $adapterMethods);

        $registryWithConnectionCheck = $this->registryWithProfiles([
            'configurable_profile' => $this->validProfile([
                'capabilities' => ['connection_check'],
            ]),
        ]);

        $registryWithoutCapabilities = $this->registryWithProfiles([
            'configurable_profile' => $this->validProfile(),
        ]);

        $withCapability = $registryWithConnectionCheck->profileDefinition('configurable_profile');
        $withoutCapability = $registryWithoutCapabilities->profileDefinition('configurable_profile');

        $this->assertTrue($withCapability->supports(ConnectorCapability::ConnectionCheck));
        $this->assertFalse($withoutCapability->supports(ConnectorCapability::ConnectionCheck));
        $this->assertSame(TestConnectorAdapter::class, $withCapability->adapterClass);
        $this->assertSame(TestConnectorAdapter::class, $withoutCapability->adapterClass);
    }

    #[Test]
    public function resolves_adapter_through_container_not_direct_instantiation(): void
    {
        $expectedAdapter = new TestConnectorAdapter('container-bound');

        $container = $this->createMock(Container::class);
        $container->expects($this->once())
            ->method('make')
            ->with(TestConnectorAdapter::class)
            ->willReturn($expectedAdapter);

        $registry = new ConnectorProfileRegistry($container, [
            'container_profile' => $this->validProfile(),
        ]);

        $adapter = $registry->resolveAdapter('container_profile');

        $this->assertSame($expectedAdapter, $adapter);
        $this->assertSame('container-bound', $adapter->marker);
    }

    #[Test]
    public function throws_invalid_configuration_for_missing_account_schema_key(): void
    {
        $this->expectException(InvalidConnectorProfileConfiguration::class);
        $this->expectExceptionMessage('Connector profile [broken_profile] is missing required key [account_schema].');

        $this->registryWithProfiles([
            'broken_profile' => [
                'enabled' => true,
                'adapter' => TestConnectorAdapter::class,
                'capabilities' => [],
            ],
        ]);
    }

    #[Test]
    public function throws_invalid_configuration_for_account_schema_not_implementing_contract(): void
    {
        $this->expectException(InvalidConnectorProfileConfiguration::class);
        $this->expectExceptionMessage(
            'Connector profile [broken_profile] account schema class [Tests\\Unit\\Connectors\\NotAConnectorAccountSchema] must implement App\\Support\\Connectors\\ConnectorAccountSchema.',
        );

        $this->registryWithProfiles([
            'broken_profile' => $this->validProfile([
                'account_schema' => NotAConnectorAccountSchema::class,
            ]),
        ]);
    }

    #[Test]
    public function resolves_account_schema_through_container_not_direct_instantiation(): void
    {
        $expectedSchema = new TestConnectorAccountSchema('container-bound');

        $container = $this->createMock(Container::class);
        $container->expects($this->once())
            ->method('make')
            ->with(TestConnectorAccountSchema::class)
            ->willReturn($expectedSchema);

        $registry = new ConnectorProfileRegistry($container, [
            'container_profile' => $this->validProfile(),
        ]);

        $schema = $registry->resolveAccountSchema('container_profile');

        $this->assertSame($expectedSchema, $schema);
        $this->assertSame('container-bound', $schema->marker);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validProfile(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'adapter' => TestConnectorAdapter::class,
            'account_schema' => TestConnectorAccountSchema::class,
            'capabilities' => [],
        ], $overrides);
    }

    /**
     * @param  array<string, array<string, mixed>>  $profiles
     */
    private function registryWithProfiles(array $profiles): ConnectorProfileRegistry
    {
        return new ConnectorProfileRegistry(app(Container::class), $profiles);
    }
}

final class TestConnectorAdapter implements ConnectorAdapter
{
    public function __construct(public string $marker = 'default') {}
}

final class NotAConnectorAdapter {}

final class NotAConnectorAccountSchema {}

final class TestConnectorAccountSchema implements ConnectorAccountSchema
{
    public function __construct(public string $marker = 'default') {}

    public function validate(
        ConnectorAccountSettingsInput $settings,
        CredentialMutation $credentialMutation,
        ConnectorAccountMutationMode $mode,
    ): ValidatedConnectorAccountState {
        return new ValidatedConnectorAccountState('https://example.com', 'default', null, []);
    }
}
