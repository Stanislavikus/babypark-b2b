<?php

namespace Tests\Feature;

use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkspaceProductCostPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function migration_materializes_product_cost_permission_without_granting_it_to_any_role(): void
    {
        $permissionId = DB::table('workspace_permissions')
            ->where('code', WorkspacePermissions::MANAGE_PRODUCT_COST)
            ->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(
            0,
            DB::table('workspace_role_permissions')
                ->where('workspace_permission_id', $permissionId)
                ->count(),
        );
    }

    #[Test]
    public function rollback_preserves_materialized_cost_permission_and_role_assignments(): void
    {
        $permissionId = DB::table('workspace_permissions')
            ->where('code', WorkspacePermissions::MANAGE_PRODUCT_COST)
            ->value('id');

        $workspaceId = DB::table('workspaces')->value('id');
        $roleId = (string) Str::uuid();

        DB::table('workspace_roles')->insert([
            'id' => $roleId,
            'workspace_id' => $workspaceId,
            'name' => 'Cost rollback role',
        ]);

        DB::table('workspace_role_permissions')->insert([
            'workspace_id' => $workspaceId,
            'workspace_role_id' => $roleId,
            'workspace_permission_id' => $permissionId,
        ]);

        $migration = require database_path(
            'migrations/2026_10_04_150000_add_manage_product_cost_permission.php'
        );

        $migration->down();

        $this->assertDatabaseHas('workspace_permissions', [
            'id' => $permissionId,
            'code' => WorkspacePermissions::MANAGE_PRODUCT_COST,
        ]);
        $this->assertDatabaseHas('workspace_role_permissions', [
            'workspace_id' => $workspaceId,
            'workspace_role_id' => $roleId,
            'workspace_permission_id' => $permissionId,
        ]);
    }
}
