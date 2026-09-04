<?php

namespace Tests\Feature\Connectors;

use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorAccountSettingsResult;
use App\Services\Connectors\ConnectorAccountSettingsService;
use App\Services\Connectors\CreateConnectorAccountInput;
use App\Services\Connectors\UpdateConnectorAccountInput;
use App\Support\Connectors\AdobePaaS\AdobePaaSAccountSchema;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectorAdapter;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSSettingsInput;
use App\Support\Connectors\AdobePaaS\Exceptions\IncompleteAdobePaaSCredentialsException;
use App\Support\Connectors\ConnectorAccountSettingsInput;
use App\Support\Connectors\ConnectorProfileDefinition;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\CredentialMutation;
use App\Support\Connectors\Exceptions\ConnectorAccountNameConflict;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Connectors\Exceptions\ConnectorAccountProfileInputMismatchException;
use App\Support\Connectors\Exceptions\ConnectorAccountSettingsValidationException;
use App\Support\Connectors\Exceptions\ConnectorAccountTargetFrozenException;
use App\Support\Connectors\Exceptions\ConnectorDefinitionNotFoundException;
use App\Support\Connectors\Exceptions\ConnectorProfileNotFoundException;
use App\Support\Connectors\Exceptions\DisabledConnectorProfileException;
use App\Support\Connectors\Exceptions\InvalidCredentialMutationException;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Concerns\InteractsWithEntityTrustFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;
use Tests\Unit\Connectors\OAuth1\AssertsOAuth1SecretsSafely;

class ConnectorAccountSettingsServiceTest extends TestCase
{
    use AssertsOAuth1SecretsSafely;
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
    use InteractsWithEntityTrustFixtures;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    private ConnectorAccountSettingsService $service;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();

        $this->workspace = $this->defaultWorkspace();
        $this->service = app(ConnectorAccountSettingsService::class);
    }

    #[Test]
    public function manage_connector_accounts_permission_is_registered(): void
    {
        $permission = Permission::findByName(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS, 'web');

        $this->assertSame(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS, $permission->name);
    }

    #[Test]
    public function create_with_keep_results_in_account_without_credentials(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $result = $this->service->create(
            $admin,
            $this->workspace,
            CreateConnectorAccountInput::adobePaas(
                connectorDefinitionId: $this->adobeConnectorDefinition()->id,
                name: 'PaaS No Creds',
                baseUrl: 'https://shop.example.com',
                storeCode: 'default',
                tenantContext: null,
                credentialMutation: CredentialMutation::keep(),
            ),
        );

        $this->assertFalse($result->hasCredentials);
        $this->assertInstanceOf(ConnectorAccountSettingsResult::class, $result);
        $this->assertDatabaseHas('connector_accounts', [
            'id' => $result->id,
            'name' => 'PaaS No Creds',
        ]);
    }

    #[Test]
    public function create_rejects_remove_credential_mutation(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $this->expectException(InvalidCredentialMutationException::class);

        $this->service->create(
            $admin,
            $this->workspace,
            CreateConnectorAccountInput::adobePaas(
                connectorDefinitionId: $this->adobeConnectorDefinition()->id,
                name: 'Invalid Remove',
                baseUrl: 'https://shop.example.com',
                storeCode: 'default',
                tenantContext: null,
                credentialMutation: CredentialMutation::remove(),
            ),
        );
    }

    #[Test]
    public function update_keep_leaves_credentials_unchanged_and_remove_clears_them(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);

        $kept = $this->service->update(
            $admin,
            $this->workspace,
            $account->id,
            UpdateConnectorAccountInput::adobePaas(
                baseUrl: 'https://shop.example.com',
                storeCode: 'default',
                tenantContext: 'tenant-a',
                credentialMutation: CredentialMutation::keep(),
            ),
        );

        $this->assertTrue($kept->hasCredentials);

        $removed = $this->service->update(
            $admin,
            $this->workspace,
            $account->id,
            UpdateConnectorAccountInput::adobePaas(
                baseUrl: 'https://shop.example.com',
                storeCode: 'default',
                tenantContext: 'tenant-a',
                credentialMutation: CredentialMutation::remove(),
            ),
        );

        $this->assertFalse($removed->hasCredentials);
        $account->refresh();
        $this->assertSame([], $account->credentials);
    }

    #[Test]
    public function unauthorized_create_denies_before_profile_registry_is_invoked(): void
    {
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $registry = new class(app()) extends ConnectorProfileRegistry
        {
            public int $profileDefinitionCalls = 0;

            public function profileDefinition(string $profileCode): ConnectorProfileDefinition
            {
                $this->profileDefinitionCalls++;

                return parent::profileDefinition($profileCode);
            }
        };
        $this->app->instance(ConnectorProfileRegistry::class, $registry);
        $service = app(ConnectorAccountSettingsService::class);

        try {
            $service->create(
                $merchandiser,
                $this->workspace,
                CreateConnectorAccountInput::adobePaas(
                    connectorDefinitionId: $this->adobeConnectorDefinition()->id,
                    name: 'Denied',
                    baseUrl: 'https://shop.example.com',
                    storeCode: 'default',
                    tenantContext: null,
                    credentialMutation: CredentialMutation::keep(),
                ),
            );
            $this->fail('Expected authorization failure.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $registry->profileDefinitionCalls);
        }
    }

    #[Test]
    public function unauthorized_create_has_same_denial_for_valid_and_invalid_definition_ids(): void
    {
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);

        $validInput = CreateConnectorAccountInput::adobePaas(
            connectorDefinitionId: $this->adobeConnectorDefinition()->id,
            name: 'Denied',
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            tenantContext: null,
            credentialMutation: CredentialMutation::keep(),
        );

        $invalidInput = CreateConnectorAccountInput::adobePaas(
            connectorDefinitionId: (string) Str::uuid(),
            name: 'Denied',
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            tenantContext: null,
            credentialMutation: CredentialMutation::keep(),
        );

        $validException = null;
        $invalidException = null;

        try {
            $this->service->create($merchandiser, $this->workspace, $validInput);
        } catch (AuthorizationException $exception) {
            $validException = $exception;
        }

        try {
            $this->service->create($merchandiser, $this->workspace, $invalidInput);
        } catch (AuthorizationException $exception) {
            $invalidException = $exception;
        }

        $this->assertInstanceOf(AuthorizationException::class, $validException);
        $this->assertInstanceOf(AuthorizationException::class, $invalidException);
    }

    #[Test]
    public function create_checks_only_create_ability_not_replace_credentials(): void
    {
        Gate::before(function ($user, string $ability): ?bool {
            if ($ability === 'replaceCredentials') {
                return false;
            }

            return null;
        });

        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $result = $this->service->create(
            $admin,
            $this->workspace,
            CreateConnectorAccountInput::adobePaas(
                connectorDefinitionId: $this->adobeConnectorDefinition()->id,
                name: 'Gate Matrix',
                baseUrl: 'https://shop.example.com',
                storeCode: 'default',
                tenantContext: null,
                credentialMutation: CredentialMutation::replace(
                    new OAuth1Credentials('ck', 'cs', 'at', 'ts'),
                ),
            ),
        );

        $this->assertTrue($result->hasCredentials);
    }

    #[Test]
    public function update_with_replace_requires_replace_credentials_ability(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $originalCredentials = $account->credentials;

        Gate::before(function ($user, string $ability): ?bool {
            if ($ability === 'replaceCredentials') {
                return false;
            }

            return null;
        });

        try {
            $this->service->update(
                $admin,
                $this->workspace,
                $account->id,
                UpdateConnectorAccountInput::adobePaas(
                    baseUrl: 'https://shop.example.com',
                    storeCode: 'default',
                    tenantContext: null,
                    credentialMutation: CredentialMutation::replace(
                        new OAuth1Credentials('ck2', 'cs2', 'at2', 'ts2'),
                    ),
                ),
            );
            $this->fail('Expected authorization failure for replace credentials.');
        } catch (AuthorizationException) {
            $account->refresh();
            $this->assertSame($originalCredentials, $account->credentials);
        }
    }

    #[Test]
    public function update_with_remove_requires_remove_credentials_ability(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        Gate::before(function ($user, string $ability): ?bool {
            if ($ability === 'removeCredentials') {
                return false;
            }

            return null;
        });

        try {
            $this->service->update(
                $admin,
                $this->workspace,
                $account->id,
                UpdateConnectorAccountInput::adobePaas(
                    baseUrl: 'https://shop.example.com',
                    storeCode: 'default',
                    tenantContext: null,
                    credentialMutation: CredentialMutation::remove(),
                ),
            );
            $this->fail('Expected authorization failure for remove credentials.');
        } catch (AuthorizationException) {
            $account->refresh();
            $this->assertNotSame([], $account->credentials);
        }
    }

    #[Test]
    public function update_with_keep_requires_only_update_settings_ability(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        Gate::before(function ($user, string $ability): ?bool {
            if ($ability === 'updateSettings') {
                return false;
            }

            return null;
        });

        try {
            $this->service->update(
                $admin,
                $this->workspace,
                $account->id,
                UpdateConnectorAccountInput::adobePaas(
                    baseUrl: 'https://updated.example.com',
                    storeCode: 'default',
                    tenantContext: null,
                    credentialMutation: CredentialMutation::keep(),
                ),
            );
            $this->fail('Expected authorization failure for update settings.');
        } catch (AuthorizationException) {
            $account->refresh();
            $this->assertSame('https://shop.example.com', $account->base_url);
        }
    }

    #[Test]
    public function cross_workspace_update_lookup_matches_unknown_account_not_found(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $otherWorkspace = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $foreignAccount = $this->createConnectorAccount($otherWorkspace);

        $unknownId = (string) Str::uuid();

        try {
            $this->service->update(
                $admin,
                $this->workspace,
                $foreignAccount->id,
                UpdateConnectorAccountInput::adobePaas(
                    baseUrl: 'https://shop.example.com',
                    storeCode: 'default',
                    tenantContext: null,
                    credentialMutation: CredentialMutation::keep(),
                ),
            );
            $this->fail('Expected not found for cross-workspace account.');
        } catch (ConnectorAccountNotFoundException $crossWorkspaceException) {
            try {
                $this->service->update(
                    $admin,
                    $this->workspace,
                    $unknownId,
                    UpdateConnectorAccountInput::adobePaas(
                        baseUrl: 'https://shop.example.com',
                        storeCode: 'default',
                        tenantContext: null,
                        credentialMutation: CredentialMutation::keep(),
                    ),
                );
                $this->fail('Expected not found for unknown account.');
            } catch (ConnectorAccountNotFoundException $unknownException) {
                $this->assertSame($crossWorkspaceException->getMessage(), $unknownException->getMessage());
            }
        }
    }

    #[Test]
    public function create_rejects_unknown_connector_definition_before_persistence(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $this->expectException(ConnectorDefinitionNotFoundException::class);

        $this->service->create(
            $admin,
            $this->workspace,
            CreateConnectorAccountInput::adobePaas(
                connectorDefinitionId: (string) Str::uuid(),
                name: 'Missing Definition',
                baseUrl: 'https://shop.example.com',
                storeCode: 'default',
                tenantContext: null,
                credentialMutation: CredentialMutation::keep(),
            ),
        );
    }

    #[Test]
    public function create_rejects_unknown_auth_profile_via_registry_exception(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $this->expectException(ConnectorProfileNotFoundException::class);

        $this->service->create(
            $admin,
            $this->workspace,
            new CreateConnectorAccountInput(
                connectorDefinitionId: $this->adobeConnectorDefinition()->id,
                name: 'Unknown Profile',
                authProfile: 'missing_profile',
                settings: new AdobePaaSSettingsInput(
                    'https://shop.example.com',
                    'default',
                    null,
                ),
                credentialMutation: CredentialMutation::keep(),
            ),
        );
    }

    #[Test]
    public function create_rejects_disabled_auth_profile_via_registry_exception(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $registry = new ConnectorProfileRegistry(app(), [
            'adobe_commerce_paas_oauth1_integration' => [
                'enabled' => false,
                'connector_definition_code' => 'adobe_commerce',
                'adapter' => AdobePaaSConnectorAdapter::class,
                'account_schema' => AdobePaaSAccountSchema::class,
                'capabilities' => [],
            ],
        ]);
        $this->app->instance(ConnectorProfileRegistry::class, $registry);

        $this->expectException(DisabledConnectorProfileException::class);

        app(ConnectorAccountSettingsService::class)->create(
            $admin,
            $this->workspace,
            CreateConnectorAccountInput::adobePaas(
                connectorDefinitionId: $this->adobeConnectorDefinition()->id,
                name: 'Disabled Profile',
                baseUrl: 'https://shop.example.com',
                storeCode: 'default',
                tenantContext: null,
                credentialMutation: CredentialMutation::keep(),
            ),
        );
    }

    #[Test]
    public function create_rejects_invalid_name_before_persistence(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $this->expectException(ConnectorAccountSettingsValidationException::class);

        $this->service->create(
            $admin,
            $this->workspace,
            CreateConnectorAccountInput::adobePaas(
                connectorDefinitionId: $this->adobeConnectorDefinition()->id,
                name: '',
                baseUrl: 'https://shop.example.com',
                storeCode: 'default',
                tenantContext: null,
                credentialMutation: CredentialMutation::keep(),
            ),
        );
    }

    #[Test]
    public function duplicate_active_name_create_throws_name_conflict_without_partial_state(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $definition = $this->adobeConnectorDefinition();

        $this->service->create(
            $admin,
            $this->workspace,
            CreateConnectorAccountInput::adobePaas(
                connectorDefinitionId: $definition->id,
                name: 'Shared Name',
                baseUrl: 'https://shop.example.com',
                storeCode: 'default',
                tenantContext: null,
                credentialMutation: CredentialMutation::replace(
                    new OAuth1Credentials('ck', 'cs', 'at', 'ts'),
                ),
            ),
        );

        try {
            $this->service->create(
                $admin,
                $this->workspace,
                CreateConnectorAccountInput::adobePaas(
                    connectorDefinitionId: $definition->id,
                    name: 'Shared Name',
                    baseUrl: 'https://other.example.com',
                    storeCode: 'storeTwo',
                    tenantContext: null,
                    credentialMutation: CredentialMutation::keep(),
                ),
            );
            $this->fail('Expected ConnectorAccountNameConflict.');
        } catch (ConnectorAccountNameConflict) {
            $this->assertSame(
                1,
                ConnectorAccount::withoutWorkspaceScope()
                    ->where('workspace_id', $this->workspace->id)
                    ->where('connector_definition_id', $definition->id)
                    ->where('name', 'Shared Name')
                    ->count(),
            );
        }
    }

    #[Test]
    public function settings_result_never_exposes_credentials_and_model_hides_them_from_serialization(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $result = $this->service->create(
            $admin,
            $this->workspace,
            CreateConnectorAccountInput::adobePaas(
                connectorDefinitionId: $this->adobeConnectorDefinition()->id,
                name: 'Safe Result',
                baseUrl: 'https://shop.example.com',
                storeCode: 'default',
                tenantContext: null,
                credentialMutation: CredentialMutation::replace(
                    new OAuth1Credentials('ck_safe', 'cs_safe', 'at_safe', 'ts_safe'),
                ),
            ),
        );

        $this->assertTrue($result->hasCredentials);
        $this->assertObjectNotHasProperty('credentials', $result);
        $this->assertFalse(method_exists($result, 'credentials'));

        $account = ConnectorAccount::withoutWorkspaceScope()->findOrFail($result->id);
        $array = $account->toArray();
        $json = $account->toJson();

        $this->assertArrayNotHasKey('credentials', $array);
        $this->assertStringNotContainsString('cs_safe', $json);
        $this->assertStringNotContainsString('ts_safe', $json);
    }

    #[Test]
    public function runtime_factory_builds_request_context_and_signed_request_without_user_context(): void
    {
        $account = $this->createConnectorAccount($this->workspace, [
            'base_url' => 'https://shop.example.com',
            'store_code' => 'default',
            'credentials' => AdobePaaSCredentialMapper::toStorageArray(
                new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test'),
            ),
        ]);

        $context = app(AdobePaaSRequestContextFactory::class)->create($this->workspace->id, $account->id);

        $this->assertSame('https://shop.example.com', $context->baseUrl);
        $this->assertSame('default', $context->storeCode);
        $this->assertSame('ck_test', $context->credentials->consumerKey);

        $request = (new AdobePaaSConnectionCheckRequestFactory(new OAuth1RequestSigner))->build(
            $context,
            new OAuth1SigningContext('abc123nonce', 1_700_000_000),
        );

        $expectedAuthorization = (new OAuth1RequestSigner)->sign(
            'GET',
            'https://shop.example.com/rest/default/V1/products/attributes?searchCriteria%5BpageSize%5D=1',
            null,
            null,
            $context->credentials,
            new OAuth1SigningContext('abc123nonce', 1_700_000_000),
        );

        self::assertSameOAuth1AuthorizationHeader($expectedAuthorization, $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function runtime_factory_not_found_is_identical_for_wrong_workspace_and_unknown_id(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $otherWorkspace = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $factory = app(AdobePaaSRequestContextFactory::class);

        try {
            $factory->create($otherWorkspace->id, $account->id);
            $this->fail('Expected not found for wrong workspace.');
        } catch (ConnectorAccountNotFoundException $wrongWorkspace) {
            try {
                $factory->create($this->workspace->id, (string) Str::uuid());
                $this->fail('Expected not found for unknown id.');
            } catch (ConnectorAccountNotFoundException $unknownId) {
                $this->assertSame($wrongWorkspace->getMessage(), $unknownId->getMessage());
            }
        }
    }

    #[Test]
    public function runtime_factory_treats_soft_deleted_account_as_not_found(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $account->delete();

        $this->expectException(ConnectorAccountNotFoundException::class);

        app(AdobePaaSRequestContextFactory::class)->create($this->workspace->id, $account->id);
    }

    #[Test]
    public function runtime_factory_rejects_incomplete_credentials(): void
    {
        $account = $this->createConnectorAccount($this->workspace, [
            'credentials' => [],
        ]);

        $this->expectException(IncompleteAdobePaaSCredentialsException::class);

        app(AdobePaaSRequestContextFactory::class)->create($this->workspace->id, $account->id);
    }

    #[Test]
    public function schema_rejects_profile_input_mismatch_through_service_create_path(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $this->expectException(ConnectorAccountProfileInputMismatchException::class);

        $this->service->create(
            $admin,
            $this->workspace,
            new CreateConnectorAccountInput(
                connectorDefinitionId: $this->adobeConnectorDefinition()->id,
                name: 'Mismatch',
                authProfile: 'adobe_commerce_paas_oauth1_integration',
                settings: new MismatchedConnectorSettingsInput,
                credentialMutation: CredentialMutation::keep(),
            ),
        );
    }

    #[Test]
    public function target_change_is_rejected_when_trusted_merchant_confirmed_links_exist(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);
        $this->prepareEntityTrustConfiguration($account);
        [$product, $variant] = $this->createSimpleEntityTrustProduct($this->workspace, 'FREEZE-SKU');

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $this->workspace,
                $account->id,
                $variant,
                'FREEZE-SKU',
                '5001',
                $this->createWorkspaceActor($this->workspace),
            ),
        );

        $this->expectException(ConnectorAccountTargetFrozenException::class);

        $this->service->update(
            $admin,
            $this->workspace,
            $account->id,
            UpdateConnectorAccountInput::adobePaas(
                baseUrl: 'https://new-target.example.com',
                storeCode: 'default',
                tenantContext: null,
                credentialMutation: CredentialMutation::keep(),
            ),
        );
    }

    #[Test]
    public function target_change_is_allowed_without_trusted_merchant_confirmed_links(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);

        $result = $this->service->update(
            $admin,
            $this->workspace,
            $account->id,
            UpdateConnectorAccountInput::adobePaas(
                baseUrl: 'https://new-target.example.com',
                storeCode: 'store_two',
                tenantContext: null,
                credentialMutation: CredentialMutation::keep(),
            ),
        );

        $this->assertSame('https://new-target.example.com', $result->baseUrl);
        $this->assertSame('store_two', $result->storeCode);
    }

    #[Test]
    public function credential_rotation_is_allowed_with_trusted_merchant_confirmed_links(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);
        $this->prepareEntityTrustConfiguration($account);
        [$product, $variant] = $this->createSimpleEntityTrustProduct($this->workspace, 'ROTATE-SKU');

        $link = ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $this->workspace,
                $account->id,
                $variant,
                'ROTATE-SKU',
                '5101',
                $this->createWorkspaceActor($this->workspace),
            ),
        );

        $result = $this->service->update(
            $admin,
            $this->workspace,
            $account->id,
            UpdateConnectorAccountInput::adobePaas(
                baseUrl: (string) $account->base_url,
                storeCode: (string) $account->store_code,
                tenantContext: null,
                credentialMutation: CredentialMutation::replace(
                    new OAuth1Credentials('ck_rot', 'cs_rot', 'at_rot', 'ts_rot'),
                ),
            ),
        );

        $this->assertTrue($result->hasCredentials);
        $link->refresh();
        $this->assertTrue($link->hasMerchantConfirmedTrust());
        $this->assertSame('5101', $link->external_record_discriminator);
    }

    #[Test]
    public function tenant_context_only_update_does_not_trigger_target_freeze(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);
        $this->prepareEntityTrustConfiguration($account);
        [$product, $variant] = $this->createSimpleEntityTrustProduct($this->workspace, 'TENANT-SKU');

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $this->workspace,
                $account->id,
                $variant,
                'TENANT-SKU',
                '5201',
                $this->createWorkspaceActor($this->workspace),
            ),
        );

        $result = $this->service->update(
            $admin,
            $this->workspace,
            $account->id,
            UpdateConnectorAccountInput::adobePaas(
                baseUrl: (string) $account->base_url,
                storeCode: (string) $account->store_code,
                tenantContext: 'tenant-updated',
                credentialMutation: CredentialMutation::keep(),
            ),
        );

        $this->assertSame('tenant-updated', $result->tenantContext);
    }
}

final class MismatchedConnectorSettingsInput implements ConnectorAccountSettingsInput {}
