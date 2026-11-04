<?php

namespace Tests\Integration\MySql;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class BrandDraftAssignmentConcurrencyTest extends TestCase
{
    use InteractsWithWorkspaceRbac;

    #[Test]
    public function draft_creation_waits_for_brand_deactivation_and_then_fails_closed(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Artisan::call('migrate:fresh');
        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $workspace = Workspace::query()->where('is_default', true)->sole();
        $actor = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Brand Concurrency Manager',
            [WorkspacePermissions::MANAGE_PRODUCTS],
        );
        $this->assignRoleToMembership($membership, $role);

        $brand = Brand::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Concurrent Brand',
            'logo_media_asset_id' => null,
            'short_description' => null,
            'is_active' => true,
        ]);

        $ipcDir = sys_get_temp_dir().'/brand-draft-concurrency-'.uniqid('', true);
        if (! mkdir($ipcDir) && ! is_dir($ipcDir)) {
            $this->fail('Could not create IPC directory.');
        }

        $worker = base_path('tests/Support/BrandDraftAssignmentConcurrencyWorker.php');

        $deactivate = new Process([
            PHP_BINARY,
            $worker,
            'deactivate',
            $workspace->id,
            $actor->id,
            (string) $brand->id,
            $ipcDir,
        ], base_path());
        $deactivate->setTimeout(120);
        $deactivate->start();

        $this->waitForFile($ipcDir.'/deactivate_workspace_locked');

        $create = new Process([
            PHP_BINARY,
            $worker,
            'create',
            $workspace->id,
            (string) $brand->id,
            $ipcDir,
        ], base_path());
        $create->setTimeout(120);
        $create->start();

        $this->waitForFile($ipcDir.'/create_started');
        usleep(300_000);

        $this->assertFileDoesNotExist(
            $ipcDir.'/create_result',
            'Draft creation unexpectedly completed while Brand deactivation held the Workspace lock.',
        );

        touch($ipcDir.'/parent_release_deactivate');

        $deactivate->wait();
        $create->wait();

        $this->assertSame('committed', file_get_contents($ipcDir.'/deactivate_result'));
        $this->assertSame(0, $deactivate->getExitCode(), $deactivate->getErrorOutput());
        $this->assertSame('rejected', file_get_contents($ipcDir.'/create_result'));
        $this->assertSame(0, $create->getExitCode(), $create->getErrorOutput());

        $this->assertFalse($brand->fresh()->is_active);
        $this->assertFalse(
            Product::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('name', 'Concurrent inactive Brand product')
                ->exists(),
        );
    }

    private function waitForFile(string $path, int $seconds = 60): void
    {
        $deadline = time() + $seconds;

        while (! file_exists($path) && time() < $deadline) {
            usleep(50_000);
        }

        if (! file_exists($path)) {
            $this->fail("Timed out waiting for {$path}");
        }
    }
}
