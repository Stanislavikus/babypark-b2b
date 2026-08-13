<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Services\Workspace\WorkspaceRbacLegacyPreflight;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceRbacLegacyPreflightException;
use App\Support\Workspace\Rbac\WorkspaceRbacLegacyPreflightFailureReason;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkspaceRbacLegacyPreflightTest extends TestCase
{
    use RefreshDatabase;

    private WorkspaceRbacLegacyPreflight $preflight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->preflight = app(WorkspaceRbacLegacyPreflight::class);
    }

    #[Test]
    public function safe_baseline_is_safe(): void
    {
        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $result = $this->preflight->evaluate();

        $this->assertTrue($result->isSafe);
        $this->assertSame([], $result->failureReasonCodes());
        $this->assertNotNull($result->defaultWorkspaceId);
        $this->assertSame(0, $result->rolesCount);
        $this->assertSame(0, $result->modelHasRolesCount);
        $this->assertSame(0, $result->modelHasPermissionsCount);
        $this->assertSame(0, $result->roleHasPermissionsCount);
    }

    #[Test]
    public function multiple_workspaces_fails_closed(): void
    {
        Workspace::query()->create(['name' => 'Second', 'is_default' => false]);

        $result = $this->preflight->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::MultipleWorkspaces->value,
            $result->failureReasonCodes(),
        );
    }

    #[Test]
    public function zero_default_workspaces_fails_closed(): void
    {
        Workspace::query()->where('is_default', true)->update(['is_default' => false]);

        $result = $this->preflight->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::ZeroDefaultWorkspaces->value,
            $result->failureReasonCodes(),
        );
    }

    #[Test]
    public function multiple_default_workspaces_fails_closed(): void
    {
        Workspace::query()->create(['name' => 'Also default', 'is_default' => true]);

        $result = $this->preflight->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::MultipleDefaultWorkspaces->value,
            $result->failureReasonCodes(),
        );
    }

    #[Test]
    public function no_active_staff_admin_or_director_fails_closed(): void
    {
        $result = $this->preflight->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::NoActiveStaffAdminOrDirector->value,
            $result->failureReasonCodes(),
        );
    }

    #[Test]
    public function only_inactive_staff_admin_or_director_fails_closed(): void
    {
        User::factory()->create([
            'role' => UserRole::Director,
            'is_active' => false,
            'customer_id' => null,
        ]);

        $result = $this->preflight->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::OnlyInactiveStaffAdminOrDirector->value,
            $result->failureReasonCodes(),
        );
    }

    #[Test]
    public function spatie_roles_non_empty_fails_closed(): void
    {
        Role::create(['name' => 'legacy-role', 'guard_name' => 'web']);

        $result = $this->preflight->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertSame(1, $result->rolesCount);
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::SpatieRolesNonEmpty->value,
            $result->failureReasonCodes(),
        );
    }

    #[Test]
    public function spatie_model_has_roles_non_empty_fails_closed(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'assigned-role', 'guard_name' => 'web']);

        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);

        $result = $this->preflight->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertSame(1, $result->modelHasRolesCount);
        $this->assertArrayHasKey(User::class, $result->modelHasRolesModelTypeCounts);
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::SpatieModelHasRolesNonEmpty->value,
            $result->failureReasonCodes(),
        );
    }

    #[Test]
    public function spatie_model_has_permissions_non_empty_fails_closed(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS, 'web');

        DB::table('model_has_permissions')->insert([
            'permission_id' => Permission::findByName(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS, 'web')->id,
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);

        $result = $this->preflight->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertSame(1, $result->modelHasPermissionsCount);
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::SpatieModelHasPermissionsNonEmpty->value,
            $result->failureReasonCodes(),
        );
    }

    #[Test]
    public function spatie_role_has_permissions_non_empty_fails_closed(): void
    {
        $role = Role::create(['name' => 'legacy-role', 'guard_name' => 'web']);
        $permission = Permission::findOrCreate(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS, 'web');

        DB::table('role_has_permissions')->insert([
            'permission_id' => $permission->id,
            'role_id' => $role->id,
        ]);

        $result = $this->preflight->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertSame(1, $result->roleHasPermissionsCount);
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::SpatieRoleHasPermissionsNonEmpty->value,
            $result->failureReasonCodes(),
        );
    }

    #[Test]
    public function missing_canonical_permission_codes_fails_closed(): void
    {
        WorkspacePermission::query()
            ->where('code', WorkspacePermissions::MANAGE_WORKSPACE_ACCESS)
            ->delete();

        $result = $this->preflight->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertContains(
            WorkspacePermissions::MANAGE_WORKSPACE_ACCESS,
            $result->missingCanonicalPermissionCodes,
        );
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::MissingCanonicalPermissionCodes->value,
            $result->failureReasonCodes(),
        );
    }

    #[Test]
    public function assert_safe_throws_structured_exception(): void
    {
        try {
            $this->preflight->assertSafe();
            $this->fail('Expected WorkspaceRbacLegacyPreflightException.');
        } catch (WorkspaceRbacLegacyPreflightException $exception) {
            $this->assertFalse($exception->result->isSafe);
            $this->assertNotEmpty($exception->result->failureReasonCodes());
        }
    }

    #[Test]
    public function spatie_permissions_table_rows_do_not_fail_preflight(): void
    {
        Permission::findOrCreate(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS, 'web');
        Permission::findOrCreate(WorkspacePermissions::MANAGE_TAX_SETTINGS, 'web');

        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $result = $this->preflight->evaluate();

        $this->assertTrue($result->isSafe);
    }
}
