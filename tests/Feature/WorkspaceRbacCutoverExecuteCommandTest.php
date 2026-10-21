<?php

namespace Tests\Feature;

use App\Console\Commands\WorkspaceRbacCutoverCheckCommand;
use App\Console\Commands\WorkspaceRbacCutoverExecuteCommand;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceRole;
use App\Services\Workspace\WorkspaceAccessEffectiveHolderQuery;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WorkspaceRbacCutoverExecuteCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        Permission::findOrCreate('legacy-spatie-tax-permission');
    }

    protected function tearDown(): void
    {
        if (app()->isDownForMaintenance()) {
            $this->artisan('up');
        }

        parent::tearDown();
    }

    #[Test]
    public function execute_refuses_outside_maintenance_mode(): void
    {
        $exitCode = Artisan::call('workspace-rbac:cutover-execute');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'not in maintenance mode',
            Artisan::output(),
        );
        $this->assertSame(0, WorkspaceRole::query()->count());
    }

    #[Test]
    public function check_command_remains_read_only_without_execute_option(): void
    {
        $this->seedStaffAdminDirector();

        $exitCode = Artisan::call('workspace-rbac:cutover-check');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('CHECK-ONLY', Artisan::output());
    }

    #[Test]
    public function execute_and_check_are_separate_commands(): void
    {
        $this->assertNotSame(
            WorkspaceRbacCutoverCheckCommand::class,
            WorkspaceRbacCutoverExecuteCommand::class,
        );
    }

    #[Test]
    public function unsafe_preflight_fails_without_backfill(): void
    {
        $this->artisan('down');

        User::query()->delete();

        $exitCode = Artisan::call('workspace-rbac:cutover-execute');

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, WorkspaceRole::query()->count());
    }

    #[Test]
    public function execute_invokes_backfill_when_preflight_safe_in_maintenance_mode(): void
    {
        $this->seedStaffAdminDirector();
        $this->artisan('down');

        $exitCode = Artisan::call('workspace-rbac:cutover-execute');

        $this->assertSame(0, $exitCode);
        $this->assertGreaterThan(0, WorkspaceRole::query()->count());
        $this->assertStringContainsString('EXECUTE completed successfully', Artisan::output());
    }

    #[Test]
    public function effective_holder_query_returns_zero_without_access_grants(): void
    {
        $this->seedStaffAdminDirector();
        $this->artisan('down');

        $workspaceId = Workspace::query()->where('is_default', true)->value('id');
        $exitCode = Artisan::call('workspace-rbac:cutover-execute');
        $this->assertSame(0, $exitCode);

        $managePermissionId = DB::table('workspace_permissions')
            ->where('code', WorkspacePermissions::MANAGE_WORKSPACE_ACCESS)
            ->value('id');

        DB::table('workspace_user_roles')->delete();
        DB::table('workspace_role_permissions')
            ->where('workspace_permission_id', $managePermissionId)
            ->delete();

        $this->assertSame(
            0,
            app(WorkspaceAccessEffectiveHolderQuery::class)->countEffectiveHolders($workspaceId),
        );
    }

    #[Test]
    public function execute_command_fails_closed_when_post_backfill_holder_count_is_zero(): void
    {
        $admin = $this->seedStaffAdminDirector();
        $this->artisan('down');

        $workspaceId = Workspace::query()->where('is_default', true)->value('id');
        $deactivatedDuringBackfill = false;

        DB::listen(function ($query) use ($admin, &$deactivatedDuringBackfill): void {
            if (! $deactivatedDuringBackfill
                && str_contains(strtolower($query->sql), 'insert')
                && str_contains(strtolower($query->sql), 'workspace_user_roles')) {
                User::query()->whereKey($admin->id)->update(['is_active' => false]);
                $deactivatedDuringBackfill = true;
            }
        });

        $exitCode = Artisan::call('workspace-rbac:cutover-execute');
        $output = Artisan::output();

        $this->assertTrue($deactivatedDuringBackfill);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Backfill completed.', $output);
        $this->assertStringContainsString(
            'zero effective manage_workspace_access holders',
            $output,
        );
        $this->assertGreaterThan(0, WorkspaceRole::query()->count());
        $this->assertSame(
            0,
            app(WorkspaceAccessEffectiveHolderQuery::class)->countEffectiveHolders($workspaceId),
        );
    }

    private function seedStaffAdminDirector(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);
    }
}
