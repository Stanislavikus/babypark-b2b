<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Services\Workspace\WorkspaceAccessMutationCoordinator;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessLockoutException;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class WorkspaceAccessMutationCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private WorkspaceAccessMutationCoordinator $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->coordinator = app(WorkspaceAccessMutationCoordinator::class);
    }

    #[Test]
    public function permits_creation_of_first_effective_holder_from_zero(): void
    {
        [$workspace, $membership] = $this->makeMembershipWithoutAccess();

        $this->coordinator->mutateLocked($workspace, function (Workspace $lockedWorkspace) use ($membership): void {
            $role = $this->createRoleWithPermission(
                $lockedWorkspace->id,
                'First Holder Role',
                WorkspacePermissions::MANAGE_WORKSPACE_ACCESS,
            );

            DB::table('workspace_user_roles')->insert([
                'workspace_id' => $lockedWorkspace->id,
                'workspace_user_id' => $membership->id,
                'workspace_role_id' => $role->id,
            ]);
        });

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $membership->id,
        ]);
    }

    #[Test]
    public function with_two_effective_holders_removing_one_succeeds(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $holderA = $this->makeEffectiveHolder($workspace, 'Holder A');
        $holderB = $this->makeEffectiveHolder($workspace, 'Holder B');

        $this->coordinator->mutateLocked($workspace, function () use ($holderA): void {
            WorkspaceUser::query()
                ->whereKey($holderA->id)
                ->update(['is_active' => false]);
        });

        $this->assertDatabaseHas('workspace_users', [
            'id' => $holderA->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('workspace_users', [
            'id' => $holderB->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function removing_last_effective_holder_throws_and_rolls_back(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $holder = $this->makeEffectiveHolder($workspace, 'Only Holder');

        try {
            $this->coordinator->mutateLocked($workspace, function () use ($holder): void {
                WorkspaceUser::query()
                    ->whereKey($holder->id)
                    ->update(['is_active' => false]);
            });
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
    public function removing_last_holder_role_assignment_rolls_back(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $holder = $this->makeEffectiveHolder($workspace, 'Only Holder');

        try {
            $this->coordinator->mutateLocked($workspace, function () use ($holder): void {
                DB::table('workspace_user_roles')
                    ->where('workspace_user_id', $holder->id)
                    ->delete();
            });
            $this->fail('Expected WorkspaceAccessLockoutException.');
        } catch (WorkspaceAccessLockoutException) {
            // expected
        }

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $holder->id,
        ]);
    }

    #[Test]
    public function removing_manage_workspace_access_from_last_holder_role_rolls_back(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $holder = $this->makeEffectiveHolder($workspace, 'Only Holder');
        $roleId = DB::table('workspace_user_roles')
            ->where('workspace_user_id', $holder->id)
            ->value('workspace_role_id');

        try {
            $this->coordinator->mutateLocked($workspace, function () use ($roleId): void {
                $permissionId = WorkspacePermission::query()
                    ->where('code', WorkspacePermissions::MANAGE_WORKSPACE_ACCESS)
                    ->value('id');

                DB::table('workspace_role_permissions')
                    ->where('workspace_role_id', $roleId)
                    ->where('workspace_permission_id', $permissionId)
                    ->delete();
            });
            $this->fail('Expected WorkspaceAccessLockoutException.');
        } catch (WorkspaceAccessLockoutException) {
            // expected
        }

        $this->assertDatabaseHas('workspace_role_permissions', [
            'workspace_role_id' => $roleId,
        ]);
    }

    #[Test]
    public function inactive_workspace_user_does_not_count(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $inactiveHolder = $this->makeEffectiveHolder($workspace, 'Inactive Membership');
        WorkspaceUser::query()->whereKey($inactiveHolder->id)->update(['is_active' => false]);

        $this->coordinator->mutateLocked($workspace, function () use ($workspace): void {
            $replacement = $this->makeEffectiveHolder($workspace, 'Replacement Holder');
            $this->assertDatabaseHas('workspace_users', ['id' => $replacement->id, 'is_active' => true]);
        });
    }

    #[Test]
    public function globally_inactive_user_does_not_count(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $inactiveUserHolder = $this->makeEffectiveHolder($workspace, 'Inactive User Holder');
        User::query()->whereKey($inactiveUserHolder->user_id)->update(['is_active' => false]);

        $this->coordinator->mutateLocked($workspace, function () use ($workspace): void {
            $replacement = $this->makeEffectiveHolder($workspace, 'Replacement Holder');
            $this->assertDatabaseHas('workspace_users', ['id' => $replacement->id, 'is_active' => true]);
        });
    }

    #[Test]
    public function same_user_active_in_another_workspace_does_not_satisfy_target_workspace(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->sole();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);

        $holder = $this->makeEffectiveHolder($workspaceA, 'Target Workspace Holder');
        $user = User::query()->findOrFail($holder->user_id);

        WorkspaceUser::query()->create([
            'workspace_id' => $workspaceB->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $otherRole = $this->createRoleWithPermission(
            $workspaceB->id,
            'Other Workspace Role',
            WorkspacePermissions::MANAGE_WORKSPACE_ACCESS,
        );
        $otherMembership = WorkspaceUser::query()
            ->where('workspace_id', $workspaceB->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $workspaceB->id,
            'workspace_user_id' => $otherMembership->id,
            'workspace_role_id' => $otherRole->id,
        ]);

        try {
            $this->coordinator->mutateLocked($workspaceA, function () use ($holder): void {
                WorkspaceUser::query()
                    ->whereKey($holder->id)
                    ->update(['is_active' => false]);
            });
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
    public function permission_of_same_role_in_another_workspace_cannot_satisfy_target_workspace(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->sole();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);

        $holder = $this->makeEffectiveHolder($workspaceA, 'Target Holder');
        $this->makeEffectiveHolder($workspaceB, 'Other Workspace Holder');

        try {
            $this->coordinator->mutateLocked($workspaceA, function () use ($holder): void {
                WorkspaceUser::query()
                    ->whereKey($holder->id)
                    ->update(['is_active' => false]);
            });
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
    public function mutation_exception_from_callback_rolls_back_without_false_successful_state(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $holder = $this->makeEffectiveHolder($workspace, 'Holder');

        try {
            $this->coordinator->mutateLocked($workspace, function () use ($holder): void {
                WorkspaceUser::query()
                    ->whereKey($holder->id)
                    ->update(['is_active' => false]);

                throw new RuntimeException('Mutator failed.');
            });
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseHas('workspace_users', [
            'id' => $holder->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function coordinator_uses_explicit_workspace_not_ambient_workspace_context(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->sole();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);

        $holderB = $this->makeEffectiveHolder($workspaceB, 'Workspace B Holder');

        app(WorkspaceContext::class)->current();

        try {
            $this->coordinator->mutateLocked($workspaceA, function () use ($holderB): void {
                WorkspaceUser::query()
                    ->whereKey($holderB->id)
                    ->update(['is_active' => false]);
            });
            $this->fail('Expected WorkspaceAccessLockoutException.');
        } catch (WorkspaceAccessLockoutException) {
            // expected — workspace A has no holders; B's holder cannot satisfy A
        }

        $this->assertDatabaseHas('workspace_users', [
            'id' => $holderB->id,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: Workspace, 1: WorkspaceUser}
     */
    private function makeMembershipWithoutAccess(): array
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $user = User::factory()->create(['is_active' => true]);

        $membership = WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        return [$workspace, $membership];
    }

    private function makeEffectiveHolder(Workspace $workspace, string $roleName): WorkspaceUser
    {
        $user = User::factory()->create(['is_active' => true]);

        $membership = WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $role = $this->createRoleWithPermission(
            $workspace->id,
            $roleName,
            WorkspacePermissions::MANAGE_WORKSPACE_ACCESS,
        );

        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $workspace->id,
            'workspace_user_id' => $membership->id,
            'workspace_role_id' => $role->id,
        ]);

        return $membership;
    }

    private function createRoleWithPermission(string $workspaceId, string $name, string $permissionCode): WorkspaceRole
    {
        $role = WorkspaceRole::query()->create([
            'workspace_id' => $workspaceId,
            'name' => $name,
        ]);

        $permission = WorkspacePermission::query()
            ->where('code', $permissionCode)
            ->firstOrFail();

        DB::table('workspace_role_permissions')->insert([
            'workspace_id' => $workspaceId,
            'workspace_role_id' => $role->id,
            'workspace_permission_id' => $permission->id,
        ]);

        return $role;
    }
}
