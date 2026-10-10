<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\FieldMappingAuthorizationService;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\FieldMappingReadModelProjector;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class FieldMappingAuthorizationServiceTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private FieldMappingAuthorizationService $authorizationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
        ]);

        $this->authorizationService = app(FieldMappingAuthorizationService::class);
    }

    #[Test]
    public function view_sync_mappings_allows_read_and_denies_mutation(): void
    {
        [$workspace, $account, $configuration] = $this->mappingFixture();

        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Mapping Viewer',
            [WorkspacePermissions::VIEW_SYNC_MAPPINGS],
        );
        $this->assignRoleToMembership($membership, $role);

        $this->authorizationService->projectReadModel(
            $actor,
            $workspace->id,
            $account->id,
            $configuration->id,
        );

        $this->expectException(AuthorizationException::class);
        $this->authorizationService->confirm(
            $actor,
            $workspace->id,
            $account->id,
            $configuration->id,
            'binding-id',
            'external_key',
        );
    }

    #[Test]
    public function manage_sync_mappings_allows_read_and_mutation(): void
    {
        [$workspace, $account, $configuration] = $this->mappingFixture();

        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Mapping Manager',
            [WorkspacePermissions::MANAGE_SYNC_MAPPINGS],
        );
        $this->assignRoleToMembership($membership, $role);

        $binding = $this->productBinding('name');

        $this->authorizationService->projectReadModel(
            $actor,
            $workspace->id,
            $account->id,
            $configuration->id,
        );

        $this->assertDatabaseMissing('field_mappings', [
            'sync_configuration_id' => $configuration->id,
            'field_binding_id' => $binding->id,
            'external_field_key' => 'name',
        ]);

        $updatedConfiguration = $this->authorizationService->confirm(
            $actor,
            $workspace->id,
            $account->id,
            $configuration->id,
            $binding->id,
            'name',
        );

        $this->assertSame($configuration->id, $updatedConfiguration->id);
        $this->assertDatabaseHas('field_mappings', [
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'field_binding_id' => $binding->id,
            'external_field_key' => 'name',
        ]);
    }

    #[Test]
    public function foreign_sync_configuration_id_fails_closed(): void
    {
        [$workspace, $account, $configuration] = $this->mappingFixture();
        $foreignAccount = $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
        $foreignConfiguration = $this->createProductsSyncConfiguration($foreignAccount);

        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Mapping Manager',
            [WorkspacePermissions::MANAGE_SYNC_MAPPINGS],
        );
        $this->assignRoleToMembership($membership, $role);

        $binding = $this->productBinding('name');
        $mappingCountBefore = FieldMapping::query()->count();

        $this->expectException(SyncConfigurationNotFoundException::class);

        try {
            $this->authorizationService->confirm(
                $actor,
                $workspace->id,
                $account->id,
                $foreignConfiguration->id,
                $binding->id,
                'name',
            );
        } finally {
            $this->assertSame($mappingCountBefore, FieldMapping::query()->count());
        }
    }

    #[Test]
    public function cross_workspace_account_configuration_tuple_fails_closed(): void
    {
        [$workspace, $account, $configuration] = $this->mappingFixture();
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);
        $foreignAccount = ConnectorAccount::withoutWorkspaceScope()->create([
            'workspace_id' => $otherWorkspace->id,
            'connector_definition_id' => $account->connector_definition_id,
            'name' => 'Foreign Account',
            'auth_profile' => $account->auth_profile,
            'base_url' => 'https://foreign.example.com',
            'store_code' => 'default',
            'is_enabled' => true,
            'settings' => [],
            'credentials' => [],
        ]);

        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Mapping Manager',
            [WorkspacePermissions::MANAGE_SYNC_MAPPINGS],
        );
        $this->assignRoleToMembership($membership, $role);

        $binding = $this->productBinding('name');
        $mappingCountBefore = FieldMapping::query()->count();

        $this->expectException(AuthorizationException::class);

        try {
            $this->authorizationService->confirm(
                $actor,
                $workspace->id,
                $foreignAccount->id,
                $configuration->id,
                $binding->id,
                'name',
            );
        } finally {
            $this->assertSame($mappingCountBefore, FieldMapping::query()->count());
        }
    }

    #[Test]
    public function connector_manage_permission_does_not_authorize_mapping(): void
    {
        [$workspace, $account, $configuration] = $this->mappingFixture();

        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Connector Manager',
            [WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS],
        );
        $this->assignRoleToMembership($membership, $role);

        $this->expectException(AuthorizationException::class);
        $this->authorizationService->projectReadModel(
            $actor,
            $workspace->id,
            $account->id,
            $configuration->id,
        );
    }

    #[Test]
    public function foreign_connector_account_id_fails_closed(): void
    {
        [$workspace, $account, $configuration] = $this->mappingFixture();
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);
        $foreignAccount = ConnectorAccount::withoutWorkspaceScope()->create([
            'workspace_id' => $otherWorkspace->id,
            'connector_definition_id' => $account->connector_definition_id,
            'name' => 'Foreign Account',
            'auth_profile' => $account->auth_profile,
            'base_url' => 'https://foreign.example.com',
            'store_code' => 'default',
            'is_enabled' => true,
            'settings' => [],
            'credentials' => [],
        ]);

        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Mapping Manager',
            [WorkspacePermissions::MANAGE_SYNC_MAPPINGS],
        );
        $this->assignRoleToMembership($membership, $role);

        $this->expectException(AuthorizationException::class);
        $this->authorizationService->projectReadModel(
            $actor,
            $workspace->id,
            $foreignAccount->id,
            $configuration->id,
        );
    }

    #[Test]
    public function inner_services_remain_actor_agnostic(): void
    {
        $mutation = app(FieldMappingMutationService::class);
        $projector = app(FieldMappingReadModelProjector::class);

        $this->assertTrue((new \ReflectionMethod($mutation, 'confirm'))->getNumberOfParameters() === 4);
        $this->assertTrue((new \ReflectionMethod($projector, 'project'))->getNumberOfParameters() === 2);
    }

    /**
     * @return array{0: Workspace, 1: ConnectorAccount, 2: SyncConfiguration}
     */
    private function mappingFixture(): array
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['name', 'sku']);

        return [$workspace, $account, $configuration];
    }
}
