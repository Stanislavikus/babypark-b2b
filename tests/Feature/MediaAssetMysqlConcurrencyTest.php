<?php

namespace Tests\Feature;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MediaAssetMysqlConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithWorkspaceRbac;

    #[Test]
    public function concurrent_identical_ingest_reuses_one_workspace_original(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MediaAsset ingest concurrency proof requires MySQL.');
        }

        $permission = WorkspacePermission::query()->firstOrCreate([
            'code' => WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $permissionWasCreated = $permission->wasRecentlyCreated;

        $workspace = Workspace::query()->create([
            'name' => 'MediaAsset concurrency '.uniqid('', true),
            'is_default' => false,
        ]);
        $actor = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions($workspace->id, 'Asset concurrency manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);

        $ipcDir = sys_get_temp_dir().'/media-asset-concurrency-'.uniqid('', true);
        File::ensureDirectoryExists($ipcDir);
        $sourcePath = $ipcDir.'/source.png';
        file_put_contents($sourcePath, $this->pngBytes(1200, 900));

        $processes = [];
        $storedPath = null;

        try {
            $worker = base_path('tests/Support/MediaAssetIngestConcurrencyWorker.php');

            for ($i = 0; $i < 2; $i++) {
                $process = new Process([
                    PHP_BINARY,
                    $worker,
                    (string) $workspace->id,
                    (string) $actor->id,
                    $sourcePath,
                    $ipcDir,
                ], base_path());

                $process->setTimeout(90);
                $process->start();

                $processes[] = [
                    'pid' => $process->getPid(),
                    'process' => $process,
                ];
            }

            $this->waitForReadyWorkers($ipcDir, 2);
            file_put_contents($ipcDir.'/go', '1');

            $results = [];

            foreach ($processes as $entry) {
                /** @var Process $process */
                $process = $entry['process'];
                $process->wait();

                $this->assertSame(
                    0,
                    $process->getExitCode(),
                    $process->getErrorOutput().$process->getOutput(),
                );

                $resultPath = $ipcDir.'/'.$entry['pid'].'.result';
                $this->assertFileExists($resultPath);
                $results[] = json_decode(
                    (string) file_get_contents($resultPath),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            }

            $this->assertSame(['success', 'success'], collect($results)->pluck('status')->sort()->values()->all());
            $assetIds = collect($results)->pluck('asset_id')->unique()->values()->all();
            $this->assertCount(1, $assetIds);

            $asset = MediaAsset::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereKey($assetIds[0])
                ->sole();

            $this->assertSame(1, MediaAsset::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('content_sha256', $asset->content_sha256)
                ->count());

            $storedPath = (string) $asset->storage_path;
            Storage::disk((string) $asset->storage_disk)->assertExists($storedPath);
        } finally {
            foreach ($processes as $entry) {
                /** @var Process $process */
                $process = $entry['process'];

                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('public')->delete($storedPath);
            }

            DB::transaction(function () use (
                $workspace,
                $actor,
                $membership,
                $role,
                $permission,
                $permissionWasCreated,
            ): void {
                DB::table('media_assets')
                    ->where('workspace_id', $workspace->id)
                    ->delete();

                DB::table('workspace_user_roles')
                    ->where('workspace_id', $workspace->id)
                    ->where('workspace_user_id', $membership->id)
                    ->where('workspace_role_id', $role->id)
                    ->delete();

                DB::table('workspace_role_permissions')
                    ->where('workspace_id', $workspace->id)
                    ->where('workspace_role_id', $role->id)
                    ->delete();

                DB::table('workspace_roles')
                    ->where('workspace_id', $workspace->id)
                    ->where('id', $role->id)
                    ->delete();

                DB::table('workspace_users')
                    ->where('workspace_id', $workspace->id)
                    ->where('id', $membership->id)
                    ->delete();

                Workspace::query()->whereKey($workspace->id)->delete();
                DB::table('users')->where('id', $actor->id)->delete();

                if ($permissionWasCreated) {
                    WorkspacePermission::query()->whereKey($permission->id)->delete();
                }
            });

            File::deleteDirectory($ipcDir);
        }
    }

    #[Test]
    public function mysql_restrict_fk_blocks_direct_asset_delete_when_product_media_still_references_it(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MediaAsset direct-delete FK proof requires MySQL.');
        }

        $workspace = Workspace::query()->create([
            'name' => 'MediaAsset FK guard '.uniqid('', true),
            'is_default' => false,
        ]);
        $asset = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/fk-guard.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'sku' => 'MEDIA-FK-'.uniqid(),
            'name' => 'Media FK guard product',
            'images' => [],
            'is_active' => true,
        ]);
        $productMediaId = (string) Str::uuid();

        DB::table('product_media')->insert([
            'id' => $productMediaId,
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $asset->id,
            'role' => 'gallery',
            'sort_order' => 0,
            'locale' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            try {
                DB::table('media_assets')
                    ->where('workspace_id', $workspace->id)
                    ->where('id', $asset->id)
                    ->delete();

                $this->fail('MySQL RESTRICT FK must reject a direct MediaAsset delete while ProductMedia references it.');
            } catch (QueryException) {
                $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
                $this->assertDatabaseHas('product_media', [
                    'id' => $productMediaId,
                    'media_asset_id' => $asset->id,
                ]);
            }
        } finally {
            DB::table('product_media')->where('id', $productMediaId)->delete();
            DB::table('media_assets')->where('id', $asset->id)->delete();
            Product::withoutWorkspaceScope()->whereKey($product->id)->delete();
            Workspace::query()->whereKey($workspace->id)->delete();
        }
    }

    #[Test]
    public function concurrent_direct_product_media_attach_cannot_create_dangling_reference_during_guarded_delete(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MediaAsset lifecycle concurrency proof requires MySQL.');
        }

        $permission = WorkspacePermission::query()->firstOrCreate([
            'code' => WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $permissionWasCreated = $permission->wasRecentlyCreated;
        $workspace = Workspace::query()->create([
            'name' => 'MediaAsset lifecycle concurrency '.uniqid('', true),
            'is_default' => false,
        ]);
        $actor = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions($workspace->id, 'Asset lifecycle concurrency manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);
        $asset = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/lifecycle-concurrency.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'sku' => 'MEDIA-RACE-'.uniqid(),
            'name' => 'Media lifecycle concurrency product',
            'images' => [],
            'is_active' => true,
        ]);
        $ipcDir = sys_get_temp_dir().'/media-lifecycle-concurrency-'.uniqid('', true);
        File::ensureDirectoryExists($ipcDir);
        $delete = null;
        $attach = null;

        try {
            $worker = base_path('tests/Support/MediaAssetLifecycleConcurrencyWorker.php');
            $delete = new Process([
                PHP_BINARY,
                $worker,
                'delete',
                (string) $workspace->id,
                (string) $actor->id,
                (string) $asset->id,
                (string) $product->id,
                $ipcDir,
            ], base_path());
            $delete->setTimeout(120);
            $delete->start();

            $this->waitForFile($ipcDir.'/delete_applied');

            $attach = new Process([
                PHP_BINARY,
                $worker,
                'attach',
                (string) $workspace->id,
                (string) $actor->id,
                (string) $asset->id,
                (string) $product->id,
                $ipcDir,
            ], base_path());
            $attach->setTimeout(120);
            $attach->start();

            $this->waitForFile($ipcDir.'/attach_before_insert');
            usleep(300_000);

            $this->assertFileDoesNotExist(
                $ipcDir.'/attach_result',
                'Direct ProductMedia insert unexpectedly completed while the Asset delete transaction was uncommitted.',
            );

            touch($ipcDir.'/parent_release_delete');
            $delete->wait();
            $attach->wait();

            $this->assertSame(0, $delete->getExitCode(), $delete->getErrorOutput().$delete->getOutput());
            $this->assertSame('committed', (string) file_get_contents($ipcDir.'/delete_result'));
            $this->assertSame(0, $attach->getExitCode(), $attach->getErrorOutput().$attach->getOutput());
            $this->assertSame('rejected', (string) file_get_contents($ipcDir.'/attach_result'));
            $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
            $this->assertDatabaseMissing('product_media', [
                'workspace_id' => $workspace->id,
                'product_id' => $product->id,
                'media_asset_id' => $asset->id,
            ]);
        } finally {
            foreach ([$delete, $attach] as $process) {
                if ($process instanceof Process && $process->isRunning()) {
                    $process->stop(1);
                }
            }

            DB::table('product_media')
                ->where('workspace_id', $workspace->id)
                ->where('product_id', $product->id)
                ->delete();
            MediaAsset::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereKey($asset->id)
                ->delete();
            Product::withoutWorkspaceScope()->whereKey($product->id)->delete();
            DB::table('workspace_user_roles')
                ->where('workspace_id', $workspace->id)
                ->where('workspace_user_id', $membership->id)
                ->where('workspace_role_id', $role->id)
                ->delete();
            DB::table('workspace_role_permissions')
                ->where('workspace_id', $workspace->id)
                ->where('workspace_role_id', $role->id)
                ->delete();
            DB::table('workspace_roles')
                ->where('workspace_id', $workspace->id)
                ->where('id', $role->id)
                ->delete();
            DB::table('workspace_users')
                ->where('workspace_id', $workspace->id)
                ->where('id', $membership->id)
                ->delete();
            Workspace::query()->whereKey($workspace->id)->delete();
            DB::table('users')->where('id', $actor->id)->delete();

            if ($permissionWasCreated) {
                WorkspacePermission::query()->whereKey($permission->id)->delete();
            }

            File::deleteDirectory($ipcDir);
        }
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

    private function waitForReadyWorkers(string $ipcDir, int $expected): void
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            if (count(glob($ipcDir.'/*.ready') ?: []) === $expected) {
                return;
            }

            usleep(25_000);
        }

        $this->fail('Timed out waiting for MediaAsset ingest concurrency workers.');
    }

    private function pngBytes(int $width, int $height): string
    {
        $bytes = "\x89PNG\r\n\x1a\n";
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $bytes .= pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
        $bytes .= pack('N', 0).'IEND'.pack('N', crc32('IEND'));

        return $bytes;
    }
}
