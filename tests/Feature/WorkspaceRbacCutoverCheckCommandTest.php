<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceRbacCutoverCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
    }

    #[Test]
    public function safe_preflight_returns_success_exit_code(): void
    {
        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $exitCode = Artisan::call('workspace-rbac:cutover-check');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Safe for cutover: yes', Artisan::output());
    }

    #[Test]
    public function unsafe_preflight_returns_non_zero_exit_code(): void
    {
        User::factory()->create([
            'role' => UserRole::Manager,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $exitCode = Artisan::call('workspace-rbac:cutover-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Safe for cutover: no', $output);
        $this->assertStringContainsString('no_active_staff_admin_or_director', $output);
    }

    #[Test]
    public function failure_reason_and_diagnostic_state_are_surfaced(): void
    {
        DB::table('workspace_permissions')
            ->where('code', 'manage_workspace_access')
            ->delete();

        $exitCode = Artisan::call('workspace-rbac:cutover-check');

        $this->assertSame(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('missing_canonical_permission_codes', $output);
        $this->assertStringContainsString('manage_workspace_access', $output);
        $this->assertStringContainsString('Missing canonical permission codes:', $output);
    }

    #[Test]
    public function command_creates_zero_target_rbac_materialization(): void
    {
        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $membershipCount = DB::table('workspace_users')->count();
        $roleCount = DB::table('workspace_roles')->count();
        $assignmentCount = DB::table('workspace_user_roles')->count();
        $rolePermissionCount = DB::table('workspace_role_permissions')->count();

        Artisan::call('workspace-rbac:cutover-check');

        $this->assertSame($membershipCount, DB::table('workspace_users')->count());
        $this->assertSame($roleCount, DB::table('workspace_roles')->count());
        $this->assertSame($assignmentCount, DB::table('workspace_user_roles')->count());
        $this->assertSame($rolePermissionCount, DB::table('workspace_role_permissions')->count());
    }

    #[Test]
    public function repeated_runs_remain_read_only(): void
    {
        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        Artisan::call('workspace-rbac:cutover-check');
        $firstOutput = Artisan::output();

        Artisan::call('workspace-rbac:cutover-check');
        $secondOutput = Artisan::output();

        $this->assertSame($firstOutput, $secondOutput);
        $this->assertStringContainsString('CHECK-ONLY', $secondOutput);
    }

    #[Test]
    public function no_execute_or_apply_mode_exists(): void
    {
        $definition = Artisan::all()['workspace-rbac:cutover-check'];

        $this->assertStringNotContainsString('--execute', $definition->getSynopsis());
        $this->assertStringNotContainsString('--apply', $definition->getSynopsis());
    }

    #[Test]
    public function command_never_invokes_backfill_execution(): void
    {
        $source = file_get_contents(app_path('Console/Commands/WorkspaceRbacCutoverCheckCommand.php'));

        $this->assertStringNotContainsString('$backfill->execute', $source);
        $this->assertStringNotContainsString('LegacyBackfill::execute', $source);

        Artisan::call('workspace-rbac:cutover-check');

        $this->assertStringContainsString('Backfill execute() is never invoked', Artisan::output());
    }
}
