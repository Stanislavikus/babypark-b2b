<?php

namespace Tests\Feature\Sync;

use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class RunSyncPreviewPermissionTest extends TestCase
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
        $this->assertContains(WorkspacePermissions::RUN_SYNC_PREVIEW, WorkspacePermissions::catalogue());
        $this->assertContains(WorkspacePermissions::RUN_SYNC_LIVE, WorkspacePermissions::catalogue());
        $this->assertContains(WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS, WorkspacePermissions::catalogue());
    }

    #[Test]
    public function run_sync_preview_is_not_auto_granted_to_legacy_template_roles(): void
    {
        $workspace = $this->defaultWorkspace();

        $legacyRoleCodes = WorkspaceRole::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('template_key')
            ->pluck('template_key')
            ->all();

        if ($legacyRoleCodes === []) {
            $this->markTestSkipped('No legacy template roles seeded in this environment.');
        }

        $previewPermissionId = WorkspacePermission::query()
            ->where('code', WorkspacePermissions::RUN_SYNC_PREVIEW)
            ->value('id');

        $this->assertNotNull($previewPermissionId);

        $autoGrantedCount = DB::table('workspace_role_permissions')
            ->where('workspace_id', $workspace->id)
            ->where('workspace_permission_id', $previewPermissionId)
            ->count();

        $this->assertSame(0, $autoGrantedCount);
    }
}
