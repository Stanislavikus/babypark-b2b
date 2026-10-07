<?php

namespace Tests\Integration\MySql;

use App\Enums\UserRole;
use App\Models\Category;
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

final class CategoryDeleteConcurrencyTest extends TestCase
{
    use InteractsWithWorkspaceRbac;

    #[Test]
    public function concurrent_assignment_cannot_attach_product_to_category_being_deleted(): void
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
            'Category Delete Concurrency Manager',
            [WorkspacePermissions::MANAGE_PRODUCTS],
        );
        $this->assignRoleToMembership($membership, $role);

        $category = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Concurrent delete target',
            'parent_id' => null,
            'sort_order' => 0,
            'is_active' => true,
            'stock_display_threshold' => 10,
        ]);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Concurrent assignment product',
            'category_id' => null,
            'is_active' => true,
        ]);

        $ipcDir = sys_get_temp_dir().'/category-delete-concurrency-'.uniqid('', true);
        if (! mkdir($ipcDir) && ! is_dir($ipcDir)) {
            $this->fail('Could not create IPC directory.');
        }

        $worker = base_path('tests/Support/CategoryDeleteConcurrencyWorker.php');

        $delete = new Process([
            PHP_BINARY,
            $worker,
            'delete',
            $workspace->id,
            $actor->id,
            (string) $category->id,
            $ipcDir,
        ], base_path());
        $delete->setTimeout(120);
        $delete->start();

        $this->waitForFile($ipcDir.'/delete_category_locked');

        $assign = new Process([
            PHP_BINARY,
            $worker,
            'assign',
            (string) $product->id,
            (string) $category->id,
            $ipcDir,
        ], base_path());
        $assign->setTimeout(120);
        $assign->start();

        $this->waitForFile($ipcDir.'/assign_before_update');
        usleep(300_000);

        $this->assertFileDoesNotExist(
            $ipcDir.'/assign_result',
            'Assignment unexpectedly completed while the category delete lock was held.',
        );

        touch($ipcDir.'/parent_release_delete');

        $delete->wait();
        $assign->wait();

        $this->assertSame('committed', file_get_contents($ipcDir.'/delete_result'));
        $this->assertSame(0, $delete->getExitCode(), $delete->getErrorOutput());
        $this->assertSame('rejected', file_get_contents($ipcDir.'/assign_result'));
        $this->assertSame(0, $assign->getExitCode(), $assign->getErrorOutput());

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertNull($product->fresh()->category_id);
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
