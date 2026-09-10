<?php

namespace Tests\Feature;

use App\Filament\Pages\WorkspaceAccess\WorkspaceAccessRolesTable;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\Rbac\WorkspacePermissionLabels;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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
