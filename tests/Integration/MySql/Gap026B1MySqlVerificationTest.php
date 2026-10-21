<?php

namespace Tests\Integration\MySql;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\UserLifecycleService;
use App\Services\Workspace\WorkspaceAccessMutationService;
use App\Support\Workspace\Rbac\Exceptions\UserLifecycleIntegrityException;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessLockoutException;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class Gap026B1MySqlVerificationTest extends TestCase
{
    use InteractsWithWorkspaceRbac;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only GAP-026B-1 verification.');
        }

        Artisan::call('migrate:fresh');
        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
    }

    #[Test]
    public function access_application_mutation_and_last_holder_rollback_on_mysql(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $holder = $this->makeEffectiveHolder($workspace, $actor, 'Only Holder');
        $service = app(WorkspaceAccessMutationService::class);
        $roleId = DB::table('workspace_user_roles')
            ->where('workspace_user_id', $holder->id)
            ->value('workspace_role_id');

        try {
            $service->removeRole($actor, $workspace, $holder->id, (string) $roleId);
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
    public function multi_workspace_global_user_deactivation_on_mysql(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $targetUser = User::factory()->create(['is_active' => true]);

        $this->makeEffectiveHolder($workspaceA, $targetUser, 'Target A');
        $this->makeEffectiveHolder($workspaceB, $targetUser, 'Target B');
        $this->makeEffectiveHolder($workspaceA, User::factory()->create(), 'Backup A');
        $this->makeEffectiveHolder($workspaceB, User::factory()->create(), 'Backup B');

        $updated = app(UserLifecycleService::class)->update($targetUser, ['is_active' => false]);

        $this->assertFalse($updated->is_active);
    }

    #[Test]
    public function rollback_when_one_affected_workspace_would_lose_final_holder_on_mysql(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $targetUser = User::factory()->create(['is_active' => true]);

        $this->makeEffectiveHolder($workspaceA, $targetUser, 'Only Holder A');
        $this->makeEffectiveHolder($workspaceB, $targetUser, 'Target B');
        $this->makeEffectiveHolder($workspaceB, User::factory()->create(), 'Backup B');

        try {
            app(UserLifecycleService::class)->update($targetUser, ['is_active' => false]);
            $this->fail('Expected UserLifecycleIntegrityException.');
        } catch (UserLifecycleIntegrityException) {
            // expected
        }

        $this->assertTrue($targetUser->fresh()->is_active);
    }

    #[Test]
    public function check_only_command_remains_read_only_on_mysql(): void
    {
        User::factory()->create([
            'is_active' => true,
            'customer_id' => null,
        ]);

        $membershipCount = DB::table('workspace_users')->count();
        $roleCount = DB::table('workspace_roles')->count();

        $exitCode = Artisan::call('workspace-rbac:cutover-check');

        $this->assertContains($exitCode, [0, 1]);
        $this->assertSame($membershipCount, DB::table('workspace_users')->count());
        $this->assertSame($roleCount, DB::table('workspace_roles')->count());
        $this->assertStringContainsString('CHECK-ONLY', Artisan::output());
    }
}
