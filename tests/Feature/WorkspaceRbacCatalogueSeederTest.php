<?php

namespace Tests\Feature;

use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WorkspaceRbacCatalogueSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function catalogue_contains_exactly_nine_resolved_codes(): void
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $codes = WorkspacePermission::query()->orderBy('code')->pluck('code')->all();

        $this->assertCount(9, $codes);
        $this->assertEqualsCanonicalizing(WorkspacePermissions::catalogue(), $codes);
    }

    #[Test]
    public function target_seeder_is_idempotent(): void
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->assertSame(9, WorkspacePermission::query()->count());
    }

    #[Test]
    public function legacy_spatie_workspace_permission_seeder_still_works(): void
    {
        $this->seed(WorkspacePermissionSeeder::class);

        $this->assertNotNull(
            Permission::query()
                ->where('name', WorkspacePermissions::MANAGE_TAX_SETTINGS)
                ->where('guard_name', 'web')
                ->first(),
        );
        $this->assertNotNull(
            Permission::query()
                ->where('name', WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS)
                ->where('guard_name', 'web')
                ->first(),
        );
    }

    #[Test]
    public function database_seeder_creates_no_rbac_membership_or_role_assignments(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(9, WorkspacePermission::query()->count());
        $this->assertSame(0, WorkspaceUser::query()->count());
        $this->assertSame(0, WorkspaceRole::query()->count());
        $this->assertDatabaseCount('workspace_user_roles', 0);
        $this->assertDatabaseCount('workspace_role_permissions', 0);
    }

    #[Test]
    public function database_seeder_includes_workspace_seeder_and_both_permission_seeders(): void
    {
        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->assertSame(9, WorkspacePermission::query()->count());
        $this->assertGreaterThanOrEqual(2, Permission::query()->count());
    }
}
