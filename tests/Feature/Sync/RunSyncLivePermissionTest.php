<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Models\WorkspacePermission;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class RunSyncLivePermissionTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
    }

    #[Test]
    public function catalogue_contains_tenth_run_sync_live_permission(): void
    {
        $this->assertCount(10, WorkspacePermissions::catalogue());
        $this->assertContains(WorkspacePermissions::RUN_SYNC_LIVE, WorkspacePermissions::catalogue());
    }

    #[Test]
    public function workspace_rbac_permission_seeder_is_idempotent_for_ten_permissions(): void
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->assertSame(10, WorkspacePermission::query()->count());
    }

    #[Test]
    public function seeding_creates_run_sync_live_catalogue_row(): void
    {
        $this->assertNotNull(
            WorkspacePermission::query()->where('code', WorkspacePermissions::RUN_SYNC_LIVE)->first(),
        );
    }

    #[Test]
    public function run_sync_live_is_not_auto_granted_to_legacy_template_roles(): void
    {
        $workspace = $this->defaultWorkspace();

        $livePermissionId = WorkspacePermission::query()
            ->where('code', WorkspacePermissions::RUN_SYNC_LIVE)
            ->value('id');

        $this->assertNotNull($livePermissionId);

        $autoGrantedCount = DB::table('workspace_role_permissions')
            ->where('workspace_id', $workspace->id)
            ->where('workspace_permission_id', $livePermissionId)
            ->count();

        $this->assertSame(0, $autoGrantedCount);
    }

    #[Test]
    public function preview_only_actor_is_denied_live_permission(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Preview Only',
            [WorkspacePermissions::RUN_SYNC_PREVIEW],
        );
        $this->assignRoleToMembership($membership, $role);

        $authorization = app(WorkspaceAuthorization::class);

        $this->assertFalse($authorization->allows($actor, $workspace, WorkspacePermissions::RUN_SYNC_LIVE));
    }

    #[Test]
    public function explicit_live_grant_is_recognized(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Live Runner',
            [WorkspacePermissions::RUN_SYNC_LIVE],
        );
        $this->assignRoleToMembership($membership, $role);

        $authorization = app(WorkspaceAuthorization::class);

        $this->assertTrue($authorization->allows($actor, $workspace, WorkspacePermissions::RUN_SYNC_LIVE));
    }
}
