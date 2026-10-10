<?php

namespace Tests\Feature\Connectors;

use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\ConnectorAccountPolicy;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorAccountPolicyTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
    }

    #[Test]
    public function policy_is_auto_discovered_for_connector_account_model(): void
    {
        $this->assertInstanceOf(ConnectorAccountPolicy::class, Gate::getPolicyFor(ConnectorAccount::class));
    }

    #[Test]
    public function view_only_permission_allows_safe_read_but_not_discovery_or_manage(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $user = $this->createStaffUser(UserRole::Manager);
        $this->grantConnectorView($workspace, $user);

        $this->assertTrue(Gate::forUser($user)->allows('view', $account));
        $this->assertFalse(Gate::forUser($user)->allows('viewRunDiscovery', $account));
        $this->assertFalse(Gate::forUser($user)->allows('runDiscovery', $account));
        $this->assertFalse(Gate::forUser($user)->allows('runConnectionCheck', $account));
        $this->assertFalse(Gate::forUser($user)->allows('create', [ConnectorAccount::class, $workspace]));
    }

    #[Test]
    public function discovery_permission_allows_safe_read_and_discovery_control(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $user = $this->createStaffUser(UserRole::Manager);
        $this->grantConnectorDiscovery($workspace, $user);

        $this->assertTrue(Gate::forUser($user)->allows('view', $account));
        $this->assertTrue(Gate::forUser($user)->allows('viewRunDiscovery', $account));
        $this->assertTrue(Gate::forUser($user)->allows('runDiscovery', $account));
        $this->assertFalse(Gate::forUser($user)->allows('runConnectionCheck', $account));
    }

    #[Test]
    public function manage_permission_allows_full_connector_control_surface(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $user = $this->createStaffUserWithConnectorManage(UserRole::Manager);

        $this->assertTrue(Gate::forUser($user)->allows('view', $account));
        $this->assertTrue(Gate::forUser($user)->allows('viewRunDiscovery', $account));
        $this->assertTrue(Gate::forUser($user)->allows('runDiscovery', $account));
        $this->assertTrue(Gate::forUser($user)->allows('runConnectionCheck', $account));
        $this->assertTrue(Gate::forUser($user)->allows('create', [ConnectorAccount::class, $workspace]));
        $this->assertTrue(Gate::forUser($user)->allows('updateSettings', $account));
        $this->assertTrue(Gate::forUser($user)->allows('replaceCredentials', $account));
        $this->assertTrue(Gate::forUser($user)->allows('removeCredentials', $account));
    }

    #[Test]
    public function no_connector_permission_denies_all_abilities(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $user = $this->createStaffUser(UserRole::Manager);
        $this->makeWorkspaceMembership($workspace, $user, true);

        $this->assertFalse(Gate::forUser($user)->allows('view', $account));
        $this->assertFalse(Gate::forUser($user)->allows('viewRunDiscovery', $account));
        $this->assertFalse(Gate::forUser($user)->allows('runConnectionCheck', $account));
    }

    #[Test]
    public function legacy_job_title_labels_grant_no_connector_authority(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);

        foreach ([UserRole::Admin, UserRole::Director, UserRole::Merchandiser] as $role) {
            $user = $this->createStaffUser($role);
            $this->makeWorkspaceMembership($workspace, $user, true);

            $this->assertFalse(Gate::forUser($user)->allows('view', $account), $role->value);
            $this->assertFalse(Gate::forUser($user)->allows('runConnectionCheck', $account), $role->value);
            $this->assertFalse(Gate::forUser($user)->allows('viewRunDiscovery', $account), $role->value);
        }
    }

    #[Test]
    public function global_spatie_grant_alone_grants_no_connector_authority(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $user = $this->createStaffUser(UserRole::Manager);
        $this->makeWorkspaceMembership($workspace, $user, true);

        Permission::findOrCreate(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS, 'web');
        $user->givePermissionTo(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);

        $this->assertFalse(Gate::forUser($user)->allows('view', $account));
        $this->assertFalse(Gate::forUser($user)->allows('runConnectionCheck', $account));
    }

    #[Test]
    public function workspace_membership_without_rbac_grants_no_connector_authority(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $user = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->assertFalse(Gate::forUser($user)->allows('view', $account));
    }

    #[Test]
    public function inactive_user_is_denied(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $user = $this->createStaffUser(UserRole::Manager);
        $this->grantConnectorManage($workspace, $user);
        $user->update(['is_active' => false]);

        $this->assertFalse(Gate::forUser($user)->allows('view', $account));
    }

    #[Test]
    public function inactive_workspace_user_is_denied(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $user = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($workspace, $user, false);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Inactive membership role',
            [WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS],
        );
        $this->assignRoleToMembership($membership, $role);

        $this->assertFalse(Gate::forUser($user)->allows('view', $account));
    }

    #[Test]
    public function permission_in_workspace_a_does_not_authorize_workspace_b(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $accountB = $this->createConnectorAccount($workspaceB);
        $user = $this->createStaffUserWithConnectorManage(UserRole::Manager);

        $this->assertFalse(Gate::forUser($user)->allows('view', $accountB));
    }

    #[Test]
    public function run_discovery_denies_disabled_account_even_with_manage_permission(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace, ['is_enabled' => false]);
        $user = $this->createStaffUserWithConnectorManage(UserRole::Manager);

        $this->assertTrue(Gate::forUser($user)->allows('viewRunDiscovery', $account));
        $this->assertFalse(Gate::forUser($user)->allows('runDiscovery', $account));
    }

    #[Test]
    public function stale_hydrated_active_user_with_db_deactivated_is_denied(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $user = $this->createStaffUserWithConnectorManage(UserRole::Manager);

        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->assertFalse(Gate::forUser($user)->allows('view', $account));
    }
}
