<?php

namespace Tests\Feature;

use App\Filament\Pages\WorkspaceAccess\WorkspaceAccessRolesTable;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAccessMutationService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\Rbac\WorkspacePermissionLabels;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class WorkspaceAccessRolesTableTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function permission_revocation_after_mount_fails_closed_on_refresh(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $membership = $this->grantManageWorkspaceAccess($workspace, $actor);

        $component = Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->assertOk();

        DB::table('workspace_user_roles')
            ->where('workspace_user_id', $membership->id)
            ->delete();

        $component->call('$refresh')->assertForbidden();
    }

    #[Test]
    public function create_role_persists_through_mutation_service_with_null_template_key(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->callTableAction('createRole', data: [
                'name' => 'Merchant Role',
                'permissions' => [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
            ])
            ->assertNotified(__('workspace_access.notifications.role_created'));

        $this->assertDatabaseHas('workspace_roles', [
            'workspace_id' => $workspace->id,
            'name' => 'Merchant Role',
            'template_key' => null,
        ]);
    }

    #[Test]
    public function rename_role_persists_through_mutation_service(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Before',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->callTableAction(
                'renameRole',
                $role,
                data: ['name' => 'After'],
            )
            ->assertNotified(__('workspace_access.notifications.role_renamed'));

        $this->assertDatabaseHas('workspace_roles', [
            'id' => $role->id,
            'name' => 'After',
        ]);
    }

    #[Test]
    public function edit_permissions_persists_canonical_bundle_through_mutation_service(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Editable',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );
        $permissions = WorkspacePermissions::catalogue();

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->callTableAction(
                'editPermissions',
                $role,
                data: ['permissions' => $permissions],
            )
            ->assertNotified(__('workspace_access.notifications.role_permissions_updated'));

        $storedCodes = DB::table('workspace_role_permissions')
            ->join('workspace_permissions', 'workspace_permissions.id', '=', 'workspace_role_permissions.workspace_permission_id')
            ->where('workspace_role_permissions.workspace_role_id', $role->id)
            ->orderBy('workspace_permissions.code')
            ->pluck('workspace_permissions.code')
            ->all();

        $this->assertEqualsCanonicalizing($permissions, $storedCodes);
    }

    #[Test]
    public function mounted_edit_permissions_normalizes_browser_checkbox_map_and_preserves_other_roles(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $actorMembership = $this->makeWorkspaceMembership($workspace, $actor, true);
        $editableRole = $this->createRoleWithPermissions(
            $workspace->id,
            'Editable Access Role',
            [WorkspacePermissions::MANAGE_WORKSPACE_ACCESS],
        );
        $this->assignRoleToMembership($actorMembership, $editableRole);

        $untouchedRole = $this->createRoleWithPermissions(
            $workspace->id,
            'Untouched Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );
        $untouchedRolePermissionIds = DB::table('workspace_role_permissions')
            ->where('workspace_role_id', $untouchedRole->id)
            ->pluck('workspace_permission_id')
            ->all();

        $component = Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->mountTableAction('editPermissions', $editableRole)
            ->assertTableActionDataSet([
                'permissions' => [WorkspacePermissions::MANAGE_WORKSPACE_ACCESS],
            ])
            ->set('mountedActions.0.data.permissions', [
                WorkspacePermissions::MANAGE_WORKSPACE_ACCESS => true,
                WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS => true,
            ])
            ->assertSet('mountedActions.0.data.permissions', [
                WorkspacePermissions::MANAGE_WORKSPACE_ACCESS => true,
                WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS => true,
            ]);

        $component
            ->callMountedTableAction()
            ->assertNotified(__('workspace_access.notifications.role_permissions_updated'));

        $storedCodes = DB::table('workspace_role_permissions')
            ->join('workspace_permissions', 'workspace_permissions.id', '=', 'workspace_role_permissions.workspace_permission_id')
            ->where('workspace_role_permissions.workspace_role_id', $editableRole->id)
            ->orderBy('workspace_permissions.code')
            ->pluck('workspace_permissions.code')
            ->all();

        $this->assertSame([
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::MANAGE_WORKSPACE_ACCESS,
        ], $storedCodes);

        $this->assertTrue(
            app(WorkspaceAuthorization::class)->allows(
                $actor->fresh(),
                $workspace->fresh(),
                WorkspacePermissions::MANAGE_WORKSPACE_ACCESS,
            ),
        );

        $this->assertSame(
            $untouchedRolePermissionIds,
            DB::table('workspace_role_permissions')
                ->where('workspace_role_id', $untouchedRole->id)
                ->pluck('workspace_permission_id')
                ->all(),
        );
    }

    #[Test]
    public function mounted_edit_permissions_passes_canonical_codes_to_mutation_service(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Editable',
            [WorkspacePermissions::MANAGE_WORKSPACE_ACCESS],
        );

        $this->grantManageWorkspaceAccess($workspace, $actor);

        $this->mock(WorkspaceAccessMutationService::class, function (MockInterface $mock) use ($actor, $workspace, $role): void {
            $mock->shouldReceive('updateRolePermissions')
                ->once()
                ->withArgs(function (User $actualActor, Workspace $actualWorkspace, string $actualRoleId, array $actualPermissionCodes) use ($actor, $workspace, $role): bool {
                    $this->assertSame($actor->id, $actualActor->id);
                    $this->assertSame($workspace->id, $actualWorkspace->id);
                    $this->assertSame($role->id, $actualRoleId);
                    $this->assertSame([
                        WorkspacePermissions::MANAGE_WORKSPACE_ACCESS,
                        WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
                    ], $actualPermissionCodes);

                    return true;
                })
                ->andReturn($role->fresh());
        });

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->mountTableAction('editPermissions', $role)
            ->assertTableActionDataSet([
                'permissions' => [WorkspacePermissions::MANAGE_WORKSPACE_ACCESS],
            ])
            ->set('mountedActions.0.data.permissions', [
                WorkspacePermissions::MANAGE_WORKSPACE_ACCESS => true,
                WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS => true,
            ])
            ->assertSet('mountedActions.0.data.permissions', [
                WorkspacePermissions::MANAGE_WORKSPACE_ACCESS => true,
                WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS => true,
            ])
            ->callMountedTableAction()
            ->assertNotified(__('workspace_access.notifications.role_permissions_updated'));
    }

    #[Test]
    public function mounted_edit_permissions_rejects_forged_permission_from_browser_checkbox_map(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Editable',
            [WorkspacePermissions::MANAGE_WORKSPACE_ACCESS],
        );

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->mountTableAction('editPermissions', $role)
            ->set('mountedActions.0.data.permissions', [
                WorkspacePermissions::MANAGE_WORKSPACE_ACCESS => true,
                'forged_unknown_permission' => true,
            ])
            ->callMountedTableAction()
            ->assertNotified(__('workspace_access.errors.unknown_permission'));

        $storedCodes = DB::table('workspace_role_permissions')
            ->join('workspace_permissions', 'workspace_permissions.id', '=', 'workspace_role_permissions.workspace_permission_id')
            ->where('workspace_role_permissions.workspace_role_id', $role->id)
            ->orderBy('workspace_permissions.code')
            ->pluck('workspace_permissions.code')
            ->all();

        $this->assertSame([WorkspacePermissions::MANAGE_WORKSPACE_ACCESS], $storedCodes);
    }

    #[Test]
    public function mounted_edit_permissions_still_rejects_removing_final_access_holder(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $holderMembership = $this->makeWorkspaceMembership($workspace, $actor, true);
        $holderRole = $this->createRoleWithPermissions(
            $workspace->id,
            'Only Holder Role',
            [WorkspacePermissions::MANAGE_WORKSPACE_ACCESS],
        );
        $this->assignRoleToMembership($holderMembership, $holderRole);

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->mountTableAction('editPermissions', $holderRole)
            ->set('mountedActions.0.data.permissions', [
                WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS => true,
            ])
            ->callMountedTableAction()
            ->assertNotified(__('workspace_access.errors.lockout'));

        $storedCodes = DB::table('workspace_role_permissions')
            ->join('workspace_permissions', 'workspace_permissions.id', '=', 'workspace_role_permissions.workspace_permission_id')
            ->where('workspace_role_permissions.workspace_role_id', $holderRole->id)
            ->orderBy('workspace_permissions.code')
            ->pluck('workspace_permissions.code')
            ->all();

        $this->assertSame([WorkspacePermissions::MANAGE_WORKSPACE_ACCESS], $storedCodes);
    }

    #[Test]
    public function delete_unused_role_works_and_assigned_role_is_rejected_safely(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);

        $unused = $this->createRoleWithPermissions(
            $workspace->id,
            'Unused Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );
        $assigned = $this->createRoleWithPermissions(
            $workspace->id,
            'Assigned Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );
        $this->assignRoleToMembership($this->makeWorkspaceMembership($workspace), $assigned);

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->callTableAction('deleteRole', $unused)
            ->assertNotified(__('workspace_access.notifications.role_deleted'));

        $this->assertDatabaseMissing('workspace_roles', ['id' => $unused->id]);

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->callTableAction('deleteRole', $assigned)
            ->assertNotified(__('workspace_access.errors.role_still_assigned'));

        $this->assertDatabaseHas('workspace_roles', ['id' => $assigned->id]);
    }

    #[Test]
    public function permission_labels_map_canonical_codes_to_merchant_copy(): void
    {
        app()->setLocale('uk');

        foreach (WorkspacePermissionLabels::options() as $code => $label) {
            $this->assertSame(__("workspace_access.permissions.{$code}"), $label);
            $this->assertNotSame($code, $label);
        }

        $this->assertSame('Керування доступом', WorkspacePermissionLabels::label(WorkspacePermissions::MANAGE_WORKSPACE_ACCESS));
    }

    #[Test]
    public function edit_permissions_modal_shows_merchant_labels_not_raw_codes(): void
    {
        app()->setLocale('uk');

        foreach (WorkspacePermissionLabels::options() as $code => $label) {
            $this->assertStringNotContainsString($code, $label);
        }

        $this->assertSame(
            'Керування доступом',
            WorkspacePermissionLabels::label(WorkspacePermissions::MANAGE_WORKSPACE_ACCESS),
        );
    }

    #[Test]
    public function assignment_count_is_workspace_correct(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Counted Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );
        $this->assignRoleToMembership($this->makeWorkspaceMembership($workspace), $role);
        $this->assignRoleToMembership($this->makeWorkspaceMembership($workspace), $role);

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->assertSee('Counted Role')
            ->assertSee('2');
    }

    #[Test]
    public function secondary_workspace_roles_are_not_listed(): void
    {
        $workspace = $this->defaultWorkspace();
        $otherWorkspace = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->createRoleWithPermissions(
            $workspace->id,
            'Default Workspace Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );
        $this->createRoleWithPermissions(
            $otherWorkspace->id,
            'Foreign Workspace Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->assertSee('Default Workspace Role')
            ->assertDontSee('Foreign Workspace Role');
    }
}
