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
            $this->assertTrue(Gate::forUser($user)->allows($ability, $ability === 'create' ? [$account::class, $workspace] : $account));
        }

        $permissionHolder = $this->createStaffUser(UserRole::Manager);
        $permissionHolder->givePermissionTo(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);
        $this->assertTrue(Gate::forUser($permissionHolder)->allows($ability, $ability === 'create' ? [$account::class, $workspace] : $account));
    }

    #[Test]
    #[DataProvider('accountAbilityProvider')]
    public function account_abilities_deny_same_roles_in_different_workspace(string $ability): void
    {
        $workspace = $this->defaultWorkspace();
        $otherWorkspace = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $account = $this->createConnectorAccount($otherWorkspace);
        $admin = $this->createStaffUser(UserRole::Admin);

        $this->assertFalse(Gate::forUser($admin)->allows($ability, $ability === 'create' ? [$account::class, $otherWorkspace] : $account));
    }

    #[Test]
    #[DataProvider('accountAbilityProvider')]
    public function merchandiser_is_denied_even_with_manage_connector_accounts_permission(string $ability): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $merchandiser->givePermissionTo(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);

        $this->assertFalse(Gate::forUser($merchandiser)->allows($ability, $ability === 'create' ? [$account::class, $workspace] : $account));
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
        return [
            'view' => ['view'],
            'runConnectionCheck' => ['runConnectionCheck'],
            'updateSettings' => ['updateSettings'],
            'replaceCredentials' => ['replaceCredentials'],
            'removeCredentials' => ['removeCredentials'],
            'create' => ['create'],
        ];
    }
}
