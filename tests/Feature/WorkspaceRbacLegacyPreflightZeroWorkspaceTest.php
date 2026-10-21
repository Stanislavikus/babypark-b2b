<?php

namespace Tests\Feature;

use App\Services\Workspace\WorkspaceRbacLegacyPreflight;
use App\Support\Workspace\Rbac\WorkspaceRbacLegacyPreflightFailureReason;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceRbacLegacyPreflightZeroWorkspaceTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite_zero_workspace_preflight');
        $app['config']->set('database.connections.sqlite_zero_workspace_preflight', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        return $app;
    }

    #[Test]
    public function zero_workspaces_fails_closed(): void
    {
        Artisan::call('migrate:fresh');
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('workspaces')->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        $result = app(WorkspaceRbacLegacyPreflight::class)->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertSame(0, $result->totalWorkspacesCount);
        $this->assertSame(0, $result->defaultWorkspacesCount);
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::ZeroWorkspaces->value,
            $result->failureReasonCodes(),
        );
    }
}
