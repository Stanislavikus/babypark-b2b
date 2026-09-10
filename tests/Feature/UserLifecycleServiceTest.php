<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Workspace\UserLifecycleService;
use App\Support\Workspace\Rbac\Exceptions\UserDeletionForbiddenException;
use App\Support\Workspace\Rbac\Exceptions\UserLifecycleIntegrityException;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class UserLifecycleServiceTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private UserLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->service = app(UserLifecycleService::class);
    }

    #[Test]
    public function deactivation_with_no_workspace_user_succeeds(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $updated = $this->service->update($user, ['is_active' => false]);

        $this->assertFalse($updated->is_active);
    }

    #[Test]
    public function multi_workspace_deactivation_succeeds_when_every_workspace_retains_another_holder(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);

        $targetUser = User::factory()->create(['is_active' => true]);
        $this->makeEffectiveHolder($workspaceA, $targetUser, 'Target Holder A');
        $this->makeEffectiveHolder($workspaceB, $targetUser, 'Target Holder B');
        $this->makeEffectiveHolder($workspaceA, User::factory()->create(), 'Backup A');
        $this->makeEffectiveHolder($workspaceB, User::factory()->create(), 'Backup B');

        $updated = $this->service->update($targetUser, ['is_active' => false]);

        $this->assertFalse($updated->is_active);
    }

    #[Test]
    public function one_unsafe_workspace_causes_entire_user_update_rollback(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);

        $targetUser = User::factory()->create(['is_active' => true]);
        $this->makeEffectiveHolder($workspaceA, $targetUser, 'Only Holder A');
        $this->makeEffectiveHolder($workspaceB, $targetUser, 'Target Holder B');
        $this->makeEffectiveHolder($workspaceB, User::factory()->create(), 'Backup B');

        try {
            $this->service->update($targetUser, ['is_active' => false]);
            $this->fail('Expected UserLifecycleIntegrityException.');
        } catch (UserLifecycleIntegrityException) {
            // expected
        }

        $this->assertTrue($targetUser->fresh()->is_active);
    }

    #[Test]
    public function holder_in_another_workspace_cannot_compensate_for_missing_holder_in_target_workspace(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);

        $targetUser = User::factory()->create(['is_active' => true]);
        $this->makeEffectiveHolder($workspaceA, $targetUser, 'Only Holder A');
        $this->makeEffectiveHolder($workspaceB, $targetUser, 'Holder B');
        $this->makeEffectiveHolder($workspaceB, User::factory()->create(), 'Backup B');

        try {
            $this->service->update($targetUser, ['is_active' => false]);
            $this->fail('Expected UserLifecycleIntegrityException.');
        } catch (UserLifecycleIntegrityException) {
            // expected
        }

        $this->assertTrue($targetUser->fresh()->is_active);
    }

    #[Test]
    public function reactivation_preserves_workspace_user_active_state(): void
    {
        $workspace = $this->defaultWorkspace();
        $user = User::factory()->create(['is_active' => false]);
        $membership = $this->makeWorkspaceMembership($workspace, $user, false);

        $updated = $this->service->update($user, ['is_active' => true]);

        $this->assertTrue($updated->is_active);
        $this->assertDatabaseHas('workspace_users', [
            'id' => $membership->id,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function reactivation_preserves_all_workspace_role_assignments(): void
    {
        $workspace = $this->defaultWorkspace();
        $user = User::factory()->create(['is_active' => false]);
        $membership = $this->makeEffectiveHolder($workspace, $user, 'Preserved Role');
        $roleId = DB::table('workspace_user_roles')
            ->where('workspace_user_id', $membership->id)
            ->value('workspace_role_id');

        $this->service->update($user, ['is_active' => true]);

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $membership->id,
            'workspace_role_id' => $roleId,
        ]);
    }

    #[Test]
    public function user_role_update_does_not_synchronize_workspace_rbac(): void
    {
        $workspace = $this->defaultWorkspace();
        $user = User::factory()->create(['role' => UserRole::Manager, 'is_active' => true]);
        $membershipCount = WorkspaceUser::query()->count();
        $roleCount = DB::table('workspace_roles')->count();
        $assignmentCount = DB::table('workspace_user_roles')->count();

        $this->service->update($user, ['role' => UserRole::Admin]);

        $this->assertSame(UserRole::Admin, $user->fresh()->role);
        $this->assertSame($membershipCount, WorkspaceUser::query()->count());
        $this->assertSame($roleCount, DB::table('workspace_roles')->count());
        $this->assertSame($assignmentCount, DB::table('workspace_user_roles')->count());
    }

    #[Test]
    public function customer_id_update_does_not_create_membership_or_rewrite_roles(): void
    {
        $workspace = $this->defaultWorkspace();
        $customer = Customer::query()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Test Customer',
            'login' => 'test-customer-'.Str::random(6),
            'password' => 'password',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['customer_id' => null]);
        $this->makeEffectiveHolder($workspace, $user, 'Existing Role');

        $membershipCount = WorkspaceUser::query()->count();
        $assignmentCount = DB::table('workspace_user_roles')->count();

        $this->service->update($user, ['customer_id' => $customer->id]);

        $this->assertSame($customer->id, $user->fresh()->customer_id);
        $this->assertSame($membershipCount, WorkspaceUser::query()->count());
        $this->assertSame($assignmentCount, DB::table('workspace_user_roles')->count());
    }

    #[Test]
    public function user_with_workspace_user_cannot_hard_delete(): void
    {
        $workspace = $this->defaultWorkspace();
        $user = User::factory()->create();
        $this->makeWorkspaceMembership($workspace, $user);

        $this->expectException(UserDeletionForbiddenException::class);

        $this->service->delete($user);
    }

    #[Test]
    public function denied_hard_delete_preserves_memberships_and_role_pivots(): void
    {
        $workspace = $this->defaultWorkspace();
        $user = User::factory()->create();
        $membership = $this->makeEffectiveHolder($workspace, $user, 'Holder Role');
        $rolePivotCount = DB::table('workspace_user_roles')->count();

        try {
            $this->service->delete($user);
            $this->fail('Expected UserDeletionForbiddenException.');
        } catch (UserDeletionForbiddenException) {
            // expected
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('workspace_users', ['id' => $membership->id]);
        $this->assertSame($rolePivotCount, DB::table('workspace_user_roles')->count());
    }

    #[Test]
    public function user_with_no_workspace_user_retains_allowed_delete_behavior(): void
    {
        $user = User::factory()->create();

        $this->service->delete($user);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
