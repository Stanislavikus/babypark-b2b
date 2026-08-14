<?php

namespace Tests\Feature;

use App\Filament\Pages\WorkspaceAccess\WorkspaceAccessMembersTable;
use App\Models\User;
use App\Models\Workspace;
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

class WorkspaceAccessMembersTableTest extends TestCase
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
    public function shows_existing_users_only_copy_and_no_add_actions(): void
    {
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($this->defaultWorkspace(), $actor);

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessMembersTable::class)
            ->assertSee(__('workspace_access.members.existing_users_only'))
            ->assertDontSee('Додати користувача')
            ->assertDontSee('Invite')
            ->assertDontSee('Запросити');
    }

    #[Test]
    public function permission_revocation_after_mount_fails_closed_on_refresh(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $membership = $this->grantManageWorkspaceAccess($workspace, $actor);

        $component = Livewire::actingAs($actor)
            ->test(WorkspaceAccessMembersTable::class)
            ->assertOk();

        DB::table('workspace_user_roles')
            ->where('workspace_user_id', $membership->id)
            ->delete();

        $component->call('$refresh')->assertForbidden();
    }

    #[Test]
    public function assign_and_remove_role_persist_through_mutation_service(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $target = $this->makeWorkspaceMembership($workspace);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Integration Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessMembersTable::class)
            ->callTableAction(
                'assignRole',
                $target,
                data: ['role_id' => $role->id],
            )
            ->assertNotified(__('workspace_access.notifications.member_role_assigned'));

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $target->id,
            'workspace_role_id' => $role->id,
        ]);

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessMembersTable::class)
            ->callTableAction(
                'removeRole',
                $target,
                data: ['role_id' => $role->id],
            )
            ->assertNotified(__('workspace_access.notifications.member_role_removed'));

        $this->assertDatabaseMissing('workspace_user_roles', [
            'workspace_user_id' => $target->id,
            'workspace_role_id' => $role->id,
        ]);
    }

    #[Test]
    public function deactivate_and_activate_membership_persist_through_mutation_service(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');
        $target = $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Target Holder');

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessMembersTable::class)
            ->callTableAction('deactivateMembership', $target)
            ->assertNotified(__('workspace_access.notifications.membership_deactivated'));

        $this->assertDatabaseHas('workspace_users', [
            'id' => $target->id,
            'is_active' => false,
        ]);

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessMembersTable::class)
            ->callTableAction('activateMembership', $target)
            ->assertNotified(__('workspace_access.notifications.membership_activated'));

        $this->assertDatabaseHas('workspace_users', [
            'id' => $target->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function anti_lockout_surfaces_merchant_safe_feedback_without_writes(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create();
        $holder = $this->makeEffectiveHolder($workspace, $actor, 'Only Holder');
        $roleId = DB::table('workspace_user_roles')
            ->where('workspace_user_id', $holder->id)
            ->value('workspace_role_id');

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessMembersTable::class)
            ->callTableAction(
                'removeRole',
                $holder,
                data: ['role_id' => (string) $roleId],
            )
            ->assertNotified(__('workspace_access.errors.lockout'));

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $holder->id,
            'workspace_role_id' => $roleId,
        ]);
    }

    #[Test]
    public function foreign_role_id_submitted_through_livewire_is_rejected(): void
    {
        $workspace = $this->defaultWorkspace();
        $otherWorkspace = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $target = $this->makeWorkspaceMembership($workspace);
        $foreignRole = $this->createRoleWithPermissions(
            $otherWorkspace->id,
            'Foreign Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessMembersTable::class)
            ->callTableAction(
                'assignRole',
                $target,
                data: ['role_id' => $foreignRole->id],
            )
            ->assertNotified(__('workspace_access.errors.foreign_target'));

        $this->assertDatabaseMissing('workspace_user_roles', [
            'workspace_user_id' => $target->id,
            'workspace_role_id' => $foreignRole->id,
        ]);
    }

    #[Test]
    public function role_options_exclude_foreign_workspace_roles(): void
    {
        $workspace = $this->defaultWorkspace();
        $otherWorkspace = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $target = $this->makeWorkspaceMembership($workspace);
        $localRole = $this->createRoleWithPermissions(
            $workspace->id,
            'Local Assignable',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );
        $foreignRole = $this->createRoleWithPermissions(
            $otherWorkspace->id,
            'Foreign Assignable',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessMembersTable::class)
            ->callTableAction(
                'assignRole',
                $target,
                data: ['role_id' => $localRole->id],
            )
            ->assertNotified(__('workspace_access.notifications.member_role_assigned'));

        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $target->id,
            'workspace_role_id' => $localRole->id,
        ]);

        Livewire::actingAs($actor)
            ->test(WorkspaceAccessMembersTable::class)
            ->callTableAction(
                'assignRole',
                $target,
                data: ['role_id' => $foreignRole->id],
            )
            ->assertNotified(__('workspace_access.errors.foreign_target'));

        $this->assertDatabaseMissing('workspace_user_roles', [
            'workspace_user_id' => $target->id,
            'workspace_role_id' => $foreignRole->id,
        ]);
    }
}
