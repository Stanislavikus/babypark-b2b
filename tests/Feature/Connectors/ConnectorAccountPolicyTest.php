<?php

namespace Tests\Feature\Connectors;

use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\Workspace;
use App\Policies\ConnectorAccountPolicy;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
    }

    #[Test]
    public function policy_is_auto_discovered_for_connector_account_model(): void
    {
        $this->assertInstanceOf(ConnectorAccountPolicy::class, Gate::getPolicyFor(ConnectorAccount::class));
    }

    #[Test]
    #[DataProvider('accountAbilityProvider')]
    public function account_abilities_allow_authorized_roles_in_same_workspace(string $ability): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);

        foreach ([UserRole::Admin, UserRole::Director] as $role) {
            $user = $this->createStaffUser($role);
            $this->assertTrue(Gate::forUser($user)->allows($ability, $this->policyArguments($ability, $account, $workspace)));
        }

        $permissionHolder = $this->createStaffUser(UserRole::Manager);
        $permissionHolder->givePermissionTo(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);
        $this->assertTrue(Gate::forUser($permissionHolder)->allows($ability, $this->policyArguments($ability, $account, $workspace)));
    }

    #[Test]
    #[DataProvider('accountAbilityProvider')]
    public function account_abilities_deny_same_roles_in_different_workspace(string $ability): void
    {
        if ($ability === 'viewAny') {
            $this->markTestSkipped('viewAny is workspace-scoped via current workspace, not a foreign record.');
        }

        $workspace = $this->defaultWorkspace();
        $otherWorkspace = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $account = $this->createConnectorAccount($otherWorkspace);
        $admin = $this->createStaffUser(UserRole::Admin);

        $this->assertFalse(Gate::forUser($admin)->allows($ability, $this->policyArguments($ability, $account, $otherWorkspace)));
    }

    #[Test]
    #[DataProvider('managementAbilityProvider')]
    public function merchandiser_is_denied_management_abilities_even_with_manage_connector_accounts_permission(string $ability): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $merchandiser->givePermissionTo(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);

        $this->assertFalse(Gate::forUser($merchandiser)->allows($ability, $this->policyArguments($ability, $account, $workspace)));
    }

    #[Test]
    #[DataProvider('readAbilityProvider')]
    public function merchandiser_is_allowed_read_abilities_in_same_workspace(string $ability): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);

        $this->assertTrue(Gate::forUser($merchandiser)->allows($ability, $this->policyArguments($ability, $account, $workspace)));
    }

    #[Test]
    #[DataProvider('runDiscoveryAllowedProvider')]
    public function run_discovery_allows_authorized_roles_in_same_workspace(UserRole $role, bool $withPermission, bool $expected): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $user = $this->createStaffUser($role);

        if ($withPermission) {
            $user->givePermissionTo(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);
        }

        $this->assertSame(
            $expected,
            Gate::forUser($user)->allows('runDiscovery', $account),
        );
    }

    #[Test]
    public function run_discovery_denies_same_roles_in_different_workspace(): void
    {
        $otherWorkspace = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $account = $this->createConnectorAccount($otherWorkspace);
        $admin = $this->createStaffUser(UserRole::Admin);

        $this->assertFalse(Gate::forUser($admin)->allows('runDiscovery', $account));
    }

    #[Test]
    public function run_discovery_denies_all_roles_for_disabled_account(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace, ['is_enabled' => false]);

        foreach ([UserRole::Admin, UserRole::Director, UserRole::Merchandiser] as $role) {
            $user = $this->createStaffUser($role);
            $this->assertFalse(Gate::forUser($user)->allows('runDiscovery', $account));
        }

        $manager = $this->createStaffUser(UserRole::Manager);
        $manager->givePermissionTo(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);
        $this->assertFalse(Gate::forUser($manager)->allows('runDiscovery', $account));
    }

    #[Test]
    public function create_allows_admin_in_own_workspace_and_denies_other_workspace(): void
    {
        $workspace = $this->defaultWorkspace();
        $otherWorkspace = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $admin = $this->createStaffUser(UserRole::Admin);

        $this->assertTrue(Gate::forUser($admin)->allows('create', [ConnectorAccount::class, $workspace]));
        $this->assertFalse(Gate::forUser($admin)->allows('create', [ConnectorAccount::class, $otherWorkspace]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function accountAbilityProvider(): array
    {
        return array_merge(
            self::readAbilityProvider(),
            self::managementAbilityProvider(),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function readAbilityProvider(): array
    {
        return [
            'viewAny' => ['viewAny'],
            'view' => ['view'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function managementAbilityProvider(): array
    {
        return [
            'runConnectionCheck' => ['runConnectionCheck'],
            'updateSettings' => ['updateSettings'],
            'replaceCredentials' => ['replaceCredentials'],
            'removeCredentials' => ['removeCredentials'],
            'create' => ['create'],
        ];
    }

    /**
     * @return array<string, array{0: UserRole, 1: bool, 2: bool}>
     */
    public static function runDiscoveryAllowedProvider(): array
    {
        return [
            'admin allowed' => [UserRole::Admin, false, true],
            'director allowed' => [UserRole::Director, false, true],
            'merchandiser allowed' => [UserRole::Merchandiser, false, true],
            'manager without permission denied' => [UserRole::Manager, false, false],
            'manager with permission allowed' => [UserRole::Manager, true, true],
            'warehouse without permission denied' => [UserRole::Warehouse, false, false],
            'warehouse with permission allowed' => [UserRole::Warehouse, true, true],
            'programmer without permission denied' => [UserRole::Programmer, false, false],
            'programmer with permission allowed' => [UserRole::Programmer, true, true],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function policyArguments(string $ability, ConnectorAccount $account, Workspace $workspace): array
    {
        return match ($ability) {
            'create' => [ConnectorAccount::class, $workspace],
            'viewAny' => [ConnectorAccount::class],
            default => [$account],
        };
    }
}
