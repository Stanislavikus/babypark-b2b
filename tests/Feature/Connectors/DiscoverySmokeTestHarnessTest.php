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
        $account = $this->createConnectorAccount($this->workspace);
        $definition = $this->adobeConnectorDefinition();
        $validated = app(DiscoverySmokeTestHarness::class)->normalizeAccountSettings(
            (string) $account->base_url,
            (string) $account->store_code,
            $account->tenant_context,
        );

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
    public function account_reuse_matches_full_six_part_tuple(): void
    {
        $harness = app(DiscoverySmokeTestHarness::class);
        $definition = $this->adobeConnectorDefinition();

        $baseAccount = $this->createConnectorAccount($this->workspace, [
            'base_url' => 'https://shop.example.com',
            'store_code' => 'default',
            'tenant_context' => 'tenant-a',
        ]);

        $differentTenant = $this->createConnectorAccount($this->workspace, [
            'base_url' => 'https://shop.example.com',
            'store_code' => 'default',
            'tenant_context' => 'tenant-b',
            'name' => 'Other tenant',
        ]);

        $validatedA = $harness->normalizeAccountSettings(
            'https://shop.example.com',
            'default',
            'tenant-a',
        );

        $this->assertSame(
            $baseAccount->id,
            $harness->findMatchingAccount($this->workspace, $definition, $validatedA)?->id,
        );

        $validatedB = $harness->normalizeAccountSettings(
            'https://shop.example.com',
            'default',
            'tenant-b',
        );

        $this->assertSame(
            $differentTenant->id,
            $harness->findMatchingAccount($this->workspace, $definition, $validatedB)?->id,
        );
        $this->assertNotSame($baseAccount->id, $harness->findMatchingAccount($this->workspace, $definition, $validatedB)?->id);
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
    public function disabled_matched_account_stops_without_mutation(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace, ['is_enabled' => false]);
        $definition = $this->adobeConnectorDefinition();
        $validated = app(DiscoverySmokeTestHarness::class)->normalizeAccountSettings(
            (string) $account->base_url,
            (string) $account->store_code,
            $account->tenant_context,
        );

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
