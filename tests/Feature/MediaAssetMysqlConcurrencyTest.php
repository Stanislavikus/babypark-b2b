<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MediaAssetMysqlConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithWorkspaceRbac;

    #[Test]
    public function concurrent_identical_ingest_reuses_one_workspace_original(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MediaAsset ingest concurrency proof requires MySQL.');
        }

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $workspace = $this->defaultWorkspace();
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

            DB::transaction(function () use ($workspace, $actor, $membership, $role): void {
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

                DB::table('users')->where('id', $actor->id)->delete();
            });

            File::deleteDirectory($ipcDir);
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
