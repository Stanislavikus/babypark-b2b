<?php

namespace Tests\Feature;

use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkspaceProductPermissionsMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function migration_materializes_product_permissions_without_granting_them_to_any_role(): void
    {
        foreach ([
            WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
            WorkspacePermissions::MANAGE_PRODUCTS,
        ] as $code) {
            $permissionId = DB::table('workspace_permissions')
                ->where('code', $code)
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

    #[Test]
    public function migration_is_idempotent_and_rollback_preserves_existing_canonical_permissions(): void
    {
        $before = DB::table('workspace_permissions')
            ->whereIn('code', [
                WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
                WorkspacePermissions::MANAGE_PRODUCTS,
            ])
            ->pluck('id', 'code')
            ->all();

        $migration = require database_path(
            'migrations/2026_10_04_163000_materialize_product_workspace_permissions.php'
        );

        $migration->up();

        $this->assertSame(
            $before,
            DB::table('workspace_permissions')
                ->whereIn('code', array_keys($before))
                ->pluck('id', 'code')
                ->all(),
        );

        $migration->down();

        foreach ($before as $code => $id) {
            $this->assertSame(
                $id,
                DB::table('workspace_permissions')->where('code', $code)->value('id'),
            );
        }
    }
}
