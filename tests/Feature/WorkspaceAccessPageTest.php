<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\WorkspaceAccess;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class WorkspaceAccessPageTest extends TestCase
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
    public function actor_with_manage_workspace_access_sees_navigation_and_page(): void
    {
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($this->defaultWorkspace(), $actor);

        $this->actingAs($actor)
            ->get('/admin/workspace-access')
            ->assertOk()
            ->assertSee(__('workspace_access.tabs.members'))
            ->assertSee(__('workspace_access.tabs.roles'));
    }

    #[Test]
    public function actor_without_permission_cannot_access_page_or_navigation(): void
    {
        $actor = User::factory()->create();

        $this->assertFalse(WorkspaceAccess::canAccess());

        $this->actingAs($actor)
            ->get('/admin/workspace-access')
            ->assertForbidden();
    }

    #[Test]
    public function legacy_admin_alone_cannot_access_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get('/admin/workspace-access')
            ->assertForbidden();
    }

    #[Test]
    public function legacy_director_alone_cannot_access_page(): void
    {
        $director = User::factory()->create(['role' => UserRole::Director]);

        $this->actingAs($director)
            ->get('/admin/workspace-access')
            ->assertForbidden();
    }

    #[Test]
    public function global_spatie_grant_alone_cannot_access_page(): void
    {
        $actor = User::factory()->create();
        Permission::findOrCreate(WorkspacePermissions::MANAGE_WORKSPACE_ACCESS, 'web');
        $actor->givePermissionTo(WorkspacePermissions::MANAGE_WORKSPACE_ACCESS);

        $this->actingAs($actor)
            ->get('/admin/workspace-access')
            ->assertForbidden();
    }

    #[Test]
    public function inactive_global_user_is_denied(): void
    {
        $actor = User::factory()->create(['is_active' => false]);
        $this->grantManageWorkspaceAccess($this->defaultWorkspace(), $actor);

        $this->actingAs($actor)
            ->get('/admin/workspace-access')
            ->assertForbidden();
    }

    #[Test]
    public function inactive_workspace_user_is_denied(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($this->defaultWorkspace(), $actor, false);
        $role = $this->createRoleWithPermissions(
            $this->defaultWorkspace()->id,
            'Inactive membership role',
            [WorkspacePermissions::MANAGE_WORKSPACE_ACCESS],
        );
        $this->assignRoleToMembership($membership, $role);

        $this->actingAs($actor)
            ->get('/admin/workspace-access')
            ->assertForbidden();
    }

    #[Test]
    public function can_access_does_not_use_tax_settings_authorization_helper(): void
    {
        $source = File::get(app_path('Filament/Pages/WorkspaceAccess.php'));

        $this->assertStringNotContainsString('WorkspaceTaxSettingsAuthorization', $source);
        $this->assertStringContainsString('WorkspaceAuthorization::class)->allows', $source);
    }

    #[Test]
    public function secondary_workspace_rows_are_not_rendered(): void
    {
        $workspace = $this->defaultWorkspace();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other Workspace',
            'is_default' => false,
        ]);

        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($workspace, $actor);

        $defaultMember = $this->makeWorkspaceMembership($workspace, User::factory()->create([
            'name' => 'Default Workspace Member',
            'email' => 'default-member@example.test',
        ]));
        $foreignMember = $this->makeWorkspaceMembership($otherWorkspace, User::factory()->create([
            'name' => 'Foreign Workspace Member',
            'email' => 'foreign-member@example.test',
        ]));
        $foreignRole = $this->createRoleWithPermissions(
            $otherWorkspace->id,
            'Foreign Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );

        $response = $this->actingAs($actor)
            ->get('/admin/workspace-access');

        $response->assertOk()
            ->assertSee('Default Workspace Member')
            ->assertDontSee('Foreign Workspace Member')
            ->assertDontSee('Foreign Role');

        $this->assertNotSame($defaultMember->workspace_id, $foreignMember->workspace_id);
        $this->assertSame($otherWorkspace->id, $foreignRole->workspace_id);
    }
}
