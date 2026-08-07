<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorDiscoveryRunTrigger;
use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\ConnectorSchemaSource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorAccountPersistencePort;
use App\Services\Connectors\ConnectorAccountSettingsResult;
use App\Services\Connectors\ConnectorDiscoveryDispatchPort;
use App\Services\Connectors\DiscoverySmokeTestAbortedException;
use App\Services\Connectors\DiscoverySmokeTestHarness;
use App\Services\Connectors\DiscoverySmokeTestPromptGateway;
use App\Support\Connectors\ConnectorAccountMutationMode;
use App\Support\Connectors\ConnectorAccountSchema;
use App\Support\Connectors\ConnectorDiscoveryDispatchDecision;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\Exceptions\UnsupportedConnectorCapabilityException;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\ValidatedConnectorAccountState;
use App\Support\Workspace\WorkspaceMembership;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\EnablesConnectorSchemaDiscoveryCapability;
use Tests\TestCase;

class DiscoverySmokeTestHarnessTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use EnablesConnectorSchemaDiscoveryCapability;
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->enableSchemaDiscoveryCapability();

        $this->workspace = $this->defaultWorkspace();
    }

    #[Test]
    public function environment_guard_refuses_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('connectors:discovery-smoke-test', ['--actor-email' => 'admin@babypark.ua'])
            ->expectsOutputToContain('only available in local and testing environments')
            ->assertFailed();
    }

    #[Test]
    public function missing_actor_email_fails_without_creating_a_user(): void
    {
        $beforeCount = User::query()->count();

        $this->artisan('connectors:discovery-smoke-test', ['--actor-email' => ''])
            ->expectsOutputToContain('--actor-email option is required')
            ->assertFailed();

        $this->assertSame($beforeCount, User::query()->count());
    }

    #[Test]
    public function invalid_actor_email_fails_clearly(): void
    {
        $this->artisan('connectors:discovery-smoke-test', ['--actor-email' => 'nobody@example.com'])
            ->expectsOutputToContain('No active staff user found')
            ->assertFailed();
    }

    #[Test]
    public function missing_adobe_definition_fails_clearly(): void
    {
        ConnectorDefinition::query()
            ->where('code', 'adobe_commerce')
            ->update(['code' => '__missing_adobe__']);

        $harness = app(DiscoverySmokeTestHarness::class);

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('adobe_commerce');

        $harness->resolveAdobeDefinition();
    }

    #[Test]
    public function inactive_adobe_definition_fails_clearly(): void
    {
        ConnectorDefinition::query()
            ->where('code', 'adobe_commerce')
            ->update(['status' => ConnectorDefinitionStatus::Draft]);

        $harness = app(DiscoverySmokeTestHarness::class);

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('not active');

        $harness->resolveAdobeDefinition();
    }

    #[Test]
    public function profile_missing_schema_discovery_capability_fails_clearly(): void
    {
        config(['connectors.profiles.adobe_commerce_paas_oauth1_integration.capabilities' => ['connection_check']]);
        $this->app->forgetInstance(ConnectorProfileRegistry::class);

        $harness = app(DiscoverySmokeTestHarness::class);

        $this->expectException(UnsupportedConnectorCapabilityException::class);

        $harness->assertSchemaDiscoveryCapability();
    }

    #[Test]
    public function merchandiser_without_create_permission_cannot_create_account_through_harness(): void
    {
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $definition = $this->adobeConnectorDefinition();
        $prompts = new RecordingDiscoverySmokeTestPromptGateway;
        $validated = app(DiscoverySmokeTestHarness::class)->normalizeAccountSettings(
            'https://shop.example.com',
            'default',
            null,
        );

        $settingsService = Mockery::mock(ConnectorAccountPersistencePort::class);
        $settingsService->shouldNotReceive('create');
        $this->app->instance(ConnectorAccountPersistencePort::class, $settingsService);

        $harness = app(DiscoverySmokeTestHarness::class);

        $this->expectException(AuthorizationException::class);

        $harness->resolveAccountPath(
            $merchandiser,
            $this->workspace,
            $definition,
            $validated,
            null,
            false,
            $prompts,
        );

        $this->assertNotContains('askOAuth1Credentials', $prompts->calls);
    }

    #[Test]
    public function merchandiser_cannot_replace_credentials_without_replace_permission(): void
    {
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $account = $this->createConnectorAccount($this->workspace);
        $definition = $this->adobeConnectorDefinition();
        $validated = app(DiscoverySmokeTestHarness::class)->normalizeAccountSettings(
            (string) $account->base_url,
            (string) $account->store_code,
            $account->tenant_context,
        );

        $prompts = new RecordingDiscoverySmokeTestPromptGateway(forceReplace: true);

        $settingsService = Mockery::mock(ConnectorAccountPersistencePort::class);
        $settingsService->shouldNotReceive('update');
        $this->app->instance(ConnectorAccountPersistencePort::class, $settingsService);

        $harness = app(DiscoverySmokeTestHarness::class);

        $this->expectException(AuthorizationException::class);

        $harness->resolveAccountPath(
            $merchandiser,
            $this->workspace,
            $definition,
            $validated,
            $account,
            true,
            $prompts,
        );

        $this->assertNotContains('askOAuth1Credentials', $prompts->calls);
    }

    #[Test]
    public function existing_keep_path_does_not_prompt_for_secrets_or_call_settings_service(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $definition = $this->adobeConnectorDefinition();
        $validated = app(DiscoverySmokeTestHarness::class)->normalizeAccountSettings(
            'https://shop.example.com',
            'default',
            null,
        );
        $account = $this->createSmokeTestConnectorAccount($validated);

        $prompts = new RecordingDiscoverySmokeTestPromptGateway;

        $settingsService = Mockery::mock(ConnectorAccountPersistencePort::class);
        $settingsService->shouldNotReceive('create');
        $settingsService->shouldNotReceive('update');
        $this->app->instance(ConnectorAccountPersistencePort::class, $settingsService);

        $harness = app(DiscoverySmokeTestHarness::class);

        $result = $harness->resolveAccountPath(
            $admin,
            $this->workspace,
            $definition,
            $validated,
            $account,
            false,
            $prompts,
        );

        $this->assertSame('keep', $result['path']);
        $this->assertSame($account->id, $result['account']->id);
        $this->assertNotContains('askOAuth1Credentials', $prompts->calls);
    }

    #[Test]
    public function merchandiser_with_existing_account_can_dispatch_when_run_discovery_permits(): void
    {
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $account = $this->createConnectorAccount($this->workspace);

        $dispatchService = Mockery::mock(ConnectorDiscoveryDispatchPort::class);
        $dispatchService->shouldReceive('executeManual')
            ->once()
            ->with($merchandiser, $this->workspace->id, $account->id)
            ->andReturn(ConnectorDiscoveryDispatchDecision::dispatch((string) Str::uuid(), now()->addHour()->getTimestamp()));
        $this->app->instance(ConnectorDiscoveryDispatchPort::class, $dispatchService);

        $harness = app(DiscoverySmokeTestHarness::class);
        $decision = $harness->obtainFreshDispatch($merchandiser, $this->workspace, $account, static function (): void {});

        $this->assertTrue($decision->shouldDispatch);
    }

    #[Test]
    public function preliminary_normalization_calls_registry_validate_with_keep_and_update(): void
    {
        $schema = Mockery::mock(ConnectorAccountSchema::class);
        $schema->shouldReceive('validate')
            ->once()
            ->withArgs(function ($settings, $credentialMutation, $mode): bool {
                return $credentialMutation->isKeep()
                    && $mode === ConnectorAccountMutationMode::Update;
            })
            ->andReturn(new ValidatedConnectorAccountState(
                baseUrl: 'https://shop.example.com',
                storeCode: 'default',
                tenantContext: null,
                settings: [],
            ));

        $registry = Mockery::mock(ConnectorProfileRegistry::class);
        $registry->shouldReceive('resolveAccountSchema')
            ->once()
            ->with(DiscoverySmokeTestHarness::AUTH_PROFILE)
            ->andReturn($schema);

        $harness = new DiscoverySmokeTestHarness(
            $registry,
            app(ConnectorAccountPersistencePort::class),
            app(ConnectorDiscoveryDispatchPort::class),
            app(WorkspaceMembership::class),
            app(ConnectorSchemaSourceEndpointPathValidator::class),
        );

        $harness->normalizeAccountSettings('https://shop.example.com/', 'default', '');
    }

    #[Test]
    public function create_path_delegates_persistence_to_settings_service(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $definition = $this->adobeConnectorDefinition();
        $credentials = new OAuth1Credentials('ck', 'cs', 'at', 'ts');
        $validated = app(DiscoverySmokeTestHarness::class)->normalizeAccountSettings(
            'https://shop.example.com',
            'default',
            null,
        );

        $settingsService = Mockery::mock(ConnectorAccountPersistencePort::class);
        $settingsService->shouldReceive('create')
            ->once()
            ->andReturnUsing(function () use ($definition): ConnectorAccountSettingsResult {
                $account = ConnectorAccount::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $this->workspace->id,
                    'connector_definition_id' => $definition->id,
                    'name' => 'Created via service',
                    'auth_profile' => DiscoverySmokeTestHarness::AUTH_PROFILE,
                    'base_url' => 'https://shop.example.com',
                    'store_code' => 'default',
                    'tenant_context' => null,
                    'is_enabled' => true,
                    'settings' => [],
                    'credentials' => [],
                    'connection_status' => ConnectorAccountConnectionStatus::Untested,
                ]);

                return new ConnectorAccountSettingsResult(
                    id: $account->id,
                    connectorDefinitionId: $definition->id,
                    authProfile: DiscoverySmokeTestHarness::AUTH_PROFILE,
                    baseUrl: 'https://shop.example.com',
                    storeCode: 'default',
                    tenantContext: null,
                    settings: [],
                    isEnabled: true,
                    hasCredentials: true,
                );
            });
        $this->app->instance(ConnectorAccountPersistencePort::class, $settingsService);

        $harness = app(DiscoverySmokeTestHarness::class);
        $prompts = new RecordingDiscoverySmokeTestPromptGateway;

        $result = $harness->resolveAccountPath(
            $admin,
            $this->workspace,
            $definition,
            $validated,
            null,
            false,
            $prompts,
            $credentials,
        );

        $this->assertSame('create', $result['path']);
    }

    #[Test]
    public function replace_path_delegates_persistence_to_settings_service(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);
        $definition = $this->adobeConnectorDefinition();
        $credentials = new OAuth1Credentials('ck2', 'cs2', 'at2', 'ts2');
        $validated = app(DiscoverySmokeTestHarness::class)->normalizeAccountSettings(
            (string) $account->base_url,
            (string) $account->store_code,
            $account->tenant_context,
        );

        $settingsService = Mockery::mock(ConnectorAccountPersistencePort::class);
        $settingsService->shouldReceive('update')
            ->once()
            ->andReturn(new ConnectorAccountSettingsResult(
                id: $account->id,
                connectorDefinitionId: $definition->id,
                authProfile: DiscoverySmokeTestHarness::AUTH_PROFILE,
                baseUrl: (string) $account->base_url,
                storeCode: (string) $account->store_code,
                tenantContext: $account->tenant_context,
                settings: [],
                isEnabled: true,
                hasCredentials: true,
            ));
        $this->app->instance(ConnectorAccountPersistencePort::class, $settingsService);

        $harness = app(DiscoverySmokeTestHarness::class);

        $result = $harness->resolveAccountPath(
            $admin,
            $this->workspace,
            $definition,
            $validated,
            $account,
            true,
            new RecordingDiscoverySmokeTestPromptGateway,
            $credentials,
        );

        $this->assertSame('replace', $result['path']);
    }

    #[Test]
    public function ordinary_account_with_same_tuple_is_not_reused_by_smoke_test_lookup(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $definition = $this->adobeConnectorDefinition();

        $ordinaryAccount = $this->createConnectorAccount($this->workspace, [
            'name' => 'Production Magento Store',
            'base_url' => 'https://shop.example.com',
            'store_code' => 'default',
            'tenant_context' => null,
        ]);

        $validated = $harness->normalizeAccountSettings(
            (string) $ordinaryAccount->base_url,
            (string) $ordinaryAccount->store_code,
            $ordinaryAccount->tenant_context,
        );

        $this->assertNull($harness->findMatchingSmokeTestAccount($this->workspace, $definition, $validated));
    }

    #[Test]
    public function replace_credentials_cannot_modify_ordinary_account_with_same_tuple(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $definition = $this->adobeConnectorDefinition();
        $ordinaryAccount = $this->createConnectorAccount($this->workspace, [
            'name' => 'Production Magento Store',
            'base_url' => 'https://shop.example.com',
            'store_code' => 'default',
        ]);
        $originalCredentials = $ordinaryAccount->credentials;
        $validated = app(DiscoverySmokeTestHarness::class)->normalizeAccountSettings(
            (string) $ordinaryAccount->base_url,
            (string) $ordinaryAccount->store_code,
            $ordinaryAccount->tenant_context,
        );

        $settingsService = Mockery::mock(ConnectorAccountPersistencePort::class);
        $settingsService->shouldNotReceive('update');
        $settingsService->shouldReceive('create')
            ->once()
            ->andReturnUsing(function () use ($definition, $validated): ConnectorAccountSettingsResult {
                $account = ConnectorAccount::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $this->workspace->id,
                    'connector_definition_id' => $definition->id,
                    'name' => app(DiscoverySmokeTestHarness::class)->buildSmokeTestName(
                        $this->workspace->id,
                        $definition->id,
                        DiscoverySmokeTestHarness::AUTH_PROFILE,
                        $validated->baseUrl,
                        $validated->storeCode,
                        $validated->tenantContext,
                    ),
                    'auth_profile' => DiscoverySmokeTestHarness::AUTH_PROFILE,
                    'base_url' => $validated->baseUrl,
                    'store_code' => $validated->storeCode,
                    'tenant_context' => $validated->tenantContext,
                    'is_enabled' => true,
                    'settings' => [],
                    'credentials' => [],
                    'connection_status' => ConnectorAccountConnectionStatus::Untested,
                ]);

                return new ConnectorAccountSettingsResult(
                    id: $account->id,
                    connectorDefinitionId: $definition->id,
                    authProfile: DiscoverySmokeTestHarness::AUTH_PROFILE,
                    baseUrl: $validated->baseUrl,
                    storeCode: $validated->storeCode,
                    tenantContext: $validated->tenantContext,
                    settings: [],
                    isEnabled: true,
                    hasCredentials: true,
                );
            });
        $this->app->instance(ConnectorAccountPersistencePort::class, $settingsService);

        $harness = app(DiscoverySmokeTestHarness::class);
        $this->assertNull($harness->findMatchingSmokeTestAccount($this->workspace, $definition, $validated));

        $prompts = new RecordingDiscoverySmokeTestPromptGateway;
        $result = $harness->resolveAccountPath(
            $admin,
            $this->workspace,
            $definition,
            $validated,
            null,
            true,
            $prompts,
            new OAuth1Credentials('ck-new', 'cs-new', 'at-new', 'ts-new'),
        );

        $this->assertSame('create', $result['path']);
        $this->assertNotSame($ordinaryAccount->id, $result['account']->id);
        $ordinaryAccount->refresh();
        $this->assertSame($originalCredentials, $ordinaryAccount->credentials);
        $this->assertNotContains('askOAuth1Credentials', $prompts->calls);
    }

    #[Test]
    public function smoke_test_account_is_created_and_reused_on_subsequent_lookup(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $definition = $this->adobeConnectorDefinition();
        $validated = $harness->normalizeAccountSettings('https://shop.example.com', 'default', null);

        $this->createConnectorAccount($this->workspace, [
            'name' => 'Production Magento Store',
            'base_url' => 'https://shop.example.com',
            'store_code' => 'default',
        ]);

        $smokeAccount = $this->createSmokeTestConnectorAccount($validated);

        $this->assertSame(
            $smokeAccount->id,
            $harness->findMatchingSmokeTestAccount($this->workspace, $definition, $validated)?->id,
        );
    }

    #[Test]
    public function exact_smoke_test_name_with_mismatched_tuple_stops_without_secret_prompt(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $definition = $this->adobeConnectorDefinition();
        $validated = $harness->normalizeAccountSettings('https://shop.example.com', 'default', null);
        $expectedName = $harness->buildSmokeTestName(
            $this->workspace->id,
            $definition->id,
            DiscoverySmokeTestHarness::AUTH_PROFILE,
            $validated->baseUrl,
            $validated->storeCode,
            $validated->tenantContext,
        );

        $this->createConnectorAccount($this->workspace, [
            'name' => $expectedName,
            'base_url' => 'https://other.example.com',
            'store_code' => 'default',
        ]);

        $prompts = new RecordingDiscoverySmokeTestPromptGateway;

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('identity collision');

        try {
            $harness->findMatchingSmokeTestAccount($this->workspace, $definition, $validated);
        } finally {
            $this->assertNotContains('askOAuth1Credentials', $prompts->calls);
        }
    }

    #[Test]
    public function smoke_test_account_reuse_matches_full_six_part_tuple_and_name(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $definition = $this->adobeConnectorDefinition();

        $validatedA = $harness->normalizeAccountSettings(
            'https://shop.example.com',
            'default',
            'tenant-a',
        );
        $accountA = $this->createSmokeTestConnectorAccount($validatedA);

        $validatedB = $harness->normalizeAccountSettings(
            'https://shop.example.com',
            'default',
            'tenant-b',
        );
        $accountB = $this->createSmokeTestConnectorAccount($validatedB);

        $this->assertSame(
            $accountA->id,
            $harness->findMatchingSmokeTestAccount($this->workspace, $definition, $validatedA)?->id,
        );
        $this->assertSame(
            $accountB->id,
            $harness->findMatchingSmokeTestAccount($this->workspace, $definition, $validatedB)?->id,
        );
        $this->assertNotSame(
            $accountA->id,
            $harness->findMatchingSmokeTestAccount($this->workspace, $definition, $validatedB)?->id,
        );
    }

    #[Test]
    public function smoke_test_name_is_collision_safe_for_different_tenant_contexts(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $definition = $this->adobeConnectorDefinition();

        $nameA = $harness->buildSmokeTestName(
            $this->workspace->id,
            $definition->id,
            DiscoverySmokeTestHarness::AUTH_PROFILE,
            'https://shop.example.com',
            'default',
            'tenant-a',
        );

        $nameB = $harness->buildSmokeTestName(
            $this->workspace->id,
            $definition->id,
            DiscoverySmokeTestHarness::AUTH_PROFILE,
            'https://shop.example.com',
            'default',
            'tenant-b',
        );

        $this->assertNotSame($nameA, $nameB);
        $this->assertStringStartsWith('Smoke Test — ', $nameA);
        $this->assertStringNotContainsString('shop.example.com', $nameA);
    }

    #[Test]
    public function tenant_context_prompt_is_normalized_not_ignored(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);

        $withWhitespace = $harness->normalizeAccountSettings(
            'https://shop.example.com',
            'default',
            '  my-tenant  ',
        );

        $this->assertSame('my-tenant', $withWhitespace->tenantContext);

        $blank = $harness->normalizeAccountSettings(
            'https://shop.example.com',
            'default',
            '   ',
        );

        $this->assertNull($blank->tenantContext);
    }

    #[Test]
    public function disabled_matched_smoke_test_account_stops_without_mutation(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $definition = $this->adobeConnectorDefinition();
        $validated = app(DiscoverySmokeTestHarness::class)->normalizeAccountSettings(
            'https://shop.example.com',
            'default',
            null,
        );
        $account = $this->createSmokeTestConnectorAccount($validated, ['is_enabled' => false]);

        $harness = app(DiscoverySmokeTestHarness::class);

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('disabled');

        $harness->resolveAccountPath(
            $admin,
            $this->workspace,
            $definition,
            $validated,
            $account,
            false,
            new RecordingDiscoverySmokeTestPromptGateway,
        );
    }

    #[Test]
    public function wrong_resolver_valid_source_code_fails_as_canonical_missing(): void
    {
        $definition = $this->adobeConnectorDefinition();

        ConnectorSchemaSource::query()
            ->where('code', 'live_account_attributes')
            ->delete();

        ConnectorSchemaSource::query()->create([
            'connector_definition_id' => $definition->id,
            'code' => 'replacement_live_source',
            'label' => 'Replacement',
            'source_kind' => ConnectorSchemaSourceKind::AccountApi,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::LiveFetch,
            'schema_scope' => ConnectorSchemaScope::Account,
            'reference_url' => 'https://example.com',
            'endpoint_path' => '/V1/replacement/attributes',
            'schema_version' => 'test',
            'is_primary' => true,
            'verification_status' => ConnectorSchemaVerificationStatus::Verified,
            'sort_order' => 99,
        ]);

        $harness = app(DiscoverySmokeTestHarness::class);

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('live_account_attributes');

        $harness->resolveCanonicalSchemaSource($definition);
    }

    #[Test]
    public function unexpected_persistence_exception_does_not_leak_secrets_to_console(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $sentinels = [
            'sentinel-consumer-key-'.Str::random(6),
            'sentinel-consumer-secret-'.Str::random(6),
            'sentinel-access-token-'.Str::random(6),
            'sentinel-access-token-secret-'.Str::random(6),
        ];
        $beforeCount = ConnectorAccount::withoutWorkspaceScope()->count();

        $settingsService = Mockery::mock(ConnectorAccountPersistencePort::class);
        $settingsService->shouldReceive('create')
            ->once()
            ->andThrow(new \RuntimeException(
                'Persistence failed with '.$sentinels[0].' and '.$sentinels[1],
                0,
                new \RuntimeException('Nested '.$sentinels[2].' / '.$sentinels[3]),
            ));
        $this->app->instance(ConnectorAccountPersistencePort::class, $settingsService);

        $this->artisan('connectors:discovery-smoke-test', [
            '--actor-email' => $admin->email,
        ])
            ->expectsQuestion('Magento base URL (e.g. https://magento.example.com)', 'https://shop.example.com')
            ->expectsQuestion('Store code', 'default')
            ->expectsQuestion('Tenant context (optional, press Enter to skip)', '')
            ->expectsQuestion('Consumer Key', $sentinels[0])
            ->expectsQuestion('Consumer Secret', $sentinels[1])
            ->expectsQuestion('Access Token', $sentinels[2])
            ->expectsQuestion('Access Token Secret', $sentinels[3])
            ->expectsOutputToContain('unexpected error occurred')
            ->doesntExpectOutput($sentinels[0])
            ->doesntExpectOutput($sentinels[1])
            ->doesntExpectOutput($sentinels[2])
            ->doesntExpectOutput($sentinels[3])
            ->assertFailed();

        $this->assertSame($beforeCount, ConnectorAccount::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function validate_successful_run_rejects_incorrect_run_account_linkage(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $account = $this->createConnectorAccount($this->workspace);
        $otherAccount = $this->createConnectorAccount($this->workspace, ['name' => 'Other account']);
        $source = ConnectorSchemaSource::query()->where('code', 'live_account_attributes')->firstOrFail();
        $bundle = $this->seedQueuedRun($account, $source, hash('sha256', 'hash'), 1);
        $this->completeQueuedRun($account, $source, $bundle['run']->id, $bundle['snapshot']->id, hash('sha256', 'hash'), 1);

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($bundle['run']->id);
        $run->update(['connector_account_id' => $otherAccount->id]);
        $run->refresh();

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('account does not match');

        $harness->validateSuccessfulRun(
            $run,
            $source,
            $account,
            $this->workspace,
            $account,
        );
    }

    #[Test]
    public function validate_successful_run_rejects_incorrect_field_row_count(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $account = $this->createConnectorAccount($this->workspace);
        $source = ConnectorSchemaSource::query()->where('code', 'live_account_attributes')->firstOrFail();
        $bundle = $this->seedQueuedRun($account, $source, hash('sha256', 'hash'), 1);
        $this->completeQueuedRun($account, $source, $bundle['run']->id, $bundle['snapshot']->id, hash('sha256', 'hash'), 1);

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($bundle['run']->id);

        ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('snapshot_id', $bundle['snapshot']->id)
            ->where('external_field_key', 'size')
            ->delete();

        $run->refresh();

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('snapshot-field row count');

        $harness->validateSuccessfulRun(
            $run,
            $source,
            $account,
            $this->workspace,
            $account,
        );
    }

    #[Test]
    public function validate_successful_run_rejects_incorrect_connection_status(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $account = $this->createConnectorAccount($this->workspace);
        $source = ConnectorSchemaSource::query()->where('code', 'live_account_attributes')->firstOrFail();
        $bundle = $this->seedQueuedRun($account, $source, hash('sha256', 'hash'), 1);
        $this->completeQueuedRun($account, $source, $bundle['run']->id, $bundle['snapshot']->id, hash('sha256', 'hash'), 1);

        $account->update(['connection_status' => ConnectorAccountConnectionStatus::AttentionRequired]);
        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($bundle['run']->id);

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('connection_status');

        $harness->validateSuccessfulRun(
            $run,
            $source,
            $account,
            $this->workspace,
            $account,
        );
    }

    #[Test]
    public function poll_run_to_terminal_prints_status_on_each_iteration(): void
    {
        Carbon::setTestNow('2026-08-03 12:00:00');

        $harness = app(DiscoverySmokeTestHarness::class);
        $account = $this->createConnectorAccount($this->workspace);
        $source = ConnectorSchemaSource::query()->where('code', 'live_account_attributes')->firstOrFail();
        $bundle = $this->seedQueuedRun($account, $source, hash('sha256', 'hash'), 1);
        $run = $bundle['run'];
        $iterations = 0;

        $buffer = new BufferedOutput;
        $output = new OutputStyle(new ArrayInput([]), $buffer);
        $deadline = now()->addSeconds(10);

        $result = $harness->pollRunToTerminal(
            $run,
            function () use (&$iterations, $account, $source, $bundle): void {
                $iterations++;

                if ($iterations === 2) {
                    $this->completeQueuedRun(
                        $account,
                        $source,
                        $bundle['run']->id,
                        $bundle['snapshot']->id,
                        hash('sha256', 'hash'),
                        1,
                    );
                }
            },
            $deadline,
            $output,
        );

        $capturedOutput = $buffer->fetch();
        $this->assertGreaterThanOrEqual(2, $iterations);
        $this->assertGreaterThanOrEqual(2, substr_count($capturedOutput, 'Polling run ['));
        $this->assertTrue($result->isTerminal());

        Carbon::setTestNow();
    }

    #[Test]
    public function zero_schema_sources_fails_explicitly_and_creates_nothing(): void
    {
        ConnectorSchemaSource::query()
            ->where('acquisition_mode', ConnectorSchemaAcquisitionMode::LiveFetch)
            ->where('schema_scope', ConnectorSchemaScope::Account)
            ->delete();

        $harness = app(DiscoverySmokeTestHarness::class);
        $definition = $this->adobeConnectorDefinition();
        $beforeCount = ConnectorSchemaSource::query()->count();

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('ConnectorFoundationSeeder');

        try {
            $harness->resolveCanonicalSchemaSource($definition);
        } finally {
            $this->assertSame($beforeCount, ConnectorSchemaSource::query()->count());
        }
    }

    #[Test]
    public function exactly_one_schema_source_is_reused_without_modification(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $definition = $this->adobeConnectorDefinition();
        $beforeCount = ConnectorSchemaSource::query()->count();

        $source = $harness->resolveCanonicalSchemaSource($definition);

        $this->assertSame('live_account_attributes', $source->code);
        $this->assertSame($beforeCount, ConnectorSchemaSource::query()->count());
    }

    #[Test]
    public function multiple_schema_sources_fails_with_ambiguity(): void
    {
        $definition = $this->adobeConnectorDefinition();

        ConnectorSchemaSource::query()->create([
            'connector_definition_id' => $definition->id,
            'code' => 'duplicate_live_source',
            'label' => 'Duplicate',
            'source_kind' => ConnectorSchemaSourceKind::AccountApi,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::LiveFetch,
            'schema_scope' => ConnectorSchemaScope::Account,
            'reference_url' => 'https://example.com',
            'endpoint_path' => '/V1/duplicate/attributes',
            'schema_version' => 'test',
            'is_primary' => true,
            'verification_status' => ConnectorSchemaVerificationStatus::Verified,
            'sort_order' => 99,
        ]);

        $harness = app(DiscoverySmokeTestHarness::class);
        $beforeCount = ConnectorSchemaSource::query()->count();

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('Ambiguous schema source');

        try {
            $harness->resolveCanonicalSchemaSource($definition);
        } finally {
            $this->assertSame($beforeCount, ConnectorSchemaSource::query()->count());
        }
    }

    #[Test]
    public function no_secret_value_appears_in_captured_console_output(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $secret = 'super-secret-consumer-key-'.Str::random(8);
        $definition = $this->adobeConnectorDefinition();

        $settingsService = Mockery::mock(ConnectorAccountPersistencePort::class);
        $settingsService->shouldReceive('create')
            ->once()
            ->andReturnUsing(function () use ($definition): ConnectorAccountSettingsResult {
                $account = ConnectorAccount::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $this->workspace->id,
                    'connector_definition_id' => $definition->id,
                    'name' => 'Smoke Test — abcdef12',
                    'auth_profile' => DiscoverySmokeTestHarness::AUTH_PROFILE,
                    'base_url' => 'https://shop.example.com',
                    'store_code' => 'default',
                    'tenant_context' => null,
                    'is_enabled' => true,
                    'settings' => [],
                    'credentials' => [],
                    'connection_status' => ConnectorAccountConnectionStatus::Untested,
                ]);

                return new ConnectorAccountSettingsResult(
                    id: $account->id,
                    connectorDefinitionId: $definition->id,
                    authProfile: DiscoverySmokeTestHarness::AUTH_PROFILE,
                    baseUrl: 'https://shop.example.com',
                    storeCode: 'default',
                    tenantContext: null,
                    settings: [],
                    isEnabled: true,
                    hasCredentials: true,
                );
            });
        $this->app->instance(ConnectorAccountPersistencePort::class, $settingsService);

        $this->artisan('connectors:discovery-smoke-test', [
            '--actor-email' => $admin->email,
        ])
            ->expectsQuestion('Magento base URL (e.g. https://magento.example.com)', 'https://shop.example.com')
            ->expectsQuestion('Store code', 'default')
            ->expectsQuestion('Tenant context (optional, press Enter to skip)', '')
            ->expectsQuestion('Consumer Key', $secret)
            ->expectsQuestion('Consumer Secret', 'cs')
            ->expectsQuestion('Access Token', 'at')
            ->expectsQuestion('Access Token Secret', 'ts')
            ->expectsConfirmation('Worker is running — continue?', 'no')
            ->doesntExpectOutput($secret)
            ->assertFailed();
    }

    #[Test]
    public function in_process_manual_trigger_override_does_not_persist_to_env_file(): void
    {
        config(['connectors.discovery.manual_trigger_enabled' => false]);

        $harness = app(DiscoverySmokeTestHarness::class);
        $harness->enableInProcessManualTrigger();

        $this->assertTrue(config('connectors.discovery.manual_trigger_enabled'));

        $envContents = file_get_contents(base_path('.env')) ?: '';
        $this->assertStringNotContainsString('CONNECTOR_DISCOVERY_MANUAL_TRIGGER_ENABLED=true', $envContents);
    }

    #[Test]
    public function active_existing_run_is_drained_but_not_counted_as_proof_run(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);
        $definition = $this->adobeConnectorDefinition();
        $source = ConnectorSchemaSource::query()
            ->where('code', 'live_account_attributes')
            ->firstOrFail();

        $activeRun = ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => ConnectorDiscoveryRunTrigger::Manual,
            'status' => ConnectorDiscoveryRunStatus::Running,
            'execution_attempts' => 1,
            'retry_until_at' => now()->addHour(),
        ]);
        $activeRunId = $activeRun->id;
        $newRunOneId = (string) Str::uuid();
        $newRunTwoId = (string) Str::uuid();

        $dispatchService = Mockery::mock(ConnectorDiscoveryDispatchPort::class);
        $dispatchService->shouldReceive('executeManual')
            ->times(3)
            ->andReturn(
                ConnectorDiscoveryDispatchDecision::existing($activeRunId),
                ConnectorDiscoveryDispatchDecision::dispatch($newRunOneId, now()->addHour()->getTimestamp()),
                ConnectorDiscoveryDispatchDecision::dispatch($newRunTwoId, now()->addMinutes(90)->getTimestamp()),
            );
        $this->app->instance(ConnectorDiscoveryDispatchPort::class, $dispatchService);

        $harness = app(DiscoverySmokeTestHarness::class);

        $activeRun->update([
            'status' => ConnectorDiscoveryRunStatus::Succeeded,
            'finished_at' => now(),
            'snapshot_id' => $this->createSucceededSnapshot($account, $source, $activeRunId)->id,
            'fields_received' => 5,
            'fields_normalized' => 5,
            'execution_attempts' => 1,
        ]);

        $decisionOne = $harness->obtainFreshDispatch($admin, $this->workspace, $account, static function (): void {});
        $this->assertTrue($decisionOne->shouldDispatch);
        $this->assertSame($newRunOneId, $decisionOne->discoveryRunId);
        $this->assertNotSame($activeRunId, $decisionOne->discoveryRunId);

        $decisionTwo = $harness->obtainFreshDispatch($admin, $this->workspace, $account, static function (): void {});
        $this->assertTrue($decisionTwo->shouldDispatch);
        $this->assertSame($newRunTwoId, $decisionTwo->discoveryRunId);
    }

    #[Test]
    public function polling_deadline_is_derived_from_decision_retry_timestamp(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $retryUntil = now()->addMinutes(45)->getTimestamp();

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $this->createConnectorAccount($this->workspace)->id,
            'connector_schema_source_id' => ConnectorSchemaSource::query()->firstOrFail()->id,
            'trigger' => ConnectorDiscoveryRunTrigger::Manual,
            'status' => ConnectorDiscoveryRunStatus::Queued,
            'execution_attempts' => 0,
            'retry_until_at' => Carbon::createFromTimestamp($retryUntil),
        ]);

        $decision = ConnectorDiscoveryDispatchDecision::dispatch($run->id, $retryUntil);
        $deadline = $harness->computePollingDeadline($decision, $run);

        $this->assertTrue($deadline->equalTo(Carbon::createFromTimestamp($retryUntil)->addSeconds(DiscoverySmokeTestHarness::POLL_GRACE_SECONDS)));
    }

    #[Test]
    public function two_run_orchestration_produces_distinct_ids_and_matching_hashes(): void
    {
        Carbon::setTestNow('2026-08-03 12:00:00');

        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);
        $source = ConnectorSchemaSource::query()->where('code', 'live_account_attributes')->firstOrFail();

        $canonicalHash = hash('sha256', 'stable-schema');

        $runOneBundle = $this->seedQueuedRun($account, $source, $canonicalHash, 1);
        $runTwoBundle = $this->seedQueuedRun($account, $source, $canonicalHash, 2);
        $runOneId = $runOneBundle['run']->id;
        $runTwoId = $runTwoBundle['run']->id;
        $snapshotOneId = $runOneBundle['snapshot']->id;
        $snapshotTwoId = $runTwoBundle['snapshot']->id;

        $dispatchService = Mockery::mock(ConnectorDiscoveryDispatchPort::class);
        $dispatchService->shouldReceive('executeManual')
            ->twice()
            ->andReturn(
                ConnectorDiscoveryDispatchDecision::dispatch($runOneId, now()->addHour()->getTimestamp()),
                ConnectorDiscoveryDispatchDecision::dispatch($runTwoId, now()->addHour()->getTimestamp()),
            );
        $this->app->instance(ConnectorDiscoveryDispatchPort::class, $dispatchService);

        $harness = app(DiscoverySmokeTestHarness::class);
        $harness->enableInProcessManualTrigger();

        $output = new OutputStyle(new ArrayInput([]), new BufferedOutput);

        $result = $harness->runStabilityCheck(
            $admin,
            $this->workspace,
            $account,
            $source,
            $output,
            function () use ($account, $source, $runOneId, $runTwoId, $snapshotOneId, $snapshotTwoId, $canonicalHash): void {
                foreach ([
                    [$runOneId, $snapshotOneId, 1],
                    [$runTwoId, $snapshotTwoId, 2],
                ] as [$runId, $snapshotId, $offset]) {
                    $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->find($runId);

                    if ($run !== null && ! $run->isTerminal()) {
                        $this->completeQueuedRun($account, $source, $runId, $snapshotId, $canonicalHash, $offset);

                        return;
                    }
                }
            },
        );

        $this->assertNotSame($result['first']['run_id'], $result['second']['run_id']);
        $this->assertNotSame($result['first']['snapshot_id'], $result['second']['snapshot_id']);
        $this->assertSame($canonicalHash, $result['first']['canonical_hash']);
        $this->assertSame($canonicalHash, $result['second']['canonical_hash']);

        Carbon::setTestNow();
    }

    private function createSmokeTestConnectorAccount(
        ValidatedConnectorAccountState $validated,
        array $overrides = [],
    ): ConnectorAccount {
        $harness = app(DiscoverySmokeTestHarness::class);
        $definition = $this->adobeConnectorDefinition();
        $name = $harness->buildSmokeTestName(
            $this->workspace->id,
            $definition->id,
            DiscoverySmokeTestHarness::AUTH_PROFILE,
            $validated->baseUrl,
            $validated->storeCode,
            $validated->tenantContext,
        );

        return $this->createConnectorAccount($this->workspace, array_merge([
            'name' => $name,
            'base_url' => $validated->baseUrl,
            'store_code' => $validated->storeCode,
            'tenant_context' => $validated->tenantContext,
        ], $overrides));
    }

    #[Test]
    public function validate_successful_run_accepts_received_greater_than_normalized(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $account = $this->createConnectorAccount($this->workspace);
        $source = ConnectorSchemaSource::query()->where('code', 'live_account_attributes')->firstOrFail();
        $accountBefore = new ConnectorAccount([
            'last_discovery_at' => null,
            'last_successful_discovery_at' => null,
        ]);
        $bundle = $this->seedQueuedRun($account, $source, hash('sha256', 'pilot-hash'), 1);
        $this->completeQueuedRun($account, $source, $bundle['run']->id, $bundle['snapshot']->id, hash('sha256', 'pilot-hash'), 1);

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($bundle['run']->id);
        $run->update([
            'fields_received' => 106,
            'fields_normalized' => 2,
        ]);

        $evidence = $harness->validateSuccessfulRun(
            $run->refresh(),
            $source,
            $accountBefore,
            $this->workspace,
            $account,
        );

        $this->assertSame(106, $evidence['run']->fields_received);
        $this->assertSame(2, $evidence['run']->fields_normalized);
        $this->assertSame(2, $evidence['snapshot']->field_count);
    }

    #[Test]
    public function validate_successful_run_rejects_received_less_than_normalized(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $account = $this->createConnectorAccount($this->workspace);
        $source = ConnectorSchemaSource::query()->where('code', 'live_account_attributes')->firstOrFail();
        $bundle = $this->seedQueuedRun($account, $source, hash('sha256', 'hash'), 1);
        $this->completeQueuedRun($account, $source, $bundle['run']->id, $bundle['snapshot']->id, hash('sha256', 'hash'), 1);

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($bundle['run']->id);
        $run->update([
            'fields_received' => 1,
            'fields_normalized' => 2,
        ]);

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('Resolved success invariant failed');

        $harness->validateSuccessfulRun(
            $run->refresh(),
            $source,
            $account,
            $this->workspace,
            $account,
        );
    }

    #[Test]
    public function validate_successful_run_rejects_zero_or_null_field_counts(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $account = $this->createConnectorAccount($this->workspace);
        $source = ConnectorSchemaSource::query()->where('code', 'live_account_attributes')->firstOrFail();
        $bundle = $this->seedQueuedRun($account, $source, hash('sha256', 'hash'), 1);
        $this->completeQueuedRun($account, $source, $bundle['run']->id, $bundle['snapshot']->id, hash('sha256', 'hash'), 1);

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($bundle['run']->id);
        $run->update([
            'fields_received' => 0,
            'fields_normalized' => 0,
        ]);

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('Resolved success invariant failed');

        $harness->validateSuccessfulRun(
            $run->refresh(),
            $source,
            $account,
            $this->workspace,
            $account,
        );
    }

    #[Test]
    public function validate_successful_run_rejects_snapshot_field_count_mismatch_with_normalized_count(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $account = $this->createConnectorAccount($this->workspace);
        $source = ConnectorSchemaSource::query()->where('code', 'live_account_attributes')->firstOrFail();
        $bundle = $this->seedQueuedRun($account, $source, hash('sha256', 'hash'), 1);
        $this->completeQueuedRun($account, $source, $bundle['run']->id, $bundle['snapshot']->id, hash('sha256', 'hash'), 1);

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($bundle['run']->id);
        $run->update([
            'fields_received' => 106,
            'fields_normalized' => 102,
        ]);

        $this->expectException(DiscoverySmokeTestAbortedException::class);
        $this->expectExceptionMessage('field_count (2) does not match fields_normalized (102)');

        $harness->validateSuccessfulRun(
            $run->refresh(),
            $source,
            $account,
            $this->workspace,
            $account,
        );
    }

    private function createSucceededSnapshot(
        ConnectorAccount $account,
        ConnectorSchemaSource $source,
        string $runId,
    ): ConnectorSchemaSnapshot {
        return ConnectorSchemaSnapshot::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'discovery_run_id' => $runId,
            'schema_version' => 'test',
            'field_count' => 1,
            'canonical_hash' => hash('sha256', 'snapshot'),
            'captured_at' => now(),
        ]);
    }

    /**
     * @return array{run: ConnectorDiscoveryRun, snapshot: ConnectorSchemaSnapshot}
     */
    private function seedQueuedRun(
        ConnectorAccount $account,
        ConnectorSchemaSource $source,
        string $canonicalHash,
        int $offsetSeconds,
    ): array {
        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => ConnectorDiscoveryRunTrigger::Manual,
            'status' => ConnectorDiscoveryRunStatus::Queued,
            'execution_attempts' => 0,
            'retry_until_at' => now()->addHour(),
        ]);

        $snapshot = ConnectorSchemaSnapshot::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'discovery_run_id' => $run->id,
            'schema_version' => 'test',
            'field_count' => 2,
            'canonical_hash' => $canonicalHash,
            'captured_at' => now()->addSeconds($offsetSeconds),
        ]);

        return ['run' => $run, 'snapshot' => $snapshot];
    }

    private function completeQueuedRun(
        ConnectorAccount $account,
        ConnectorSchemaSource $source,
        string $runId,
        string $snapshotId,
        string $canonicalHash,
        int $offsetSeconds,
    ): void {
        ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'snapshot_id' => $snapshotId,
            'external_field_key' => 'color',
            'external_label' => 'Color',
            'normalized_data_type' => 'string',
            'is_required' => false,
            'is_multi_value' => false,
            'is_localizable' => false,
            'normalized_payload' => (object) ['key' => 'color'],
            'canonical_hash' => hash('sha256', 'color'),
            'sort_order' => 1,
        ]);
        ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'snapshot_id' => $snapshotId,
            'external_field_key' => 'size',
            'external_label' => 'Size',
            'normalized_data_type' => 'string',
            'is_required' => false,
            'is_multi_value' => false,
            'is_localizable' => false,
            'normalized_payload' => (object) ['key' => 'size'],
            'canonical_hash' => hash('sha256', 'size'),
            'sort_order' => 2,
        ]);

        ConnectorSchemaSnapshot::withoutWorkspaceScope()
            ->where('id', $snapshotId)
            ->update(['field_count' => 2]);

        ConnectorDiscoveryRun::withoutWorkspaceScope()
            ->where('id', $runId)
            ->update([
                'status' => ConnectorDiscoveryRunStatus::Succeeded,
                'execution_attempts' => 1,
                'finished_at' => now()->addSeconds($offsetSeconds),
                'fields_received' => 2,
                'fields_normalized' => 2,
                'snapshot_id' => $snapshotId,
            ]);

        $account->update([
            'last_discovery_at' => now()->addSeconds($offsetSeconds),
            'last_successful_discovery_at' => now()->addSeconds($offsetSeconds),
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);
    }

    private function seedSucceededRun(
        ConnectorAccount $account,
        ConnectorSchemaSource $source,
        string $runId,
        string $snapshotId,
        string $canonicalHash,
    ): void {
        ConnectorSchemaSnapshot::withoutWorkspaceScope()->create([
            'id' => $snapshotId,
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'discovery_run_id' => $runId,
            'schema_version' => 'test',
            'field_count' => 2,
            'canonical_hash' => $canonicalHash,
            'captured_at' => now(),
        ]);

        ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'snapshot_id' => $snapshotId,
            'external_field_key' => 'color',
            'external_label' => 'Color',
            'normalized_data_type' => 'string',
            'is_required' => false,
            'is_multi_value' => false,
            'is_localizable' => false,
            'normalized_payload' => (object) ['key' => 'color'],
            'canonical_hash' => hash('sha256', 'color'),
            'sort_order' => 1,
        ]);

        ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'id' => $runId,
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => ConnectorDiscoveryRunTrigger::Manual,
            'status' => ConnectorDiscoveryRunStatus::Succeeded,
            'execution_attempts' => 1,
            'retry_until_at' => now()->addHour(),
            'finished_at' => now(),
            'fields_received' => 2,
            'fields_normalized' => 2,
            'snapshot_id' => $snapshotId,
        ]);

        $account->update([
            'last_discovery_at' => now(),
            'last_successful_discovery_at' => now(),
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);
    }
}

final class RecordingDiscoverySmokeTestPromptGateway implements DiscoverySmokeTestPromptGateway
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        private readonly bool $forceReplace = false,
    ) {}

    public function askBaseUrl(): string
    {
        $this->calls[] = 'askBaseUrl';

        return 'https://shop.example.com';
    }

    public function askStoreCode(): string
    {
        $this->calls[] = 'askStoreCode';

        return 'default';
    }

    public function askTenantContext(): ?string
    {
        $this->calls[] = 'askTenantContext';

        return null;
    }

    public function confirmReplaceCredentials(): bool
    {
        $this->calls[] = 'confirmReplaceCredentials';

        return $this->forceReplace;
    }

    public function askOAuth1Credentials(): OAuth1Credentials
    {
        $this->calls[] = 'askOAuth1Credentials';

        return new OAuth1Credentials('ck', 'cs', 'at', 'ts');
    }

    public function confirmWorkerRunning(): bool
    {
        $this->calls[] = 'confirmWorkerRunning';

        return true;
    }
}
