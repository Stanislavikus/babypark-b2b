<?php

namespace Tests\Feature;

use App\Services\Workspace\WorkspaceRbacLegacyPreflight;
use App\Support\Workspace\Rbac\WorkspaceRbacLegacyPreflightFailureReason;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceRbacLegacyPreflightZeroWorkspaceTest extends TestCase
{
    #[Test]
    public function zero_workspaces_fails_closed(): void
    {
        Artisan::call('migrate:fresh');
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        DB::table('workspaces')->delete();

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $result = app(WorkspaceRbacLegacyPreflight::class)->evaluate();

        $this->assertFalse($result->isSafe);
        $this->assertContains(
            WorkspaceRbacLegacyPreflightFailureReason::ZeroWorkspaces->value,
            $result->failureReasonCodes(),
        );
    }
}
