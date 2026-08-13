<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Services\Workspace\WorkspaceAccessMutationService;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessLockoutException;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessMutationRejectedException;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessUnauthorizedException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class WorkspaceAccessMutationServiceTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private WorkspaceAccessMutationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->service = app(WorkspaceAccessMutationService::class);
    }

    #[Test]
    public function actor_with_manage_workspace_access_can_mutate(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $target = $this->makeWorkspaceMembership($workspace);

        $role = $this->service->createRole($actor, $workspace, 'Custom Role', [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
        ]);

        $this->service->assignRole($actor, $workspace, $target->id, $role->id);

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $target->id,
            'workspace_role_id' => $role->id,
        ]);
    }

    #[Test]
    public function actor_without_permission_is_denied(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $target = $this->makeWorkspaceMembership($workspace);

        $this->expectException(WorkspaceAccessUnauthorizedException::class);

        $this->service->activateMembership($actor, $workspace, $target->id);
    }

    #[Test]
    public function legacy_admin_role_alone_does_not_authorize(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $target = $this->makeWorkspaceMembership($workspace);

        $this->expectException(WorkspaceAccessUnauthorizedException::class);

        $this->service->activateMembership($actor, $workspace, $target->id);
    }

    #[Test]
    public function global_spatie_permission_alone_does_not_authorize(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        Permission::findOrCreate(WorkspacePermissions::MANAGE_WORKSPACE_ACCESS, 'web');
        $actor->givePermissionTo(WorkspacePermissions::MANAGE_WORKSPACE_ACCESS);
        $target = $this->makeWorkspaceMembership($workspace);

        $this->expectException(WorkspaceAccessUnauthorizedException::class);

        $this->service->activateMembership($actor, $workspace, $target->id);
    }

    #[Test]
    public function inactive_user_is_denied(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create(['is_active' => false]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Access Role',
            [WorkspacePermissions::MANAGE_WORKSPACE_ACCESS],
        );
        $this->assignRoleToMembership($membership, $role);
        $target = $this->makeWorkspaceMembership($workspace);

        $this->expectException(WorkspaceAccessUnauthorizedException::class);

        $this->service->activateMembership($actor, $workspace, $target->id);
    }

    #[Test]
    public function inactive_workspace_user_is_denied(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor, false);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Access Role',
            [WorkspacePermissions::MANAGE_WORKSPACE_ACCESS],
        );
        $this->assignRoleToMembership($membership, $role);
        $target = $this->makeWorkspaceMembership($workspace);

        $this->expectException(WorkspaceAccessUnauthorizedException::class);

        $this->service->activateMembership($actor, $workspace, $target->id);
    }

    #[Test]
    public function workspace_a_holder_cannot_mutate_workspace_b(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspaceA, $actor);
        $target = $this->makeWorkspaceMembership($workspaceB);

        $this->expectException(WorkspaceAccessUnauthorizedException::class);

        $this->service->activateMembership($actor, $workspaceB, $target->id);
    }

    #[Test]
    public function foreign_membership_id_fails_closed(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspaceA, $actor);
        $foreignMembership = $this->makeWorkspaceMembership($workspaceB);

        $this->expectException(WorkspaceAccessMutationRejectedException::class);

        $this->service->activateMembership($actor, $workspaceA, $foreignMembership->id);
    }

    #[Test]
    public function foreign_role_id_fails_closed(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspaceA, $actor);
        $target = $this->makeWorkspaceMembership($workspaceA);
        $foreignRole = $this->createRoleWithPermissions(
            $workspaceB->id,
            'Foreign Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );

        $this->expectException(WorkspaceAccessMutationRejectedException::class);

        $this->service->assignRole($actor, $workspaceA, $target->id, $foreignRole->id);
    }

    #[Test]
    public function role_assignment_works(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $target = $this->makeWorkspaceMembership($workspace);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Viewer',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );

        $this->service->assignRole($actor, $workspace, $target->id, $role->id);

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $target->id,
            'workspace_role_id' => $role->id,
        ]);
    }

    #[Test]
    public function role_removal_succeeds_when_another_holder_remains(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');

        $target = $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Removable Holder');
        $roleId = DB::table('workspace_user_roles')
            ->where('workspace_user_id', $target->id)
            ->value('workspace_role_id');

        $this->service->removeRole($actor, $workspace, $target->id, (string) $roleId);

        $this->assertDatabaseMissing('workspace_user_roles', [
            'workspace_user_id' => $target->id,
            'workspace_role_id' => $roleId,
        ]);
    }

    #[Test]
    public function removing_final_holder_assignment_rolls_back(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $holder = $this->makeEffectiveHolder($workspace, $actor, 'Only Holder');
        $roleId = DB::table('workspace_user_roles')
            ->where('workspace_user_id', $holder->id)
            ->value('workspace_role_id');

        try {
            $this->service->removeRole($actor, $workspace, $holder->id, (string) $roleId);
            $this->fail('Expected WorkspaceAccessLockoutException.');
        } catch (WorkspaceAccessLockoutException) {
            // expected
        }

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $holder->id,
            'workspace_role_id' => $roleId,
        ]);
    }

    #[Test]
    public function membership_deactivation_succeeds_with_another_holder(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');
        $target = $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Target Holder');

        $this->service->deactivateMembership($actor, $workspace, $target->id);

        $this->assertDatabaseHas('workspace_users', [
            'id' => $target->id,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function last_holder_membership_deactivation_rolls_back(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $holder = $this->makeEffectiveHolder($workspace, $actor, 'Only Holder');

        try {
            $this->service->deactivateMembership($actor, $workspace, $holder->id);
            $this->fail('Expected WorkspaceAccessLockoutException.');
        } catch (WorkspaceAccessLockoutException) {
            // expected
        }

        $this->assertDatabaseHas('workspace_users', [
            'id' => $holder->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function membership_reactivation_preserves_roles(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');

        $target = $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Inactive Target');
        $roleId = DB::table('workspace_user_roles')
            ->where('workspace_user_id', $target->id)
            ->value('workspace_role_id');

        WorkspaceUser::query()->whereKey($target->id)->update(['is_active' => false]);

        $this->service->activateMembership($actor, $workspace, $target->id);

        $this->assertDatabaseHas('workspace_users', [
            'id' => $target->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $target->id,
            'workspace_role_id' => $roleId,
        ]);
    }

    #[Test]
    public function custom_role_gets_null_template_key(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);

        $role = $this->service->createRole($actor, $workspace, 'Merchant Custom', [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
        ]);

        $this->assertNull($role->template_key);
    }

    #[Test]
    public function rename_preserves_existing_template_key(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);

        $role = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Bootstrap',
            'template_key' => 'admin_director',
        ]);

        $renamed = $this->service->renameRole($actor, $workspace, $role->id, 'Renamed Bootstrap');

        $this->assertSame('admin_director', $renamed->template_key);
        $this->assertSame('Renamed Bootstrap', $renamed->name);
    }

    #[Test]
    public function permission_edit_accepts_canonical_codes_only(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');

        $role = $this->service->createRole($actor, $workspace, 'Editable Role', [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
        ]);

        $updated = $this->service->updateRolePermissions($actor, $workspace, $role->id, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
        ]);

        $codes = $updated->permissions()->orderBy('code')->pluck('code')->all();

        $this->assertSame([
            WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
        ], $codes);
    }

    #[Test]
    public function unknown_permission_is_rejected(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);

        $this->expectException(WorkspaceAccessMutationRejectedException::class);

        $this->service->createRole($actor, $workspace, 'Bad Role', ['rogue_permission']);
    }

    #[Test]
    public function removing_manage_workspace_access_from_last_holder_role_rolls_back(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $holder = $this->makeEffectiveHolder($workspace, $actor, 'Only Holder');
        $roleId = DB::table('workspace_user_roles')
            ->where('workspace_user_id', $holder->id)
            ->value('workspace_role_id');

        try {
            $this->service->updateRolePermissions($actor, $workspace, (string) $roleId, [
                WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            ]);
            $this->fail('Expected WorkspaceAccessLockoutException.');
        } catch (WorkspaceAccessLockoutException) {
            // expected
        }

        $this->assertDatabaseHas('workspace_role_permissions', [
            'workspace_role_id' => $roleId,
            'workspace_permission_id' => WorkspacePermission::query()
                ->where('code', WorkspacePermissions::MANAGE_WORKSPACE_ACCESS)
                ->value('id'),
        ]);
    }

    #[Test]
    public function unused_role_deletion_succeeds(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);

        $role = $this->service->createRole($actor, $workspace, 'Disposable Role', [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
        ]);

        $this->service->deleteRole($actor, $workspace, $role->id);

        $this->assertDatabaseMissing('workspace_roles', ['id' => $role->id]);
    }

    #[Test]
    public function assigned_role_deletion_is_rejected(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');

        $target = $this->makeWorkspaceMembership($workspace);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Assigned Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );
        $this->assignRoleToMembership($target, $role);

        $this->expectException(WorkspaceAccessMutationRejectedException::class);

        $this->service->deleteRole($actor, $workspace, $role->id);
    }

    #[Test]
    public function access_operations_do_not_create_or_hard_delete_workspace_user(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $target = $this->makeWorkspaceMembership($workspace);
        $membershipCount = WorkspaceUser::query()->count();

        $role = $this->service->createRole($actor, $workspace, 'Role', [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
        ]);
        $this->service->assignRole($actor, $workspace, $target->id, $role->id);
        $this->service->deactivateMembership($actor, $workspace, $target->id);
        $this->service->activateMembership($actor, $workspace, $target->id);
        $this->service->removeRole($actor, $workspace, $target->id, $role->id);
        $this->service->deleteRole($actor, $workspace, $role->id);

        $this->assertSame($membershipCount, WorkspaceUser::query()->count());
        $this->assertDatabaseHas('workspace_users', ['id' => $target->id]);
    }

    #[Test]
    public function post_lock_authorization_uses_fresh_user_state_after_global_deactivation(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');
        $target = $this->makeWorkspaceMembership($workspace);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Assignable Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );

        User::query()->whereKey($actor->id)->update(['is_active' => false]);
        $this->assertTrue($actor->is_active, 'Pre-lock hydrated actor must remain stale in memory.');

        $this->expectException(WorkspaceAccessUnauthorizedException::class);

        try {
            $this->service->assignRole($actor, $workspace, $target->id, $role->id);
        } finally {
            $this->assertDatabaseMissing('workspace_user_roles', [
                'workspace_user_id' => $target->id,
                'workspace_role_id' => $role->id,
            ]);
        }
    }

    #[Test]
    public function missing_canonical_permission_db_row_fails_closed(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');

        DB::table('workspace_permissions')
            ->where('code', WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS)
            ->delete();

        try {
            $this->service->createRole($actor, $workspace, 'Broken Role', [
                WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            ]);
            $this->fail('Expected WorkspaceAccessMutationRejectedException.');
        } catch (WorkspaceAccessMutationRejectedException $exception) {
            $this->assertStringContainsString('view_connector_accounts', $exception->getMessage());
        }

        $this->assertDatabaseMissing('workspace_roles', ['name' => 'Broken Role']);
    }

    #[Test]
    public function failed_missing_catalogue_resolution_does_not_partially_change_role_permissions(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');

        $role = $this->service->createRole($actor, $workspace, 'Existing Role', [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
        ]);

        $originalPermissionCount = DB::table('workspace_role_permissions')
            ->where('workspace_role_id', $role->id)
            ->count();

        DB::table('workspace_permissions')
            ->where('code', WorkspacePermissions::RUN_CONNECTOR_DISCOVERY)
            ->delete();

        try {
            $this->service->updateRolePermissions($actor, $workspace, $role->id, [
                WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
                WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
            ]);
            $this->fail('Expected WorkspaceAccessMutationRejectedException.');
        } catch (WorkspaceAccessMutationRejectedException $exception) {
            $this->assertStringContainsString('run_connector_discovery', $exception->getMessage());
        }

        $this->assertSame(
            $originalPermissionCount,
            DB::table('workspace_role_permissions')->where('workspace_role_id', $role->id)->count(),
        );
    }

    #[Test]
    public function delete_role_rejects_assigned_state_freshly_observed_after_lock(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');

        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Assigned After Pre-check',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );
        $target = $this->makeWorkspaceMembership($workspace);
        $this->assignRoleToMembership($target, $role);

        $this->expectException(WorkspaceAccessMutationRejectedException::class);

        $this->service->deleteRole($actor, $workspace, $role->id);

        $this->assertDatabaseHas('workspace_roles', ['id' => $role->id]);
    }
}
