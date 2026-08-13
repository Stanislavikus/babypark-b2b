<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceRbacFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
    }

    #[Test]
    public function migration_creates_all_five_tables_with_expected_columns_and_indexes(): void
    {
        foreach ([
            'workspace_users',
            'workspace_roles',
            'workspace_permissions',
            'workspace_user_roles',
            'workspace_role_permissions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue(Schema::hasColumns('workspace_users', [
            'id',
            'workspace_id',
            'user_id',
            'is_active',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('workspace_roles', [
            'id',
            'workspace_id',
            'name',
            'template_key',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('workspace_permissions', ['id', 'code']));
        $this->assertTrue(Schema::hasColumns('workspace_user_roles', [
            'workspace_id',
            'workspace_user_id',
            'workspace_role_id',
        ]));
        $this->assertTrue(Schema::hasColumns('workspace_role_permissions', [
            'workspace_id',
            'workspace_role_id',
            'workspace_permission_id',
        ]));

        $this->assertTrue($this->indexExists('workspace_users', 'wu_ws_user_unique'));
        $this->assertTrue($this->indexExists('workspace_users', 'wu_ws_id_unique'));
        $this->assertTrue($this->indexExists('workspace_roles', 'wr_ws_name_unique'));
        $this->assertTrue($this->indexExists('workspace_roles', 'wr_ws_template_key_unique'));
        $this->assertTrue($this->indexExists('workspace_roles', 'wr_ws_id_unique'));
        $this->assertTrue($this->indexExists('workspace_user_roles', 'wur_user_role_unique'));
        $this->assertTrue($this->indexExists('workspace_user_roles', 'wur_ws_user_id_idx'));
        $this->assertTrue($this->indexExists('workspace_user_roles', 'wur_ws_role_id_idx'));
        $this->assertTrue($this->indexExists('workspace_role_permissions', 'wrp_role_permission_unique'));
        $this->assertTrue($this->indexExists('workspace_role_permissions', 'wrp_ws_role_id_idx'));
    }

    #[Test]
    public function migration_rolls_back_cleanly_and_re_migrates(): void
    {
        $this->rollbackThrough('2026_08_13_100000_workspace_rbac_foundation');

        foreach ([
            'workspace_role_permissions',
            'workspace_user_roles',
            'workspace_permissions',
            'workspace_roles',
            'workspace_users',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Table still exists after rollback: {$table}");
        }

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('workspace_users'));
    }

    #[Test]
    public function parent_workspace_foreign_key_rejects_orphan_workspace_user(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('workspace_users')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function parent_user_foreign_key_rejects_orphan_workspace_user(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $this->expectException(QueryException::class);

        DB::table('workspace_users')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => 999_999,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function cross_workspace_user_and_role_assignment_is_rejected(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->sole();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $user = User::factory()->create();

        $workspaceUserA = WorkspaceUser::query()->create([
            'workspace_id' => $workspaceA->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $roleB = WorkspaceRole::query()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Role B',
        ]);

        $this->expectException(QueryException::class);

        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $workspaceB->id,
            'workspace_user_id' => $workspaceUserA->id,
            'workspace_role_id' => $roleB->id,
        ]);
    }

    #[Test]
    public function same_workspace_user_and_role_assignment_succeeds(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $user = User::factory()->create();

        $workspaceUser = WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $role = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Operator',
        ]);

        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $workspace->id,
            'workspace_user_id' => $workspaceUser->id,
            'workspace_role_id' => $role->id,
        ]);

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_id' => $workspace->id,
            'workspace_user_id' => $workspaceUser->id,
            'workspace_role_id' => $role->id,
        ]);
    }

    #[Test]
    public function duplicate_workspace_user_membership_is_rejected(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $user = User::factory()->create();

        WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function same_user_may_belong_to_two_different_workspaces(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->sole();
        $workspaceB = Workspace::query()->create(['name' => 'Second', 'is_default' => false]);
        $user = User::factory()->create();

        WorkspaceUser::query()->create([
            'workspace_id' => $workspaceA->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        WorkspaceUser::query()->create([
            'workspace_id' => $workspaceB->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $this->assertSame(2, WorkspaceUser::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function duplicate_role_name_inside_one_workspace_is_rejected(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Manager',
        ]);

        $this->expectException(QueryException::class);

        WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Manager',
        ]);
    }

    #[Test]
    public function same_role_name_in_another_workspace_is_allowed(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->sole();
        $workspaceB = Workspace::query()->create(['name' => 'Second', 'is_default' => false]);

        WorkspaceRole::query()->create([
            'workspace_id' => $workspaceA->id,
            'name' => 'Manager',
        ]);

        WorkspaceRole::query()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Manager',
        ]);

        $this->assertSame(2, WorkspaceRole::query()->where('name', 'Manager')->count());
    }

    #[Test]
    public function duplicate_non_null_template_key_inside_one_workspace_is_rejected(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Bootstrap A',
            'template_key' => 'bootstrap_admin',
        ]);

        $this->expectException(QueryException::class);

        WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Bootstrap B',
            'template_key' => 'bootstrap_admin',
        ]);
    }

    #[Test]
    public function multiple_null_template_keys_inside_one_workspace_are_allowed(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Custom A',
            'template_key' => null,
        ]);

        WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Custom B',
            'template_key' => null,
        ]);

        $this->assertSame(2, WorkspaceRole::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('template_key')
            ->count());
    }

    #[Test]
    public function cross_workspace_role_permission_guard_cannot_be_forged(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->sole();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);

        $roleA = WorkspaceRole::query()->create([
            'workspace_id' => $workspaceA->id,
            'name' => 'Role A',
        ]);

        $permission = WorkspacePermission::query()
            ->where('code', WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS)
            ->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('workspace_role_permissions')->insert([
            'workspace_id' => $workspaceB->id,
            'workspace_role_id' => $roleA->id,
            'workspace_permission_id' => $permission->id,
        ]);
    }

    #[Test]
    public function restrict_blocks_deletion_of_referenced_user(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $user = User::factory()->create();

        WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        $user->delete();
    }

    #[Test]
    public function restrict_blocks_deletion_of_referenced_workspace_user(): void
    {
        [$workspaceUser, $role] = $this->makeMembershipWithRole();

        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $workspaceUser->workspace_id,
            'workspace_user_id' => $workspaceUser->id,
            'workspace_role_id' => $role->id,
        ]);

        $this->expectException(QueryException::class);

        $workspaceUser->delete();
    }

    #[Test]
    public function restrict_blocks_deletion_of_referenced_workspace_role(): void
    {
        [$workspaceUser, $role] = $this->makeMembershipWithRole();
        $permission = WorkspacePermission::query()
            ->where('code', WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS)
            ->firstOrFail();

        DB::table('workspace_role_permissions')->insert([
            'workspace_id' => $role->workspace_id,
            'workspace_role_id' => $role->id,
            'workspace_permission_id' => $permission->id,
        ]);

        $this->expectException(QueryException::class);

        $role->delete();
    }

    #[Test]
    public function restrict_blocks_deletion_of_referenced_workspace_permission(): void
    {
        [, $role] = $this->makeMembershipWithRole();
        $permission = WorkspacePermission::query()
            ->where('code', WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS)
            ->firstOrFail();

        DB::table('workspace_role_permissions')->insert([
            'workspace_id' => $role->workspace_id,
            'workspace_role_id' => $role->id,
            'workspace_permission_id' => $permission->id,
        ]);

        $this->expectException(QueryException::class);

        $permission->delete();
    }

    /**
     * @return array{0: WorkspaceUser, 1: WorkspaceRole}
     */
    private function makeMembershipWithRole(): array
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $user = User::factory()->create();

        $workspaceUser = WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $role = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Role '.Str::uuid(),
        ]);

        return [$workspaceUser, $role];
    }

    private function rollbackThrough(string $targetMigration): void
    {
        $migrations = DB::table('migrations')
            ->orderByDesc('batch')
            ->orderByDesc('migration')
            ->pluck('migration')
            ->values();

        $position = $migrations->search($targetMigration);

        $this->assertNotSame(
            false,
            $position,
            "Target migration is not recorded as applied: {$targetMigration}",
        );

        $this->artisan('migrate:rollback', [
            '--step' => ((int) $position) + 1,
        ])->assertExitCode(0);
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list('{$table}')");

            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();
        $result = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $index]
        );

        return $result !== [];
    }
}
