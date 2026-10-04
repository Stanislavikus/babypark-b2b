<?php

namespace Tests\Feature;

use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
