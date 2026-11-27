<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Services\Workspace\WorkspaceRbacLegacyBackfill;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceRbacLegacyBackfillConflictException;
use App\Support\Workspace\Rbac\WorkspaceRbacLegacyTemplateDisplayNames;
use App\Support\Workspace\Rbac\WorkspaceRbacLegacyTemplateKeys;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceRbacLegacyBackfillTest extends TestCase
{
    use RefreshDatabase;

    private WorkspaceRbacLegacyBackfill $backfill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->backfill = app(WorkspaceRbacLegacyBackfill::class);
    }

    #[Test]
    public function backfill_materializes_memberships_roles_and_permissions(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);
        $director = User::factory()->create([
            'role' => UserRole::Director,
            'is_active' => true,
            'customer_id' => null,
        ]);
        $merchandiser = User::factory()->create([
            'role' => UserRole::Merchandiser,
            'is_active' => true,
            'customer_id' => null,
        ]);
        $inactiveStaff = User::factory()->create([
            'role' => UserRole::Manager,
            'is_active' => false,
            'customer_id' => null,
        ]);
        $manager = User::factory()->create([
            'role' => UserRole::Manager,
            'is_active' => true,
            'customer_id' => null,
        ]);
        $customer = Customer::query()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => 'customer-linked-guid',
            'name' => 'Linked Customer',
            'login' => 'customer-linked',
            'password' => 'password',
            'is_active' => true,
        ]);
        $customerLinked = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => $customer->id,
        ]);

        $displayNames = new WorkspaceRbacLegacyTemplateDisplayNames(
            accessManagerDisplayName: 'Access Manager Label',
            connectorDiscoveryDisplayName: 'Discovery Operator Label',
        );

        $this->backfill->execute($displayNames);

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $admin->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $inactiveStaff->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('workspace_users', [
            'user_id' => $customerLinked->id,
        ]);

        $accessManagerRole = WorkspaceRole::query()
            ->where('workspace_id', $workspace->id)
            ->where('template_key', WorkspaceRbacLegacyTemplateKeys::ACCESS_MANAGER)
            ->firstOrFail();
        $discoveryRole = WorkspaceRole::query()
            ->where('workspace_id', $workspace->id)
            ->where('template_key', WorkspaceRbacLegacyTemplateKeys::CONNECTOR_DISCOVERY_OPERATOR)
            ->firstOrFail();

        $this->assertSame('Access Manager Label', $accessManagerRole->name);
        $this->assertSame('Discovery Operator Label', $discoveryRole->name);

        $this->assertRolePermissions($accessManagerRole, WorkspaceRbacLegacyTemplateKeys::permissionsForKey(
            WorkspaceRbacLegacyTemplateKeys::ACCESS_MANAGER,
        ));
        $this->assertRolePermissions($discoveryRole, WorkspaceRbacLegacyTemplateKeys::permissionsForKey(
            WorkspaceRbacLegacyTemplateKeys::CONNECTOR_DISCOVERY_OPERATOR,
        ));

        $this->assertUserHasBootstrapRole($workspace->id, $admin->id, $accessManagerRole->id);
        $this->assertUserHasBootstrapRole($workspace->id, $director->id, $accessManagerRole->id);
        $this->assertUserHasBootstrapRole($workspace->id, $merchandiser->id, $discoveryRole->id);
        $this->assertUserHasNoBootstrapRoles($workspace->id, $manager->id);
        $this->assertUserHasNoBootstrapRoles($workspace->id, $inactiveStaff->id);

        $this->assertMappingPermissionsAssignedToNobody($workspace->id);
        $this->assertNoDirectMembershipPermissionPath();
    }

    #[Test]
    public function rerun_is_idempotent_and_preserves_renamed_role_display_name(): void
    {
        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $firstNames = new WorkspaceRbacLegacyTemplateDisplayNames('First Access Name', 'First Discovery Name');
        $this->backfill->execute($firstNames);

        $workspace = Workspace::query()->where('is_default', true)->sole();
        $membershipCount = WorkspaceUser::query()->where('workspace_id', $workspace->id)->count();
        $roleCount = WorkspaceRole::query()->where('workspace_id', $workspace->id)->count();
        $assignmentCount = DB::table('workspace_user_roles')->where('workspace_id', $workspace->id)->count();
        $rolePermissionCount = DB::table('workspace_role_permissions')->where('workspace_id', $workspace->id)->count();

        WorkspaceRole::query()
            ->where('workspace_id', $workspace->id)
            ->where('template_key', WorkspaceRbacLegacyTemplateKeys::ACCESS_MANAGER)
            ->update(['name' => 'Merchant Renamed Access Role']);

        $secondNames = new WorkspaceRbacLegacyTemplateDisplayNames('Different Access Name', 'Different Discovery Name');
        $this->backfill->execute($secondNames);

        $this->assertSame($membershipCount, WorkspaceUser::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame($roleCount, WorkspaceRole::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame($assignmentCount, DB::table('workspace_user_roles')->where('workspace_id', $workspace->id)->count());
        $this->assertSame($rolePermissionCount, DB::table('workspace_role_permissions')->where('workspace_id', $workspace->id)->count());

        $accessManagerRole = WorkspaceRole::query()
            ->where('workspace_id', $workspace->id)
            ->where('template_key', WorkspaceRbacLegacyTemplateKeys::ACCESS_MANAGER)
            ->firstOrFail();

        $this->assertSame('Merchant Renamed Access Role', $accessManagerRole->name);
    }

    #[Test]
    public function backfill_uses_resolved_default_workspace_not_hardcoded_uuid(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $displayNames = new WorkspaceRbacLegacyTemplateDisplayNames('Access', 'Discovery');
        $this->backfill->execute($displayNames);

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
        ]);
    }

    #[Test]
    public function admin_with_existing_custom_role_assignment_stops_without_touching_custom_assignment(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $membership = WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $admin->id,
            'is_active' => true,
        ]);

        $customRole = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Custom Admin Role',
            'template_key' => null,
        ]);

        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $workspace->id,
            'workspace_user_id' => $membership->id,
            'workspace_role_id' => $customRole->id,
        ]);

        $displayNames = new WorkspaceRbacLegacyTemplateDisplayNames('Access', 'Discovery');

        try {
            $this->backfill->execute($displayNames);
            $this->fail('Expected WorkspaceRbacLegacyBackfillConflictException.');
        } catch (WorkspaceRbacLegacyBackfillConflictException) {
            // expected
        }

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $membership->id,
            'workspace_role_id' => $customRole->id,
        ]);
        $this->assertDatabaseMissing('workspace_roles', [
            'workspace_id' => $workspace->id,
            'template_key' => WorkspaceRbacLegacyTemplateKeys::ACCESS_MANAGER,
        ]);
    }

    #[Test]
    public function manager_with_existing_custom_role_assignment_stops(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $manager = User::factory()->create([
            'role' => UserRole::Manager,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $membership = WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $manager->id,
            'is_active' => true,
        ]);

        $customRole = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Custom Manager Role',
            'template_key' => null,
        ]);

        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $workspace->id,
            'workspace_user_id' => $membership->id,
            'workspace_role_id' => $customRole->id,
        ]);

        $displayNames = new WorkspaceRbacLegacyTemplateDisplayNames('Access', 'Discovery');

        $this->expectException(WorkspaceRbacLegacyBackfillConflictException::class);
        $this->backfill->execute($displayNames);
    }

    #[Test]
    public function unknown_unassigned_custom_workspace_role_stops(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Unassigned Custom Role',
            'template_key' => null,
        ]);

        $displayNames = new WorkspaceRbacLegacyTemplateDisplayNames('Access', 'Discovery');

        $this->expectException(WorkspaceRbacLegacyBackfillConflictException::class);
        $this->backfill->execute($displayNames);
    }

    #[Test]
    public function customer_linked_existing_workspace_user_stops(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $customer = Customer::query()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => 'customer-existing-guid',
            'name' => 'Existing Customer',
            'login' => 'customer-existing',
            'password' => 'password',
            'is_active' => true,
        ]);

        $customerUser = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => $customer->id,
        ]);

        WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $customerUser->id,
            'is_active' => true,
        ]);

        $displayNames = new WorkspaceRbacLegacyTemplateDisplayNames('Access', 'Discovery');

        $this->expectException(WorkspaceRbacLegacyBackfillConflictException::class);
        $this->backfill->execute($displayNames);
    }

    #[Test]
    public function recognized_partial_bootstrap_state_safely_completes(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $merchandiser = User::factory()->create([
            'role' => UserRole::Merchandiser,
            'is_active' => true,
            'customer_id' => null,
        ]);

        WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $merchandiser->id,
            'is_active' => true,
        ]);

        $partialRole = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Partial Discovery',
            'template_key' => WorkspaceRbacLegacyTemplateKeys::CONNECTOR_DISCOVERY_OPERATOR,
        ]);

        $viewPermissionId = DB::table('workspace_permissions')
            ->where('code', WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS)
            ->value('id');

        DB::table('workspace_role_permissions')->insert([
            'workspace_id' => $workspace->id,
            'workspace_role_id' => $partialRole->id,
            'workspace_permission_id' => $viewPermissionId,
        ]);

        $displayNames = new WorkspaceRbacLegacyTemplateDisplayNames('Access', 'Discovery');
        $this->backfill->execute($displayNames);

        $this->assertRolePermissions($partialRole, WorkspaceRbacLegacyTemplateKeys::permissionsForKey(
            WorkspaceRbacLegacyTemplateKeys::CONNECTOR_DISCOVERY_OPERATOR,
        ));
        $this->assertUserHasBootstrapRole($workspace->id, $merchandiser->id, $partialRole->id);
    }

    #[Test]
    public function exact_successful_bootstrap_state_is_idempotent_no_op(): void
    {
        User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        $displayNames = new WorkspaceRbacLegacyTemplateDisplayNames('Access', 'Discovery');
        $this->backfill->execute($displayNames);

        $workspace = Workspace::query()->where('is_default', true)->sole();
        $membershipCount = WorkspaceUser::query()->where('workspace_id', $workspace->id)->count();
        $roleCount = WorkspaceRole::query()->where('workspace_id', $workspace->id)->count();
        $assignmentCount = DB::table('workspace_user_roles')->where('workspace_id', $workspace->id)->count();
        $rolePermissionCount = DB::table('workspace_role_permissions')->where('workspace_id', $workspace->id)->count();

        $this->backfill->execute($displayNames);

        $this->assertSame($membershipCount, WorkspaceUser::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame($roleCount, WorkspaceRole::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame($assignmentCount, DB::table('workspace_user_roles')->where('workspace_id', $workspace->id)->count());
        $this->assertSame($rolePermissionCount, DB::table('workspace_role_permissions')->where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function late_conflict_rolls_back_all_writes_and_preserves_pre_existing_rows(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $director = User::factory()->create([
            'role' => UserRole::Director,
            'is_active' => true,
            'customer_id' => null,
        ]);
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);
        $merchandiser = User::factory()->create([
            'role' => UserRole::Merchandiser,
            'is_active' => true,
            'customer_id' => null,
        ]);
        $inactiveManager = User::factory()->create([
            'role' => UserRole::Manager,
            'is_active' => false,
            'customer_id' => null,
        ]);

        $merchandiserMembership = WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $merchandiser->id,
            'is_active' => true,
        ]);

        $inactiveMembership = WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $inactiveManager->id,
            'is_active' => false,
        ]);

        $displayNames = new WorkspaceRbacLegacyTemplateDisplayNames('Access', 'Discovery');

        try {
            $this->backfill->execute($displayNames);
            $this->fail('Expected WorkspaceRbacLegacyBackfillConflictException.');
        } catch (WorkspaceRbacLegacyBackfillConflictException) {
            // expected late conflict on inactive membership materialization
        }

        $this->assertDatabaseMissing('workspace_roles', [
            'workspace_id' => $workspace->id,
            'template_key' => WorkspaceRbacLegacyTemplateKeys::ACCESS_MANAGER,
        ]);
        $this->assertDatabaseMissing('workspace_roles', [
            'workspace_id' => $workspace->id,
            'template_key' => WorkspaceRbacLegacyTemplateKeys::CONNECTOR_DISCOVERY_OPERATOR,
        ]);
        $this->assertDatabaseMissing('workspace_users', [
            'user_id' => $director->id,
        ]);
        $this->assertDatabaseMissing('workspace_users', [
            'user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('workspace_users', [
            'id' => $merchandiserMembership->id,
            'user_id' => $merchandiser->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('workspace_users', [
            'id' => $inactiveMembership->id,
            'user_id' => $inactiveManager->id,
            'is_active' => false,
        ]);

        $this->assertSame(0, DB::table('workspace_role_permissions')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, DB::table('workspace_user_roles')->where('workspace_id', $workspace->id)->count());
    }

    /**
     * @param  list<string>  $expectedCodes
     */
    private function assertRolePermissions(WorkspaceRole $role, array $expectedCodes): void
    {
        $codes = DB::table('workspace_role_permissions')
            ->join('workspace_permissions', 'workspace_permissions.id', '=', 'workspace_role_permissions.workspace_permission_id')
            ->where('workspace_role_permissions.workspace_role_id', $role->id)
            ->orderBy('workspace_permissions.code')
            ->pluck('workspace_permissions.code')
            ->all();

        $this->assertEqualsCanonicalizing($expectedCodes, $codes);
    }

    private function assertUserHasBootstrapRole(string $workspaceId, int $userId, string $roleId): void
    {
        $membershipId = WorkspaceUser::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->value('id');

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_id' => $workspaceId,
            'workspace_user_id' => $membershipId,
            'workspace_role_id' => $roleId,
        ]);
    }

    private function assertUserHasNoBootstrapRoles(string $workspaceId, int $userId): void
    {
        $membershipId = WorkspaceUser::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->value('id');

        $count = DB::table('workspace_user_roles')
            ->join('workspace_roles', 'workspace_roles.id', '=', 'workspace_user_roles.workspace_role_id')
            ->where('workspace_user_roles.workspace_user_id', $membershipId)
            ->whereIn('workspace_roles.template_key', WorkspaceRbacLegacyTemplateKeys::bootstrapKeys())
            ->count();

        $this->assertSame(0, $count);
    }

    private function assertMappingPermissionsAssignedToNobody(string $workspaceId): void
    {
        $mappingCodes = [
            WorkspacePermissions::VIEW_SYNC_MAPPINGS,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        ];

        $count = DB::table('workspace_user_roles')
            ->join('workspace_role_permissions', 'workspace_role_permissions.workspace_role_id', '=', 'workspace_user_roles.workspace_role_id')
            ->join('workspace_permissions', 'workspace_permissions.id', '=', 'workspace_role_permissions.workspace_permission_id')
            ->where('workspace_user_roles.workspace_id', $workspaceId)
            ->whereIn('workspace_permissions.code', $mappingCodes)
            ->count();

        $this->assertSame(0, $count);
    }

    private function assertNoDirectMembershipPermissionPath(): void
    {
        $this->assertFalse(
            DB::getSchemaBuilder()->hasTable('workspace_user_permissions'),
            'Direct membership permission grants must not exist.',
        );
    }
}
