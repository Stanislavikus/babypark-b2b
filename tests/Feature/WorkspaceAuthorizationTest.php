<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WorkspaceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private WorkspaceAuthorization $authorization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->authorization = app(WorkspaceAuthorization::class);
    }

    #[Test]
    public function active_membership_with_one_role_and_permission_allows(): void
    {
        [$user, $workspace] = $this->makeUserAndWorkspace();
        $this->assignPermission($workspace, $user, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS);

        $this->assertTrue(
            $this->authorization->allows($user, $workspace, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS),
        );
    }

    #[Test]
    public function no_membership_denies(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $this->assertFalse(
            $this->authorization->allows($user, $workspace, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS),
        );
        $this->assertNull($this->authorization->activeMembership($user, $workspace));
        $this->assertSame([], $this->authorization->effectivePermissions($user, $workspace));
    }

    #[Test]
    public function inactive_membership_denies(): void
    {
        [$user, $workspace] = $this->makeUserAndWorkspace();
        $this->assignPermission($workspace, $user, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS);

        WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->update(['is_active' => false]);

        $this->assertFalse(
            $this->authorization->allows($user, $workspace, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS),
        );
    }

    #[Test]
    public function inactive_user_denies(): void
    {
        [$user, $workspace] = $this->makeUserAndWorkspace();
        $this->assignPermission($workspace, $user, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS);

        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->assertFalse(
            $this->authorization->allows($user, $workspace, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS),
        );
        $this->assertSame([], $this->authorization->effectivePermissions($user, $workspace));
    }

    #[Test]
    public function stale_hydrated_user_with_db_deactivated_yields_empty_effective_permissions(): void
    {
        [$user, $workspace] = $this->makeUserAndWorkspace();
        $this->assignPermission($workspace, $user, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS);

        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->assertSame([], $this->authorization->effectivePermissions($user, $workspace));
        $this->assertNull($this->authorization->activeMembership($user, $workspace));
    }

    #[Test]
    public function active_global_user_with_inactive_membership_yields_empty_permissions(): void
    {
        [$user, $workspace] = $this->makeUserAndWorkspace();
        $membership = $this->membershipFor($workspace, $user);
        WorkspaceUser::query()->whereKey($membership->id)->update(['is_active' => false]);
        $this->assignPermission($workspace, $user, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS);

        $this->assertSame([], $this->authorization->effectivePermissions($user, $workspace));
    }

    #[Test]
    public function multiple_roles_produce_additive_union(): void
    {
        [$user, $workspace] = $this->makeUserAndWorkspace();
        $membership = $this->membershipFor($workspace, $user);

        $roleA = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Role A',
        ]);
        $roleB = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Role B',
        ]);

        $this->attachRole($membership, $roleA);
        $this->attachRole($membership, $roleB);

        $this->attachPermission($roleA, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS);
        $this->attachPermission($roleB, WorkspacePermissions::VIEW_SYNC_MAPPINGS);

        $permissions = $this->authorization->effectivePermissions($user, $workspace);

        $this->assertSame([
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::VIEW_SYNC_MAPPINGS,
        ], $permissions);
    }

    #[Test]
    public function duplicate_permission_through_two_roles_appears_once(): void
    {
        [$user, $workspace] = $this->makeUserAndWorkspace();
        $membership = $this->membershipFor($workspace, $user);

        $roleA = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Role A',
        ]);
        $roleB = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Role B',
        ]);

        $this->attachRole($membership, $roleA);
        $this->attachRole($membership, $roleB);
        $this->attachPermission($roleA, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS);
        $this->attachPermission($roleB, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS);

        $this->assertSame(
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
            $this->authorization->effectivePermissions($user, $workspace),
        );
    }

    #[Test]
    public function permission_in_workspace_a_does_not_authorize_workspace_b(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->sole();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        [$user] = $this->makeUserAndWorkspace($workspaceA);

        $this->assignPermission($workspaceA, $user, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS);

        $this->assertTrue(
            $this->authorization->allows($user, $workspaceA, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS),
        );
        $this->assertFalse(
            $this->authorization->allows($user, $workspaceB, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS),
        );
    }

    #[Test]
    public function ambient_default_workspace_does_not_corrupt_explicit_workspace_b_authorization(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->sole();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        [$user] = $this->makeUserAndWorkspace($workspaceB);

        $this->assignPermission($workspaceB, $user, WorkspacePermissions::MANAGE_SYNC_MAPPINGS);

        app(WorkspaceContext::class)->current();

        $this->assertFalse(
            $this->authorization->allows($user, $workspaceA, WorkspacePermissions::MANAGE_SYNC_MAPPINGS),
        );
        $this->assertTrue(
            $this->authorization->allows($user, $workspaceB, WorkspacePermissions::MANAGE_SYNC_MAPPINGS),
        );
    }

    #[Test]
    public function legacy_admin_role_alone_grants_no_rbac_permission(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $this->assertFalse(
            $this->authorization->allows($user, $workspace, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS),
        );
    }

    #[Test]
    public function legacy_spatie_permission_alone_grants_no_rbac_permission(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->where('is_default', true)->sole();

        Permission::findOrCreate(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS, 'web');
        $user->givePermissionTo(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);

        $this->assertFalse(
            $this->authorization->allows($user, $workspace, WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS),
        );
    }

    #[Test]
    public function rogue_assigned_database_permission_does_not_expand_authority(): void
    {
        [$user, $workspace] = $this->makeUserAndWorkspace();
        $membership = $this->membershipFor($workspace, $user);

        $role = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Rogue Role',
        ]);

        $this->attachRole($membership, $role);
        $this->attachPermission($role, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS);

        $roguePermissionId = (string) Str::uuid();

        DB::table('workspace_permissions')->insert([
            'id' => $roguePermissionId,
            'code' => 'rogue_unseeded_permission',
        ]);

        DB::table('workspace_role_permissions')->insert([
            'workspace_id' => $workspace->id,
            'workspace_role_id' => $role->id,
            'workspace_permission_id' => $roguePermissionId,
        ]);

        $this->assertFalse(
            $this->authorization->allows($user, $workspace, 'rogue_unseeded_permission'),
        );
        $this->assertNotContains(
            'rogue_unseeded_permission',
            $this->authorization->effectivePermissions($user, $workspace),
        );
        $this->assertTrue(
            $this->authorization->allows($user, $workspace, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS),
        );
    }

    #[Test]
    public function effective_permissions_are_deterministic(): void
    {
        [$user, $workspace] = $this->makeUserAndWorkspace();
        $membership = $this->membershipFor($workspace, $user);

        $roleZ = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Z Role',
        ]);
        $roleA = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'A Role',
        ]);

        $this->attachRole($membership, $roleZ);
        $this->attachRole($membership, $roleA);
        $this->attachPermission($roleZ, WorkspacePermissions::MANAGE_SYNC_MAPPINGS);
        $this->attachPermission($roleA, WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS);

        $first = $this->authorization->effectivePermissions($user, $workspace);
        $second = $this->authorization->effectivePermissions($user, $workspace);

        $this->assertSame($first, $second);
        $this->assertSame([
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
        ], $first);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeUserAndWorkspace(?Workspace $workspace = null): array
    {
        $workspace ??= Workspace::query()->where('is_default', true)->sole();
        $user = User::factory()->create();

        return [$user, $workspace];
    }

    private function membershipFor(Workspace $workspace, User $user): WorkspaceUser
    {
        return WorkspaceUser::query()->firstOrCreate([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ], [
            'is_active' => true,
        ]);
    }

    private function assignPermission(Workspace $workspace, User $user, string $permissionCode): void
    {
        $membership = $this->membershipFor($workspace, $user);
        $role = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Role '.$permissionCode,
        ]);

        $this->attachRole($membership, $role);
        $this->attachPermission($role, $permissionCode);
    }

    private function attachRole(WorkspaceUser $membership, WorkspaceRole $role): void
    {
        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $membership->workspace_id,
            'workspace_user_id' => $membership->id,
            'workspace_role_id' => $role->id,
        ]);
    }

    private function attachPermission(WorkspaceRole $role, string $permissionCode): void
    {
        $permission = WorkspacePermission::query()
            ->where('code', $permissionCode)
            ->firstOrFail();

        DB::table('workspace_role_permissions')->insert([
            'workspace_id' => $role->workspace_id,
            'workspace_role_id' => $role->id,
            'workspace_permission_id' => $permission->id,
        ]);
    }
}
